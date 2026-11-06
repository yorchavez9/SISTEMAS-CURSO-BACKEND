<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\CajaMovimiento;
use App\Models\CajaSesion;
use App\Models\Pago;
use App\Models\Venta;
use App\Models\VentaCuota;
use App\Modules\Caja\Services\CajaService;
use App\Modules\Caja\Services\PagoService;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CajaController extends Controller
{
    public function __construct(
        private readonly EmpresaActual $empresa,
        private readonly CajaService $caja,
        private readonly PagoService $pagos,
    ) {}

    public function estado(Request $request): JsonResponse
    {
        $e = $this->empresa->get();
        $s = $this->caja->sesionDe($e, $request->user());

        return response()->json(['data' => [
            'metodos' => collect(Pago::METODOS)->map(fn ($l, $k) => ['metodo' => $k, 'label' => $l])->values(),
            'cajas' => $this->caja->cajas($e)->map(fn ($c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'establecimiento_id' => $c->establecimiento_id,
                // Con el código SUNAT: varios establecimientos pueden tener el mismo nombre.
                'establecimiento' => $c->establecimiento ? "{$c->establecimiento->nombre} ({$c->establecimiento->cod_local})" : null,
                'abierta_por' => $c->sesionAbierta?->usuario?->name,
            ]),
            'sesion' => $s ? $this->sesionDto($s) : null,
        ]]);
    }

    public function abrir(Request $request): JsonResponse
    {
        $d = $request->validate(['caja_id' => ['required', 'integer'], 'monto_apertura' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        $s = $this->caja->abrir($this->empresa->get(), $request->user(), $d['caja_id'], (string) $d['monto_apertura']);

        return response()->json(['data' => $this->sesionDto($s), 'message' => 'Caja abierta.'], 201);
    }

    public function movimiento(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', 'in:ingreso,egreso'],
            'concepto' => ['required', Rule::in(['ingreso_manual', 'gasto', 'retiro'])],
            'metodo' => ['required', Rule::in(array_keys(Pago::METODOS))],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'descripcion' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['descripcion' => 'descripción']);
        $s = $this->sesionPropia($request);
        $this->caja->movimiento($s, $request->user(), $d);

        return response()->json(['data' => $this->sesionDto($s->fresh()), 'message' => 'Movimiento registrado.'], 201);
    }

    public function cerrar(Request $request): JsonResponse
    {
        $d = $request->validate(['efectivo_contado' => ['required', 'numeric', 'min:0'], 'observacion' => ['nullable', 'string', 'max:500']]);
        $s = $this->caja->cerrar($this->sesionPropia($request), $request->user(), (string) $d['efectivo_contado'], $d['observacion'] ?? null);

        return response()->json(['data' => $this->sesionDto($s), 'message' => 'Caja cerrada.']);
    }

    public function sesiones(Request $request): JsonResponse
    {
        $p = CajaSesion::query()->with(['caja:id,nombre', 'usuario:id,name'])->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($s) => [
                'id' => $s->id, 'caja' => $s->caja?->nombre, 'usuario' => $s->usuario?->name, 'estado' => $s->estado,
                'apertura_at' => $s->apertura_at?->toIso8601String(), 'cierre_at' => $s->cierre_at?->toIso8601String(),
                'monto_apertura' => (float) $s->monto_apertura, 'efectivo_esperado' => $s->efectivo_esperado !== null ? (float) $s->efectivo_esperado : null,
                'efectivo_contado' => $s->efectivo_contado !== null ? (float) $s->efectivo_contado : null, 'diferencia' => $s->diferencia !== null ? (float) $s->diferencia : null,
            ]),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function sesion(CajaSesion $sesion): JsonResponse
    {
        return response()->json(['data' => $this->sesionDto($sesion)]);
    }

    // ---- Cobranzas ----

    public function pagosVenta(Venta $venta): JsonResponse
    {
        return response()->json(['data' => $this->ventaDto($venta->load(['pagos.usuario:id,name', 'cuotas', 'comprobante']))]);
    }

    public function cobrar(Request $request, Venta $venta): JsonResponse
    {
        $d = $request->validate([
            'pagos' => ['required', 'array', 'min:1', 'max:10'],
            'pagos.*.metodo' => ['required', Rule::in(array_keys(Pago::METODOS))],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:100'],
        ]);
        $this->pagos->registrar($venta, $this->sesionPropia($request), $request->user(), $d['pagos'], 'cobranza');

        return response()->json(['data' => $this->ventaDto($venta->fresh(['pagos.usuario:id,name', 'cuotas', 'comprobante'])), 'message' => 'Cobro registrado.'], 201);
    }

    public function anularPago(Request $request, Pago $pago): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']]);
        $this->pagos->anular($pago, $request->user(), $d['motivo']);

        return response()->json(['data' => $this->ventaDto(Venta::with(['pagos.usuario:id,name', 'cuotas', 'comprobante'])->findOrFail($pago->venta_id)), 'message' => 'Pago anulado.']);
    }

    /** Cuentas por cobrar con antigüedad de deuda (por cuota vencida más antigua). */
    public function cuentasPorCobrar(Request $request): JsonResponse
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        $q = trim((string) $request->query('q', ''));
        $ventas = Venta::query()->with(['cuotas', 'comprobante', 'cliente:id,razon_social,numero_documento'])
            ->where('estado', 'registrada')->where('saldo', '>', 0)
            ->when($q !== '', fn ($w) => $w->whereHas('comprobante', fn ($c) => $c->where('cliente_razon_social', 'like', "%$q%")->orWhere('cliente_num_doc', 'like', "$q%")))
            ->orderBy('fecha')->get();

        $tramos = ['al_dia' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'mas_90' => '0'];
        $filas = $ventas->map(function (Venta $v) use ($hoy, &$tramos) {
            $vencida = $v->cuotas->first(fn (VentaCuota $c) => Dec::cmp($c->monto_pagado, $c->monto) < 0 && $c->fecha_vencimiento->toDateString() < $hoy);
            $ref = $vencida?->fecha_vencimiento ?? ($v->forma_pago === 'contado' ? $v->fecha : null);
            $dias = $ref && $ref->toDateString() < $hoy ? (int) $ref->diffInDays(Carbon::parse($hoy)) : 0;
            $tramo = match (true) { $dias === 0 => 'al_dia', $dias <= 30 => '1_30', $dias <= 60 => '31_60', $dias <= 90 => '61_90', default => 'mas_90' };
            $tramos[$tramo] = Dec::add($tramos[$tramo], $v->saldo);
            $proxima = $v->cuotas->first(fn ($c) => Dec::cmp($c->monto_pagado, $c->monto) < 0);

            return [
                'venta_id' => $v->id, 'fecha' => $v->fecha->toDateString(), 'comprobante' => $v->comprobante?->numero(),
                'cliente' => $v->comprobante?->cliente_razon_social, 'cliente_doc' => $v->comprobante?->cliente_num_doc,
                'forma_pago' => $v->forma_pago, 'total' => (float) $v->total, 'pagado' => (float) $v->monto_pagado, 'saldo' => (float) $v->saldo,
                'proximo_vencimiento' => $proxima?->fecha_vencimiento?->toDateString(), 'dias_vencido' => $dias, 'tramo' => $tramo,
            ];
        });

        // Saldos a favor del cliente: ventas dadas de baja o con notas de crédito después de haber sido pagadas.
        $aFavor = Venta::query()->with(['comprobante'])->where('saldo', '<', 0)->orderBy('fecha')->get()->map(fn (Venta $v) => [
            'venta_id' => $v->id, 'fecha' => $v->fecha->toDateString(), 'comprobante' => $v->comprobante?->numero(), 'comprobante_estado' => $v->comprobante?->estado,
            'cliente' => $v->comprobante?->cliente_razon_social, 'cliente_doc' => $v->comprobante?->cliente_num_doc,
            'pagado' => (float) $v->monto_pagado, 'a_favor' => (float) Dec::round(Dec::sub('0', $v->saldo)),
        ]);

        return response()->json(['data' => [
            'saldos_a_favor' => $aFavor->values(),
            'filas' => $filas->values(),
            'total' => (float) Dec::round(collect($tramos)->reduce(fn ($s, $x) => Dec::add($s, $x), '0')),
            'tramos' => array_map(fn ($x) => (float) Dec::round($x), $tramos),
        ]]);
    }

    /** Devuelve al cliente un saldo a favor (egreso de la caja abierta). */
    public function reembolsar(Request $request, Venta $venta): JsonResponse
    {
        $d = $request->validate([
            'metodo' => ['required', Rule::in(array_keys(Pago::METODOS))],
            'monto' => ['required', 'numeric', 'gt:0'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);
        $aFavor = Dec::sub('0', $venta->saldo);
        if (Dec::cmp($d['monto'], $aFavor) > 0) {
            throw ValidationException::withMessages(['monto' => 'El reembolso supera el saldo a favor ('.Dec::round($aFavor).').']);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $venta, $d) {
            $v = Venta::withoutGlobalScopes()->lockForUpdate()->findOrFail($venta->id);
            $this->pagos->reembolsar($v, $this->sesionPropia($request), $request->user(), (string) $d['monto'], $d['metodo'], null, $d['referencia'] ?? null);
            $this->pagos->recalcular($v);
        });

        return response()->json(['data' => $this->ventaDto($venta->fresh(['pagos.usuario:id,name', 'cuotas', 'comprobante'])), 'message' => 'Reembolso registrado.'], 201);
    }

    private function sesionPropia(Request $request): CajaSesion
    {
        return $this->caja->sesionDe($this->empresa->get(), $request->user())
            ?? throw ValidationException::withMessages(['caja' => 'No tienes una caja abierta.']);
    }

    private function sesionDto(CajaSesion $s): array
    {
        $s->loadMissing(['caja.establecimiento', 'usuario:id,name']);
        $movs = CajaMovimiento::query()->where('caja_sesion_id', $s->id)->orderByDesc('id')->limit(200)->get();

        return [
            'id' => $s->id, 'estado' => $s->estado, 'caja' => ['id' => $s->caja->id, 'nombre' => $s->caja->nombre, 'establecimiento' => $s->caja->establecimiento ? "{$s->caja->establecimiento->nombre} ({$s->caja->establecimiento->cod_local})" : null, 'establecimiento_id' => $s->caja->establecimiento_id],
            'usuario' => $s->usuario?->name, 'apertura_at' => $s->apertura_at?->toIso8601String(), 'cierre_at' => $s->cierre_at?->toIso8601String(),
            'monto_apertura' => (float) $s->monto_apertura,
            'resumen' => $s->estado === 'cerrada' && $s->resumen_cierre ? $s->resumen_cierre : $this->caja->resumen($s),
            'efectivo_contado' => $s->efectivo_contado !== null ? (float) $s->efectivo_contado : null,
            'diferencia' => $s->diferencia !== null ? (float) $s->diferencia : null,
            'movimientos' => $movs->map(fn ($m) => ['id' => $m->id, 'tipo' => $m->tipo, 'concepto' => $m->concepto, 'metodo' => $m->metodo, 'monto' => (float) $m->monto, 'descripcion' => $m->descripcion, 'fecha' => $m->created_at?->toIso8601String()]),
        ];
    }

    private function ventaDto(Venta $v): array
    {
        return [
            'venta_id' => $v->id, 'comprobante' => $v->comprobante?->numero(), 'cliente' => $v->comprobante?->cliente_razon_social, 'forma_pago' => $v->forma_pago,
            'total' => (float) $v->total, 'pagado' => (float) $v->monto_pagado, 'saldo' => (float) $v->saldo, 'estado_pago' => $v->estado_pago,
            'cuotas' => $v->cuotas->map(fn ($c) => ['numero' => $c->numero, 'monto' => (float) $c->monto, 'pagado' => (float) $c->monto_pagado, 'fecha_vencimiento' => $c->fecha_vencimiento->toDateString()]),
            'pagos' => $v->pagos->map(fn (Pago $p) => [
                'id' => $p->id, 'tipo' => $p->tipo, 'metodo' => $p->metodo, 'monto' => (float) $p->monto, 'monto_recibido' => $p->monto_recibido !== null ? (float) $p->monto_recibido : null,
                'vuelto' => (float) $p->vuelto, 'referencia' => $p->referencia, 'estado' => $p->estado, 'usuario' => $p->usuario?->name,
                'fecha' => $p->created_at?->toIso8601String(), 'motivo_anulacion' => $p->motivo_anulacion,
            ]),
        ];
    }
}
