<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Modules\Reportes\Services\MetricasService;
use App\Modules\Reportes\Services\RegistroVentasExcel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly MetricasService $m) {}

    private function rango(Request $r): array
    {
        $d = $r->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'moneda' => ['sometimes', 'in:PEN,USD'],
        ]);

        return [$d['desde'], $d['hasta'], $d['moneda'] ?? 'PEN'];
    }

    /** Ventas agrupadas por día, producto, cliente, usuario o tipo de comprobante. */
    public function ventas(Request $request): JsonResponse
    {
        [$desde, $hasta, $moneda] = $this->rango($request);
        $agrupar = $request->validate(['agrupar' => ['required', 'in:dia,producto,cliente,usuario,tipo']])['agrupar'];
        $e = $this->empresa->id();

        if ($agrupar === 'producto') {
            // Solo facturas/boletas vigentes (las devoluciones por NC se ven en el reporte de comprobantes).
            $filas = DB::table('venta_detalles')->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
                ->join('comprobantes', 'comprobantes.venta_id', '=', 'ventas.id')
                ->where('ventas.empresa_id', $e)->where('comprobantes.moneda', $moneda)->whereIn('comprobantes.estado', MetricasService::ESTADOS_VALIDOS)
                ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta])->whereNull('venta_detalles.valor_referencial')
                ->selectRaw('COALESCE(venta_detalles.codigo, "—") clave, venta_detalles.descripcion etiqueta, SUM(venta_detalles.cantidad) cantidad, SUM(venta_detalles.base) base, SUM(venta_detalles.igv) igv, SUM(venta_detalles.total) total')
                ->groupBy('venta_detalles.codigo', 'venta_detalles.descripcion')->orderByDesc('total')->get();
        } else {
            $q = $this->m->base($e, $desde, $hasta, $moneda);
            [$clave, $etiqueta] = match ($agrupar) {
                'dia' => ['comprobantes.fecha_emision', 'comprobantes.fecha_emision'],
                'cliente' => ['comprobantes.cliente_num_doc', 'MAX(comprobantes.cliente_razon_social)'],
                'tipo' => ['comprobantes.tipo_documento', 'comprobantes.tipo_documento'],
                'usuario' => ['users.id', 'MAX(users.name)'],
            };
            if ($agrupar === 'usuario') {
                // Facturas/boletas por la venta; notas, por quien las solicitó (auditoría de la nota).
                $q->leftJoin('ventas', 'ventas.id', '=', 'comprobantes.venta_id')
                    ->leftJoin('comprobantes as ref', 'ref.id', '=', 'comprobantes.comprobante_referencia_id')
                    ->leftJoin('ventas as vref', 'vref.id', '=', 'ref.venta_id')
                    ->leftJoin('users', 'users.id', '=', DB::raw('COALESCE(ventas.user_id, vref.user_id)'));
            }
            $filas = $q->selectRaw("$clave clave, $etiqueta etiqueta, COUNT(*) cantidad, SUM(".MetricasService::SIGNO.' * comprobantes.valor_venta) base, SUM('.MetricasService::SIGNO.' * comprobantes.igv) igv, SUM('.MetricasService::SIGNO.' * comprobantes.total) total')
                ->groupBy(DB::raw($clave))->orderBy($agrupar === 'dia' ? 'clave' : 'total', $agrupar === 'dia' ? 'asc' : 'desc')->get();
        }

        $filas = $filas->map(fn ($f) => ['clave' => (string) $f->clave, 'etiqueta' => (string) $f->etiqueta, 'cantidad' => (float) $f->cantidad,
            'base' => round((float) $f->base, 2), 'igv' => round((float) $f->igv, 2), 'total' => round((float) $f->total, 2)]);

        return response()->json(['data' => ['filas' => $filas, 'totales' => [
            'base' => round($filas->sum('base'), 2), 'igv' => round($filas->sum('igv'), 2), 'total' => round($filas->sum('total'), 2),
        ]]]);
    }

    /**
     * Registro de ventas (formato de libro): un renglón por comprobante con importes con signo
     * (las notas de crédito restan), y el resumen tributario del periodo.
     */
    public function registro(Request $request): JsonResponse
    {
        [$desde, $hasta, $moneda] = $this->rango($request);

        return response()->json(['data' => $this->datosRegistro($this->empresa->id(), $desde, $hasta, $moneda)]);
    }

    /** El mismo registro en Excel (.xlsx), generado en el sistema: la API mezclaría documentos de otros sistemas de la cuenta. */
    public function registroExcel(Request $request, RegistroVentasExcel $excel): Response
    {
        [$desde, $hasta, $moneda] = $this->rango($request);
        $empresa = $this->empresa->get();
        $contenido = $excel->generar($empresa, $desde, $hasta, $moneda, $this->datosRegistro($empresa->id, $desde, $hasta, $moneda));

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="registro-ventas-'.$empresa->ruc."-$desde-al-$hasta.xlsx\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{filas: \Illuminate\Support\Collection, resumen: \Illuminate\Support\Collection, totales: \Illuminate\Support\Collection} */
    private function datosRegistro(int $e, string $desde, string $hasta, string $moneda): array
    {
        $base = fn () => DB::table('comprobantes')->where('comprobantes.empresa_id', $e)->where('comprobantes.moneda', $moneda)
            ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta])->whereIn('comprobantes.tipo_documento', ['01', '03', '07', '08']);

        $filas = $base()->leftJoin('comprobantes as ref', 'ref.id', '=', 'comprobantes.comprobante_referencia_id')
            ->orderBy('comprobantes.fecha_emision')->orderBy('comprobantes.serie')->orderBy('comprobantes.correlativo')
            ->get(['comprobantes.fecha_emision', 'comprobantes.tipo_documento', 'comprobantes.serie', 'comprobantes.correlativo', 'comprobantes.cliente_tipo_doc',
                'comprobantes.cliente_num_doc', 'comprobantes.cliente_razon_social', 'comprobantes.estado', 'comprobantes.gravadas', 'comprobantes.exoneradas',
                'comprobantes.inafectas', 'comprobantes.exportacion', 'comprobantes.gratuitas', 'comprobantes.igv', 'comprobantes.isc', 'comprobantes.icbper', 'comprobantes.total',
                DB::raw("CONCAT(ref.serie, '-', ref.correlativo) as referencia")])
            ->map(function ($f) {
                $anulado = ! in_array($f->estado, MetricasService::ESTADOS_VALIDOS, true);
                $s = $f->tipo_documento === '07' ? -1 : 1;
                $out = ['fecha' => $f->fecha_emision, 'tipo' => $f->tipo_documento, 'numero' => "{$f->serie}-{$f->correlativo}", 'referencia' => $f->referencia,
                    'cliente_tipo_doc' => $f->cliente_tipo_doc, 'cliente_doc' => $f->cliente_num_doc, 'cliente' => $f->cliente_razon_social, 'estado' => $f->estado];
                foreach (['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'igv', 'isc', 'icbper', 'total'] as $c) {
                    // Los comprobantes anulados/rechazados figuran en el registro con importes en cero.
                    $out[$c] = $anulado ? 0.0 : round($s * (float) $f->$c, 2);
                }

                return $out;
            });

        $resumen = $this->m->base($e, $desde, $hasta, $moneda)->select(array_merge(['comprobantes.tipo_documento as tipo', DB::raw('COUNT(*) as cantidad')], MetricasService::columnasConSigno()))
            ->groupBy('comprobantes.tipo_documento')->orderBy('comprobantes.tipo_documento')->get()
            ->map(fn ($r) => collect((array) $r)->map(fn ($v, $k) => in_array($k, ['tipo'], true) ? $v : round((float) $v, 2))->all());
        $totales = collect(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'igv', 'isc', 'icbper', 'total'])
            ->mapWithKeys(fn ($c) => [$c => round($resumen->sum($c), 2)]);

        return ['filas' => $filas, 'resumen' => $resumen, 'totales' => $totales];
    }
}
