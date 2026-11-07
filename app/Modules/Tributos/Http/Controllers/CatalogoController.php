<?php

namespace App\Modules\Tributos\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Catálogos SUNAT locales (lectura para cualquier usuario autenticado). */
class CatalogoController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Cache::get(self::CACHE_KEY) ?? $this->cargar();

        return response()->json(['data' => $data]);
    }

    public const CACHE_KEY = 'catalogos_sunat:v1';

    private function cargar(): array
    {
        $data = (function () {
            $catalogos = DB::table('sunat_catalogos')->where('activo', true)->orderBy('catalogo')->orderBy('codigo')
                ->get(['catalogo', 'codigo', 'descripcion', 'validado_api', 'meta'])
                ->map(fn ($r) => [
                    'catalogo' => $r->catalogo, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion,
                    'validado_api' => (bool) $r->validado_api, 'meta' => $r->meta ? json_decode($r->meta, true) : null,
                ])
                ->groupBy('catalogo')->map(fn ($g) => $g->map(fn ($r) => array_diff_key($r, ['catalogo' => 1]))->values());

            $afectaciones = DB::table('tipos_afectacion_igv')->orderBy('codigo')->get()->map(fn ($a) => [
                'codigo' => $a->codigo, 'descripcion' => $a->descripcion, 'grupo' => $a->grupo,
                'es_gratuito' => (bool) $a->es_gratuito, 'tributo' => ['codigo' => $a->tributo_codigo, 'nombre' => $a->tributo_nombre, 'tipo' => $a->tributo_tipo],
                'calcula_igv' => (bool) $a->calcula_igv, 'validado_api' => (bool) $a->validado_api, 'activo' => (bool) $a->activo,
                'evidencia' => $a->evidencia, 'observacion' => $a->observacion,
            ]);

            return ['catalogos' => $catalogos, 'afectaciones_igv' => $afectaciones];
        })();
        // No se guarda en caché si aún no se cargaron los catálogos (antes de db:seed): se mostraría vacío 10 minutos.
        if ($data['catalogos']->isNotEmpty() && $data['afectaciones_igv']->isNotEmpty()) {
            Cache::put(self::CACHE_KEY, $data, 600);
        }

        return $data;
    }
}
