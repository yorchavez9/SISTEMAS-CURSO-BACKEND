<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Serie;
use App\Modules\Facturacion\Services\NotaService;
use App\Modules\Tributos\Calculo\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotaController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly NotaService $svc) {}

    /** Datos para el formulario de nota: líneas devolvibles, crédito disponible, series y motivos. */
    public function opciones(Comprobante $comprobante): JsonResponse
    {
        abort_unless(in_array($comprobante->tipo_documento, ['01', '03'], true) && $comprobante->venta, 422, 'Solo se emiten notas sobre facturas o boletas.');
        $venta = $comprobante->venta;
        $ncs = Comprobante::query()->where('comprobante_referencia_id', $comprobante->id)->where('tipo_documento', '07')->where('estado', '!=', 'error')->get();
        $devueltas = ComprobanteItem::query()->whereIn('comprobante_id', $ncs->pluck('id'))->whereNotNull('venta_detalle_id')
            ->selectRaw('venta_detalle_id, SUM(cantidad) as cant')->groupBy('venta_detalle_id')->pluck('cant', 'venta_detalle_id');
        $prefijo = $comprobante->serie[0];
        $series = Serie::query()->whereIn('tipo_documento', ['07', '08'])->where('activo', true)->whereNotNull('api_serie_id')
            ->where('serie', 'like', "$prefijo%")->orderByDesc('predeterminada')->orderBy('serie')->get(['id', 'tipo_documento', 'serie', 'correlativo_actual']);
        $motivos = DB::table('sunat_catalogos')->whereIn('catalogo', ['09', '10'])->where('activo', true)->orderBy('codigo')->get(['catalogo', 'codigo', 'descripcion', 'validado_api']);

        return response()->json(['data' => [
            'comprobante' => ['id' => $comprobante->id, 'numero' => $comprobante->numero(), 'tipo_documento' => $comprobante->tipo_documento, 'estado' => $comprobante->estado,
                'total' => (float) $comprobante->total, 'moneda' => $comprobante->moneda, 'cliente' => $comprobante->cliente_razon_social],
            'credito_disponible' => (float) Dec::round(Dec::sub($comprobante->total, $ncs->reduce(fn ($s, $n) => Dec::add($s, $n->total), '0'))),
            'tiene_notas_credito' => $ncs->isNotEmpty(),
            'venta' => ['pagado' => (float) $venta->monto_pagado, 'saldo' => (float) $venta->saldo, 'estado' => $venta->estado],
            'lineas' => $venta->detalles->map(fn ($l) => [
                'venta_detalle_id' => $l->id, 'descripcion' => $l->descripcion, 'unidad' => $l->unidad, 'cantidad' => (float) $l->cantidad,
                'devuelto' => (float) ($devueltas[$l->id] ?? 0), 'disponible' => (float) Dec::sub($l->cantidad, $devueltas[$l->id] ?? '0'),
                'precio_efectivo' => (float) Dec::round(Dec::div($l->total, $l->cantidad), 4), 'tip_afe_igv' => $l->tip_afe_igv,
                'gratuito' => $l->valor_referencial !== null, 'producto_id' => $l->producto_id,
            ]),
            'series' => $series->map(fn ($s) => ['id' => $s->id, 'tipo_documento' => $s->tipo_documento, 'serie' => $s->serie, 'siguiente' => $s->siguienteNumero()]),
            'motivos' => [
                'nc' => $motivos->where('catalogo', '09')->whereIn('codigo', [...NotaService::NC_TOTAL, ...NotaService::NC_POR_ITEM, ...NotaService::NC_POR_MONTO])
                    ->map(fn ($m) => ['codigo' => $m->codigo, 'descripcion' => $m->descripcion, 'validado_api' => (bool) $m->validado_api,
                        'modo' => in_array($m->codigo, NotaService::NC_TOTAL, true) ? 'total' : (in_array($m->codigo, NotaService::NC_POR_ITEM, true) ? 'item' : 'monto')])->values(),
                'nd' => $motivos->where('catalogo', '10')->whereIn('codigo', NotaService::ND_MOTIVOS)
                    ->map(fn ($m) => ['codigo' => $m->codigo, 'descripcion' => $m->descripcion, 'validado_api' => (bool) $m->validado_api, 'modo' => 'monto'])->values(),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'tipo_documento' => ['required', 'in:07,08'],
            'comprobante_id' => ['required', 'integer'],
            'serie_id' => ['required', 'integer'],
            'cod_motivo' => ['required', 'string', 'size:2'],
            'des_motivo' => ['required', 'string', 'min:5', 'max:250'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.venta_detalle_id' => ['nullable', 'integer'],
            'items.*.cantidad' => ['nullable', 'numeric', 'gt:0'],
            'items.*.descripcion' => ['nullable', 'string', 'max:255'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gt:0'],
            'items.*.tip_afe_igv' => ['nullable', 'string', 'size:2'],
            'devolver_stock' => ['sometimes', 'boolean'],
            'reembolso' => ['nullable', 'array'],
            'reembolso.metodo' => ['nullable', 'string'],
        ], [], ['des_motivo' => 'sustento del motivo']);
        $nota = $this->svc->emitir($this->empresa->get(), $request->user(), $d);

        $creada = $nota->wasRecentlyCreated;
        $nota = $nota->fresh(); // ya pasó por la API en esta misma solicitud

        return response()->json([
            'data' => ComprobanteController::resumen($nota),
            'message' => ($nota->tipo_documento === '07' ? 'Nota de crédito ' : 'Nota de débito ').ComprobanteController::mensajeEnvio($nota),
        ], $creada ? 201 : 200);
    }
}
