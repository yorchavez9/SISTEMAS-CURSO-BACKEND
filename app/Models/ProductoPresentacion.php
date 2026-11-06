<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPresentacion extends Model
{
    protected $table = 'producto_presentaciones';

    protected $fillable = ['producto_id', 'nombre', 'unidad', 'factor', 'precio_venta', 'codigo_barras', 'activo'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['factor' => 'decimal:6', 'precio_venta' => 'decimal:6', 'activo' => 'boolean'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
