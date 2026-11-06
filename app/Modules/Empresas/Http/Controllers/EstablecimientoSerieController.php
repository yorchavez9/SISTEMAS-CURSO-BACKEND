<?php

namespace App\Modules\Empresas\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Establecimiento;
use App\Models\Serie;
use App\Modules\Empresas\Services\SincronizacionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Establecimientos y series de la empresa activa (header X-Empresa-Id). */
class EstablecimientoSerieController extends Controller
{
    public function __construct(
        private readonly EmpresaActual $empresa,
        private readonly SincronizacionApiService $sync,
        private readonly Auditor $auditor,
    ) {}

    public function index(): JsonResponse
    {
        $ests = Establecimiento::query()->with(['series' => fn ($q) => $q->orderBy('tipo_documento')->orderBy('serie')])
            ->orderByDesc('es_principal')->orderBy('cod_local')->get();
        $sinEst = Serie::query()->whereNull('establecimiento_id')->orderBy('serie')->get();

        return response()->json(['data' => [
            'establecimientos' => $ests->map(fn ($e) => $this->est($e)),
            'series_sin_establecimiento' => $sinEst->map(fn ($s) => $this->serie($s)),
        ]]);
    }

    public function sincronizar(): JsonResponse
    {
        $n = $this->conApi(fn () => $this->sync->importar($this->empresa->get()));

        return response()->json(['data' => $n, 'message' => "Sincronizado: {$n['establecimientos']} establecimientos y {$n['series']} series."]);
    }

    public function storeEstablecimiento(Request $request): JsonResponse
    {
        $empresaId = $this->empresa->id();
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'cod_local' => ['required', 'regex:/^\d{4}$/', Rule::unique('establecimientos')->where('empresa_id', $empresaId)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'es_principal' => ['boolean'],
        ], ['cod_local.regex' => 'El código de establecimiento SUNAT tiene 4 dígitos ("0000" = domicilio fiscal).', 'cod_local.unique' => 'Ya existe un establecimiento con ese código.']);

        $est = $this->conApi(fn () => $this->sync->crearEstablecimiento($this->empresa->get(), $data));

        return response()->json(['data' => $this->est($est->load('series')), 'message' => 'Establecimiento registrado en el sistema y en la API.'], 201);
    }

    public function storeSerie(Request $request): JsonResponse
    {
        $empresaId = $this->empresa->id();
        $data = $request->validate([
            'establecimiento_id' => ['required', Rule::exists('establecimientos', 'id')->where('empresa_id', $empresaId)],
            'tipo_documento' => ['required', Rule::in(array_keys(Serie::TIPOS_API))],
            'serie' => ['required', 'regex:/^[A-Z][A-Z0-9]{3}$/', Rule::unique('series')->where('empresa_id', $empresaId)],
            'correlativo_inicial' => ['nullable', 'integer', 'min:0', 'max:99999999'],
        ], ['serie.regex' => 'La serie tiene 4 caracteres en mayúsculas (ej. F001, B001, FC01).', 'serie.unique' => 'La serie ya existe en esta empresa.']);

        $prefijos = Serie::PREFIJOS[$data['tipo_documento']];
        if (! in_array($data['serie'][0], $prefijos, true)) {
            throw ValidationException::withMessages(['serie' => 'Para este tipo de comprobante la serie debe empezar con '.implode(' o ', $prefijos).'.']);
        }

        $est = Establecimiento::findOrFail($data['establecimiento_id']);
        $serie = $this->conApi(fn () => $this->sync->crearSerie($this->empresa->get(), $est, $data['tipo_documento'], $data['serie'], (int) ($data['correlativo_inicial'] ?? 0)));
        $this->auditor->registrar('serie.creada', $serie, [], ['serie' => $serie->serie, 'tipo_documento' => $serie->tipo_documento, 'establecimiento' => $est->cod_local], $empresaId);

        return response()->json(['data' => $this->serie($serie), 'message' => 'Serie registrada en el sistema y en la API.'], 201);
    }

    /**
     * activo: se refleja en la API (afecta a todos los sistemas de la cuenta).
     * predeterminada: preferencia LOCAL; una por tipo de documento (y por familia F/B en notas).
     */
    public function updateSerie(Request $request, Serie $serie): JsonResponse
    {
        $data = $request->validate(['activo' => ['sometimes', 'boolean'], 'predeterminada' => ['sometimes', 'boolean']]);
        abort_if($data === [], 422, 'Indica activo o predeterminada.');
        $antes = ['activo' => $serie->activo, 'predeterminada' => (bool) $serie->predeterminada];
        if (array_key_exists('activo', $data) && $data['activo'] !== $serie->activo) {
            $serie = $this->conApi(fn () => $this->sync->cambiarEstadoSerie($this->empresa->get(), $serie, $data['activo']));
        }
        if (array_key_exists('predeterminada', $data)) {
            if ($data['predeterminada'] && (! $serie->activo || ! $serie->api_serie_id)) {
                throw ValidationException::withMessages(['predeterminada' => 'Solo una serie activa y sincronizada puede ser la predeterminada.']);
            }
            DB::transaction(function () use ($serie, $data) {
                if ($data['predeterminada']) {
                    Serie::query()->where('tipo_documento', $serie->tipo_documento)->where('serie', 'like', $serie->serie[0].'%')
                        ->whereKeyNot($serie->id)->update(['predeterminada' => false]);
                }
                $serie->update(['predeterminada' => $data['predeterminada']]);
            });
        }
        $this->auditor->registrar('serie.actualizada', $serie, $antes, $data, $this->empresa->id());

        $msg = array_key_exists('predeterminada', $data)
            ? ($data['predeterminada'] ? "{$serie->serie} es ahora la serie predeterminada." : "{$serie->serie} ya no es la predeterminada.")
            : ($data['activo'] ? 'Serie activada.' : 'Serie desactivada.');

        return response()->json(['data' => $this->serie($serie->fresh()), 'message' => $msg]);
    }

    /** Traduce fallos de la API a respuestas comprensibles (422 negocio, 503 conexión, 429 límite). */
    private function conApi(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (SunatApiRateLimitException $e) {
            abort(429, 'La API de facturación está ocupada. Intenta en un minuto.');
        } catch (SunatApiConnectionException $e) {
            abort(503, 'No se pudo conectar con la API de facturación.');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }
    }

    private function est(Establecimiento $e): array
    {
        return [
            'id' => $e->id, 'nombre' => $e->nombre, 'cod_local' => $e->cod_local, 'direccion' => $e->direccion, 'ubigeo' => $e->ubigeo,
            'telefono' => $e->telefono, 'email' => $e->email, 'es_principal' => $e->es_principal, 'activo' => $e->activo,
            'sincronizado' => $e->api_sucursal_id !== null, 'sincronizado_at' => $e->sincronizado_at?->toIso8601String(),
            'series' => $e->relationLoaded('series') ? $e->series->map(fn ($s) => $this->serie($s)) : [],
        ];
    }

    private function serie(Serie $s): array
    {
        return [
            'id' => $s->id, 'establecimiento_id' => $s->establecimiento_id, 'tipo_documento' => $s->tipo_documento, 'serie' => $s->serie,
            'correlativo_actual' => $s->correlativo_actual, 'siguiente_numero' => $s->siguienteNumero(), 'activo' => $s->activo,
            'predeterminada' => (bool) $s->predeterminada,
            'sincronizado' => $s->api_serie_id !== null, 'sincronizado_at' => $s->sincronizado_at?->toIso8601String(),
        ];
    }
}
