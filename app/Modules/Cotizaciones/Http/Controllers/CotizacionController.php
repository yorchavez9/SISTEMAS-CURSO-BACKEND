<?php

namespace App\Modules\Cotizaciones\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Modules\Cotizaciones\Services\CotizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CotizacionController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly CotizacionService $svc) {}

    public static function dto(Cotizacion $c, bool $detalle = false): array
    {
        $out = [
            'id' => $c->id, 'codigo' => $c->codigo(), 'fecha_emision' => $c->fecha_emision->toDateString(), 'fecha_validez' => $c->fecha_validez->toDateString(),
            'cliente' => ['id' => $c->cliente_id, 'tipo_doc' => $c->cliente_tipo_doc, 'num_doc' => $c->cliente_num_doc, 'razon_social' => $c->cliente_razon_social, 'email' => $c->cliente_email],
            'moneda' => $c->moneda, 'total' => (float) $c->total,
            // "vencida" es un estado calculado a partir de la fecha de validez.
            'estado' => $c->vencida() ? 'vencida' : $c->estado, 'convertible' => $c->convertible(),
            'venta' => $c->venta_id ? ['id' => $c->venta_id, 'comprobante_id' => $c->venta?->comprobante?->id, 'numero' => $c->venta?->comprobante?->numero()] : null,
        ];
        if ($detalle) {
            $out += [
                'tasa_igv' => (float) $c->tasa_igv, 'observacion' => $c->observacion, 'usuario' => $c->usuario?->name,
                'totales' => collect(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'igv', 'isc', 'icbper', 'descuentos', 'total'])->mapWithKeys(fn ($k) => [$k => (float) $c->$k]),
                'items' => $c->items->map(fn ($i) => [
                    'producto_id' => $i->producto_id, 'codigo' => $i->codigo, 'descripcion' => $i->descripcion, 'unidad' => $i->unidad,
                    'cantidad' => (float) $i->cantidad, 'precio_unitario' => (float) $i->precio_unitario, 'tip_afe_igv' => $i->tip_afe_igv,
                    'descuento' => (float) $i->descuento, 'base' => (float) $i->base, 'igv' => (float) $i->igv, 'total' => (float) $i->total,
                ]),
            ];
        }

        return $out;
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(['borrador', 'enviada', 'aceptada', 'rechazada', 'convertida', 'vencida'])]]);
        $hoy = Carbon::now('America/Lima')->toDateString();
        $p = Cotizacion::query()->with('venta.comprobante')
            ->when($f['estado'] ?? null, function ($q, $e) use ($hoy) {
                if ($e === 'vencida') {
                    $q->whereIn('estado', ['borrador', 'enviada'])->whereDate('fecha_validez', '<', $hoy);
                } elseif (in_array($e, ['borrador', 'enviada'], true)) {
                    $q->where('estado', $e)->whereDate('fecha_validez', '>=', $hoy);
                } else {
                    $q->where('estado', $e);
                }
            })
            ->when($f['q'] ?? null, function ($q, $t) {
                $q->where(function ($w) use ($t) {
                    if (preg_match('/^(?:COT-)?0*(\d+)$/i', $t, $m)) {
                        $w->orWhere('numero', (int) $m[1]);
                    }
                    $w->orWhere('cliente_num_doc', 'like', "$t%")->orWhere('cliente_razon_social', 'like', "%$t%");
                });
            })
            ->orderByDesc('numero')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => self::dto($c)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function show(Cotizacion $cotizacion): JsonResponse
    {
        return response()->json(['data' => self::dto($cotizacion->load(['items', 'venta.comprobante', 'usuario:id,name']), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'cliente_nombre' => ['nullable', 'string', 'max:250'],
            'cliente_email' => ['nullable', 'email', 'max:150'],
            'fecha_validez' => ['required', 'date_format:Y-m-d'],
            'moneda' => ['nullable', Rule::in(['PEN', 'USD'])],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['nullable', 'integer'],
            'items.*.descripcion' => ['required_without:items.*.producto_id', 'nullable', 'string', 'max:255'],
            'items.*.unidad' => ['nullable', 'string', 'max:3'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.tip_afe_igv' => ['nullable', 'string', 'size:2'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
        ], [], ['fecha_validez' => 'validez', 'items' => 'ítems']);
        $c = $this->svc->crear($this->empresa->get(), $request->user(), $d);

        return response()->json(['data' => self::dto($c->load(['items', 'usuario:id,name']), true), 'message' => "Cotización {$c->codigo()} registrada."], 201);
    }

    public function estado(Request $request, Cotizacion $cotizacion): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['enviada', 'aceptada', 'rechazada'])]]);
        $c = $this->svc->cambiarEstado($cotizacion, $d['estado']);

        return response()->json(['data' => self::dto($c->load(['items', 'venta.comprobante', 'usuario:id,name']), true), 'message' => "Cotización {$c->codigo()}: {$d['estado']}."]);
    }
}
