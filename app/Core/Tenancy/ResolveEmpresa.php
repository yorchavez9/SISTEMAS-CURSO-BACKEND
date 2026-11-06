<?php

namespace App\Core\Tenancy;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa activa desde el header X-Empresa-Id y verifica que el usuario pertenezca a ella.
 * Uso: middleware('empresa') o middleware('empresa:clientes.ver') para exigir además un permiso.
 */
class ResolveEmpresa
{
    public function __construct(private readonly EmpresaActual $actual) {}

    public function handle(Request $request, Closure $next, ?string $permiso = null): Response
    {
        $id = $request->header('X-Empresa-Id');
        abort_unless(is_string($id) && ctype_digit($id), 400, 'Falta el header X-Empresa-Id (empresa activa).');

        $empresa = Empresa::query()->where('activo', true)->find((int) $id);
        $user = $request->user();
        abort_unless($empresa && $user?->perteneceA($empresa), 403, 'No tienes acceso a esta empresa.');
        if ($permiso !== null) {
            abort_unless($user->puede($empresa, $permiso), 403, 'No tienes permiso para realizar esta acción.');
        }

        $this->actual->establecer($empresa);

        return $next($request);
    }
}
