<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrearAdminCommand extends Command
{
    protected $signature = 'sistema:crear-admin {email} {--name=Administrador} {--password= : Si se omite se genera una aleatoria}';

    protected $description = 'Crea (o reactiva) un usuario superadministrador';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Correo inválido.');

            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
        if (strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name'), 'password' => $password, 'is_superadmin' => true, 'activo' => true],
        );

        $this->info("Superadministrador listo: $email");
        if (! $this->option('password')) {
            $this->warn("Contraseña generada (cópiala ahora, no se vuelve a mostrar): $password");
        }

        return self::SUCCESS;
    }
}
