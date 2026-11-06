<?php

namespace App\Modules\Usuarios\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Permisos\Permisos;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de una empresa. Un usuario puede pertenecer a varias empresas con un rol distinto en cada una.
 */
class UsuarioEmpresaController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function roles(): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => collect(Permisos::ROLES)->map(fn ($r, $k) => ['nombre' => $k, 'label' => $r['label'], 'permisos' => Permisos::delRol($k)])->values(),
            'modulos' => collect(Permisos::MODULOS)->map(fn ($m, $k) => ['modulo' => $k, 'label' => $m['label'], 'acciones' => $m['acciones']])->values(),
        ]]);
    }

    public function index(Empresa $empresa): JsonResponse
    {
        Gate::authorize('verUsuarios', $empresa);
        $usuarios = $empresa->usuarios()->orderBy('name')->get()->map(fn (User $u) => $this->presentar($empresa, $u));

        return response()->json(['data' => $usuarios]);
    }

    /** Crea un usuario nuevo o agrega uno existente (por correo) a la empresa. */
    public function store(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('gestionarUsuarios', $empresa);
        $existente = User::where('email', strtolower((string) $request->input('email')))->first();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'rol' => ['required', Rule::in(array_keys(Permisos::ROLES))],
            'name' => [$existente ? 'nullable' : 'required', 'string', 'min:3', 'max:120'],
            'password' => [$existente ? 'prohibited' : 'required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ], ['password.prohibited' => 'El usuario ya existe: se agregará a la empresa con su contraseña actual.'],
            ['name' => 'nombre', 'password' => 'contraseña', 'rol' => 'rol']);

        if ($existente && $existente->perteneceA($empresa) && ! $existente->is_superadmin) {
            throw ValidationException::withMessages(['email' => 'Este usuario ya pertenece a la empresa.']);
        }

        $user = DB::transaction(function () use ($existente, $data, $empresa) {
            $user = $existente ?? User::create([
                'name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'],
            ]);
            $user->asignarEnEmpresa($empresa, $data['rol']);

            return $user;
        });

        $this->auditor->registrar($existente ? 'usuario.agregado_a_empresa' : 'usuario.creado', $user, [], ['email' => $user->email, 'rol' => $data['rol']], $empresa->id);

        return response()->json([
            'data' => $this->presentar($empresa, $user->fresh()),
            'message' => $existente ? 'Usuario existente agregado a la empresa.' : 'Usuario creado.',
        ], 201);
    }

    public function update(Request $request, Empresa $empresa, User $usuario): JsonResponse
    {
        Gate::authorize('gestionarUsuarios', $empresa);
        abort_unless($usuario->perteneceA($empresa) && ! $usuario->is_superadmin, 404, 'El usuario no pertenece a esta empresa.');
        $data = $request->validate([
            'rol' => ['sometimes', Rule::in(array_keys(Permisos::ROLES))],
            'activo' => ['sometimes', 'boolean'],
        ]);
        $antes = ['roles' => $usuario->rolesEn($empresa), 'activo' => $this->activoEn($empresa, $usuario)];

        $quitaAdmin = (isset($data['rol']) && $data['rol'] !== 'admin') || (($data['activo'] ?? true) === false);
        if ($quitaAdmin && in_array('admin', $antes['roles'], true)) {
            $this->asegurarOtroAdmin($empresa, $usuario);
        }
        if ($usuario->is($request->user()) && ($data['activo'] ?? true) === false) {
            throw ValidationException::withMessages(['activo' => 'No puedes desactivarte a ti mismo.']);
        }

        DB::transaction(function () use ($data, $usuario, $empresa) {
            if (isset($data['rol'])) {
                $usuario->enEmpresa($empresa)->syncRoles([$data['rol']]);
            }
            if (array_key_exists('activo', $data)) {
                $usuario->empresas()->updateExistingPivot($empresa->id, ['activo' => $data['activo']]);
            }
        });
        $this->auditor->registrar('usuario.actualizado', $usuario, $antes, $data, $empresa->id);

        return response()->json(['data' => $this->presentar($empresa, $usuario->fresh()), 'message' => 'Usuario actualizado.']);
    }

    public function destroy(Request $request, Empresa $empresa, User $usuario): JsonResponse
    {
        Gate::authorize('gestionarUsuarios', $empresa);
        // Igual que en update(): el superadministrador no se administra desde una empresa.
        abort_unless($usuario->empresas()->whereKey($empresa->id)->exists() && ! $usuario->is_superadmin, 404, 'El usuario no pertenece a esta empresa.');
        if ($usuario->is($request->user())) {
            throw ValidationException::withMessages(['usuario' => 'No puedes retirarte a ti mismo de la empresa.']);
        }
        if (in_array('admin', $usuario->rolesEn($empresa), true)) {
            $this->asegurarOtroAdmin($empresa, $usuario);
        }
        DB::transaction(function () use ($usuario, $empresa) {
            $usuario->enEmpresa($empresa)->syncRoles([]);
            $usuario->empresas()->detach($empresa->id);
        });
        $this->auditor->registrar('usuario.retirado_de_empresa', $usuario, ['email' => $usuario->email], [], $empresa->id);

        return response()->json(['message' => 'Usuario retirado de la empresa.']);
    }

    private function asegurarOtroAdmin(Empresa $empresa, User $saliente): void
    {
        $otros = $empresa->usuarios()->wherePivot('activo', true)->where('users.id', '!=', $saliente->id)->get()
            ->filter(fn (User $u) => in_array('admin', $u->rolesEn($empresa), true));
        if ($otros->isEmpty()) {
            throw ValidationException::withMessages(['rol' => 'La empresa debe conservar al menos un administrador activo.']);
        }
    }

    private function activoEn(Empresa $empresa, User $u): bool
    {
        return (bool) ($u->empresas()->whereKey($empresa->id)->first()?->pivot?->activo ?? false);
    }

    private function presentar(Empresa $empresa, User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'activo_global' => $u->activo,
            'activo' => $this->activoEn($empresa, $u),
            'roles' => $u->rolesEn($empresa),
        ];
    }
}
