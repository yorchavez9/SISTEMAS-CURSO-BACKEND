<?php

namespace App\Modules\Facturacion\Services;

use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Comprobante;
use App\Models\ComprobanteArchivo;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Serie;
use App\Models\Venta;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Comunicación con la API para un comprobante. Estructuras reales (Etapa 1):
 *  - POST 201: datos{id, serie, correlativo, totales{}, sunat{estado: "enviado"|..., codigo, descripcion, notas, hash_cpe}}
 *  - 422 correlativo duplicado: errores.correlativo ["El correlativo N ya existe para la serie …"]
 *  - GET /{recurso}/{id}: datos.sunat.estado aceptado|rechazado|pendiente|enviado
 *  - GET xml (application/xml), cdr (application/zip), pdf?format=a4 (application/pdf)
 */
class EmisionService
{
    public const RESULTADO_ENVIADO = 'enviado';
    public const RESULTADO_FINAL = 'final';
    public const RESULTADO_ERROR = 'error';
    public const RESULTADO_RENUMERADO = 'renumerado';
    public const MAX_RENUMERACIONES = 3;

    public function __construct(private readonly SunatApiClientFactory $factory) {}

    /** Envía el comprobante. Devuelve enviado | final | error. Lanza excepciones de conexión/límite para que la cola reintente. */
    public function emitir(Comprobante $c): string
    {
        if ($c->api_id) {
            return $c->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO; // ya llegó a la API: no se reenvía
        }
        $api = $this->api($c);
        $c->increment('intentos');
        $path = '/'.$c->recursoApi();

        try {
            $res = $api->post($path, $c->payload);
        } catch (SunatApiConnectionException $e) {
            // No sabemos si la API alcanzó a crear el documento: el siguiente intento lo resolverá
            // (si ya existe, la API responde 422 "correlativo ya existe" y se recupera por consulta).
            $this->log($c, 'emitir', 'POST', $path, $c->payload, null, null, $e->getMessage());
            $c->update(['ultimo_error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
        $this->log($c, 'emitir', 'POST', $path, $c->payload, $res);

        if ($res->ok()) {
            $this->aplicarDocumentoApi($c, $res->datos(), 'Recibido por la API');

            return $c->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }

        $err = $res->errores() ?? [];
        if ($res->status === 422 && isset($err['correlativo'])) {
            return $this->recuperarPorNumero($c, $api, implode(' ', (array) $err['correlativo']));
        }

        $detalle = $this->mensaje($res);
        if ($res->status >= 500) {
            $c->update(['ultimo_error' => $detalle]);
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}: $detalle");
        }
        // 4xx de negocio/validación: la API no creó el documento. Requiere corrección; no se reintenta solo.
        $c->cambiarEstado('error', "La API rechazó el envío (HTTP {$res->status}): $detalle", ['ultimo_error' => $detalle]);

        return self::RESULTADO_ERROR;
    }

    /** Consulta el estado SUNAT. Devuelve true si llegó a un estado final. */
    public function consultarEstado(Comprobante $c): bool
    {
        if (! $c->api_id) {
            return false;
        }
        $path = '/'.$c->recursoApi().'/'.$c->api_id;
        $res = $this->api($c)->get($path);
        $this->log($c, 'consultar_estado', 'GET', $path, null, $res);
        if ($res->ok() && is_array($res->datos())) {
            $this->aplicarDocumentoApi($c, $res->datos(), null);
        }

        return $c->esFinal();
    }

    /** Descarga y guarda XML, CDR y PDF (A4) en almacenamiento privado. */
    public function descargarArchivos(Comprobante $c, array $tipos = ['xml', 'cdr', 'pdf']): array
    {
        $api = $this->api($c);
        $empresa = Empresa::findOrFail($c->empresa_id);
        $guardados = [];
        foreach ($tipos as $tipo) {
            $path = '/'.$c->recursoApi().'/'.$c->api_id.'/'.$tipo;
            $res = $api->get($path, $tipo === 'pdf' ? ['format' => 'a4'] : []);
            $this->log($c, "descargar_$tipo", 'GET', $path, null, $res, binario: true);
            if ($res->status !== 200 || $res->raw === '' || str_contains($res->contentType, 'json')) {
                continue; // p. ej. CDR aún no disponible
            }
            $ext = ['xml' => 'xml', 'cdr' => 'zip', 'pdf' => 'pdf'][$tipo];
            $archivo = sprintf('comprobantes/%d/%s-%s-%s-%d%s.%s', $empresa->id, $empresa->ruc, $c->tipo_documento, $c->serie, $c->correlativo, $tipo === 'cdr' ? '-cdr' : '', $ext);
            Storage::disk('local')->put($archivo, $res->raw);
            ComprobanteArchivo::updateOrCreate(['comprobante_id' => $c->id, 'tipo' => $tipo], [
                'path' => $archivo, 'size' => strlen($res->raw), 'sha256' => hash('sha256', $res->raw),
            ]);
            $guardados[] = $tipo;
        }

        return $guardados;
    }

    /** PDF en un formato de impresión (a4, a5, ticket-80, ticket-58), sin guardarlo. */
    public function pdf(Comprobante $c, string $formato): ?SunatApiResponse
    {
        $path = '/'.$c->recursoApi().'/'.$c->api_id.'/pdf';
        $res = $this->api($c)->get($path, ['format' => $formato]);
        $this->log($c, "imprimir_$formato", 'GET', $path."?format=$formato", null, $res, binario: true);

        return $res->status === 200 && str_starts_with($res->raw, '%PDF') ? $res : null;
    }

    /**
     * La API dice que el número ya existe. Si es NUESTRO comprobante (un envío anterior llegó pero
     * se perdió la respuesta), se adopta; si pertenece a otro documento, es un conflicto que exige revisión.
     */
    private function recuperarPorNumero(Comprobante $c, SunatApiClient $api, string $detalle): string
    {
        $path = '/'.$c->recursoApi();
        $res = $api->get($path, ['serie' => $c->serie, 'correlativo' => $c->correlativo]);
        $this->log($c, 'recuperar_por_numero', 'GET', $path."?serie={$c->serie}&correlativo={$c->correlativo}", null, $res);
        $lista = $res->datos();
        $lista = isset($lista['datos']) ? $lista['datos'] : (is_array($lista) ? $lista : []);
        $doc = collect($lista)->first(fn ($d) => (int) ($d['correlativo'] ?? 0) === $c->correlativo && ($d['serie'] ?? '') === $c->serie);

        if ($doc && $this->mismoDocumento($c, $doc)) {
            $this->aplicarDocumentoApi($c, $doc, 'Recuperado: la API ya tenía este comprobante (respuesta anterior perdida)');

            return $c->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }

        // El número lo usó OTRO documento (p. ej. otro sistema que emite con la misma cuenta y serie).
        // Nuestro comprobante nunca llegó a SUNAT: es válido asignarle el siguiente número libre.
        $renumeraciones = $c->estados()->where('detalle', 'like', 'Renumerado%')->count();
        if ($renumeraciones < self::MAX_RENUMERACIONES) {
            $this->renumerar($c, $api);

            return self::RESULTADO_RENUMERADO;
        }
        $c->cambiarEstado('error', "Número ocupado en la API por otro documento. $detalle", ['ultimo_error' => 'Conflicto de numeración: '.$detalle]);

        return self::RESULTADO_ERROR;
    }

    /**
     * Toma el último correlativo de la serie en la API (GET /series) y asigna al comprobante el
     * siguiente número libre, con bloqueo de la serie local. Solo se usa si el comprobante no tiene api_id.
     */
    private function renumerar(Comprobante $c, SunatApiClient $api): void
    {
        $res = $api->get('/series');
        $this->log($c, 'sincronizar_serie', 'GET', '/series', null, $res);
        $lista = $res->datos();
        $lista = isset($lista['datos']) ? $lista['datos'] : (is_array($lista) ? $lista : []);
        $remoto = (int) (collect($lista)->firstWhere('serie', $c->serie)['correlativo'] ?? 0);

        DB::transaction(function () use ($c, $remoto) {
            $serie = Serie::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->serie_id);
            $anterior = $c->numero();
            $nuevo = max((int) $serie->correlativo_actual, $remoto, $c->correlativo) + 1;
            $serie->correlativo_actual = $nuevo;
            $serie->save();
            $payload = $c->payload;
            $payload['correlativo'] = $nuevo;
            $c->correlativo = $nuevo;
            // El kardex referencia el número del comprobante: se actualiza junto con la renumeración.
            if ($c->venta_id) {
                MovimientoInventario::withoutGlobalScopes()->where('origen_type', Venta::class)->where('origen_id', $c->venta_id)
                    ->update(['documento' => "{$c->serie}-$nuevo"]);
            }
            $c->cambiarEstado('por_enviar', "Renumerado de $anterior a {$c->serie}-$nuevo: el número estaba ocupado en la API por otro documento (último en la API: {$c->serie}-$remoto).", ['payload' => $payload]);
        });
    }

    private function mismoDocumento(Comprobante $c, array $doc): bool
    {
        return ($doc['cliente']['num_doc'] ?? null) === $c->cliente_num_doc
            && Dec::cmp(Dec::round($doc['totales']['total'] ?? -1), Dec::round($c->total)) === 0;
    }

    private function aplicarDocumentoApi(Comprobante $c, array $d, ?string $detalle): void
    {
        $sunat = $d['sunat'] ?? [];
        $estadoApi = strtolower((string) ($sunat['estado'] ?? 'enviado'));
        $estado = match (true) {
            $estadoApi === 'aceptado' => 'aceptado',
            $estadoApi === 'rechazado' => 'rechazado',
            $estadoApi === 'observado' => 'observado',
            $estadoApi === 'anulado' => 'anulado',
            // Boleta con solo_registro: la API la guarda "pendiente" hasta que se envíe en el resumen diario.
            $estadoApi === 'pendiente' && $c->envio_por_resumen => 'pendiente_resumen',
            default => 'enviado', // enviado | pendiente | en proceso
        };
        $totApi = $d['totales'] ?? null;
        $extra = [
            'api_id' => $d['id'] ?? $c->api_id,
            'sunat_codigo' => isset($sunat['codigo']) ? (string) $sunat['codigo'] : $c->sunat_codigo,
            'sunat_descripcion' => isset($sunat['descripcion']) ? mb_substr((string) $sunat['descripcion'], 0, 1000) : $c->sunat_descripcion,
            'sunat_notas' => $sunat['notas'] ?? $c->sunat_notas,
            'hash_cpe' => $sunat['hash_cpe'] ?? $c->hash_cpe,
            'enviado_at' => $c->enviado_at ?? now(),
            'ultimo_error' => null,
        ];
        if (is_array($totApi)) {
            // Conciliación: el total de la API debe coincidir con el calculado localmente (motor validado).
            $extra['totales_api'] = $totApi;
            $extra['diferencia_totales'] = Dec::cmp(Dec::round($totApi['total'] ?? 0), Dec::round($c->total)) !== 0;
        }
        if ($estado === 'aceptado' && ! $c->aceptado_at) {
            $extra['aceptado_at'] = now();
        }
        $texto = $detalle ?? (in_array($estado, Comprobante::FINALES, true) ? trim(($extra['sunat_codigo'] ?? '').' '.($extra['sunat_descripcion'] ?? '')) : null);
        $c->cambiarEstado($estado, $texto, $extra);
        $this->anularReferenciaSiCorresponde($c);
    }

    /**
     * Una nota de crédito total (01 anulación de la operación, 02 anulación por error en el RUC, 06 devolución total)
     * aceptada por SUNAT anula el comprobante al que se refiere. Su estado SUNAT sigue "aceptado"; se marca
     * anulado_por_nota_id para mostrarlo como Anulado y bloquear nuevas notas, bajas y guías.
     */
    private function anularReferenciaSiCorresponde(Comprobante $nota): void
    {
        if ($nota->tipo_documento !== '07' || ! in_array($nota->cod_motivo, NotaService::NC_TOTAL, true)
            || ! in_array($nota->estado, ['aceptado', 'observado'], true) || ! $nota->comprobante_referencia_id) {
            return;
        }
        $ref = Comprobante::withoutGlobalScopes()->find($nota->comprobante_referencia_id);
        if (! $ref || $ref->anulado_por_nota_id) {
            return;
        }
        $ref->update(['anulado_por_nota_id' => $nota->id]);
        $ref->estados()->create(['estado_anterior' => $ref->estado, 'estado_nuevo' => 'anulado',
            'detalle' => "Anulado por la nota de crédito {$nota->numero()} (motivo {$nota->cod_motivo}: {$nota->des_motivo})"]);
    }

    private function api(Comprobante $c): SunatApiClient
    {
        return $this->factory->paraEmpresa(Empresa::findOrFail($c->empresa_id));
    }

    private function mensaje(SunatApiResponse $r): string
    {
        $m = $r->mensaje() ?? "HTTP {$r->status}";
        if ($r->errores()) {
            $m .= ': '.collect($r->errores())->flatten()->implode(' ');
        }

        return mb_substr($m, 0, 1000);
    }

    private function log(Comprobante $c, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null, bool $binario = false): void
    {
        ApiSolicitud::create([
            'empresa_id' => $c->empresa_id, 'comprobante_id' => $c->id, 'operacion' => $op, 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res?->status,
            'respuesta' => $res ? ($binario && ! str_contains($res->contentType, 'json') ? ['content_type' => $res->contentType, 'bytes' => strlen($res->raw)] : $res->json) : null,
            'duracion_ms' => $res?->durationMs, 'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
