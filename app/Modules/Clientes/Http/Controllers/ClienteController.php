<?php

namespace App\Modules\Clientes\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Integrations\SunatApi\Exceptions\CredencialesNoConfiguradasException;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Models\Cliente;
use App\Modules\Clientes\DocumentoIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClienteController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $p = Cliente::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('numero_documento', 'like', "$q%")
                ->orWhere('razon_social', 'like', "%$q%")->orWhere('nombre_comercial', 'like', "%$q%")))
            ->when($request->filled('tipo_documento'), fn ($w) => $w->where('tipo_documento', $request->query('tipo_documento')))
            ->when($request->has('activo'), fn ($w) => $w->where('activo', $request->boolean('activo')))
            ->orderBy('razon_social')
            ->paginate(min(100, max(5, (int) $request->query('por_pagina', 15))));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => $this->dto($c)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json(['data' => $this->dto($cliente)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $cliente = Cliente::create($data);
        $this->auditor->registrar('cliente.creado', $cliente, [], $cliente->only(['tipo_documento', 'numero_documento', 'razon_social']));

        return response()->json(['data' => $this->dto($cliente), 'message' => 'Cliente registrado.'], 201);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $data = $this->validar($request, $cliente);
        $antes = $cliente->only(array_keys($data));
        $cliente->update($data);
        $this->auditor->registrar('cliente.actualizado', $cliente, $antes, $cliente->only(array_keys($data)));

        return response()->json(['data' => $this->dto($cliente), 'message' => 'Cliente actualizado.']);
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        // Eliminación lógica: los comprobantes guardan una copia de los datos del cliente.
        $cliente->delete();
        $this->auditor->registrar('cliente.eliminado', $cliente, $cliente->only(['tipo_documento', 'numero_documento', 'razon_social']));

        return response()->json(['message' => 'Cliente eliminado.']);
    }

    /**
     * Busca RUC/DNI: primero en los clientes de la empresa; si no existe, consulta la API
     * (GET /buscar-documento, que usa SUNAT/RENIEC). No guarda nada.
     */
    public function consultarDocumento(Request $request, SunatApiClientFactory $factory): JsonResponse
    {
        $data = $request->validate(['tipo' => ['required', Rule::in(['1', '6'])], 'numero' => ['required', 'string']]);
        if ($err = DocumentoIdentidad::error($data['tipo'], $data['numero'])) {
            throw ValidationException::withMessages(['numero' => $err]);
        }
        if ($local = Cliente::where('tipo_documento', $data['tipo'])->where('numero_documento', $data['numero'])->first()) {
            return response()->json(['data' => ['existe' => true, 'cliente' => $this->dto($local), 'fuente' => 'sistema']]);
        }

        $cacheKey = "doc:{$data['tipo']}:{$data['numero']}";
        try {
            $res = Cache::remember($cacheKey, 86400, function () use ($factory, $data) {
                $r = $factory->paraEmpresa($this->empresa->get())->get('/buscar-documento', ['tipo' => $data['tipo'], 'numero' => $data['numero']]);

                return $r->ok() && is_array($r->datos()) ? $r->datos() : ['__error' => $r->mensaje() ?? "HTTP {$r->status}"];
            });
        } catch (CredencialesNoConfiguradasException) {
            abort(409, 'Configura las credenciales de la API para consultar documentos.');
        } catch (SunatApiRateLimitException) {
            abort(429, 'La API está ocupada. Intenta en un minuto.');
        } catch (SunatApiConnectionException) {
            abort(503, 'No se pudo conectar con la API de facturación.');
        }
        if (isset($res['__error'])) {
            Cache::forget($cacheKey);
            abort(404, 'No se encontraron datos para ese documento: '.$res['__error']);
        }

        // Respuesta real (Etapa 3): {tipo_doc, num_doc, razon_social, direccion, estado, condicion, source|fuente}
        $estado = $res['estado'] ?? null;
        $condicion = $res['condicion'] ?? null;
        $advertencia = ($data['tipo'] === '6' && (($estado && $estado !== 'ACTIVO') || ($condicion && $condicion !== 'HABIDO')))
            ? "El contribuyente figura en SUNAT como $estado / $condicion."
            : null;

        return response()->json(['data' => ['existe' => false, 'fuente' => $res['source'] ?? $res['fuente'] ?? 'api',
            'estado' => $estado, 'condicion' => $condicion, 'advertencia' => $advertencia, 'cliente' => [
            'tipo_documento' => (string) ($res['tipo_doc'] ?? $data['tipo']),
            'numero_documento' => (string) ($res['num_doc'] ?? $data['numero']),
            'razon_social' => $res['razon_social'] ?? null,
            'direccion' => $res['direccion'] ?? null,
        ]]]);
    }

    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $empresaId = $this->empresa->id();
        $data = $request->validate([
            'tipo_documento' => [$cliente ? 'sometimes' : 'required', Rule::in(DocumentoIdentidad::TIPOS)],
            'numero_documento' => [$cliente ? 'sometimes' : 'required', 'string', 'max:15'],
            'razon_social' => [$cliente ? 'sometimes' : 'required', 'string', 'min:2', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'limite_credito' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'dias_credito' => ['nullable', 'integer', 'min:0', 'max:365'],
            'activo' => ['sometimes', 'boolean'],
        ], [], ['numero_documento' => 'número de documento', 'razon_social' => 'razón social / nombres']);

        $tipo = $data['tipo_documento'] ?? $cliente?->tipo_documento;
        $num = isset($data['numero_documento']) ? strtoupper(trim($data['numero_documento'])) : $cliente?->numero_documento;
        if (isset($data['numero_documento'])) {
            $data['numero_documento'] = $num;
        }
        if ($err = DocumentoIdentidad::error($tipo, $num)) {
            throw ValidationException::withMessages(['numero_documento' => $err]);
        }
        $dup = Cliente::withTrashed()->where('empresa_id', $empresaId)->where('tipo_documento', $tipo)->where('numero_documento', $num)
            ->when($cliente, fn ($q) => $q->whereKeyNot($cliente->id))->first();
        if ($dup) {
            throw ValidationException::withMessages(['numero_documento' => $dup->trashed()
                ? 'Existe un cliente eliminado con este documento. Restáuralo en lugar de crear uno nuevo.'
                : 'Ya existe un cliente con este documento.']);
        }

        return $data;
    }

    private function dto(Cliente $c): array
    {
        return $c->only(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'email', 'telefono', 'dias_credito', 'activo'])
            + ['limite_credito' => $c->limite_credito !== null ? (float) $c->limite_credito : null];
    }
}
