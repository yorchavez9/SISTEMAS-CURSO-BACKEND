<?php

namespace Database\Seeders;

use App\Core\Permisos\Permisos;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotente: se puede ejecutar en cada despliegue. */
class PermisosSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permisos::todos() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (array_keys(Permisos::ROLES) as $rol) {
            // Roles globales (empresa_id NULL): se asignan dentro de cada empresa.
            $role = Role::query()->where('name', $rol)->whereNull('empresa_id')->first()
                ?? Role::create(['name' => $rol, 'guard_name' => 'web', 'empresa_id' => null]);
            $role->syncPermissions(Permisos::delRol($rol));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
