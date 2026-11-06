<?php

namespace App\Modules\Inventario\Services;

use App\Core\Auditoria\Auditor;
use App\Models\Almacen;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\User;
use App\Models\Venta;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inventario valorizado por costo promedio ponderado. Toda operación ocurre dentro de una transacción
 * y bloquea la fila de stock (orden por producto para evitar interbloqueos entre operaciones concurrentes).
 */
class InventarioService
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Almacenes activos; crea "Almacén principal" en los establecimientos que no tengan ninguno. */
    public function almacenes(Empresa $empresa)
    {
        foreach (Establecimiento::deEmpresa($empresa->id)->where('activo', true)->get() as $est) {
            if (! Almacen::deEmpresa($empresa->id)->where('establecimiento_id', $est->id)->exists()) {
                Almacen::create(['empresa_id' => $empresa->id, 'establecimiento_id' => $est->id, 'nombre' => 'Almacén principal', 'es_principal' => true]);
            }
        }

        return Almacen::deEmpresa($empresa->id)->with('establecimiento:id,nombre,cod_local')->where('activo', true)->orderBy('establecimiento_id')->orderByDesc('es_principal')->get();
    }

    public function almacenPrincipal(int $empresaId, int $establecimientoId): Almacen
    {
        return Almacen::deEmpresa($empresaId)->where('establecimiento_id', $establecimientoId)->where('activo', true)->orderByDesc('es_principal')->first()
            ?? Almacen::create(['empresa_id' => $empresaId, 'establecimiento_id' => $establecimientoId, 'nombre' => 'Almacén principal', 'es_principal' => true]);
    }

    /**
     * Entrada, salida o ajuste al conteo físico (cantidad_fisica). Devuelve los movimientos creados.
     *
     * @param  list<array{producto_id: int, cantidad?: numeric, cantidad_fisica?: numeric, costo_unitario?: numeric}>  $items
     */
    public function registrar(Empresa $empresa, User $user, Almacen $almacen, string $tipo, array $items, ?string $documento, ?string $observacion): array
    {
        $productos = $this->productosInventariables($empresa, $items);
        $lote = (string) Str::uuid();

        $movs = DB::transaction(function () use ($empresa, $user, $almacen, $tipo, $items, $productos, $documento, $observacion, $lote) {
            $out = [];
            foreach (collect($items)->sortBy('producto_id') as $it) {
                $p = $productos[$it['producto_id']];
                if ($tipo === 'ajuste') {
                    $stock = $this->stockBloqueado($empresa->id, $almacen->id, $p->id);
                    $dif = Dec::sub(Dec::n($it['cantidad_fisica']), Dec::n($stock->cantidad));
                    if (Dec::isZero($dif)) {
                        continue;
                    }
                    $entrada = Dec::cmp($dif, '0') > 0;
                    $out[] = $this->mover($empresa->id, $user->id, $almacen, $p, $entrada ? 'ajuste_entrada' : 'ajuste_salida', $entrada ? 'entrada' : 'salida',
                        $entrada ? $dif : Dec::sub('0', $dif), $it['costo_unitario'] ?? null, $documento, $observacion ?? 'Ajuste a inventario físico', null, null, $lote);
                } else {
                    $out[] = $this->mover($empresa->id, $user->id, $almacen, $p, $tipo, $tipo, Dec::n($it['cantidad']),
                        $it['costo_unitario'] ?? null, $documento, $observacion, null, null, $lote);
                }
            }

            return $out;
        });
        $this->auditor->registrar("inventario.$tipo", $almacen, [], ['lote' => $lote, 'items' => count($movs), 'documento' => $documento], $empresa->id);

        return $movs;
    }

    public function transferir(Empresa $empresa, User $user, Almacen $origen, Almacen $destino, array $items, ?string $observacion): array
    {
        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages(['destino_id' => 'El almacén de destino debe ser distinto al de origen.']);
        }
        $productos = $this->productosInventariables($empresa, $items);
        $lote = (string) Str::uuid();

        return DB::transaction(function () use ($empresa, $user, $origen, $destino, $items, $productos, $observacion, $lote) {
            $out = [];
            foreach (collect($items)->sortBy('producto_id') as $it) {
                $p = $productos[$it['producto_id']];
                $sal = $this->mover($empresa->id, $user->id, $origen, $p, 'transferencia_salida', 'salida', Dec::n($it['cantidad']), null, "A: {$destino->nombre}", $observacion, null, null, $lote);
                // Entra al destino al mismo costo con el que salió del origen.
                $out[] = $sal;
                $out[] = $this->mover($empresa->id, $user->id, $destino, $p, 'transferencia_entrada', 'entrada', Dec::n($it['cantidad']), $sal->costo_unitario, "De: {$origen->nombre}", $observacion, null, null, $lote);
            }
            $this->auditor->registrar('inventario.transferencia', $origen, [], ['destino' => $destino->id, 'lote' => $lote], $empresa->id);

            return $out;
        });
    }

    /** Descuenta del almacén principal del establecimiento los bienes vendidos. Se llama DENTRO de la transacción de la venta. */
    public function descontarVenta(Venta $venta, string $documento, int $userId): void
    {
        $detalles = $venta->detalles()->whereNotNull('producto_id')->get();
        if ($detalles->isEmpty()) {
            return;
        }
        $productos = Producto::deEmpresa($venta->empresa_id)->whereIn('id', $detalles->pluck('producto_id'))
            ->where('tipo', 'bien')->where('controla_stock', true)->get()->keyBy('id');
        if ($productos->isEmpty()) {
            return;
        }
        $almacen = $this->almacenPrincipal($venta->empresa_id, $venta->establecimiento_id);
        $permitirNegativo = (bool) Empresa::whereKey($venta->empresa_id)->value('permitir_stock_negativo');
        // Agrupa por producto (un producto puede repetirse en varias líneas) y bloquea en orden.
        foreach ($detalles->groupBy('producto_id')->sortKeys() as $productoId => $lineas) {
            if (! $p = $productos->get($productoId)) {
                continue;
            }
            $cant = $lineas->reduce(fn ($s, $l) => Dec::add($s, $l->cantidad), '0');
            $this->mover($venta->empresa_id, $userId, $almacen, $p, 'venta', 'salida', $cant, null, $documento, null, Venta::class, $venta->id, permitirNegativo: $permitirNegativo);
        }
    }

    /**
     * Reingresa al almacén principal los bienes devueltos por una nota de crédito, al costo con el que salieron en la venta.
     *
     * @param  list<array{producto_id: int, cantidad: string}>  $lineas
     */
    public function devolverPorNota(Venta $venta, array $lineas, string $documento, int $userId): void
    {
        $productos = Producto::deEmpresa($venta->empresa_id)->whereIn('id', array_column($lineas, 'producto_id'))
            ->where('tipo', 'bien')->where('controla_stock', true)->get()->keyBy('id');
        $almacen = $this->almacenPrincipal($venta->empresa_id, $venta->establecimiento_id);
        foreach (collect($lineas)->groupBy('producto_id')->sortKeys() as $productoId => $ls) {
            if (! $p = $productos->get($productoId)) {
                continue;
            }
            $cant = $ls->reduce(fn ($s, $l) => Dec::add($s, $l['cantidad']), '0');
            $salida = MovimientoInventario::withoutGlobalScopes()->where('origen_type', Venta::class)->where('origen_id', $venta->id)
                ->where('producto_id', $productoId)->where('tipo', 'venta')->first();
            $this->mover($venta->empresa_id, $userId, $almacen, $p, 'devolucion', 'entrada', $cant, $salida?->costo_unitario, $documento,
                'Devolución por nota de crédito', Venta::class, $venta->id);
        }
    }

    /** Vuelve a sacar del almacén lo que una nota de crédito había reingresado (cuando esa nota se da de baja). */
    public function revertirDevolucion(Venta $venta, array $lineas, string $documento, int $userId): void
    {
        $productos = Producto::deEmpresa($venta->empresa_id)->whereIn('id', array_column($lineas, 'producto_id'))->get()->keyBy('id');
        $almacen = $this->almacenPrincipal($venta->empresa_id, $venta->establecimiento_id);
        foreach (collect($lineas)->groupBy('producto_id')->sortKeys() as $productoId => $ls) {
            if (! $p = $productos->get($productoId)) {
                continue;
            }
            $cant = $ls->reduce(fn ($s, $l) => Dec::add($s, $l['cantidad']), '0');
            // La mercadería ya salió físicamente: se permite saldo negativo para no bloquear la baja.
            $this->mover($venta->empresa_id, $userId, $almacen, $p, 'anulacion_devolucion', 'salida', $cant, null, $documento,
                'Baja de nota de crédito', Venta::class, $venta->id, permitirNegativo: true);
        }
    }

    public function kardex(int $productoId, ?int $almacenId, ?string $desde, ?string $hasta)
    {
        return MovimientoInventario::query()->with(['almacen:id,nombre', 'usuario:id,name'])->where('producto_id', $productoId)
            ->when($almacenId, fn ($q) => $q->where('almacen_id', $almacenId))
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->orderBy('id')->limit(1000)->get();
    }

    private function mover(int $empresaId, int $userId, Almacen $almacen, Producto $p, string $tipo, string $sentido, string $cantidad, mixed $costo,
        ?string $documento, ?string $observacion, ?string $origenType = null, ?int $origenId = null, ?string $lote = null, bool $permitirNegativo = false): MovimientoInventario
    {
        if (Dec::cmp($cantidad, '0') <= 0) {
            throw ValidationException::withMessages(['items' => "La cantidad de {$p->descripcion} debe ser mayor a cero."]);
        }
        $stock = $this->stockBloqueado($empresaId, $almacen->id, $p->id);
        $saldo = Dec::n($stock->cantidad);
        $prom = Dec::n($stock->costo_promedio);

        if ($sentido === 'entrada') {
            $cu = $costo !== null ? Dec::n($costo) : (Dec::cmp($prom, '0') > 0 ? $prom : Dec::n($p->costo));
            $nuevoSaldo = Dec::add($saldo, $cantidad);
            // Promedio ponderado: (saldo·promedio + cantidad·costo) / nuevo saldo
            $nuevoProm = Dec::cmp($saldo, '0') <= 0 ? $cu : Dec::div(Dec::add(Dec::mul($saldo, $prom), Dec::mul($cantidad, $cu)), $nuevoSaldo);
        } else {
            if (! $permitirNegativo && Dec::cmp($cantidad, $saldo) > 0) {
                throw ValidationException::withMessages(['items' => "Stock insuficiente de {$p->descripcion} en {$almacen->nombre}: disponible ".rtrim(rtrim(Dec::round($saldo, 4), '0'), '.').', requerido '.rtrim(rtrim(Dec::round($cantidad, 4), '0'), '.').'.']);
            }
            $cu = $prom;
            $nuevoSaldo = Dec::sub($saldo, $cantidad);
            $nuevoProm = $prom;
        }
        $stock->update(['cantidad' => Dec::round($nuevoSaldo, 4), 'costo_promedio' => Dec::round($nuevoProm, 6)]);

        return MovimientoInventario::create([
            'empresa_id' => $empresaId, 'almacen_id' => $almacen->id, 'producto_id' => $p->id, 'tipo' => $tipo, 'sentido' => $sentido,
            'cantidad' => Dec::round($cantidad, 4), 'costo_unitario' => Dec::round($cu, 6), 'valor' => Dec::round(Dec::mul($cantidad, $cu)),
            'saldo_cantidad' => Dec::round($nuevoSaldo, 4), 'saldo_costo_promedio' => Dec::round($nuevoProm, 6),
            'documento' => $documento, 'observacion' => $observacion, 'origen_type' => $origenType, 'origen_id' => $origenId, 'lote' => $lote, 'user_id' => $userId,
        ]);
    }

    private function stockBloqueado(int $empresaId, int $almacenId, int $productoId): Stock
    {
        Stock::withoutGlobalScopes()->insertOrIgnore([
            'empresa_id' => $empresaId, 'almacen_id' => $almacenId, 'producto_id' => $productoId, 'cantidad' => 0, 'costo_promedio' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Stock::withoutGlobalScopes()->where('almacen_id', $almacenId)->where('producto_id', $productoId)->lockForUpdate()->firstOrFail();
    }

    /** @return array<int, Producto> */
    private function productosInventariables(Empresa $empresa, array $items): array
    {
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Agrega al menos un producto.']);
        }
        $ids = collect($items)->pluck('producto_id');
        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Un producto aparece más de una vez; agrupa las cantidades.']);
        }
        $ps = Producto::deEmpresa($empresa->id)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $i => $id) {
            $p = $ps->get($id) ?? throw ValidationException::withMessages(["items.$i.producto_id" => 'Producto no encontrado.']);
            if ($p->tipo !== 'bien' || ! $p->controla_stock) {
                throw ValidationException::withMessages(["items.$i.producto_id" => "{$p->descripcion} no controla stock (servicio o sin control)."]);
            }
        }

        return $ps->all();
    }
}
