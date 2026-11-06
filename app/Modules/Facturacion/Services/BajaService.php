<?php

namespace App\Modules\Facturacion\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Comunicacion;
use App\Models\ComunicacionItem;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Caja\Services\PagoService;
use App\Modules\Facturacion\Jobs\EnviarComunicacionJob;
use App\Modules\Inventario\Services\InventarioService;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bajas ante SUNAT:
 *  - RA (POST /anulaciones): facturas y sus notas (serie F). Ejemplos Postman RA-01/RA-02 aceptados.
 *  - RC de anulación (POST /resumenes con "anular"): boletas. Ejemplo RC-02 aceptado.
 * Hallazgo H-1 (corregido): si se omite fecha_comunicacion la API usa la fecha UTC y SUNAT rechaza con 2301
 * después de las 19:00 de Lima. Por eso SIEMPRE se envía fecha_comunicacion con la fecha de Lima.
 */
class BajaService
{
    public const PLAZO_DIAS = 7;

    public function __construct(
        private readonly SunatApiClientFactory $factory,
        private readonly Auditor $auditor,
        private readonly InventarioService $inventario,
        private readonly PagoService $pagos,
    ) {}

    /** @param list<int> $ids */
    public function solicitar(Empresa $empresa, User $user, array $ids, string $motivo): Comunicacion
    {
        $hoy = Carbon::now('America/Lima')->startOfDay();

        $com = DB::transaction(function () use ($empresa, $user, $ids, $motivo, $hoy) {
            $cs = Comprobante::deEmpresa($empresa->id)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($cs->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['comprobante_ids' => 'Comprobante no encontrado.']);
            }
            foreach ($cs as $c) {
                if ($c->estado !== 'aceptado') {
                    throw ValidationException::withMessages(['comprobante_ids' => "{$c->numero()} no está aceptado por SUNAT (estado: {$c->estado})."]);
                }
                if ($c->fecha_emision->copy()->addDays(self::PLAZO_DIAS)->lt($hoy)) {
                    throw ValidationException::withMessages(['comprobante_ids' => "{$c->numero()} superó el plazo de ".self::PLAZO_DIAS.' días para la baja: emite una nota de crédito 01.']);
                }
                if ($c->tipo_documento !== '03' && $c->serie[0] !== 'F') {
                    throw ValidationException::withMessages(['comprobante_ids' => "{$c->numero()}: las notas de boletas se anulan con otra nota; la baja por resumen está validada solo para boletas."]);
                }
                $activa = ComunicacionItem::query()->where('comprobante_id', $c->id)
                    ->join('comunicaciones', 'comunicaciones.id', '=', 'comunicacion_items.comunicacion_id')
                    ->where('comunicaciones.accion', 'anulacion')->whereNotIn('comunicaciones.estado', ['rechazado', 'error'])->exists();
                if ($activa) {
                    throw ValidationException::withMessages(['comprobante_ids' => "{$c->numero()} ya tiene una baja en curso o aceptada."]);
                }
            }
            $tipos = $cs->map(fn ($c) => $c->tipo_documento === '03' ? 'RC' : 'RA')->unique();
            $fechas = $cs->map(fn ($c) => $c->fecha_emision->toDateString())->unique();
            if ($tipos->count() > 1 || $fechas->count() > 1) {
                throw ValidationException::withMessages(['comprobante_ids' => 'Agrupa en una misma baja comprobantes del mismo tipo (facturas/notas o boletas) y de la misma fecha de emisión.']);
            }
            $tipo = $tipos->first();
            $fecha = $fechas->first();
            if ($tipo === 'RA') {
                $payload = [
                    'fecha_generacion' => $fecha,
                    'fecha_comunicacion' => $hoy->toDateString(), // explícita: evita el defecto UTC de la API (H-1)
                    'detalles' => $cs->map(fn ($c) => ['tipo_documento' => $c->tipo_documento, 'serie' => $c->serie, 'correlativo' => (string) $c->correlativo, 'motivo' => $motivo])->values()->all(),
                    'enviar_automatico' => true,
                ];
            } else {
                if ($cs->contains(fn ($c) => ! $c->api_id)) {
                    throw ValidationException::withMessages(['comprobante_ids' => 'La boleta no tiene identificador en la API.']);
                }
                $payload = [
                    'fecha_resumen' => $fecha,
                    'anular' => $cs->map(fn ($c) => ['id' => $c->api_id, 'motivo' => $motivo])->values()->all(),
                    'enviar_automatico' => true,
                ];
            }
            $com = Comunicacion::create([
                'empresa_id' => $empresa->id, 'tipo' => $tipo, 'fecha_documentos' => $fecha, 'motivo' => $motivo,
                'estado' => 'por_enviar', 'payload' => $payload, 'user_id' => $user->id,
            ]);
            foreach ($cs as $c) {
                ComunicacionItem::create(['comunicacion_id' => $com->id, 'comprobante_id' => $c->id, 'motivo' => $motivo]);
                $c->estados()->create(['estado_anterior' => $c->estado, 'estado_nuevo' => $c->estado, 'detalle' => "Baja solicitada ({$tipo}): $motivo"]);
            }
            EnviarComunicacionJob::dispatch($com->id)->afterCommit();

            return $com;
        });
        $this->auditor->registrar('baja.solicitada', $com, [], ['tipo' => $com->tipo, 'comprobantes' => $ids, 'motivo' => $motivo], $empresa->id);

