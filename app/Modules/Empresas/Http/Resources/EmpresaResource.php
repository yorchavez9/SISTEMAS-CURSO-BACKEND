<?php

namespace App\Modules\Empresas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Empresa */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->direccion,
            'ubigeo' => $this->ubigeo,
            'departamento' => $this->departamento,
            'provincia' => $this->provincia,
            'distrito' => $this->distrito,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'entorno_activo' => $this->entorno_activo,
            'activo' => $this->activo,
            'permitir_stock_negativo' => (bool) $this->permitir_stock_negativo,
            'envio_boletas' => $this->envio_boletas ?? 'individual',
            'mis_roles' => $user ? ($user->is_superadmin ? ['superadmin'] : $user->rolesEn($this->resource)) : [],
            'mis_permisos' => $user ? $user->permisosEn($this->resource) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
