<?php

namespace App\Modules\Guias\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\ApiSolicitud;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\GuiaRemision;
use App\Models\Serie;
use App\Models\User;
use App\Modules\Facturacion\Services\ProcesoSunat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Guía de remisión remitente (tipo 09) vía POST /guias-remision. Estructuras reales (Etapa 1 y prueba 2026-11-06):
 *  - 201 datos{id, serie, correlativo, numero_completo, sunat{estado: enviado|pendiente|rechazado, codigo, descripcion, ticket}, archivos{}}
 *  - correlativo explícito respetado; duplicado → 422 errores.correlativo ["El correlativo N ya existe para la serie …"]
 *  - motivo 04 → 422 si el destinatario no es el emisor
 *  - GET /{id}/estado sin ticket → 409 {guia{estado_sunat:"rechazado", codigo_sunat:"BUILD_ERROR", descripcion_sunat:"Cliente No autorizado"}, siguiente_accion}
 *    (la cuenta no tiene credenciales GRE de SUNAT, H-7). No es un rechazo de SUNAT: se corrige y se reenvía con POST /{id}/enviar.
 *  - GET /{id}/estado aceptada (T009-6, beta 2026-11-06) → 200 datos = guía completa con
 *    sunat{estado:"aceptado", codigo:"0", descripcion:"ACEPTADA", ticket} y archivos{xml, cdr, pdf} (URLs).
 * La lectura admite además campos estado_sunat/codigo_sunat (formato del 409) por robustez.
 */
class GuiaService
{
    public const RESULTADO_ENVIADO = 'enviado';
    public const RESULTADO_FINAL = 'final';
    public const RESULTADO_ERROR = 'error';
    public const RESULTADO_RENUMERADO = 'renumerado';
    public const MAX_RENUMERACIONES = 3;

    /** Errores previos al ticket que no son rechazos de SUNAT: la misma guía puede reenviarse. */
    public const CODIGOS_REENVIABLES = ['BUILD_ERROR'];

    public function __construct(private readonly SunatApiClientFactory $factory, private readonly Auditor $auditor) {}

