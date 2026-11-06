<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Configuración inicial: permite registrar el primer superadministrador SOLO
 * mientras no exista ningún usuario. Después de eso el endpoint queda cerrado.
 */
class SetupController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function estado(): JsonResponse
    {
        return response()->json(['data' => ['requiere_configuracion' => ! User::query()->exists()]]);
    }

    public function registrarSuperadmin(Request $request): JsonResponse
    {
        abort_if(User::query()->exists(), 403, 'El sistema ya fue configurado. Inicia sesión.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], [
            'name' => 'nombre', 'email' => 'correo', 'password' => 'contraseña',
        ]);

        // Dos solicitudes simultáneas no pueden crear dos superadministradores:
        // lock atómico + verificación dentro de la transacción con bloqueo de la tabla.
        $user = Cache::lock('setup:superadmin', 10)->block(5, function () use ($data) {
            return DB::transaction(function () use ($data) {
                abort_if(User::query()->lockForUpdate()->count() > 0, 403, 'El sistema ya fue configurado. Inicia sesión.');

                return User::create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => $data['password'],
                    'is_superadmin' => true,
                    'activo' => true,
                ]);
            });
        });

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $this->auditor->registrar('setup.superadmin_creado', $user, [], ['email' => $user->email]);

        return app(AuthController::class)->me($request)->setStatusCode(201);
    }
}
