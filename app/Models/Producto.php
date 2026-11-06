<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    protected $fillable = [
        'empresa_id', 'categoria_id', 'tipo', 'codigo', 'codigo_barras', 'cod_producto_sunat', 'descripcion', 'unidad', 'moneda',
        'precio_venta', 'costo', 'tip_afe_igv', 'porcentaje_isc', 'tip_sis_isc', 'aplica_icbper', 'factor_icbper',
        'controla_stock', 'stock_minimo', 'activo',
    ];

    protected $attributes = ['tipo' => 'bien', 'moneda' => 'PEN', 'aplica_icbper' => false, 'controla_stock' => true, 'activo' => true];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:6', 'costo' => 'decimal:6', 'porcentaje_isc' => 'decimal:2', 'factor_icbper' => 'decimal:4',
            'stock_minimo' => 'decimal:3', 'aplica_icbper' => 'boolean', 'controla_stock' => 'boolean', 'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function presentaciones(): HasMany
    {
        return $this->hasMany(ProductoPresentacion::class);
    }

    /** Ítem con la forma del payload de la API (la usan el cálculo y la emisión). */
    public function comoItem(string|int|float $cantidad, ?string $precio = null, ?string $tipAfe = null): array
    {
        return array_filter([
            'codigo' => $this->codigo,
            'cod_producto_sunat' => $this->cod_producto_sunat,
            'descripcion' => $this->descripcion,
            'unidad' => $this->unidad,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio ?? $this->precio_venta,
            'tip_afe_igv' => $tipAfe ?? $this->tip_afe_igv,
            'porcentaje_isc' => $this->porcentaje_isc,
            'tip_sis_isc' => $this->porcentaje_isc ? ($this->tip_sis_isc ?? '01') : null,
            'factor_icbper' => $this->aplica_icbper ? $this->factor_icbper : null,
        ], fn ($v) => $v !== null);
    }
}
