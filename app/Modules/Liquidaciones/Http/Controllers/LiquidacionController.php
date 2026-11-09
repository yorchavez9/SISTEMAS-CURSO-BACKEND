<?php

namespace App\Modules\Liquidaciones\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Establecimiento;
use App\Models\LiquidacionCompra;
use App\Models\Serie;
use App\Modules\Clientes\DocumentoIdentidad;
use App\Modules\Facturacion\Services\ProcesoSunat;
use App\Modules\Liquidaciones\Services\LiquidacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Liquidaciones de compra (tipo 04). */
class LiquidacionController extends Controller
{
    public function __construct(
        private readonly EmpresaActual $empresa,
        private readonly LiquidacionService $svc,
        private readonly ProcesoSunat $proceso,
    ) {}

    public static function dto(LiquidacionCompra $c, bool $detalle = false): array
    {
        $base = [
            'id' => $c->id, 'numero' => $c->numero(), 'fecha_emision' => $c->fecha_emision->toDateString(),
            'vendedor' => ['tipo_doc' => $c->vendedor_tipo_doc, 'num_doc' => $c->vendedor_num_doc, 'nombre' => $c->vendedor_nombre],
            'valor_venta' => (float) $c->valor_venta, 'igv' => (float) $c->igv, 'renta' => (float) $c->renta, 'neto_a_pagar' => (float) $c->neto_a_pagar,
            'monto_pagado' => (float) $c->monto_pagado, 'estado_pago' => $c->estado_pago, 'estado' => $c->estado,
            'sunat_codigo' => $c->sunat_codigo, 'sunat_descripcion' => $c->sunat_descripcion, 'ultimo_error' => $c->ultimo_error,
            'reversion' => $c->reversion_estado ? ['estado' => $c->reversion_estado, 'causal' => $c->reversion_causal, 'motivo' => $c->reversion_motivo, 'identifier' => $c->reversion_identifier, 'error' => $c->reversion_error] : null,
            'tiene_archivos' => $c->api_id !== null,
        ];
        if (! $detalle) {
            return $base;
        }

        return $base + [
            'productos_agropecuarios' => $c->productos_agropecuarios, 'porcentaje_renta' => (float) $c->porcentaje_renta,
            'vendedor_direccion' => $c->vendedor_direccion, 'vendedor_ubigeo' => $c->vendedor_ubigeo, 'lugar_direccion' => $c->lugar_direccion, 'lugar_ubigeo' => $c->lugar_ubigeo,
            'items' => $c->items, 'observacion' => $c->observacion, 'sunat_notas' => $c->sunat_notas ?? [], 'historial' => $c->historial ?? [], 'usuario' => $c->usuario?->name,
            'revertible' => $c->estado === 'aceptado' && ! in_array($c->reversion_estado, ['enviado', 'aceptado'], true),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(['por_enviar', 'enviado', 'aceptado', 'rechazado', 'error', 'revertido'])]]);
        $this->proceso->actualizarPendientes($this->empresa->id());
        $p = LiquidacionCompra::query()
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['q'] ?? null, function ($q, $t) {
                $q->where(function ($w) use ($t) {
                    if (preg_match('/^([A-Z0-9]{4})-0*(\d+)$/i', $t, $m)) {
                        $w->where(fn ($x) => $x->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]));
                    }
                    $w->orWhere('vendedor_num_doc', 'like', "$t%")->orWhere('vendedor_nombre', 'like', "%$t%");
                });
            })
            ->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => self::dto($c)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function opciones(): JsonResponse
    {
        $series = Serie::query()->where('tipo_documento', '04')->where('activo', true)->whereNotNull('api_serie_id')
            ->orderByDesc('predeterminada')->orderBy('serie')->get();
        $ests = Establecimiento::query()->whereIn('id', $series->pluck('establecimiento_id')->filter())->get()->keyBy('id');

        return response()->json(['data' => [
            'series' => $series->map(fn ($s) => ['id' => $s->id, 'serie' => $s->serie, 'siguiente' => $s->siguienteNumero(), 'predeterminada' => $s->predeterminada,
                'direccion' => $ests[$s->establecimiento_id]->direccion ?? null, 'ubigeo' => $ests[$s->establecimiento_id]->ubigeo ?? null]),
            'tasa_igv' => (float) ($this->empresa->get()->credencialApi()?->remoto['tasa_igv_vigente'] ?? 18),
            'causales' => collect(LiquidacionCompra::CAUSALES)->map(fn ($l, $k) => ['codigo' => $k, 'descripcion' => $l])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'serie_id' => ['required', 'integer'],
            'productos_agropecuarios' => ['sometimes', 'boolean'],
            'vendedor' => ['required', 'array'],
            'vendedor.tipo_doc' => ['required', Rule::in(['1', '4'])],
            'vendedor.num_doc' => ['required', 'string', 'max:15'],
            'vendedor.nombre' => ['required', 'string', 'max:500'],
            'vendedor.direccion' => ['required', 'string', 'max:500'],
            'vendedor.ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'lugar' => ['required', 'array'],
            'lugar.direccion' => ['required', 'string', 'max:500'],
            'lugar.ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.descripcion' => ['required', 'string', 'max:500'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.valor_unitario' => ['required', 'numeric', 'gt:0'],
            'items.*.unidad' => ['required', 'string', 'max:3'],
            'items.*.cod_producto_sunat' => ['required', 'regex:/^\d{8}$/'],
            'items.*.tip_afe_igv' => ['required', Rule::in(['10', '20', '30'])],
        ], ['items.*.cod_producto_sunat.regex' => 'El código SUNAT tiene 8 dígitos.'], [
            'items.*.cod_producto_sunat' => 'código SUNAT', 'vendedor.num_doc' => 'documento del vendedor', 'vendedor.nombre' => 'nombre del vendedor', 'vendedor.direccion' => 'dirección del vendedor', 'vendedor.ubigeo' => 'ubigeo del vendedor',
            'lugar.direccion' => 'lugar de la operación', 'lugar.ubigeo' => 'ubigeo del lugar', 'items.*.descripcion' => 'descripción', 'items.*.cantidad' => 'cantidad', 'items.*.valor_unitario' => 'valor unitario',
        ]);
        if ($d['vendedor']['tipo_doc'] === '1' && ($err = DocumentoIdentidad::error('1', $d['vendedor']['num_doc']))) {
            throw ValidationException::withMessages(['vendedor.num_doc' => $err]);
        }
        $c = $this->svc->crear($this->empresa->get(), $request->user(), $d)->fresh();
        $msg = match ($c->estado) {
            'aceptado' => "Liquidación {$c->numero()} aceptada por SUNAT.",
            'enviado' => "Liquidación {$c->numero()} enviada a SUNAT; esperando su respuesta.",
            'rechazado' => "Liquidación {$c->numero()} rechazada por SUNAT: {$c->sunat_codigo} {$c->sunat_descripcion}",
            'error' => "Liquidación {$c->numero()} no se pudo enviar: {$c->ultimo_error}",
            default => $c->ultimo_error ? "Liquidación {$c->numero()} registrada; aún no se pudo enviar (se reintentará)." : "Liquidación {$c->numero()} registrada.",
        };

        return response()->json(['data' => self::dto($c, true), 'message' => $msg], 201);
    }

    public function show(LiquidacionCompra $liquidacion): JsonResponse
    {
        $this->proceso->actualizarLiquidacion($liquidacion);

        return response()->json(['data' => self::dto($liquidacion->refresh()->load('usuario:id,name'), true)]);
    }

    public function reintentar(Request $request, LiquidacionCompra $liquidacion): JsonResponse
    {
        $codigos = $request->validate([
            'codigos' => ['nullable', 'array'],
            'codigos.*' => ['required', 'regex:/^\d{8}$/'],
        ], ['codigos.*.regex' => 'El código SUNAT tiene 8 dígitos.'])['codigos'] ?? [];
        if ($liquidacion->esFinal() && $liquidacion->reversion_estado !== 'enviado') {
            throw ValidationException::withMessages(['estado' => "La liquidación ya está {$liquidacion->estado}."]);
        }
        if ($liquidacion->estado === 'error' && ! $liquidacion->api_id) {
            // Un envío rechazado por validación no llegó a la API: aquí se completan los códigos que le faltaron.
            $items = collect($liquidacion->items)->map(fn ($it, $i) => empty($it['cod_producto_sunat']) && isset($codigos[$i]) ? [...$it, 'cod_producto_sunat' => $codigos[$i]] : $it)->all();
            if (collect($items)->contains(fn ($it) => empty($it['cod_producto_sunat']))) {
                throw ValidationException::withMessages(['codigos' => 'Ingresa el código SUNAT de cada bien para reenviar.']);
            }
            $liquidacion->cambiarEstado('por_enviar', 'Reintento manual solicitado', ['intentos' => 0, 'ultimo_error' => null, 'items' => $items, 'payload' => [...$liquidacion->payload, 'items' => $items]]);
            $this->proceso->emitirLiquidacion($liquidacion);
        } else {
            $this->proceso->actualizarLiquidacion($liquidacion, forzar: true);
        }

        return response()->json(['data' => self::dto($liquidacion->refresh(), true), 'message' => 'Reintento realizado.']);
    }

    public function revertir(Request $request, LiquidacionCompra $liquidacion): JsonResponse
    {
        $d = $request->validate(['causal' => ['required', Rule::in(array_keys(LiquidacionCompra::CAUSALES))], 'motivo' => ['required', 'string', 'min:5', 'max:255']]);
        $this->svc->revertir($liquidacion, $d['causal'], $d['motivo']);
        $liquidacion->refresh();
        if ($liquidacion->reversion_estado === 'error') {
            throw ValidationException::withMessages(['motivo' => $liquidacion->reversion_error]);
        }

        return response()->json(['data' => self::dto($liquidacion, true), 'message' => "Reversión de {$liquidacion->numero()} enviada a SUNAT."]);
    }

    public function pagos(LiquidacionCompra $liquidacion): JsonResponse
    {
        abort_unless($liquidacion->api_id, 409, 'La liquidación aún no está registrada en la API.');

        return response()->json(['data' => $this->svc->pagos($liquidacion)]);
    }

    public function registrarPago(Request $request, LiquidacionCompra $liquidacion): JsonResponse
    {
        abort_unless($liquidacion->api_id, 409, 'La liquidación aún no está registrada en la API.');
        $d = $request->validate(['metodo' => ['required', Rule::in(['efectivo', 'transferencia', 'yape', 'plin', 'tarjeta', 'cheque', 'otro'])], 'monto' => ['required', 'numeric', 'gt:0'], 'referencia' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->svc->registrarPago($liquidacion, array_filter($d, fn ($v) => $v !== null)), 'message' => 'Pago registrado.'], 201);
    }

    public function eliminarPago(LiquidacionCompra $liquidacion, int $pago): JsonResponse
    {
        return response()->json(['data' => $this->svc->eliminarPago($liquidacion, $pago), 'message' => 'Pago eliminado.']);
    }

    public function reporte(Request $request): JsonResponse
    {
        $periodo = $request->validate(['periodo' => ['required', 'date_format:Y-m']])['periodo'];

        return response()->json(['data' => $this->svc->reporte($this->empresa->get(), $periodo)]);
    }

    public function archivo(Request $request, LiquidacionCompra $liquidacion, string $tipo): Response
    {
        $formato = $request->validate(['formato' => ['nullable', Rule::in(['a4', 'a5', 'ticket-80', 'ticket-58'])]])['formato'] ?? 'a4';
        $a = $this->svc->archivo($liquidacion, $tipo, $formato);
        abort_unless($a, 404, $tipo === 'cdr' && ! $liquidacion->esFinal() ? 'El CDR estará disponible cuando SUNAT responda.' : 'El archivo no está disponible en la API.');

        return response($a['contenido'], 200, ['Content-Type' => $a['mime'], 'Content-Disposition' => 'attachment; filename="'.$a['nombre'].'"']);
    }
}
