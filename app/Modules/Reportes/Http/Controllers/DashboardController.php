<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Modules\Reportes\Services\MetricasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly MetricasService $m) {}

    public function __invoke(): JsonResponse
    {
        $e = $this->empresa->id();
        $hoy = Carbon::now('America/Lima');
        $d = $hoy->toDateString();
        $iniMes = $hoy->copy()->startOfMonth()->toDateString();
        // Mes anterior hasta el mismo día, para comparar periodos equivalentes.
        $iniAnt = $hoy->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $finAnt = $hoy->copy()->subMonthNoOverflow()->toDateString();

        $porDia = $this->m->base($e, $hoy->copy()->subDays(29)->toDateString(), $d)
            ->selectRaw('comprobantes.fecha_emision as fecha, SUM('.MetricasService::SIGNO.' * comprobantes.total) as total, COUNT(*) as n')
            ->groupBy('comprobantes.fecha_emision')->pluck('total', 'fecha');
        $serie = [];
        for ($i = 29; $i >= 0; $i--) {
            $f = $hoy->copy()->subDays($i)->toDateString();
            $serie[] = ['fecha' => $f, 'total' => round((float) ($porDia[$f] ?? 0), 2)];
        }

        $estados = DB::table('comprobantes')->where('empresa_id', $e)->whereDate('fecha_emision', '>=', $iniMes)
            ->selectRaw('estado, COUNT(*) n')->groupBy('estado')->pluck('n', 'estado');
        $porTipo = $this->m->base($e, $iniMes, $d)->selectRaw('comprobantes.tipo_documento as tipo, COUNT(*) n, SUM(comprobantes.total) total')
            ->groupBy('comprobantes.tipo_documento')->get()->keyBy('tipo');

        $topProductos = DB::table('venta_detalles')
            ->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
            ->where('ventas.empresa_id', $e)->where('ventas.estado', 'registrada')->whereDate('ventas.fecha', '>=', $iniMes)
            ->whereNull('venta_detalles.valor_referencial')
            ->selectRaw('COALESCE(venta_detalles.codigo, "") codigo, venta_detalles.descripcion, SUM(venta_detalles.cantidad) cantidad, SUM(venta_detalles.total) total')
            ->groupBy('venta_detalles.codigo', 'venta_detalles.descripcion')->orderByDesc('total')->limit(8)->get();

        $topClientes = $this->m->base($e, $iniMes, $d)
            ->selectRaw('comprobantes.cliente_num_doc doc, MAX(comprobantes.cliente_razon_social) nombre, SUM('.MetricasService::SIGNO.' * comprobantes.total) total, SUM(CASE WHEN comprobantes.tipo_documento IN ("01","03") THEN 1 ELSE 0 END) compras')
            ->where('comprobantes.cliente_num_doc', '!=', '99999999')
            ->groupBy('comprobantes.cliente_num_doc')->orderByDesc('total')->limit(6)->get();

        $saldos = DB::table('ventas')->where('empresa_id', $e)
            ->selectRaw('SUM(CASE WHEN saldo > 0 AND estado = "registrada" THEN saldo ELSE 0 END) por_cobrar, SUM(CASE WHEN saldo < 0 THEN -saldo ELSE 0 END) a_favor')->first();

        $recientes = Comprobante::query()->orderByDesc('id')->limit(8)->get()->map(fn ($c) => [
            'id' => $c->id, 'numero' => $c->numero(), 'tipo_documento' => $c->tipo_documento, 'cliente' => $c->cliente_razon_social,
            'total' => (float) $c->total, 'moneda' => $c->moneda, 'estado' => $c->estado, 'fecha' => $c->created_at?->toIso8601String(),
        ]);

        $alertas = [];
        $atascados = Comprobante::query()->whereIn('estado', ['por_enviar', 'enviado'])->where('updated_at', '<', now()->subMinutes(15))->count();
        if ($atascados) {
            $alertas[] = ['nivel' => 'warning', 'texto' => "$atascados comprobante(s) llevan más de 15 minutos sin respuesta de SUNAT. Ábrelos para volver a consultar o revisa el cron de `schedule:run` (sunat:sincronizar).", 'link' => '/comprobantes'];
        }
        if ($errores = Comprobante::query()->whereIn('estado', ['error', 'rechazado'])->whereDate('fecha_emision', '>=', $hoy->copy()->subDays(7)->toDateString())->count()) {
            $alertas[] = ['nivel' => 'critical', 'texto' => "$errores comprobante(s) con error o rechazados en los últimos 7 días.", 'link' => '/comprobantes'];
        }
        $sinResumen = Comprobante::query()->where('estado', 'pendiente_resumen')->whereDate('fecha_emision', '<', $d)->count();
        if ($sinResumen) {
            $alertas[] = ['nivel' => 'warning', 'texto' => "$sinResumen boleta(s) de días anteriores aún no se envían a SUNAT en el resumen diario.", 'link' => '/resumen-diario'];
        }
        $bajoMin = DB::table('productos')->leftJoin('stocks', 'stocks.producto_id', '=', 'productos.id')
            ->where('productos.empresa_id', $e)->where('productos.activo', true)->where('productos.controla_stock', true)->whereNull('productos.deleted_at')
            ->select('productos.id')->groupBy('productos.id', 'productos.stock_minimo')->havingRaw('COALESCE(SUM(stocks.cantidad), 0) <= productos.stock_minimo')->get()->count();
        if ($bajoMin) {
            $alertas[] = ['nivel' => 'warning', 'texto' => "$bajoMin producto(s) en o bajo el stock mínimo.", 'link' => '/inventario'];
        }
        if ((float) $saldos->a_favor > 0) {
            $alertas[] = ['nivel' => 'warning', 'texto' => 'Hay saldos a favor de clientes pendientes de reembolso.', 'link' => '/cuentas-por-cobrar'];
        }

        return response()->json(['data' => [
            'moneda' => 'PEN',
            'kpis' => [
                'ventas_hoy' => $this->m->ventaNeta($e, $d, $d),
                'ventas_mes' => $this->m->ventaNeta($e, $iniMes, $d),
                'ventas_mes_anterior_a_la_fecha' => $this->m->ventaNeta($e, $iniAnt, $finAnt),
                'comprobantes_mes' => (int) $porTipo->sum('n'),
                'por_cobrar' => round((float) $saldos->por_cobrar, 2),
                'saldos_a_favor' => round((float) $saldos->a_favor, 2),
            ],
            'por_tipo' => collect(['01', '03', '07', '08'])->map(fn ($t) => ['tipo' => $t, 'cantidad' => (int) ($porTipo[$t]->n ?? 0), 'total' => round((float) ($porTipo[$t]->total ?? 0), 2)]),
            'por_estado' => collect(['aceptado', 'observado', 'enviado', 'pendiente_resumen', 'por_enviar', 'rechazado', 'error', 'anulado'])->map(fn ($s) => ['estado' => $s, 'cantidad' => (int) ($estados[$s] ?? 0)]),
            'ventas_por_dia' => $serie,
            'top_productos' => $topProductos->map(fn ($p) => ['codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'cantidad' => (float) $p->cantidad, 'total' => round((float) $p->total, 2)]),
            'top_clientes' => $topClientes->map(fn ($c) => ['documento' => $c->doc, 'nombre' => $c->nombre, 'total' => round((float) $c->total, 2), 'compras' => (int) $c->compras]),
            'recientes' => $recientes,
            'alertas' => $alertas,
            'periodo' => ['hoy' => $d, 'mes_desde' => $iniMes],
        ]]);
    }
}
