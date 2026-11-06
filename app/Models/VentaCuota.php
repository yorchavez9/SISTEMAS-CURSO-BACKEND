<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCuota extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_vencimiento' => 'date', 'monto' => 'decimal:2', 'monto_pagado' => 'decimal:2'];
    }
}
