<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Almacen extends Model
{
    use BelongsToEmpresa;

    protected $table = 'almacenes';

    protected $guarded = ['id'];

    protected $attributes = ['es_principal' => false, 'activo' => true];

    protected function casts(): array
    {
        return ['es_principal' => 'boolean', 'activo' => 'boolean'];
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }
}
