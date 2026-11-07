<?php

namespace App\Modules\Facturacion\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Comprobante;
use App\Models\Comunicacion;
use App\Models\ComunicacionItem;
use App\Models\Empresa;
use App\Models\User;
use App\Modules\Facturacion\Jobs\DescargarArchivosComprobanteJob;
use App\Modules\Facturacion\Jobs\EnviarComunicacionJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resumen diario de ENVÍO de boletas (RC). Flujo validado en la Etapa 1 (RC-03, aceptado):
 *  1. POST /resumenes {fecha_resumen, enviar_automatico:false} → 201 datos{id_resumen, identifier, estado_sunat:"pendiente", documentos[]}
 *  2. POST /resumenes/{id}/enviar → 200 datos{estado_sunat:"enviado"}
 *  3. GET /resumenes/{id}/estado → datos{estado_sunat, codigo_sunat, descripcion_sunat, documentos[{id, serie_nro, estado_sunat}]}
 * H-5: la API rechaza un RC con boletas ya incluidas en otro (422 "ya están incluidas en un resumen"). El envío
 * automático solo resume fechas ANTERIORES a hoy (cuando ya no se emitirán más boletas de ese día); el usuario
 * puede enviar manualmente el de HOY. Mientras un RC de una fecha esté en curso no se crea otro para esa fecha;
 * una vez aceptado, las boletas emitidas después pueden ir en un nuevo RC de la misma fecha.
 * La API incluye TODAS las boletas pendientes de la cuenta en esa fecha: las que no emitió este sistema
 * (otro sistema con la misma cuenta) se cuentan como "documentos_externos".
 */
class ResumenService
{
    public function __construct(
        private readonly SunatApiClientFactory $factory,
        private readonly EmisionService $emision,
        private readonly Auditor $auditor,
    ) {}

    /** Boletas pendientes de resumen agrupadas por fecha de emisión. */
    public function pendientes(Empresa $empresa): Collection
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        $activas = $this->enCurso($empresa)->get(['fecha_documentos', 'identifier', 'estado'])->keyBy(fn ($c) => $c->fecha_documentos->toDateString());

