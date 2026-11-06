<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use HasFactory, SoftDeletes;

    public const ENTORNOS = ['pruebas', 'produccion'];

    protected $fillable = [
        'ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo',
        'departamento', 'provincia', 'distrito', 'email', 'telefono', 'entorno_activo', 'activo', 'permitir_stock_negativo', 'envio_boletas',
    ];

    protected $attributes = [
        'entorno_activo' => 'pruebas',
        'activo' => true,
        'permitir_stock_negativo' => false,
        'envio_boletas' => 'individual',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'permitir_stock_negativo' => 'boolean'];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('activo')->withTimestamps();
    }

    public function credencialesApi(): HasMany
    {
        return $this->hasMany(EmpresaApiCredencial::class);
    }

    public function credencialApi(?string $entorno = null): ?EmpresaApiCredencial
    {
        return $this->credencialesApi()->where('entorno', $entorno ?? $this->entorno_activo)->first();
    }
}