    public function crear(Empresa $empresa, User $user, array $d): GuiaRemision
    {
        if ($existente = GuiaRemision::deEmpresa($empresa->id)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $existente;
        }
        $hoy = Carbon::now('America/Lima')->toDateString();
        if ($d['fecha_traslado'] < $hoy) {
            throw ValidationException::withMessages(['fecha_traslado' => 'La fecha de traslado no puede ser anterior a la fecha de emisión (hoy).']);
        }

        $guia = DB::transaction(function () use ($empresa, $user, $d, $hoy) {
            $serie = Serie::deEmpresa($empresa->id)->lockForUpdate()->find($d['serie_id']);
            if (! $serie || ! $serie->activo || $serie->tipo_documento !== '09' || ! $serie->establecimiento_id || ! $serie->api_serie_id) {
                throw ValidationException::withMessages(['serie_id' => 'Elige una serie de guía de remisión remitente (09) activa y sincronizada con la API.']);
            }
            $origen = Establecimiento::deEmpresa($empresa->id)->findOrFail($serie->establecimiento_id);
            $comprobante = ! empty($d['comprobante_id']) ? Comprobante::deEmpresa($empresa->id)->find($d['comprobante_id']) : null;
            if (! empty($d['comprobante_id']) && (! $comprobante || ! in_array($comprobante->tipo_documento, ['01', '03'], true))) {
                throw ValidationException::withMessages(['comprobante_id' => 'Comprobante no encontrado.']);
            }

            $partida = ['ubigeo' => $d['partida']['ubigeo'] ?? $origen->ubigeo, 'direccion' => $d['partida']['direccion'] ?? $origen->direccion];
            $destinatario = $d['destinatario'] ?? null;
            $llegada = $d['llegada'] ?? null;
            $destino = null;
            if ($d['cod_traslado'] === '04') {
                // Regla validada (GRR-04): el destinatario es el propio emisor; los locales se identifican por código.
                $destino = Establecimiento::deEmpresa($empresa->id)->find($d['establecimiento_destino_id'] ?? 0);
                if (! $destino || $destino->id === $origen->id) {
                    throw ValidationException::withMessages(['establecimiento_destino_id' => 'Elige el establecimiento de destino (distinto al de partida).']);
                }
                $destinatario = ['tipo_doc' => '6', 'num_doc' => $empresa->ruc, 'razon_social' => $empresa->razon_social];
                $llegada = ['ubigeo' => $destino->ubigeo, 'direccion' => $destino->direccion];
            }
            if (! $destinatario || ! $llegada || empty($llegada['ubigeo']) || empty($llegada['direccion']) || empty($partida['ubigeo']) || empty($partida['direccion'])) {
                throw ValidationException::withMessages(['llegada' => 'Completa destinatario, punto de partida y punto de llegada (ubigeo y dirección).']);
            }

            $serie->correlativo_actual++;
            $serie->save();

            $payload = [
                'serie' => $serie->serie,
                'correlativo' => $serie->correlativo_actual,
                'fecha_emision' => $hoy,
                'destinatario' => ['tipo_doc' => $destinatario['tipo_doc'], 'num_doc' => $destinatario['num_doc'], 'razon_social' => $destinatario['razon_social']],
                'cod_traslado' => $d['cod_traslado'],
                'mod_traslado' => $d['mod_traslado'],
                'fecha_traslado' => $d['fecha_traslado'],
                'peso_total' => (float) $d['peso_total'],
                'und_peso_total' => $d['und_peso_total'] ?? 'KGM',
                'partida_ubigeo' => $partida['ubigeo'],
                'partida_direccion' => $partida['direccion'],
                'llegada_ubigeo' => $llegada['ubigeo'],
                'llegada_direccion' => $llegada['direccion'],
                'items' => array_map(fn ($i) => array_filter([
                    'codigo' => $i['codigo'] ?? null, 'descripcion' => $i['descripcion'], 'cantidad' => (float) $i['cantidad'], 'unidad' => $i['unidad'] ?? 'NIU',
                ], fn ($v) => $v !== null && $v !== ''), $d['items']),
                'enviar_automatico' => true,
            ];
            if (! empty($d['num_bultos'])) {
                $payload['num_bultos'] = (int) $d['num_bultos'];
            }
            if ($destino) {
                $payload += ['partida_ruc' => $empresa->ruc, 'partida_cod_local' => $origen->cod_local, 'llegada_ruc' => $empresa->ruc, 'llegada_cod_local' => $destino->cod_local];
            }
            if ($d['mod_traslado'] === '01') { // GRR-01
                $payload['fecha_de_entrega_al_transportista'] = $d['fecha_entrega_transportista'] ?? $d['fecha_traslado'];
                $payload['transportista'] = array_filter([
                    'tipo_doc' => '6', 'num_doc' => $d['transportista']['num_doc'], 'razon_social' => $d['transportista']['razon_social'],
                    'nro_mtc' => $d['transportista']['nro_mtc'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            } elseif (! empty($d['vehiculo_m1l'])) { // GRR-03
                $payload['indicadores'] = ['SUNAT_Envio_IndicadorTrasladoVehiculoM1L'];
            } else { // GRR-02 / GRR-05
                $vehiculo = ['placa' => strtoupper($d['vehiculo']['placa'])];
                if (! empty($d['vehiculo']['secundarias'])) {
                    $vehiculo['secundarios'] = array_map(fn ($p) => ['placa' => strtoupper($p)], $d['vehiculo']['secundarias']);
                }
                $payload['vehiculo'] = $vehiculo;
                $conductores = array_values(array_map(fn ($c, $i) => [
                    'tipo' => $i === 0 ? 'Principal' : 'Secundario', 'tipo_doc' => $c['tipo_doc'], 'num_doc' => $c['num_doc'],
                    'nombres' => $c['nombres'], 'apellidos' => $c['apellidos'], 'licencia' => strtoupper($c['licencia']),
                ], $d['conductores'], array_keys($d['conductores'])));
                if (count($conductores) === 1) {
                    $payload['conductor'] = $conductores[0];
                } else {
                    $payload['conductores'] = $conductores;
                }
            }

            $g = GuiaRemision::create([
                'empresa_id' => $empresa->id, 'establecimiento_id' => $origen->id, 'serie_id' => $serie->id, 'serie' => $serie->serie,
                'correlativo' => $serie->correlativo_actual, 'comprobante_id' => $comprobante?->id, 'fecha_emision' => $hoy,
                'fecha_traslado' => $d['fecha_traslado'], 'cod_traslado' => $d['cod_traslado'], 'mod_traslado' => $d['mod_traslado'],
                'peso_total' => $d['peso_total'], 'und_peso_total' => $payload['und_peso_total'], 'num_bultos' => $payload['num_bultos'] ?? null,
                'destinatario_tipo_doc' => $destinatario['tipo_doc'], 'destinatario_num_doc' => $destinatario['num_doc'], 'destinatario_razon_social' => $destinatario['razon_social'],
                'partida_ubigeo' => $partida['ubigeo'], 'partida_direccion' => $partida['direccion'],
                'llegada_ubigeo' => $llegada['ubigeo'], 'llegada_direccion' => $llegada['direccion'],
                'estado' => 'por_enviar', 'payload' => $payload, 'idempotency_key' => $d['idempotency_key'], 'user_id' => $user->id,
                'historial' => [['fecha' => now()->toIso8601String(), 'estado' => 'por_enviar', 'detalle' => 'Guía registrada']],
            ]);
            ProcesoSunat::programarGuia($g->id);

            return $g;
        });
        $this->auditor->registrar('guia.emitida', $guia, [], ['numero' => $guia->numero(), 'motivo' => $guia->cod_traslado], $empresa->id);

        return $guia;
    }

    /** Envía la guía. Si ya existe en la API y quedó en error reenviable, usa POST /{id}/enviar (sin nuevo número). */
    public function emitir(GuiaRemision $g): string
    {
        if ($g->esFinal()) {
            return self::RESULTADO_FINAL;
        }
        $api = $this->api($g);
        $g->increment('intentos');
        [$path, $body] = $g->api_id ? ["/guias-remision/{$g->api_id}/enviar", []] : ['/guias-remision', $g->payload];

        try {
            $res = $api->post($path, $body);
        } catch (SunatApiConnectionException $e) {
            $this->log($g, 'emitir', 'POST', $path, $body ?: null, null, $e->getMessage());
            $g->update(['ultimo_error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
        $this->log($g, 'emitir', 'POST', $path, $body ?: null, $res);

        if ($res->ok()) {
            $this->aplicar($g, is_array($res->datos()) ? $res->datos() : [], $g->api_id ? 'Reenviada a SUNAT' : 'Recibida por la API');

            return $g->esFinal() ? self::RESULTADO_FINAL : ($g->estado === 'error' ? self::RESULTADO_ERROR : self::RESULTADO_ENVIADO);
        }
        $err = $res->errores() ?? [];
        if ($res->status === 422 && isset($err['correlativo']) && ! $g->api_id) {
            return $this->recuperarPorNumero($g, $api, implode(' ', (array) $err['correlativo']));
        }
        $detalle = $this->mensaje($res);
        if ($res->status >= 500) {
            $g->update(['ultimo_error' => $detalle]);
            throw new SunatApiConnectionException("La API respondió HTTP {$res->status}: $detalle");
        }
        $g->cambiarEstado('error', "La API rechazó el envío (HTTP {$res->status}): $detalle", ['ultimo_error' => $detalle]);

        return self::RESULTADO_ERROR;
    }

    /** Consulta el estado ante SUNAT. Devuelve true si ya no hay que seguir consultando. */
    public function consultar(GuiaRemision $g): bool
    {
        if (! $g->api_id) {
            return false;
        }
        $api = $this->api($g);
        $path = "/guias-remision/{$g->api_id}/estado";
        $res = $api->get($path);
        $this->log($g, 'consultar_estado', 'GET', $path, null, $res);
        $datos = $res->status === 409 ? ($res->json['guia'] ?? null) : (is_array($res->datos()) ? $res->datos() : null);
        if (! $datos || ! $this->tieneEstado($datos)) {
            // Formato no reconocido: se lee la guía completa (bloque "sunat", estructura conocida).
            $res = $api->get("/guias-remision/{$g->api_id}");
            $this->log($g, 'consultar_guia', 'GET', "/guias-remision/{$g->api_id}", null, $res);
            $datos = is_array($res->datos()) ? $res->datos() : null;
        }
        if ($datos) {
            $this->aplicar($g, $datos, null);
        }

        return $g->esFinal() || $g->estado === 'error';
    }

    /** XML, CDR y PDF (A4). Los de una guía aceptada se guardan; el PDF previo puede verse sin guardarse. */
    public function descargarArchivos(GuiaRemision $g, array $tipos = ['xml', 'cdr', 'pdf']): array
    {
        $empresa = Empresa::findOrFail($g->empresa_id);
        $archivos = $g->archivos ?? [];
        foreach ($tipos as $tipo) {
            $res = $this->obtenerArchivo($g, $tipo);
            if (! $res) {
                continue;
            }
            $ext = ['xml' => 'xml', 'cdr' => 'zip', 'pdf' => 'pdf'][$tipo];
            $path = sprintf('guias/%d/%s-09-%s-%d%s.%s', $empresa->id, $empresa->ruc, $g->serie, $g->correlativo, $tipo === 'cdr' ? '-cdr' : '', $ext);
            Storage::disk('local')->put($path, $res->raw);
            $archivos[$tipo] = ['path' => $path, 'size' => strlen($res->raw), 'sha256' => hash('sha256', $res->raw)];
        }
        $g->update(['archivos' => $archivos]);

        return array_keys($archivos);
    }

    public function obtenerArchivo(GuiaRemision $g, string $tipo): ?SunatApiResponse
    {
        if (! $g->api_id) {
            return null;
        }
        $path = "/guias-remision/{$g->api_id}/$tipo";
        $res = $this->api($g)->get($path, $tipo === 'pdf' ? ['format' => 'a4'] : []);
        $this->log($g, "descargar_$tipo", 'GET', $path, null, $res, binario: true);

        return $res->status === 200 && $res->raw !== '' && ! str_contains($res->contentType, 'json') ? $res : null;
    }

    private function tieneEstado(array $d): bool
    {
        return isset($d['sunat']['estado']) || isset($d['estado_sunat']);
    }

    private function aplicar(GuiaRemision $g, array $d, ?string $detalle): void
    {
        $estadoApi = strtolower((string) ($d['sunat']['estado'] ?? $d['estado_sunat'] ?? 'enviado'));
        $codigo = $d['sunat']['codigo'] ?? $d['codigo_sunat'] ?? null;
        $descripcion = $d['sunat']['descripcion'] ?? $d['descripcion_sunat'] ?? null;
        $ticket = $d['sunat']['ticket'] ?? $d['ticket'] ?? null;
        $reenviable = $estadoApi === 'rechazado' && in_array((string) $codigo, self::CODIGOS_REENVIABLES, true);
        $estado = match (true) {
            $reenviable => 'error',
            $estadoApi === 'aceptado' => 'aceptado',
            $estadoApi === 'rechazado' => 'rechazado',
            default => 'enviado', // enviado | pendiente | en proceso
        };
        $extra = [
            'api_id' => $d['id'] ?? $g->api_id,
            'sunat_codigo' => $codigo !== null ? (string) $codigo : $g->sunat_codigo,
            'sunat_descripcion' => $descripcion !== null ? mb_substr((string) $descripcion, 0, 1000) : $g->sunat_descripcion,
            'ticket' => $ticket ?? $g->ticket,
            'enviado_at' => $g->enviado_at ?? now(),
            'ultimo_error' => $reenviable ? mb_substr("SUNAT no procesó la guía ($codigo): $descripcion", 0, 1000) : null,
        ];
        if ($estado === 'aceptado' && ! $g->aceptado_at) {
            $extra['aceptado_at'] = now();
        }
        $texto = $detalle ?? ($estado !== 'enviado' ? trim(($codigo ?? '').' '.($descripcion ?? '')) : null);
        if ($reenviable && mb_strtolower((string) $descripcion) === 'cliente no autorizado') {
            $texto .= ' — La cuenta de la API no tiene configuradas las credenciales GRE de SUNAT (client_id/client_secret de SOL).';
        }
        $g->cambiarEstado($estado, $texto ?: null, $extra);
    }

    private function recuperarPorNumero(GuiaRemision $g, SunatApiClient $api, string $detalle): string
    {
        $res = $api->get('/guias-remision', ['serie' => $g->serie, 'correlativo' => $g->correlativo]);
        $this->log($g, 'recuperar_por_numero', 'GET', "/guias-remision?serie={$g->serie}&correlativo={$g->correlativo}", null, $res);
        $lista = $res->datos();
        $lista = isset($lista['datos']) ? $lista['datos'] : (is_array($lista) ? $lista : []);
        $doc = collect($lista)->first(fn ($x) => ($x['serie'] ?? '') === $g->serie && (int) ($x['correlativo'] ?? 0) === $g->correlativo);
        if ($doc && ($doc['destinatario']['num_doc'] ?? null) === $g->destinatario_num_doc && count($doc['items'] ?? []) === count($g->payload['items'])) {
            $this->aplicar($g, $doc, 'Recuperada: la API ya tenía esta guía (respuesta anterior perdida)');

            return $g->esFinal() ? self::RESULTADO_FINAL : self::RESULTADO_ENVIADO;
        }
        $renumeraciones = collect($g->historial ?? [])->filter(fn ($h) => str_starts_with((string) ($h['detalle'] ?? ''), 'Renumerada'))->count();
        if ($renumeraciones >= self::MAX_RENUMERACIONES) {
            $g->cambiarEstado('error', "Número ocupado en la API por otro documento. $detalle", ['ultimo_error' => 'Conflicto de numeración: '.$detalle]);

            return self::RESULTADO_ERROR;
        }
        $res = $api->get('/series');
        $this->log($g, 'sincronizar_serie', 'GET', '/series', null, $res);
        $series = $res->datos();
        $series = isset($series['datos']) ? $series['datos'] : (is_array($series) ? $series : []);
        $remoto = (int) (collect($series)->firstWhere('serie', $g->serie)['correlativo'] ?? 0);
        DB::transaction(function () use ($g, $remoto) {
            $serie = Serie::withoutGlobalScopes()->lockForUpdate()->findOrFail($g->serie_id);
            $anterior = $g->numero();
            $nuevo = max((int) $serie->correlativo_actual, $remoto, $g->correlativo) + 1;
            $serie->update(['correlativo_actual' => $nuevo]);
            $payload = $g->payload;
            $payload['correlativo'] = $nuevo;
            $g->correlativo = $nuevo;
            $g->cambiarEstado('por_enviar', "Renumerada de $anterior a {$g->serie}-$nuevo: el número estaba ocupado en la API por otro documento.", ['payload' => $payload]);
        });

        return self::RESULTADO_RENUMERADO;
    }

    private function api(GuiaRemision $g): SunatApiClient
    {
        return $this->factory->paraEmpresa(Empresa::findOrFail($g->empresa_id));
    }

    private function mensaje(SunatApiResponse $r): string
    {
        $m = $r->mensaje() ?? "HTTP {$r->status}";
        if ($r->errores()) {
            $m .= ': '.collect($r->errores())->flatten()->implode(' ');
        }

        return mb_substr($m, 0, 1000);
    }

    private function log(GuiaRemision $g, string $op, string $metodo, string $endpoint, ?array $payload, ?SunatApiResponse $res, ?string $error = null, bool $binario = false): void
    {
        ApiSolicitud::create([
            'empresa_id' => $g->empresa_id, 'comprobante_id' => null, 'operacion' => "guia_$op", 'metodo' => $metodo, 'endpoint' => $endpoint,
            'payload' => $payload, 'http_status' => $res?->status,
            'respuesta' => $res ? ($binario && ! str_contains($res->contentType, 'json') ? ['content_type' => $res->contentType, 'bytes' => strlen($res->raw)] : $res->json) : null,
            'duracion_ms' => $res?->durationMs, 'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
