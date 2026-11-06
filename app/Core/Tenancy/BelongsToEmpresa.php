<?php

namespace App\Core\Tenancy;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aislamiento multiempresa: filtra automáticamente por la empresa activa y la asigna al crear.
 * Fuera de una solicitud con empresa (comandos, jobs) no filtra: allí se debe acotar explícitamente.
 */
trait BelongsToEmpresa
{
    public static function bootBelongsToEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $q) {
            $id = app(EmpresaActual::class)->id();
            if ($id !== null) {
                $q->where($q->getModel()->qualifyColumn('empresa_id'), $id);
            }
        });

        static::creating(function ($model) {
            $model->empresa_id ??= app(EmpresaActual::class)->id();
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->withoutGlobalScope('empresa')->where($this->qualifyColumn('empresa_id'), $empresaId);
    }
}
