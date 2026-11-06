<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Establecimiento extends Model
{
    use BelongsToEmpresa;

    protected $table = 'establecimientos';

    protected $fillable = [
        'empresa_id', 'nombre', 'cod_local', 'direccion', 'ubigeo', 'telefono', 'email',
        'es_principal', 'activo', 'api_sucursal_id', 'sincronizado_at',
    ];

    protected $attributes = ['es_principal' => false, 'activo' => true];

    protected function casts(): array
    {
        return ['es_principal' => 'boolean', 'activo' => 'boolean', 'sincronizado_at' => 'datetime'];
    }

    public function series(): HasMany
    {
        return $this->hasMany(Serie::class);
    }
}
