<?php

namespace App\Modules\Tributos\Calculo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Regla de cálculo de un código del catálogo 07 (fuente: tabla tipos_afectacion_igv, no código fijo). */
final class ReglaAfectacion
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $grupo,        // gravado | exonerado | inafecto | exportacion | ivap
        public readonly bool $esGratuito,
        public readonly bool $calculaIgv,
        public readonly bool $activo,
    ) {}

    /** @return array<string, self> */
    public static function todas(): array
    {
        return Cache::remember('reglas_afectacion:v1', 600, fn () => DB::table('tipos_afectacion_igv')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->codigo => new self((string) $r->codigo, $r->grupo, (bool) $r->es_gratuito, (bool) $r->calcula_igv, (bool) $r->activo)])
            ->all());
    }
}
