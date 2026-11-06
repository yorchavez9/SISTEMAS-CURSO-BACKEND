<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;

class CajaMovimiento extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }
}
