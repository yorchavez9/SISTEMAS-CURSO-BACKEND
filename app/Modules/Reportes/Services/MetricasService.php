<?php

namespace App\Modules\Reportes\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas agregadas sobre comprobantes. Regla única para "venta neta":
 * facturas y boletas suman, notas de crédito restan, notas de débito suman;
 * se excluyen los comprobantes con error, rechazados o dados de baja.
 */
class MetricasService
{
    public const ESTADOS_VALIDOS = ['por_enviar', 'pendiente_resumen', 'enviado', 'aceptado', 'observado'];

    /** Expresión SQL del signo por tipo de documento. */
    public const SIGNO = "CASE comprobantes.tipo_documento WHEN '07' THEN -1 ELSE 1 END";

    public function base(int $empresaId, ?string $desde = null, ?string $hasta = null, string $moneda = 'PEN'): Builder
    {
        return DB::table('comprobantes')
            ->where('comprobantes.empresa_id', $empresaId)
            ->whereIn('comprobantes.estado', self::ESTADOS_VALIDOS)
            ->where('comprobantes.moneda', $moneda)
            ->when($desde, fn ($q) => $q->whereDate('comprobantes.fecha_emision', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('comprobantes.fecha_emision', '<=', $hasta));
    }

    public function ventaNeta(int $empresaId, string $desde, string $hasta): float
    {
        return (float) $this->base($empresaId, $desde, $hasta)->sum(DB::raw(self::SIGNO.' * comprobantes.total'));
    }

    /** Montos con signo para reportes tributarios. */
    public static function columnasConSigno(): array
    {
        $cols = [];
        foreach (['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'igv', 'isc', 'icbper', 'total'] as $c) {
            $cols[] = DB::raw('SUM('.self::SIGNO." * comprobantes.$c) as $c");
        }

        return $cols;
    }
}
