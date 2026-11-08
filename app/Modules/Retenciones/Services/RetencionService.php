<?php

namespace App\Modules\Retenciones\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\ComprobanteRetencion;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\ProcesoSunat;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Comprobantes de retención (20, POST /retenciones) y de percepción (40, POST /percepciones), y su reversión
 * (RR, POST /reversiones; se consulta en /anulaciones/{id}/estado porque la API la guarda junto a las RA).
 * Estructura de los ejemplos Postman 18/19/20 y de la especificación OpenAPI de la API.
 *
 * Importes: por cada comprobante relacionado, retenido/percibido = importe total × tasa; neto pagado = total − retenido
 * (retención) o total cobrado = total + percibido (percepción). Se emite en soles con un pago/cobro por documento.
 */
class RetencionService
{
    public const RESULTADO_ENVIADO = 'enviado';
    public const RESULTADO_FINAL = 'final';
    public const RESULTADO_ERROR = 'error';
    public const RESULTADO_RENUMERADO = 'renumerado';

    public function __construct(private readonly SunatApiClientFactory $factory, private readonly Auditor $auditor) {}

    /** @return array<string, array{0: string, 1: float}> régimen => [descripción, tasa] */
    public static function regimenes(string $tipo): array
    {
        return $tipo === '20' ? ComprobanteRetencion::REGIMENES_RETENCION : ComprobanteRetencion::REGIMENES_PERCEPCION;
    }

