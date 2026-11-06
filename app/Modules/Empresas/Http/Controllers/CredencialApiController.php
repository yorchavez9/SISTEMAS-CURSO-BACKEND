<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Empresas\Http\Requests\GuardarCredencialesApiRequest;
use App\Modules\Empresas\Http\Resources\CredencialApiResource;
use App\Modules\Empresas\Services\CredencialesApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CredencialApiController extends Controller
{
    public function __construct(private readonly CredencialesApiService $service) {}

    public function index(Empresa $empresa): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        $creds = $empresa->credencialesApi()->get()->keyBy('entorno');

        return response()->json([
            'data' => [
                'entorno_activo' => $empresa->entorno_activo,
                'base_url' => config('services.sunat_api.base_url'),
                'entornos' => array_map(fn ($e) => CredencialApiResource::make($e, $creds->get($e)), Empresa::ENTORNOS),
            ],
        ]);
    }

    public function update(GuardarCredencialesApiRequest $request, Empresa $empresa, string $entorno): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        try {
            $cred = $this->service->guardar($empresa, $entorno, $request->validated('api_key'), $request->validated('api_secret'), $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['api_secret' => $e->getMessage()]);
        }

        if ($request->boolean('verificar', true)) {
            $cred = $this->service->verificar($empresa, $cred);
        }

        return response()->json([
            'data' => CredencialApiResource::make($entorno, $cred),
            'message' => 'Credenciales guardadas de forma cifrada.',
        ]);
    }

    public function verificar(Empresa $empresa, string $entorno): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        $cred = $empresa->credencialApi($entorno);
        abort_if(! $cred, 404, 'No hay credenciales registradas para este entorno.');
        $cred = $this->service->verificar($empresa, $cred);

        return response()->json(['data' => CredencialApiResource::make($entorno, $cred), 'message' => $cred->verificacion_mensaje]);
    }

    public function destroy(Empresa $empresa, string $entorno): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        $cred = $empresa->credencialApi($entorno);
        abort_if(! $cred, 404, 'No hay credenciales registradas para este entorno.');
        $this->service->eliminar($empresa, $cred);

        return response()->json(['data' => CredencialApiResource::make($entorno, null), 'message' => 'Credenciales eliminadas.']);
    }
}
