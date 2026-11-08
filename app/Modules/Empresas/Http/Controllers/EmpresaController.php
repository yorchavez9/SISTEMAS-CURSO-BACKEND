<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Modules\Empresas\Http\Requests\EmpresaRequest;
use App\Modules\Empresas\Http\Resources\EmpresaResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmpresaController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Empresa::class);
        $user = $request->user();
        $query = $user->is_superadmin ? Empresa::query() : $user->empresas();
        if ($user->is_superadmin && $request->boolean('eliminadas')) {
            $query = Empresa::onlyTrashed();
        }

        return EmpresaResource::collection($query->orderBy('razon_social')->get());
    }

    public function store(EmpresaRequest $request)
    {
        Gate::authorize('create', Empresa::class);
        $empresa = DB::transaction(function () use ($request) {
            $empresa = Empresa::create($request->validated());
            $request->user()->asignarEnEmpresa($empresa, 'admin');
            $this->auditor->registrar('empresa.creada', $empresa, [], $empresa->only(['ruc', 'razon_social']), $empresa->id);

            return $empresa;
        });

        return (new EmpresaResource($empresa))->additional(['message' => 'Empresa registrada.'])->response()->setStatusCode(201);
    }

    public function show(Empresa $empresa)
    {
        Gate::authorize('view', $empresa);

        return new EmpresaResource($empresa);
    }

    public function update(EmpresaRequest $request, Empresa $empresa)
    {
        Gate::authorize('update', $empresa);
        $data = $request->validated();
        if (($data['entorno_activo'] ?? null) === 'produccion' && $empresa->entorno_activo !== 'produccion'
            && $empresa->credencialApi('produccion')?->verificacion_ok !== true) {
            throw ValidationException::withMessages([
                'entorno_activo' => 'Registra y verifica las credenciales de producción antes de emitir en producción.',
            ]);
        }
        $antes = $empresa->only(array_keys($data));
        $empresa->update($data);
        $this->auditor->registrar('empresa.actualizada', $empresa, $antes, $empresa->only(array_keys($data)), $empresa->id);

        return (new EmpresaResource($empresa))->additional(['message' => 'Empresa actualizada.']);
    }

    /**
     * Elimina la empresa del sistema (borrado lógico): deja de aparecer y nadie puede operar con ella, pero sus
     * ventas, comprobantes, XML y CDR se CONSERVAN (obligación de conservar los comprobantes electrónicos) y un
     * superadministrador puede restaurarla. NO elimina la empresa en la API de facturación (DELETE /empresa es
     * irreversible y la cuenta puede ser compartida). Se confirma escribiendo el RUC.
     */
    public function destroy(Request $request, Empresa $empresa)
    {
        Gate::authorize('delete', $empresa);
        $request->validate(['confirmacion' => ['required', 'string']]);
        if (trim((string) $request->input('confirmacion')) !== $empresa->ruc) {
            throw ValidationException::withMessages(['confirmacion' => "Escribe el RUC {$empresa->ruc} para confirmar."]);
        }
        $empresa->delete();
        $this->auditor->registrar('empresa.eliminada', $empresa, ['ruc' => $empresa->ruc, 'razon_social' => $empresa->razon_social], [], $empresa->id);

        return response()->json(['message' => "Empresa {$empresa->razon_social} eliminada. Sus comprobantes se conservan y puede restaurarse."]);
    }

    public function restore(Empresa $empresa)
    {
        Gate::authorize('restore', $empresa);
        $empresa->restore();
        $this->auditor->registrar('empresa.restaurada', $empresa, [], ['ruc' => $empresa->ruc], $empresa->id);

        return (new EmpresaResource($empresa))->additional(['message' => "Empresa {$empresa->razon_social} restaurada."]);
    }
}
