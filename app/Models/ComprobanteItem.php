<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['devuelve_stock' => 'boolean', 'cantidad' => 'decimal:4', 'total' => 'decimal:2'];
    }
}