    public function crear(Empresa $empresa, User $user, array $d): ComprobanteRetencion
    {
        if ($previa = ComprobanteRetencion::deEmpresa($empresa->id)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $previa; // doble clic o reintento de red
        }
        $tipo = $d['tipo_documento'];
        $esRet = $tipo === '20';
        [, $tasa] = self::regimenes($tipo)[$d['regimen']] ?? throw ValidationException::withMessages(['regimen' => 'Régimen no válido para este comprobante.']);
        $hoy = Carbon::now('America/Lima')->toDateString();

        $c = DB::transaction(function () use ($empresa, $user, $d, $tipo, $esRet, $tasa, $hoy) {
            $serie = Serie::deEmpresa($empresa->id)->lockForUpdate()->find($d['serie_id']);
            if (! $serie || $serie->tipo_documento !== $tipo || ! $serie->activo || ! $serie->api_serie_id) {
                throw ValidationException::withMessages(['serie_id' => 'Elige una serie '.($esRet ? 'de retención (R…)' : 'de percepción (P…)').' activa y sincronizada con la API.']);
            }
            $est = $serie->establecimiento_id ? Establecimiento::find($serie->establecimiento_id) : null;

            $docs = [];
            $apiDocs = [];
            $total = $importe = '0';
            foreach ($d['documentos'] as $doc) {
                $impTotal = Dec::round((string) $doc['imp_total']);
                $monto = Dec::round(Dec::div(Dec::mul($impTotal, (string) $tasa), '100'));
                $neto = Dec::round($esRet ? Dec::sub($impTotal, $monto) : Dec::add($impTotal, $monto));
                $fechaPago = $doc['fecha_pago'] ?? $hoy;
                $docs[] = ['tipo_doc' => $doc['tipo_doc'], 'num_doc' => strtoupper($doc['num_doc']), 'fecha_emision' => $doc['fecha_emision'],
                    'imp_total' => (float) $impTotal, 'fecha_pago' => $fechaPago, 'importe' => (float) $monto, 'neto' => (float) $neto];
                $base = ['tipo_doc' => $doc['tipo_doc'], 'num_doc' => strtoupper($doc['num_doc']), 'fecha_emision' => $doc['fecha_emision'],
                    'imp_total' => (float) $impTotal, 'moneda' => 'PEN'];
                $apiDocs[] = $esRet
                    ? $base + ['fecha_retencion' => $fechaPago, 'imp_retenido' => (float) $monto, 'imp_pagar' => (float) $neto,
                        'pagos' => [['moneda' => 'PEN', 'importe' => (float) $impTotal, 'fecha' => $fechaPago]]]
                    : $base + ['fecha_percepcion' => $fechaPago, 'cobros' => [['moneda' => 'PEN', 'importe' => (float) $impTotal, 'fecha' => $fechaPago]]];
                $total = Dec::add($total, $impTotal);
                $importe = Dec::add($importe, $monto);
            }

            $serie->correlativo_actual++;
            $serie->save();
            $tercero = array_filter([
                'tipo_doc' => $d['tercero']['tipo_doc'], 'num_doc' => $d['tercero']['num_doc'],
                'razon_social' => $d['tercero']['razon_social'], 'direccion' => $d['tercero']['direccion'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            $payload = array_filter([
                'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy, 'cod_local' => $est?->cod_local,
                $esRet ? 'proveedor' : 'cliente' => $tercero, 'regimen' => $d['regimen'], 'tasa' => $tasa,
                'observacion' => $d['observacion'] ?? null, 'documentos' => $apiDocs, 'enviar_automatico' => true,
            ], fn ($v) => $v !== null && $v !== '');

            $c = ComprobanteRetencion::create([
                'empresa_id' => $empresa->id, 'establecimiento_id' => $serie->establecimiento_id, 'serie_id' => $serie->id,
                'tipo_documento' => $tipo, 'serie' => $serie->serie, 'correlativo' => $serie->correlativo_actual, 'fecha_emision' => $hoy,
                'regimen' => $d['regimen'], 'tasa' => $tasa, 'tercero_tipo_doc' => $tercero['tipo_doc'], 'tercero_num_doc' => $tercero['num_doc'],
                'tercero_razon_social' => $tercero['razon_social'], 'tercero_direccion' => $tercero['direccion'] ?? null, 'moneda' => 'PEN',
                'importe_documentos' => Dec::round($total), 'importe' => Dec::round($importe),
                'importe_neto' => Dec::round($esRet ? Dec::sub($total, $importe) : Dec::add($total, $importe)),
                'documentos' => $docs, 'observacion' => $d['observacion'] ?? null, 'estado' => 'por_enviar', 'payload' => $payload,
                'idempotency_key' => $d['idempotency_key'], 'user_id' => $user->id,
                'historial' => [['fecha' => now()->toIso8601String(), 'estado' => 'por_enviar', 'detalle' => ($esRet ? 'Retención' : 'Percepción').' registrada']],
            ]);
            ProcesoSunat::alConfirmar(fn () => app(ProcesoSunat::class)->emitirRetencion($c->fresh()));

            return $c;
        });
        $this->auditor->registrar($esRet ? 'retencion.emitida' : 'percepcion.emitida', $c, [], [
            'numero' => $c->numero(), 'tercero' => $c->tercero_num_doc, 'importe' => (string) $c->importe,
        ], $empresa->id);

        return $c;
    }

    /** Envía el comprobante a la API. Lanza SunatApiConnectionException / RateLimit (ProcesoSunat las registra). */
    public function emitir(ComprobanteRetencion $c): string
    {
        if ($c->api_id) {
            return $this->consultar($c) ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }
        $c->increment('intentos');
        $path = '/'.$c->recursoApi();
        try {
            $res = $this->api($c)->post($path, $c->payload);
        } catch (SunatApiConnectionException $e) {
            $this->log($c, 'emitir', 'POST', $path, $c->payload, null, $e->getMessage());
            throw $e;
        }
        $this->log($c, 'emitir', 'POST', $path, $c->payload, $res);
        if ($res->status >= 500) {
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
        }
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicar($c, $res->datos(), ($c->esRetencion() ? 'Retención' : 'Percepción').' registrada en la API');

            return $c->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }
        $detalle = $this->detalleError($res);
        if (preg_match('/correlativo|ya existe|duplicad/i', $detalle) && $this->renumerar($c)) {
            return self::RESULTADO_RENUMERADO;
        }
        $c->cambiarEstado('error', "La API rechazó el envío (HTTP {$res->status}): $detalle", ['ultimo_error' => mb_substr($detalle, 0, 1000)]);

        return self::RESULTADO_ERROR;
    }

    /** Lee el estado en la API. Devuelve true si quedó en un estado final. */
    public function consultar(ComprobanteRetencion $c): bool
    {
        if (! $c->api_id) {
            return false;
        }
        $path = '/'.$c->recursoApi().'/'.$c->api_id;
        $res = $this->api($c)->get($path);
        $this->log($c, 'consultar', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicar($c, $res->datos(), null);
        }

        return $c->esFinal();
    }

    /** Reversión (RR) de un comprobante aceptado. */
    public function revertir(ComprobanteRetencion $c, User $user, string $motivo): void
    {
        if ($c->estado !== 'aceptado') {
            throw ValidationException::withMessages(['motivo' => "{$c->numero()} no está aceptado por SUNAT (estado: {$c->estado})."]);
        }
        if (in_array($c->reversion_estado, ['por_enviar', 'enviado', 'aceptado'], true)) {
            throw ValidationException::withMessages(['motivo' => "{$c->numero()} ya tiene una reversión en curso o aceptada."]);
        }
        $payload = [
            'fecha_generacion' => $c->fecha_emision->toDateString(),
            'fecha_comunicacion' => Carbon::now('America/Lima')->toDateString(), // explícita (lección H-1 de las RA)
            'detalles' => [['tipo_documento' => $c->tipo_documento, 'serie' => $c->serie, 'correlativo' => (string) $c->correlativo, 'motivo' => $motivo]],
        ];
        $c->anotar("Reversión solicitada: $motivo", ['reversion_estado' => 'por_enviar', 'reversion_motivo' => $motivo, 'reversion_error' => null]);
        $this->auditor->registrar('retencion.reversion', $c, [], ['numero' => $c->numero(), 'motivo' => $motivo], $c->empresa_id);

        $res = $this->api($c)->post('/reversiones', $payload);
        $this->log($c, 'reversion', 'POST', '/reversiones', $payload, $res);
        if ($res->status >= 500) {
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}");
        }
        if (! $res->ok()) {
            $detalle = $this->detalleError($res);
            $c->anotar("La API rechazó la reversión: $detalle", ['reversion_estado' => 'error', 'reversion_error' => mb_substr($detalle, 0, 1000)]);

            return;
        }
        $this->aplicarReversion($c, (array) ($res->datos() ?? []));
    }

    public function consultarReversion(ComprobanteRetencion $c): void
    {
        if (! $c->reversion_api_id || $c->reversion_estado !== 'enviado') {
            return;
        }
        $path = "/anulaciones/{$c->reversion_api_id}/estado";
        $res = $this->api($c)->get($path);
        $this->log($c, 'reversion_consultar', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicarReversion($c, $res->datos());
        }
    }

    /** XML o CDR (se guardan una vez que SUNAT respondió) o PDF (a4, a5, ticket-80, ticket-58; en el momento). */
    public function archivo(ComprobanteRetencion $c, string $tipo, string $formato = 'a4'): ?array
    {
        if (! $c->api_id) {
            return null;
        }
        $nombre = "{$c->empresa?->ruc}-{$c->tipo_documento}-{$c->serie}-{$c->correlativo}";
        if ($tipo !== 'pdf' && ($guardado = $c->archivos[$tipo] ?? null) && Storage::disk('local')->exists($guardado)) {
            return ['contenido' => Storage::disk('local')->get($guardado), 'nombre' => basename($guardado), 'mime' => $tipo === 'cdr' ? 'application/zip' : 'application/xml'];
        }
        $path = '/'.$c->recursoApi()."/{$c->api_id}/$tipo";
        $res = $this->api($c)->get($path, $tipo === 'pdf' ? ['format' => $formato] : []);
        $this->log($c, "descargar_$tipo", 'GET', $path, null, $res, binario: true);
        if ($res->status !== 200 || $res->raw === '' || str_contains($res->contentType, 'json')) {
            return null;
        }
        $mime = ['xml' => 'application/xml', 'cdr' => 'application/zip', 'pdf' => 'application/pdf'][$tipo];
        $archivo = $tipo === 'cdr' ? "R-$nombre.zip" : "$nombre.".($tipo === 'pdf' ? "$formato.pdf" : 'xml');
        if ($tipo !== 'pdf' && $c->esFinal()) {
            $ruta = "retenciones/{$c->empresa_id}/$archivo";
            Storage::disk('local')->put($ruta, $res->raw);
            $c->forceFill(['archivos' => array_merge($c->archivos ?? [], [$tipo => $ruta])])->save();
        }

        return ['contenido' => $res->raw, 'nombre' => $archivo, 'mime' => $mime];
    }

    private function aplicar(ComprobanteRetencion $c, array $d, ?string $detalle): void
    {
        $st = strtolower((string) ($d['sunat_status'] ?? $d['estado_sunat'] ?? ($d['sunat']['estado'] ?? 'enviado')));
        $estado = match (true) {
            $st === 'aceptado' || $st === 'anulacion_en_proceso' => 'aceptado',
            $st === 'rechazado' => 'rechazado',
            in_array($st, ['anulado', 'revertido', 'reversado'], true) => 'revertido',
            default => 'enviado',
        };
        $extra = array_filter([
            'api_id' => $d['id'] ?? $c->api_id,
            'sunat_codigo' => isset($d['sunat_code']) ? (string) $d['sunat_code'] : null,
            'sunat_descripcion' => isset($d['sunat_description']) ? mb_substr((string) $d['sunat_description'], 0, 1000) : null,
        ], fn ($v) => $v !== null) + ['ultimo_error' => null];
        // Importes que confirma la API (el motor local los calcula igual; se guardan los oficiales).
        $imp = $d[$c->esRetencion() ? 'imp_retenido' : 'imp_percibido'] ?? null;
        $neto = $d[$c->esRetencion() ? 'imp_pagado' : 'imp_cobrado'] ?? null;
        if ($imp !== null) {
            $extra['importe'] = Dec::round((string) $imp);
        }
        if ($neto !== null) {
            $extra['importe_neto'] = Dec::round((string) $neto);
        }
        $texto = $detalle ?? (in_array($estado, ComprobanteRetencion::FINALES, true) ? trim(($extra['sunat_codigo'] ?? $c->sunat_codigo).' '.($extra['sunat_descripcion'] ?? $c->sunat_descripcion ?? $estado)) : null);
        if ($estado !== $c->estado || $texto) {
            $c->cambiarEstado($estado, $texto, $extra);
        } else {
            $c->forceFill($extra)->save();
        }
        if ($st === 'anulacion_en_proceso' && ! $c->reversion_estado) {
            $c->forceFill(['reversion_estado' => 'enviado'])->save();
        }
    }

    private function aplicarReversion(ComprobanteRetencion $c, array $d): void
    {
        $st = strtolower((string) ($d['estado_sunat'] ?? $d['sunat_status'] ?? 'enviado'));
        $c->forceFill(array_filter([
            // Respuesta real de POST /reversiones (beta, 2026-11-07): {voided_id, identifier, sunat_status, consulta_estado}
            'reversion_api_id' => $d['voided_id'] ?? $d['id_anulacion'] ?? $d['id_reversion'] ?? $d['id'] ?? $c->reversion_api_id,
            'reversion_identifier' => $d['identifier'] ?? null,
            'reversion_ticket' => $d['ticket'] ?? null,
        ], fn ($v) => $v !== null))->save();
        $desc = trim(($d['codigo_sunat'] ?? $d['sunat_code'] ?? '').' '.($d['descripcion_sunat'] ?? $d['sunat_description'] ?? ''));
        if ($st === 'aceptado') {
            $c->forceFill(['reversion_estado' => 'aceptado'])->save();
            $c->cambiarEstado('revertido', "Revertido ante SUNAT ({$c->reversion_identifier}): ".($desc ?: 'aceptado'));
        } elseif ($st === 'rechazado') {
            $c->anotar("Reversión {$c->reversion_identifier} rechazada: $desc", ['reversion_estado' => 'rechazado', 'reversion_error' => $desc ?: 'Rechazada por SUNAT']);
        } else {
            $c->forceFill(['reversion_estado' => 'enviado'])->save();
        }
    }

    /** El número ya existe en la API (otro sistema con la misma cuenta): toma el siguiente libre. */
    private function renumerar(ComprobanteRetencion $c): bool
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
            $c->cambiarEstado('por_enviar', "Renumerado de $anterior a {$c->serie}-$nuevo: el número estaba ocupado en la API por otro documento.", [
                'correlativo' => $nuevo, 'payload' => $payload,
            ]);

            return true;
        });
    }

    private function detalleError(SunatApiResponse $res): string
    {
        $e = $res->errores();

        return mb_substr(($res->mensaje() ?? "HTTP {$res->status}").($e ? ': '.collect($e)->flatten()->implode(' ') : ''), 0, 1000);
    }

    private function api(ComprobanteRetencion $c): SunatApiClient
    {
        return $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id));
    }

    private function log(ComprobanteRetencion $c, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null, bool $binario = false): void
    {
        ApiSolicitud::create([
            'empresa_id' => $c->empresa_id, 'comprobante_id' => null, 'operacion' => ($c->esRetencion() ? 'retencion_' : 'percepcion_').$op,
            'metodo' => $metodo, 'endpoint' => $endpoint, 'payload' => $payload, 'http_status' => $res?->status,
            'respuesta' => $binario ? null : $res?->json, 'duracion_ms' => $res?->durationMs, 'error' => $error,
        ]);
    }
}