        return Comprobante::deEmpresa($empresa->id)->where('envio_por_resumen', true)->whereIn('estado', ['por_enviar', 'pendiente_resumen'])
            ->selectRaw("fecha_emision, SUM(estado = 'pendiente_resumen') registradas, SUM(estado = 'por_enviar') sin_registrar, SUM(total) total")
            ->groupBy('fecha_emision')->orderBy('fecha_emision')->get()
            ->map(function ($r) use ($hoy, $activas) {
                $f = $r->fecha_emision->toDateString();
                $dias = Carbon::parse($f, 'America/Lima')->diffInDays(Carbon::parse($hoy, 'America/Lima'));

                return [
                    'fecha' => $f, 'registradas' => (int) $r->registradas, 'sin_registrar' => (int) $r->sin_registrar,
                    'total' => round((float) $r->total, 2), 'dias' => (int) $dias, 'es_hoy' => $f === $hoy,
                    'resumen_activo' => isset($activas[$f]) ? ['identifier' => $activas[$f]->identifier, 'estado' => $activas[$f]->estado] : null,
                ];
            })->values();
    }

    /** RC de envío que aún no terminan (ni aceptados/rechazados ni en error). */
    private function enCurso(Empresa $empresa)
    {
        return Comunicacion::deEmpresa($empresa->id)->where('accion', 'envio')->whereNotIn('estado', [...Comunicacion::FINALES, 'error']);
    }

    /** @param bool $permitirHoy true en el envío manual; el automático solo resume días anteriores. */
    public function solicitar(Empresa $empresa, User $user, string $fecha, bool $permitirHoy = false): Comunicacion
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        if ($fecha > $hoy) {
            throw ValidationException::withMessages(['fecha' => 'No se puede enviar el resumen de una fecha futura.']);
        }
        if ($fecha === $hoy && ! $permitirHoy) {
            throw ValidationException::withMessages(['fecha' => 'El envío automático solo resume días anteriores; el de hoy se envía manualmente.']);
        }

        $com = DB::transaction(function () use ($empresa, $user, $fecha) {
            $bloqueo = $this->enCurso($empresa)->whereDate('fecha_documentos', $fecha)->lockForUpdate()->first();
            if ($bloqueo) {
                throw ValidationException::withMessages(['fecha' => "El resumen ".($bloqueo->identifier ?? "#{$bloqueo->id}")." del $fecha aún está en curso (estado: {$bloqueo->estado}). Espera su respuesta."]);
            }
            $base = Comprobante::deEmpresa($empresa->id)->where('envio_por_resumen', true)->whereDate('fecha_emision', $fecha);
            if ((clone $base)->where('estado', 'por_enviar')->exists()) {
                throw ValidationException::withMessages(['fecha' => 'Hay boletas de esa fecha que aún no se registran en la API. Espera a que la cola las procese (o reinténtalas) antes de enviar el resumen.']);
            }
            $boletas = (clone $base)->where('estado', 'pendiente_resumen')->whereNotNull('api_id')->lockForUpdate()->get();
            if ($boletas->isEmpty()) {
                throw ValidationException::withMessages(['fecha' => "No hay boletas pendientes de resumen del $fecha."]);
            }
            $com = Comunicacion::create([
                'empresa_id' => $empresa->id, 'tipo' => 'RC', 'accion' => 'envio', 'fecha_documentos' => $fecha,
                'motivo' => 'Envío de boletas', 'estado' => 'por_enviar', 'user_id' => $user->id,
                // Flujo RC-03: se crea sin envío automático y se envía con POST /resumenes/{id}/enviar.
                'payload' => ['fecha_resumen' => $fecha, 'enviar_automatico' => false],
            ]);
            foreach ($boletas as $b) {
                ComunicacionItem::create(['comunicacion_id' => $com->id, 'comprobante_id' => $b->id, 'motivo' => 'Envío por resumen diario']);
            }
            EnviarComunicacionJob::dispatch($com->id)->afterCommit();

            return $com;
        });
        $this->auditor->registrar('resumen.solicitado', $com, [], ['fecha' => $fecha, 'boletas' => $com->items()->count()], $empresa->id);

        return $com;
    }

    /** Crea (si falta) y envía el RC. Devuelve true si queda pendiente de consultar el ticket. */
    public function enviar(Comunicacion $c): bool
    {
        if ($c->esFinal()) {
            return false;
        }
        $api = $this->api($c);
        if (! $c->api_id) {
            $c->increment('intentos');
            try {
                $res = $api->post('/resumenes', $c->payload);
            } catch (SunatApiConnectionException $e) {
                $this->log($c, 'crear', 'POST', '/resumenes', $c->payload, null, $e->getMessage());
                throw $e;
            }
            $this->log($c, 'crear', 'POST', '/resumenes', $c->payload, $res);
            if ($res->status >= 500) {
                throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
            }
            if (! $res->ok()) {
                $this->fallo($c, $res);

                return false;
            }
            $this->aplicar($c, $res->datos() ?? []);
            if ($c->esFinal()) {
                return false;
            }
        }
        if ($c->estado === 'por_enviar') {
            // Creado en la API ("pendiente") pero aún no enviado a SUNAT.
            $path = "/resumenes/{$c->api_id}/enviar";
            try {
                $res = $api->post($path, []);
            } catch (SunatApiConnectionException $e) {
                $this->log($c, 'enviar', 'POST', $path, null, null, $e->getMessage());
                throw $e;
            }
            $this->log($c, 'enviar', 'POST', $path, null, $res);
            if ($res->status >= 500) {
                throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
            }
            if (! $res->ok()) {
                $this->fallo($c, $res);

                return false;
            }
            $this->aplicar($c, $res->datos() ?? []);
        }

        return ! $c->esFinal();
    }

    public function consultar(Comunicacion $c): bool
    {
        if (! $c->api_id) {
            return false;
        }
        $path = "/resumenes/{$c->api_id}/estado";
        $res = $this->api($c)->get($path);
        $this->log($c, 'consultar_estado', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicar($c, $res->datos());
        }

        return $c->esFinal();
    }

    /**
     * Aplica la respuesta del RC. Estado local de la comunicación: por_enviar (creado sin enviar),
     * enviado (ticket en SUNAT), aceptado, rechazado.
     */
    private function aplicar(Comunicacion $c, array $d): void
    {
        $estadoApi = strtolower((string) ($d['estado_sunat'] ?? ''));
        $estado = match ($estadoApi) {
            'aceptado' => 'aceptado', 'rechazado' => 'rechazado', 'pendiente' => 'por_enviar', default => 'enviado',
        };
        DB::transaction(function () use ($c, $d, $estado) {
            $c->update([
                'api_id' => $d['id_resumen'] ?? $c->api_id,
                'identifier' => $d['identifier'] ?? $c->identifier,
                'ticket' => $d['ticket'] ?? $c->ticket,
                'estado' => $estado,
                'sunat_codigo' => isset($d['codigo_sunat']) ? (string) $d['codigo_sunat'] : $c->sunat_codigo,
                'sunat_descripcion' => isset($d['descripcion_sunat']) ? mb_substr((string) $d['descripcion_sunat'], 0, 1000) : $c->sunat_descripcion,
                'ultimo_error' => null,
            ]);
            if (isset($d['documentos']) && is_array($d['documentos'])) {
                $this->conciliarDocumentos($c, $d['documentos']);
            }
            $docs = collect($d['documentos'] ?? [])->keyBy('id');
            foreach ($c->items()->get() as $it) {
                $b = Comprobante::withoutGlobalScopes()->lockForUpdate()->find($it->comprobante_id);
                if (! $b || in_array($b->estado, ['aceptado', 'anulado'], true)) {
                    continue;
                }
                $estadoDoc = strtolower((string) ($docs[$b->api_id]['estado_sunat'] ?? $c->estado));
                if ($c->estado === 'aceptado' && $estadoDoc !== 'rechazado') {
                    $b->cambiarEstado('aceptado', "Aceptada en el resumen diario {$c->identifier}: {$c->sunat_codigo} {$c->sunat_descripcion}", [
                        'sunat_codigo' => $c->sunat_codigo, 'sunat_descripcion' => $c->sunat_descripcion, 'aceptado_at' => now(), 'enviado_at' => $b->enviado_at ?? now(),
                    ]);
                    DescargarArchivosComprobanteJob::dispatch($b->id)->afterCommit();
                } elseif ($c->estado === 'rechazado' || $estadoDoc === 'rechazado') {
                    // La boleta sigue pendiente en la API: puede incluirse en un nuevo resumen tras corregir la causa.
                    if ($b->estado !== 'pendiente_resumen') {
                        $b->cambiarEstado('pendiente_resumen', "Resumen {$c->identifier} rechazado: {$c->sunat_codigo} {$c->sunat_descripcion}");
                    }
                } elseif ($c->estado === 'enviado' && $b->estado === 'pendiente_resumen') {
                    $b->cambiarEstado('enviado', "Incluida en el resumen diario {$c->identifier} (ticket en SUNAT)", ['enviado_at' => now()]);
                }
            }
        });
        if ($c->estado === 'aceptado') {
            $this->auditor->registrar('resumen.aceptado', $c, [], ['identifier' => $c->identifier], $c->empresa_id);
        }
    }

    /** Los ítems pasan a ser exactamente las boletas propias que la API incluyó en el RC. */
    private function conciliarDocumentos(Comunicacion $c, array $documentos): void
    {
        $ids = collect($documentos)->filter(fn ($x) => ($x['tipo_documento'] ?? '03') === '03')->pluck('id')->filter()->all();
        $propias = Comprobante::withoutGlobalScopes()->where('empresa_id', $c->empresa_id)->where('tipo_documento', '03')
            ->whereIn('api_id', $ids)->pluck('id', 'api_id');
        $c->items()->whereNotIn('comprobante_id', $propias->values())->delete();
        $existentes = $c->items()->pluck('comprobante_id')->all();
        foreach ($propias as $compId) {
            if (! in_array($compId, $existentes, true)) {
                ComunicacionItem::create(['comunicacion_id' => $c->id, 'comprobante_id' => $compId, 'motivo' => 'Envío por resumen diario']);
            }
        }
        $c->update(['documentos_externos' => max(0, count($ids) - $propias->count())]);
    }

    /**
     * 4xx de la API. Caso conocido (H-5): las boletas de la fecha ya están en un resumen —p. ej. lo generó
     * otro sistema con la misma cuenta—. Se consulta cada boleta para adoptar su estado real.
     */
    private function fallo(Comunicacion $c, SunatApiResponse $res): void
    {
        $msg = mb_substr(($res->mensaje() ?? "HTTP {$res->status}").($res->errores() ? ': '.collect($res->errores())->flatten()->implode(' ') : ''), 0, 1000);
        $c->update(['estado' => 'error', 'ultimo_error' => $msg]);
        if (str_contains(mb_strtolower($msg), 'ya están incluidas en un resumen')) {
            foreach ($c->items()->with('comprobante')->get() as $it) {
                if ($it->comprobante && $it->comprobante->api_id) {
                    try {
                        $this->emision->consultarEstado($it->comprobante);
                    } catch (SunatApiConnectionException) {
                        // se podrá reconsultar desde el detalle del comprobante
                    }
                }
            }
            $c->update(['ultimo_error' => mb_substr($msg.' — Se consultó el estado de cada boleta en la API.', 0, 1000)]);
        }
    }

    private function api(Comunicacion $c): SunatApiClient
    {
        return $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id));
    }

    private function log(Comunicacion $c, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null): void
    {
        ApiSolicitud::create([
            'empresa_id' => $c->empresa_id, 'comprobante_id' => null, 'operacion' => "resumen_{$op}", 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res?->status, 'respuesta' => $res?->json, 'duracion_ms' => $res?->durationMs, 'error' => $error,
        ]);
    }
}
