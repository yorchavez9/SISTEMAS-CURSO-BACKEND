<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CotizacionItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'precio_unitario' => 'decimal:6'];
    }
}
