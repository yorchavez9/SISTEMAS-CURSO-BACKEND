<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Clientes\Services\ConsultaDocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Proveedor de consulta de RUC/DNI de la empresa. El token se guarda cifrado y nunca vuelve al navegador. */
class ConsultaDocumentosController extends Controller
{
    public function __construct(private readonly ConsultaDocumentoService $svc, private readonly Auditor $auditor) {}

    public function show(Empresa $empresa): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);

        return response()->json(['data' => $this->dto($empresa)]);
    }

    public function update(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        $d = $request->validate([
            'proveedor' => ['required', Rule::in(ConsultaDocumentoService::PROVEEDORES)],
            'token' => ['nullable', 'string', 'max:500'],
        ], [], ['token' => 'token']);
        if ($d['proveedor'] === 'decolecta' && empty($d['token']) && ! $empresa->consulta_token) {
            return response()->json(['message' => 'Ingresa el token de Decolecta.', 'errors' => ['token' => ['Ingresa el token de Decolecta.']]], 422);
        }
        $antes = ['proveedor' => $empresa->consulta_proveedor];
        $empresa->consulta_proveedor = $d['proveedor'];
        if (! empty($d['token'])) {
            $empresa->consulta_token = $d['token']; // vacío = conservar el actual
        }
        $empresa->save();
        $this->auditor->registrar('empresa.consulta_documentos', $empresa, $antes,
            ['proveedor' => $d['proveedor'], 'token_cambiado' => ! empty($d['token'])], $empresa->id);

        return response()->json(['data' => $this->dto($empresa), 'message' => 'Proveedor de consulta de RUC/DNI guardado.']);
    }

    /** Prueba el proveedor (con el token escrito o el guardado) consultando el RUC público de SUNAT. */
    public function probar(Request $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('gestionarCredenciales', $empresa);
        $d = $request->validate(['proveedor' => ['required', Rule::in(ConsultaDocumentoService::PROVEEDORES)], 'token' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->svc->probar($empresa, $d['proveedor'], ($d['token'] ?? null) ?: $empresa->consulta_token)]);
    }

    private function dto(Empresa $e): array
    {
        $t = $e->consulta_token;

        return [
            'proveedor' => $e->consulta_proveedor,
            'tiene_token' => (bool) $t,
            'token_final' => $t ? '••••'.substr($t, -4) : null, // solo los 4 últimos caracteres
        ];
    }
}
