<?php

namespace App\Modules\Liquidaciones\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\LiquidacionCompra;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\ProcesoSunat;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Liquidaciones de compra (04, POST /liquidaciones-compra): la empresa compra a una persona sin RUC y retiene IGV y renta.
 * Los importes definitivos los calcula la API; aquí solo se estiman hasta recibir su respuesta.
 * Respuesta real validada en beta (L002-1, 2026-11-08): datos{id, totales{…, igv, retencion_renta, neto_a_pagar}, sunat{estado, codigo, descripcion, notas}}.
 */
class LiquidacionService
{
    public const RESULTADO_ENVIADO = 'enviado';
    public const RESULTADO_FINAL = 'final';
    public const RESULTADO_ERROR = 'error';
    public const RESULTADO_RENUMERADO = 'renumerado';

    public function __construct(private readonly SunatApiClientFactory $factory, private readonly Auditor $auditor) {}

    public function crear(Empresa $empresa, User $user, array $d): LiquidacionCompra
    {
        if ($previa = LiquidacionCompra::deEmpresa($empresa->id)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $previa;
        }
        $hoy = Carbon::now('America/Lima')->toDateString();
        $tasa = (string) ($empresa->credencialApi()?->remoto['tasa_igv_vigente'] ?? 18);

        $c = DB::transaction(function () use ($empresa, $user, $d, $hoy, $tasa) {
            $serie = Serie::deEmpresa($empresa->id)->lockForUpdate()->find($d['serie_id']);
            if (! $serie || $serie->tipo_documento !== '04' || ! $serie->activo || ! $serie->api_serie_id) {
                throw ValidationException::withMessages(['serie_id' => 'Elige una serie de liquidación (L…) activa y sincronizada con la API.']);
            }
            $est = $serie->establecimiento_id ? Establecimiento::find($serie->establecimiento_id) : null;

            $items = [];
            $valor = $igv = '0';
            foreach ($d['items'] as $it) {
                $linea = Dec::round(Dec::mul((string) $it['cantidad'], (string) $it['valor_unitario']));
                $valor = Dec::add($valor, $linea);
                if ($it['tip_afe_igv'] === '10') {
                    $igv = Dec::add($igv, Dec::round(Dec::div(Dec::mul($linea, $tasa), '100')));
                }
                $items[] = array_filter([
                    'descripcion' => $it['descripcion'], 'cantidad' => (float) $it['cantidad'], 'valor_unitario' => (float) $it['valor_unitario'],
                    'unidad' => $it['unidad'], 'cod_producto_sunat' => $it['cod_producto_sunat'] ?? null, 'tip_afe_igv' => $it['tip_afe_igv'],
                ], fn ($v) => $v !== null && $v !== '');
            }

            $serie->correlativo_actual++;
            $serie->save();
            $vendedor = $d['vendedor'];
            $payload = array_filter([
                'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy, 'tipo_operacion' => '0501',
                'productos_agropecuarios' => (bool) ($d['productos_agropecuarios'] ?? false),
                'vendedor' => ['tipo_doc' => $vendedor['tipo_doc'], 'num_doc' => $vendedor['num_doc'], 'nombre' => $vendedor['nombre'],
                    'direccion' => $vendedor['direccion'], 'ubigeo' => $vendedor['ubigeo'], 'tipo_domicilio' => '01'],
                'lugar' => ['direccion' => $d['lugar']['direccion'], 'ubigeo' => $d['lugar']['ubigeo'], 'tipo' => '01'],
                'observacion' => $d['observacion'] ?? null, 'items' => $items, 'cod_local' => $est?->cod_local,
            ], fn ($v) => $v !== null && $v !== '');

            $c = LiquidacionCompra::create([
                'empresa_id' => $empresa->id, 'establecimiento_id' => $serie->establecimiento_id, 'serie_id' => $serie->id, 'serie' => $serie->serie,
                'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy, 'productos_agropecuarios' => (bool) ($d['productos_agropecuarios'] ?? false),
                'vendedor_tipo_doc' => $vendedor['tipo_doc'], 'vendedor_num_doc' => $vendedor['num_doc'], 'vendedor_nombre' => $vendedor['nombre'],
                'vendedor_direccion' => $vendedor['direccion'], 'vendedor_ubigeo' => $vendedor['ubigeo'],
                'lugar_direccion' => $d['lugar']['direccion'], 'lugar_ubigeo' => $d['lugar']['ubigeo'], 'items' => $items,
                'valor_venta' => Dec::round($valor), 'igv' => Dec::round($igv), 'neto_a_pagar' => Dec::round($valor), 'observacion' => $d['observacion'] ?? null,
                'estado' => 'por_enviar', 'payload' => $payload, 'idempotency_key' => $d['idempotency_key'], 'user_id' => $user->id,
                'historial' => [['fecha' => now()->toIso8601String(), 'estado' => 'por_enviar', 'detalle' => 'Liquidación registrada']],
            ]);
            ProcesoSunat::alConfirmar(fn () => app(ProcesoSunat::class)->emitirLiquidacion($c->fresh()));

            return $c;
        });
        $this->auditor->registrar('liquidacion.emitida', $c, [], ['numero' => $c->numero(), 'vendedor' => $c->vendedor_num_doc, 'valor' => (string) $c->valor_venta], $empresa->id);

        return $c;
    }

