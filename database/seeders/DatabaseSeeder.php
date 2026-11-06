<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos base del sistema (idempotente). NO crea usuarios: el primer superadministrador
 * se registra desde la pantalla "Configuración inicial".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PermisosSeeder::class, CatalogosSunatSeeder::class]);
    }
}
