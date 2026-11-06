<?php

namespace App\Modules\Empresas\Policies;

use App\Models\Empresa;
use App\Models\User;

class EmpresaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->activo;
    }

    public function view(User $user, Empresa $empresa): bool
    {
        return $user->activo && $user->perteneceA($empresa);
    }

    public function create(User $user): bool
    {
        return $user->activo && $user->is_superadmin;
    }

    public function update(User $user, Empresa $empresa): bool
    {
        return $user->puede($empresa, 'empresas.editar');
    }

    public function gestionarCredenciales(User $user, Empresa $empresa): bool
    {
        return $user->puede($empresa, 'credenciales_api.gestionar');
    }

    public function verUsuarios(User $user, Empresa $empresa): bool
    {
        return $user->puede($empresa, 'usuarios.ver');
    }

    public function gestionarUsuarios(User $user, Empresa $empresa): bool
    {
        return $user->puede($empresa, 'usuarios.gestionar');
    }
}
