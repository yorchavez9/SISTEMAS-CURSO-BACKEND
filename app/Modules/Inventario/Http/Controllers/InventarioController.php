<?php

namespace App\Modules\Inventario\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Modules\Inventario\Services\InventarioService;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventarioController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly InventarioService $svc) {}

    public function almacenes(): JsonResponse
    {
        return response()->json(['data' => $this->svc->almacenes($this->empresa->get())->map(fn (Almacen $a) => [
            'id' => $a->id, 'nombre' => $a->nombre, 'es_principal' => $a->es_principal, 'establecimiento_id' => $a->establecimiento_id,
            'establecimiento' => $a->establecimiento ? "{$a->establecimiento->nombre} ({$a->establecimiento->cod_local})" : null,
        ])]);
    }

    public function storeAlmacen(Request $request): JsonResponse
    {
        $empresaId = $this->empresa->id();
        $d = $request->validate([
            'establecimiento_id' => ['required', Rule::exists('establecimientos', 'id')->where('empresa_id', $empresaId)],
            'nombre' => ['required', 'string', 'max:80', Rule::unique('almacenes')->where('establecimiento_id', $request->input('establecimiento_id'))],
        ], ['nombre.unique' => 'Ya existe un almacén con ese nombre en el establecimiento.']);
        $a = Almacen::create($d + ['empresa_id' => $empresaId]);

        return response()->json(['data' => $a, 'message' => 'Almacén creado.'], 201);
    }

    /** Existencias por almacén: todos los bienes con control de stock (incluye los que aún están en cero). */
    public function stock(Request $request): JsonResponse
    {
        $empresaId = $this->empresa->id();
        $almacenId = Almacen::findOrFail((int) $request->query('almacen_id'))->id; // scope de empresa: 404 si es ajeno
        $q = trim((string) $request->query('q', ''));
        $p = Producto::query()->where('tipo', 'bien')->where('controla_stock', true)->where('activo', true)
            ->leftJoin('stocks', fn ($j) => $j->on('stocks.producto_id', '=', 'productos.id')->where('stocks.almacen_id', $almacenId))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('productos.codigo', 'like', "$q%")->orWhere('productos.descripcion', 'like', "%$q%")))
            ->when($request->boolean('bajo_minimo'), fn ($w) => $w->whereRaw('COALESCE(stocks.cantidad, 0) <= productos.stock_minimo'))
            ->orderBy('productos.descripcion')
            ->select(['productos.id', 'productos.codigo', 'productos.descripcion', 'productos.unidad', 'productos.stock_minimo', 'productos.costo',
                DB::raw('COALESCE(stocks.cantidad, 0) as cantidad'), DB::raw('COALESCE(stocks.costo_promedio, 0) as costo_promedio')])
            ->paginate(min(100, max(5, (int) $request->query('por_pagina', 20))));

        $valor = DB::table('stocks')->where('empresa_id', $empresaId)->where('almacen_id', $almacenId)->sum(DB::raw('cantidad * costo_promedio'));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($r) => [
                'producto_id' => $r->id, 'codigo' => $r->codigo, 'descripcion' => $r->descripcion, 'unidad' => $r->unidad,
                'cantidad' => (float) $r->cantidad, 'stock_minimo' => (float) $r->stock_minimo, 'costo_promedio' => (float) $r->costo_promedio,
                'valor' => (float) Dec::round(Dec::mul($r->cantidad, $r->costo_promedio)), 'bajo_minimo' => (float) $r->cantidad <= (float) $r->stock_minimo,
            ]),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage(), 'valor_total' => (float) Dec::round($valor)],
        ]);
    }

    public function movimiento(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', 'in:entrada,salida,ajuste'],
            'almacen_id' => ['required', 'integer'],
            'documento' => ['nullable', 'string', 'max:60'],
            'observacion' => [Rule::requiredIf(fn () => $request->input('tipo') !== 'entrada'), 'nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => [Rule::requiredIf(fn () => $request->input('tipo') !== 'ajuste'), 'nullable', 'numeric', 'gt:0'],
            'items.*.cantidad_fisica' => [Rule::requiredIf(fn () => $request->input('tipo') === 'ajuste'), 'nullable', 'numeric', 'min:0'],
            'items.*.costo_unitario' => [Rule::requiredIf(fn () => $request->input('tipo') === 'entrada'), 'nullable', 'numeric', 'min:0'],
        ], [], ['observacion' => 'motivo', 'items.*.costo_unitario' => 'costo unitario']);
        $almacen = Almacen::findOrFail($d['almacen_id']);
        $movs = $this->svc->registrar($this->empresa->get(), $request->user(), $almacen, $d['tipo'], $d['items'], $d['documento'] ?? null, $d['observacion'] ?? null);

        return response()->json(['data' => ['movimientos' => count($movs)], 'message' => count($movs) ? 'Movimiento registrado.' : 'Sin diferencias: el stock ya coincide con el conteo.'], 201);
    }

    public function transferencia(Request $request): JsonResponse
    {
        $d = $request->validate([
            'origen_id' => ['required', 'integer'], 'destino_id' => ['required', 'integer'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ]);
        $this->svc->transferir($this->empresa->get(), $request->user(), Almacen::findOrFail($d['origen_id']), Almacen::findOrFail($d['destino_id']), $d['items'], $d['observacion'] ?? null);

        return response()->json(['message' => 'Transferencia registrada.'], 201);
    }

    public function kardex(Request $request, Producto $producto): JsonResponse
    {
        $movs = $this->svc->kardex($producto->id, $request->integer('almacen_id') ?: null, $request->query('desde'), $request->query('hasta'));

        return response()->json(['data' => [
            'producto' => ['id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'unidad' => $producto->unidad],
            'movimientos' => $movs->map(fn (MovimientoInventario $m) => [
                'id' => $m->id, 'fecha' => $m->created_at?->toIso8601String(), 'almacen' => $m->almacen?->nombre, 'tipo' => $m->tipo, 'sentido' => $m->sentido,
                'cantidad' => (float) $m->cantidad, 'costo_unitario' => (float) $m->costo_unitario, 'valor' => (float) $m->valor,
                'saldo_cantidad' => (float) $m->saldo_cantidad, 'saldo_costo_promedio' => (float) $m->saldo_costo_promedio,
                'documento' => $m->documento, 'observacion' => $m->observacion, 'usuario' => $m->usuario?->name,
            ]),
        ]]);
    }
}
