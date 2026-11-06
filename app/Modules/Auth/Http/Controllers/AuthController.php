<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Modules\Empresas\Http\Resources\EmpresaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($cred + ['activo' => true], $request->boolean('recordar'))) {
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }
        $request->session()->regenerate();
        $this->auditor->registrar('auth.login', $request->user());

        return $this->me($request);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auditor->registrar('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $empresas = $user->is_superadmin ? \App\Models\Empresa::orderBy('razon_social')->get() : $user->empresas()->orderBy('razon_social')->get();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_superadmin' => $user->is_superadmin,
            'empresas' => EmpresaResource::collection($empresas)->resolve($request),
        ]]);
    }
}
