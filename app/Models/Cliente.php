<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    protected $fillable = [
        'empresa_id', 'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial', 'direccion',
        'ubigeo', 'email', 'telefono', 'limite_credito', 'dias_credito', 'activo',
    ];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'limite_credito' => 'decimal:2', 'dias_credito' => 'integer'];
    }

    /** Forma "cliente" que espera la API en cada comprobante (inline). */
    public function paraComprobante(): array
    {
        return array_filter([
            'tipo_doc' => $this->tipo_documento,
            'num_doc' => $this->numero_documento,
            'razon_social' => $this->razon_social,
            'direccion' => $this->direccion,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