        return $com;
    }

    /** Envía la comunicación. Devuelve true si quedó en un estado que requiere consultar el ticket. */
    public function enviar(Comunicacion $c): bool
    {
        if ($c->api_id) {
            return ! $c->esFinal();
        }
        $api = $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id));
        $c->increment('intentos');
        $path = '/'.$c->recursoApi();
        try {
            $res = $api->post($path, $c->payload);
        } catch (SunatApiConnectionException $e) {
            $this->log($c, 'enviar', 'POST', $path, $c->payload, null, $e->getMessage());
            throw $e;
        }
        $this->log($c, 'enviar', 'POST', $path, $c->payload, $res);
        if ($res->status >= 500) {
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
        }
        if (! $res->ok()) {
            $msg = ($res->mensaje() ?? "HTTP {$res->status}").($res->errores() ? ': '.collect($res->errores())->flatten()->implode(' ') : '');
            $c->update(['estado' => 'error', 'ultimo_error' => mb_substr($msg, 0, 1000)]);

            return false;
        }
        $this->aplicarRespuesta($c, $res->datos() ?? []);

        return ! $c->esFinal();
    }

    public function consultar(Comunicacion $c): bool
    {
        if (! $c->api_id) {
            return false;
        }
        $path = '/'.$c->recursoApi().'/'.$c->api_id.'/estado';
        $res = $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id))->get($path);
        $this->log($c, 'consultar_estado', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicarRespuesta($c, $res->datos());
        }

        return $c->esFinal();
    }

    /** Estructura real: {id_anulacion|id_resumen, identifier, ticket, estado_sunat, codigo_sunat, descripcion_sunat}. */
    private function aplicarRespuesta(Comunicacion $c, array $d): void
    {
        $estado = match (strtolower((string) ($d['estado_sunat'] ?? 'enviado'))) {
            'aceptado' => 'aceptado', 'rechazado' => 'rechazado', default => 'enviado',
        };
        $c->update([
            'api_id' => $d['id_anulacion'] ?? $d['id_resumen'] ?? $c->api_id,
            'identifier' => $d['identifier'] ?? $c->identifier,
            'ticket' => $d['ticket'] ?? $c->ticket,
            'estado' => $estado,
            'sunat_codigo' => isset($d['codigo_sunat']) ? (string) $d['codigo_sunat'] : $c->sunat_codigo,
            'sunat_descripcion' => isset($d['descripcion_sunat']) ? mb_substr((string) $d['descripcion_sunat'], 0, 1000) : $c->sunat_descripcion,
            'ultimo_error' => null,
        ]);
        if ($estado === 'aceptado') {
            $this->aplicarEfectos($c);
        } elseif ($estado === 'rechazado') {
            foreach ($c->items()->with('comprobante')->get() as $it) {
                $it->comprobante->estados()->create(['estado_anterior' => $it->comprobante->estado, 'estado_nuevo' => $it->comprobante->estado,
                    'detalle' => "Baja {$c->identifier} rechazada: {$c->sunat_codigo} {$c->sunat_descripcion}"]);
            }
        }
    }

    /** Efectos comerciales de una baja ACEPTADA (idempotente). */
    public function aplicarEfectos(Comunicacion $c): void
    {
        DB::transaction(function () use ($c) {
            $c = Comunicacion::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->id);
            if ($c->efectos_aplicados) {
                return;
            }
            foreach ($c->items()->get() as $it) {
                $comp = Comprobante::withoutGlobalScopes()->lockForUpdate()->findOrFail($it->comprobante_id);
                $comp->cambiarEstado('anulado', "Dado de baja ante SUNAT ({$c->identifier}): {$it->motivo}");
                $doc = $c->identifier ?? "{$c->tipo} {$comp->numero()}";

                if (in_array($comp->tipo_documento, ['01', '03'], true) && $comp->venta_id) {
                    $venta = Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($comp->venta_id);
                    $venta->update(['estado' => 'anulada', 'anulada_por_baja' => true]);
                    // Reingresa lo vendido que no haya sido ya devuelto por notas de crédito.
                    $devuelto = ComprobanteItem::query()->whereIn('comprobante_id', Comprobante::withoutGlobalScopes()->where('comprobante_referencia_id', $comp->id)->where('estado', '!=', 'anulado')->pluck('id'))
                        ->where('devuelve_stock', true)->selectRaw('venta_detalle_id, SUM(cantidad) c')->groupBy('venta_detalle_id')->pluck('c', 'venta_detalle_id');
                    $lineas = $venta->detalles()->whereNotNull('producto_id')->get()
                        ->map(fn ($l) => ['producto_id' => $l->producto_id, 'cantidad' => Dec::sub($l->cantidad, $devuelto[$l->id] ?? '0')])
                        ->filter(fn ($l) => Dec::cmp($l['cantidad'], '0') > 0)->values()->all();
                    if ($lineas) {
                        $this->inventario->devolverPorNota($venta, $lineas, $doc, $c->user_id);
                    }
                    $this->pagos->recalcular($venta);
                } elseif (in_array($comp->tipo_documento, ['07', '08'], true) && $comp->comprobante_referencia_id) {
                    // Baja de una nota: se revierte su efecto sobre la venta original.
                    $ref = Comprobante::withoutGlobalScopes()->find($comp->comprobante_referencia_id);
                    if ($ref?->venta_id) {
                        $venta = Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($ref->venta_id);
                        if ($comp->tipo_documento === '07') {
                            $venta->total_notas_credito = Dec::round(Dec::sub($venta->total_notas_credito, $comp->total));
                            if (in_array($comp->cod_motivo, ['01', '02'], true) && ! $venta->anulada_por_baja) {
                                $venta->estado = 'registrada';
                            }
                            $venta->save();
                            $devueltas = $comp->items()->where('devuelve_stock', true)->get()->map(fn ($i) => ['producto_id' => $i->producto_id, 'cantidad' => (string) $i->cantidad])->all();
                            if ($devueltas) {
                                $this->inventario->revertirDevolucion($venta, $devueltas, $doc, $c->user_id);
                            }
                        } else {
                            $venta->total_notas_debito = Dec::round(Dec::sub($venta->total_notas_debito, $comp->total));
                            $venta->save();
                        }
                        $this->pagos->recalcular($venta);
                    }
                }
            }
            $c->update(['efectos_aplicados' => true]);
        });
        $this->auditor->registrar('baja.aceptada', $c, [], ['identifier' => $c->identifier], $c->empresa_id);
    }

    private function log(Comunicacion $c, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null): void
    {
        ApiSolicitud::create([
            'empresa_id' => $c->empresa_id, 'comprobante_id' => null, 'operacion' => "baja_{$op}", 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res?->status, 'respuesta' => $res?->json, 'duracion_ms' => $res?->durationMs, 'error' => $error,
        ]);
    }
}
