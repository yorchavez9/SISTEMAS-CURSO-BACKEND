<?php

namespace App\Modules\Empresas\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\SunatApiClient;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Integrations\SunatApi\SunatApiResponse;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Serie;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sincroniza establecimientos (API: /sucursales) y series (API: /series) con la cuenta de la API.
 * Estructuras reales observadas en la Etapa 1:
 *  - sucursal: {id, nombre, cod_local, direccion, ubigeo, telefono, email, es_principal, activo, total_series}
 *  - serie:    {id, tipo, tipo_documento, serie, correlativo (último usado), siguiente_numero, sucursal{id, nombre, cod_local}, activo}
 */
class SincronizacionApiService
{
    public function __construct(
        private readonly SunatApiClientFactory $factory,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{establecimientos: int, series: int} */
    public function importar(Empresa $empresa): array
    {
        $api = $this->factory->paraEmpresa($empresa);
        $sucursales = $this->lista($api->get('/sucursales'), 'sucursales');
        $series = $this->lista($api->get('/series'), 'series');

        $res = DB::transaction(function () use ($empresa, $sucursales, $series) {
            $n = ['establecimientos' => 0, 'series' => 0];
            foreach ($sucursales as $s) {
                $this->upsertEstablecimiento($empresa, $s);
                $n['establecimientos']++;
            }
            foreach ($series as $s) {
                // Algunas series pertenecen a sucursales que no figuran en /sucursales: se crean desde el dato embebido.
                $est = isset($s['sucursal']['cod_local']) ? $this->upsertEstablecimiento($empresa, $s['sucursal'], parcial: true) : null;
                $serie = Serie::deEmpresa($empresa->id)->where('serie', $s['serie'])->lockForUpdate()->first()
                    ?? new Serie(['empresa_id' => $empresa->id, 'serie' => $s['serie']]);
                $serie->fill([
                    'tipo_documento' => $s['tipo_documento'],
                    'establecimiento_id' => $est?->id ?? $serie->establecimiento_id,
                    'activo' => (bool) ($s['activo'] ?? true),
                    'api_serie_id' => $s['id'],
                    'sincronizado_at' => now(),
                ]);
                // Nunca retroceder: el correlativo local es el máximo entre el local y el último usado en la API.
                $serie->correlativo_actual = max((int) $serie->correlativo_actual, (int) ($s['correlativo'] ?? 0));
                $serie->save();
                $n['series']++;
            }

            return $n;
        });

        $this->auditor->registrar('sincronizacion_api.importada', $empresa, [], $res, $empresa->id);

        return $res;
    }

    public function crearEstablecimiento(Empresa $empresa, array $data): Establecimiento
    {
        $api = $this->factory->paraEmpresa($empresa);
        $res = $api->post('/sucursales', array_filter([
            'nombre' => $data['nombre'], 'cod_local' => $data['cod_local'], 'direccion' => $data['direccion'] ?? null,
            'ubigeo' => $data['ubigeo'] ?? null, 'es_principal' => (bool) ($data['es_principal'] ?? false),
            'telefono' => $data['telefono'] ?? null, 'email' => $data['email'] ?? null,
        ], fn ($v) => $v !== null));
        $this->exigirOk($res, 'registrar el establecimiento');

        // La respuesta del POST no se capturó en la Etapa 1: se relee la lista para obtener el id real.
        $remota = collect($this->lista($api->get('/sucursales'), 'sucursales'))->firstWhere('cod_local', $data['cod_local']);

        return $this->upsertEstablecimiento($empresa, ($remota ?? []) + $data);
    }

    public function crearSerie(Empresa $empresa, Establecimiento $est, string $tipoDocumento, string $serie, int $correlativoInicial = 0): Serie
    {
        if (! $est->api_sucursal_id) {
            throw new RuntimeException('El establecimiento no está registrado en la API. Sincroniza primero.');
        }
        $api = $this->factory->paraEmpresa($empresa);
        // Estructura del ejemplo Postman "Crear series (todos los tipos)".
        $res = $api->post('/series', ['series' => [[
            'tipo' => Serie::TIPOS_API[$tipoDocumento], 'serie' => $serie,
            'sucursal_id' => $est->api_sucursal_id, 'correlativo_inicial' => $correlativoInicial,
        ]]]);
        $this->exigirOk($res, 'registrar la serie');

        $remota = collect($this->lista($api->get('/series'), 'series'))->firstWhere('serie', $serie);

        return Serie::create([
            'empresa_id' => $empresa->id, 'establecimiento_id' => $est->id, 'tipo_documento' => $tipoDocumento, 'serie' => $serie,
            'correlativo_actual' => max($correlativoInicial, (int) ($remota['correlativo'] ?? 0)),
            'api_serie_id' => $remota['id'] ?? null, 'sincronizado_at' => now(),
        ]);
    }

    public function cambiarEstadoSerie(Empresa $empresa, Serie $serie, bool $activo): Serie
    {
        if ($serie->api_serie_id) {
            // Ejemplo Postman "Activar/desactivar serie": PUT /series/{id} {is_active}
            $this->exigirOk($this->factory->paraEmpresa($empresa)->put("/series/{$serie->api_serie_id}", ['is_active' => $activo]), 'actualizar la serie');
        }
        $serie->update(['activo' => $activo, 'sincronizado_at' => now()]);

        return $serie;
    }

    private function upsertEstablecimiento(Empresa $empresa, array $s, bool $parcial = false): Establecimiento
    {
        $est = Establecimiento::deEmpresa($empresa->id)
            ->where(fn ($q) => $q->where('api_sucursal_id', $s['id'] ?? -1)->orWhere('cod_local', $s['cod_local']))
            ->first() ?? new Establecimiento(['empresa_id' => $empresa->id, 'cod_local' => $s['cod_local']]);

        $campos = ['nombre' => $s['nombre'] ?? $est->nombre ?? 'Establecimiento '.$s['cod_local']];
        if (! $parcial) {
            $campos += [
                'direccion' => $s['direccion'] ?? null, 'ubigeo' => $s['ubigeo'] ?? null, 'telefono' => $s['telefono'] ?? null,
                'email' => $s['email'] ?? null, 'es_principal' => (bool) ($s['es_principal'] ?? false), 'activo' => (bool) ($s['activo'] ?? true),
            ];
        }
        $est->fill($campos + ['api_sucursal_id' => $s['id'] ?? $est->api_sucursal_id, 'sincronizado_at' => now()]);
        $est->save();

        return $est;
    }

    /** Las listas pueden venir como datos[] o paginadas en datos.datos[] (Etapa 1, H-10). */
    private function lista(SunatApiResponse $res, string $que): array
    {
        $this->exigirOk($res, "leer $que");
        $d = $res->datos();

        return isset($d['datos']) && is_array($d['datos']) ? $d['datos'] : (is_array($d) ? $d : []);
    }

    private function exigirOk(SunatApiResponse $res, string $accion): void
    {
        if (! $res->ok()) {
            $detalle = $res->errores() ? ' '.json_encode($res->errores(), JSON_UNESCAPED_UNICODE) : '';
            throw new RuntimeException("La API no permitió $accion: ".($res->mensaje() ?? "HTTP {$res->status}").$detalle);
        }
    }
}