    public function emitir(LiquidacionCompra $c): string
    {
        if ($c->api_id) {
            return $this->consultar($c) ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }
        $c->increment('intentos');
        try {
            $res = $this->api($c)->post('/liquidaciones-compra', $c->payload);
        } catch (SunatApiConnectionException $e) {
            $this->log($c, 'emitir', 'POST', '/liquidaciones-compra', $c->payload, null, $e->getMessage());
            throw $e;
        }
        $this->log($c, 'emitir', 'POST', '/liquidaciones-compra', $c->payload, $res);
        if ($res->status >= 500) {
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
        }
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicar($c, $res->datos(), 'Liquidación registrada en la API');

            return $c->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }
        $detalle = $this->detalleError($res);
        if (preg_match('/correlativo|ya existe|duplicad/i', $detalle) && $this->renumerar($c)) {
            return self::RESULTADO_RENUMERADO;
        }
        $c->cambiarEstado('error', "La API rechazó el envío (HTTP {$res->status}): $detalle", ['ultimo_error' => mb_substr($detalle, 0, 1000)]);

        return self::RESULTADO_ERROR;
    }

    public function consultar(LiquidacionCompra $c): bool
    {
        if (! $c->api_id) {
            return false;
        }
        $path = "/liquidaciones-compra/{$c->api_id}";
        $res = $this->api($c)->get($path);
        $this->log($c, 'consultar', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicar($c, $res->datos(), null);
        }

        return $c->esFinal();
    }

    /** Pagos al vendedor, que administra la API. */
    public function pagos(LiquidacionCompra $c): array
    {
        $res = $this->api($c)->get("/liquidaciones-compra/{$c->api_id}/pagos");
        $this->exigirOk($res, 'pago');
        $d = (array) $res->datos();
        $c->forceFill(['monto_pagado' => Dec::round((string) ($d['monto_pagado'] ?? 0)), 'estado_pago' => $d['estado_pago'] ?? $c->estado_pago])->save();

        return $d;
    }

    public function registrarPago(LiquidacionCompra $c, array $pago): array
    {
        $path = "/liquidaciones-compra/{$c->api_id}/pagos";
        $res = $this->api($c)->post($path, $pago);
        $this->log($c, 'pago', 'POST', $path, $pago, $res);
        $this->exigirOk($res, 'monto');
        $this->auditor->registrar('liquidacion.pago', $c, [], ['numero' => $c->numero(), 'monto' => $pago['monto'], 'metodo' => $pago['metodo']], $c->empresa_id);

        return $this->pagos($c);
    }

    public function eliminarPago(LiquidacionCompra $c, int $pagoId): array
    {
        $path = "/liquidaciones-compra/{$c->api_id}/pagos/$pagoId";
        $res = $this->api($c)->delete($path);
        $this->log($c, 'pago_eliminar', 'DELETE', $path, null, $res);
        $this->exigirOk($res, 'pago');
        $this->auditor->registrar('liquidacion.pago_eliminado', $c, [], ['numero' => $c->numero(), 'pago' => $pagoId], $c->empresa_id);

        return $this->pagos($c);
    }

    /** La API exige CDR aceptado, sin pagos y dentro del 5.º día; si no se cumple, devuelve el motivo. */
    public function revertir(LiquidacionCompra $c, string $causal, string $motivo): void
    {
        if ($c->estado !== 'aceptado') {
            throw ValidationException::withMessages(['motivo' => "{$c->numero()} no está aceptada por SUNAT (estado: {$c->estado})."]);
        }
        if (in_array($c->reversion_estado, ['enviado', 'aceptado'], true)) {
            throw ValidationException::withMessages(['motivo' => "{$c->numero()} ya tiene una reversión en curso o aceptada."]);
        }
        $path = "/liquidaciones-compra/{$c->api_id}/reversion";
        $body = ['causal' => $causal, 'motivo' => $motivo];
        $res = $this->api($c)->post($path, $body);
        $this->log($c, 'reversion', 'POST', $path, $body, $res);
        if ($res->status >= 500) {
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
        }
        $this->auditor->registrar('liquidacion.reversion', $c, [], ['numero' => $c->numero()] + $body, $c->empresa_id);
        if (! $res->ok()) {
            $detalle = $this->detalleError($res);
            $c->anotar("La API rechazó la reversión: $detalle", ['reversion_estado' => 'error', 'reversion_causal' => $causal, 'reversion_motivo' => $motivo, 'reversion_error' => mb_substr($detalle, 0, 1000)]);

            return;
        }
        $c->anotar("Reversión solicitada: $motivo", ['reversion_causal' => $causal, 'reversion_motivo' => $motivo, 'reversion_error' => null, 'reversion_estado' => 'enviado']);
        $this->aplicarReversion($c, (array) ($res->datos() ?? []));
    }

    public function consultarReversion(LiquidacionCompra $c): void
    {
        if ($c->reversion_estado !== 'enviado') {
            return;
        }
        if ($c->reversion_api_id) {
            $path = "/anulaciones/{$c->reversion_api_id}/estado";
            $res = $this->api($c)->get($path);
            $this->log($c, 'reversion_consultar', 'GET', $path, null, $res);
            if ($res->ok() && is_array($res->datos())) {
                $this->aplicarReversion($c, $res->datos());
            }

            return;
        }
        $this->consultar($c);
    }

    public function archivo(LiquidacionCompra $c, string $tipo, string $formato = 'a4'): ?array
    {
        if (! $c->api_id) {
            return null;
        }
        $base = "{$c->empresa?->ruc}-04-{$c->serie}-{$c->correlativo}";
        if ($tipo !== 'pdf' && ($g = $c->archivos[$tipo] ?? null) && Storage::disk('local')->exists($g)) {
            return ['contenido' => Storage::disk('local')->get($g), 'nombre' => basename($g), 'mime' => $tipo === 'cdr' ? 'application/zip' : 'application/xml'];
        }
        $path = "/liquidaciones-compra/{$c->api_id}/$tipo";
        $res = $this->api($c)->get($path, $tipo === 'pdf' ? ['formato' => $formato] : []);
        $this->log($c, "descargar_$tipo", 'GET', $path, null, $res, binario: true);
        if ($res->status !== 200 || $res->raw === '' || str_contains($res->contentType, 'json')) {
            return null;
        }
        $nombre = $tipo === 'cdr' ? "R-$base.zip" : ($tipo === 'pdf' ? "$base.$formato.pdf" : "$base.xml");
        if ($tipo !== 'pdf' && $c->esFinal()) {
            $ruta = "liquidaciones/{$c->empresa_id}/$nombre";
            Storage::disk('local')->put($ruta, $res->raw);
            $c->forceFill(['archivos' => array_merge($c->archivos ?? [], [$tipo => $ruta])])->save();
        }

        return ['contenido' => $res->raw, 'nombre' => $nombre, 'mime' => ['xml' => 'application/xml', 'cdr' => 'application/zip', 'pdf' => 'application/pdf'][$tipo]];
    }

    /** Retenciones de IGV y renta del periodo (AAAA-MM), para el Formulario 617. */
    public function reporte(Empresa $empresa, string $periodo): array
    {
        $res = $this->factory->paraEmpresa($empresa)->get('/liquidaciones-compra/reporte-retenciones', ['periodo' => $periodo]);
        $this->exigirOk($res, 'periodo');

        return (array) $res->datos();
    }

    private function aplicar(LiquidacionCompra $c, array $d, ?string $detalle): void
    {
        $s = (array) ($d['sunat'] ?? []);
        $st = strtolower((string) ($s['estado'] ?? 'enviado'));
        $estado = match (true) {
            in_array($st, ['aceptado', 'observado', 'anulacion_en_proceso'], true) => 'aceptado',
            $st === 'rechazado' => 'rechazado',
            in_array($st, ['anulado', 'revertido', 'reversado'], true) => 'revertido',
            default => 'enviado',
        };
        $t = (array) ($d['totales'] ?? []);
        $extra = array_filter([
            'api_id' => $d['id'] ?? $c->api_id,
            'sunat_codigo' => isset($s['codigo']) ? (string) $s['codigo'] : null,
            'sunat_descripcion' => isset($s['descripcion']) ? mb_substr((string) $s['descripcion'], 0, 1000) : null,
            'sunat_notas' => $s['notas'] ?? null,
            'valor_venta' => isset($t['valor_venta']) ? Dec::round((string) $t['valor_venta']) : null,
            'igv' => isset($t['igv']) ? Dec::round((string) $t['igv']) : null,
            'porcentaje_renta' => $t['porcentaje_renta'] ?? null,
            'renta' => isset($t['retencion_renta']) ? Dec::round((string) $t['retencion_renta']) : null,
            'neto_a_pagar' => isset($t['neto_a_pagar']) ? Dec::round((string) $t['neto_a_pagar']) : null,
            'monto_pagado' => isset($d['monto_pagado']) ? Dec::round((string) $d['monto_pagado']) : null,
            'estado_pago' => $d['estado_pago'] ?? null,
        ], fn ($v) => $v !== null) + ['ultimo_error' => null];
        $texto = $detalle ?? (in_array($estado, LiquidacionCompra::FINALES, true) ? trim(($extra['sunat_codigo'] ?? '').' '.($extra['sunat_descripcion'] ?? $estado)) : null);
        if ($estado !== $c->estado || $texto) {
            if ($estado === $c->estado && $detalle === null) {
                $c->forceFill($extra)->save(); // sin cambios de estado: no repite el historial

                return;
            }
            $c->cambiarEstado($estado, $texto, $extra);
        } else {
            $c->forceFill($extra)->save();
        }
        if ($estado === 'revertido' && $c->reversion_estado === 'enviado') {
            $c->forceFill(['reversion_estado' => 'aceptado'])->save();
        }
    }

    private function aplicarReversion(LiquidacionCompra $c, array $d): void
    {
        $st = strtolower((string) ($d['estado_sunat'] ?? $d['sunat_status'] ?? 'enviado'));
        $c->forceFill(array_filter([
            'reversion_api_id' => $d['voided_id'] ?? $d['id_anulacion'] ?? $c->reversion_api_id,
            'reversion_identifier' => $d['identifier'] ?? null,
        ], fn ($v) => $v !== null))->save();
        $desc = trim(($d['codigo_sunat'] ?? '').' '.($d['descripcion_sunat'] ?? ''));
        if ($st === 'aceptado') {
            $c->forceFill(['reversion_estado' => 'aceptado'])->save();
            $c->cambiarEstado('revertido', "Revertida ante SUNAT ({$c->reversion_identifier}): ".($desc ?: 'aceptado'));
        } elseif ($st === 'rechazado') {
            $c->anotar("Reversión {$c->reversion_identifier} rechazada: $desc", ['reversion_estado' => 'rechazado', 'reversion_error' => $desc ?: 'Rechazada por SUNAT']);
        }
    }

    private function renumerar(LiquidacionCompra $c): bool
    {
        $res = $this->api($c)->get('/series');
        $remota = collect(is_array($res->datos()) ? $res->datos() : [])->firstWhere('serie', $c->serie);
        if (! $remota || ! isset($remota['correlativo'])) {
            return false;
        }

        return DB::transaction(function () use ($c, $remota) {
            $serie = Serie::withoutGlobalScopes()->lockForUpdate()->find($c->serie_id);
            $nuevo = max((int) $remota['correlativo'], (int) $serie->correlativo_actual) + 1;
            $serie->update(['correlativo_actual' => $nuevo]);
            $anterior = $c->numero();
            $payload = $c->payload;
            $payload['correlativo'] = $nuevo;
            $c->cambiarEstado('por_enviar', "Renumerado de $anterior a {$c->serie}-$nuevo: el número estaba ocupado en la API.", ['correlativo' => $nuevo, 'payload' => $payload]);

            return true;
        });
    }

    private function exigirOk(SunatApiResponse $res, string $campo): void
    {
        if (! $res->ok()) {
            throw ValidationException::withMessages([$campo => $this->detalleError($res)]);
        }
    }

    private function detalleError(SunatApiResponse $res): string
    {
        $e = $res->errores();

        return mb_substr(($res->mensaje() ?? "HTTP {$res->status}").($e ? ': '.collect($e)->flatten()->implode(' ') : ''), 0, 1000);
    }

    private function api(LiquidacionCompra $c): SunatApiClient
    {
        return $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id));
    }

    private function log(LiquidacionCompra $c, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null, bool $binario = false): void
    {
        ApiSolicitud::create([
            'empresa_id' => $c->empresa_id, 'comprobante_id' => null, 'operacion' => "liquidacion_$op", 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res?->status, 'respuesta' => $binario ? null : $res?->json, 'duracion_ms' => $res?->durationMs, 'error' => $error,
        ]);
    }
}
