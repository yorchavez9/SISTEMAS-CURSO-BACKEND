<?php

namespace App\Modules\Ventas\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Modules\Facturacion\Http\Controllers\ComprobanteController;
use App\Modules\Ventas\Services\VentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VentaController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly VentaService $svc) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'tipo_documento' => ['required', 'in:01,03'],
            'serie_id' => ['required', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'cotizacion_id' => ['nullable', 'integer'],
            'moneda' => ['sometimes', 'in:PEN,USD'],
            'forma_pago' => ['sometimes', 'in:contado,credito'],
            'cuotas' => ['nullable', 'array', 'max:60'],
            'cuotas.*.monto' => ['required', 'numeric', 'gt:0'],
            'cuotas.*.fecha_vencimiento' => ['required', 'date_format:Y-m-d'],
            'descuento_global' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'pagos' => ['nullable', 'array', 'max:10'],
            'pagos.*.metodo' => ['required', 'string'],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['nullable', 'integer'],
            'items.*.codigo' => ['nullable', 'string', 'max:30'],
            'items.*.descripcion' => ['required_without:items.*.producto_id', 'nullable', 'string', 'max:255'],
            'items.*.unidad' => ['nullable', 'string', 'max:3'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.mto_valor_unitario' => ['nullable', 'numeric', 'gt:0'],
            'items.*.tip_afe_igv' => ['nullable', 'string', 'size:2'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
        ], [], ['serie_id' => 'serie', 'cliente_id' => 'cliente', 'items' => 'ítems']);

        $venta = $this->svc->registrar($this->empresa->get(), $request->user(), $data);
        $c = $venta->comprobante;

        return response()->json([
            'data' => ['venta_id' => $venta->id, 'comprobante' => ComprobanteController::resumen($c)],
            'message' => "Venta registrada. Comprobante {$c->numero()} en cola de envío a SUNAT.",
        ], $venta->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $p = Venta::query()->with(['comprobante', 'usuario:id,name'])
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->query('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->query('hasta')))
            ->orderByDesc('id')->paginate(min(100, max(5, (int) $request->query('por_pagina', 15))));

        return response()->json([
            'data' => $p->getCollection()->map(fn (Venta $v) => [
                'id' => $v->id, 'fecha' => $v->fecha->toDateString(), 'moneda' => $v->moneda, 'forma_pago' => $v->forma_pago,
                'total' => (float) $v->total, 'estado' => $v->estado, 'usuario' => $v->usuario?->name,
                'comprobante' => $v->comprobante ? ComprobanteController::resumen($v->comprobante) : null,
            ]),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }
}
