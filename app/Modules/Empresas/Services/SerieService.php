<?php

namespace App\Modules\Empresas\Services;

use App\Models\Serie;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reserva de correlativos (decisión D3, validada en la Etapa 1: la API acepta correlativo explícito
 * y rechaza duplicados con 422). La reserva bloquea la fila de la serie para que dos ventas
 * simultáneas nunca obtengan el mismo número.
 */
class SerieService
{
    public function reservarSiguiente(int $serieId): int
    {
        return DB::transaction(function () use ($serieId) {
            $serie = Serie::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($serieId);
            if (! $serie->activo) {
                throw new RuntimeException("La serie {$serie->serie} está desactivada.");
            }
            $serie->correlativo_actual++;
            $serie->save();

            return $serie->correlativo_actual;
        });
    }
}
