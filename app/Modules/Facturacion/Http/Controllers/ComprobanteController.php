<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Http\Controllers\Controller;
use App\Models\ApiSolicitud;
use App\Models\Comprobante;
use App\Core\Tenancy\EmpresaActual;
use App\Modules\Facturacion\Services\EmisionService;
use App\Modules\Facturacion\Services\ProcesoSunat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprobanteController extends Controller
{
    public function __construct(private readonly ProcesoSunat $proceso, private readonly EmpresaActual $empresa) {}

    /** Texto para el usuario según cómo quedó el envío a la API (se hace en la misma solicitud, sin cola). */
    public static function mensajeEnvio(Comprobante $c): string
    {
        return match ($c->estado) {
            'aceptado' => "{$c->numero()} aceptado por SUNAT.",
            'observado' => "{$c->numero()} aceptado por SUNAT con observaciones.",
            'rechazado' => "{$c->numero()} rechazado por SUNAT: {$c->sunat_codigo} {$c->sunat_descripcion}",
            'enviado' => "{$c->numero()} enviado a SUNAT; esperando su respuesta.",
            'pendiente_resumen' => "{$c->numero()} registrada; se informará a SUNAT en el resumen diario.",
            'error' => "{$c->numero()} no se pudo enviar: {$c->ultimo_error}",
            default => $c->ultimo_error
                ? "{$c->numero()} registrado, pero aún no se pudo enviar a la API (se reintentará al consultarlo)."
                : "{$c->numero()} registrado.",
        };
    }

    public static function resumen(Comprobante $c): array
    {
        return [
            'id' => $c->id, 'tipo_documento' => $c->tipo_documento, 'numero' => $c->numero(), 'serie' => $c->serie, 'correlativo' => $c->correlativo,
            'fecha_emision' => $c->fecha_emision->toDateString(), 'moneda' => $c->moneda, 'total' => (float) $c->total,
            'cliente' => ['tipo_doc' => $c->cliente_tipo_doc, 'num_doc' => $c->cliente_num_doc, 'razon_social' => $c->cliente_razon_social],
            // Anulado por una NC total: se muestra "anulado"; estado_sunat conserva la respuesta de SUNAT (aceptado).
            'estado' => $c->anulado_por_nota_id ? 'anulado' : $c->estado, 'estado_sunat' => $c->estado,
            'anulado_por' => $c->anulado_por_nota_id ? ['id' => $c->anulado_por_nota_id, 'numero' => $c->anuladoPorNota?->numero()] : null,
            'sunat_codigo' => $c->sunat_codigo, 'sunat_descripcion' => $c->sunat_descripcion,
            'diferencia_totales' => $c->diferencia_totales, 'ultimo_error' => $c->ultimo_error,
            // La API genera el PDF (A4, A5, ticket 80/58 mm) en cuanto registra el documento, antes de la respuesta de SUNAT.
            'imprimible' => $c->api_id !== null && ! in_array($c->estado, ['rechazado', 'error'], true),
        ];
    }

    /**
     * PDF para imprimir en el formato pedido, obtenido de la API en el momento (formatos validados en beta
     * 2026-11-06: ticket-80 = 80 mm, ticket-58 = 58 mm, a5, a4). Se entrega "inline" para abrir el diálogo de impresión.
     */
    public function imprimir(Comprobante $comprobante, string $formato, EmisionService $emision): Response
    {
        abort_unless($comprobante->api_id, 409, 'El comprobante aún no se registra en la API. Espera unos segundos.');
        abort_if(in_array($comprobante->estado, ['rechazado', 'error'], true), 409, 'Un comprobante rechazado o con error no se imprime.');
        $res = $emision->pdf($comprobante, $formato);
        abort_unless($res, 502, 'La API no devolvió el PDF. Intenta nuevamente.');

        return response($res->raw, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$comprobante->numero()."-$formato.pdf\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // Sin cola de trabajos: al listar se refrescan (acotado) los comprobantes que esperan respuesta de SUNAT.
        $this->proceso->actualizarPendientes($this->empresa->id());
        $q = trim((string) $request->query('q', ''));
        $p = Comprobante::query()
            ->when($request->filled('tipo_documento'), fn ($w) => $w->where('tipo_documento', $request->query('tipo_documento')))
            ->when($request->filled('estado'), fn ($w) => match ($request->query('estado')) {
                'anulado' => $w->where(fn ($x) => $x->where('estado', 'anulado')->orWhereNotNull('anulado_por_nota_id')),
                'aceptado' => $w->where('estado', 'aceptado')->whereNull('anulado_por_nota_id'),
                default => $w->where('estado', $request->query('estado')),
            })
            ->when($request->filled('desde'), fn ($w) => $w->whereDate('fecha_emision', '>=', $request->query('desde')))
            ->when($request->filled('hasta'), fn ($w) => $w->whereDate('fecha_emision', '<=', $request->query('hasta')))
            ->when($q !== '', function ($w) use ($q) {
                if (preg_match('/^([A-Z0-9]{4})-0*(\d+)$/i', $q, $m)) {
                    return $w->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]);
                }

                return $w->where(fn ($x) => $x->where('cliente_num_doc', 'like', "$q%")->orWhere('cliente_razon_social', 'like', "%$q%"));
            })
            ->with('anuladoPorNota')->orderByDesc('id')->paginate(min(100, max(5, (int) $request->query('por_pagina', 15))));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => self::resumen($c) + ['archivos' => $c->archivos()->pluck('tipo')]),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function show(Comprobante $comprobante): JsonResponse
    {
        // La pantalla consulta cada pocos segundos mientras está pendiente: aquí se envía o se lee su estado en la API.
        $this->proceso->actualizar($comprobante);
        $c = $comprobante->refresh()->load(['estados', 'archivos', 'venta.detalles', 'venta.cuotas', 'items', 'referencia', 'notas']);
        $totales = $c->only(['gravadas', 'exoneradas', 'inafectas', 'exportacion', 'gratuitas', 'base_ivap', 'igv', 'ivap', 'isc', 'icbper', 'descuentos_globales', 'total_impuestos', 'valor_venta', 'sub_total', 'total']);

        return response()->json(['data' => self::resumen($c) + [
            'forma_pago' => $c->forma_pago, 'fecha_vencimiento' => $c->fecha_vencimiento?->toDateString(), 'tipo_operacion' => $c->tipo_operacion,
            'cliente_direccion' => $c->cliente_direccion, 'hash_cpe' => $c->hash_cpe, 'sunat_notas' => $c->sunat_notas,
            'totales' => array_map('floatval', $totales), 'totales_api' => $c->totales_api, 'intentos' => $c->intentos,
            'items' => $c->venta
                ? $c->venta->detalles->map(fn ($d) => $d->only(['descripcion', 'unidad', 'cantidad', 'precio_unitario', 'tip_afe_igv', 'descuento', 'base', 'igv', 'total']))
                : $c->items->map(fn ($d) => $d->only(['descripcion', 'unidad', 'cantidad', 'precio_unitario', 'tip_afe_igv', 'base', 'igv', 'total']) + ['descuento' => '0.00']),
            'motivo' => $c->cod_motivo ? ['codigo' => $c->cod_motivo, 'descripcion' => $c->des_motivo] : null,
            'referencia' => $c->referencia ? ['id' => $c->referencia->id, 'numero' => $c->referencia->numero(), 'tipo_documento' => $c->referencia->tipo_documento] : null,
            'notas' => $c->notas->map(fn ($n) => ['id' => $n->id, 'numero' => $n->numero(), 'tipo_documento' => $n->tipo_documento, 'total' => (float) $n->total, 'estado' => $n->estado]),
            'venta' => $c->venta ? ['id' => $c->venta->id, 'estado' => $c->venta->estado, 'pagado' => (float) $c->venta->monto_pagado, 'saldo' => (float) $c->venta->saldo] : null,
            'bajas' => \App\Models\Comunicacion::query()->whereHas('items', fn ($q) => $q->where('comprobante_id', $c->id))->orderByDesc('id')->get()
                ->map(fn ($b) => ['id' => $b->id, 'tipo' => $b->tipo, 'identifier' => $b->identifier, 'estado' => $b->estado, 'motivo' => $b->motivo,
                    'sunat_codigo' => $b->sunat_codigo, 'sunat_descripcion' => $b->sunat_descripcion, 'ultimo_error' => $b->ultimo_error]),
            'plazo_baja' => $c->fecha_emision->copy()->addDays(\App\Modules\Facturacion\Services\BajaService::PLAZO_DIAS)->toDateString(),
            'cuotas' => $c->venta?->cuotas->map(fn ($q) => ['numero' => $q->numero, 'monto' => (float) $q->monto, 'fecha_vencimiento' => $q->fecha_vencimiento->toDateString()]) ?? [],
            'archivos' => $c->archivos->map(fn ($a) => ['tipo' => $a->tipo, 'size' => $a->size]),
            'historial' => $c->estados->map(fn ($e) => ['de' => $e->estado_anterior, 'a' => $e->estado_nuevo, 'detalle' => $e->detalle, 'fecha' => $e->created_at?->toIso8601String()]),
            'solicitudes' => ApiSolicitud::where('comprobante_id', $c->id)->orderBy('id')->get(['operacion', 'metodo', 'endpoint', 'http_status', 'duracion_ms', 'error', 'created_at'])
                ->map(fn ($s) => $s->only(['operacion', 'metodo', 'endpoint', 'http_status', 'duracion_ms', 'error']) + ['fecha' => $s->created_at?->toIso8601String()]),
        ]]);
    }

    /** Descarga protegida (solo usuarios de la empresa con permiso). Si aún no está guardado, se trae de la API en el momento. */
    public function archivo(Comprobante $comprobante, string $tipo): StreamedResponse|JsonResponse
    {
        $a = $comprobante->archivos()->where('tipo', $tipo)->first();
        if (! $a && $comprobante->api_id) {
            $this->proceso->descargarArchivos($comprobante, [$tipo]);
            $a = $comprobante->archivos()->where('tipo', $tipo)->first();
        }
        if (! $a) {
            abort(404, $tipo === 'cdr' && ! in_array($comprobante->estado, ['aceptado', 'observado', 'anulado'], true)
                ? 'El CDR estará disponible cuando SUNAT responda.' : 'El archivo aún no está disponible. Intenta en unos segundos.');
        }
        $mime = ['xml' => 'application/xml', 'cdr' => 'application/zip', 'pdf' => 'application/pdf'][$tipo];

        return Storage::disk('local')->download($a->path, basename($a->path), ['Content-Type' => $mime]);
    }

    /** Reintento controlado: reenvía si nunca llegó a la API, o vuelve a consultar el estado si quedó pendiente. */
    public function reintentar(Comprobante $comprobante, Auditor $auditor): JsonResponse
    {
        if ($comprobante->esFinal()) {
            throw ValidationException::withMessages(['estado' => "El comprobante ya está {$comprobante->estado}."]);
        }
        if (! $comprobante->api_id) {
            // Si el número quedó ocupado por otro documento, el envío renumera automáticamente (máx. 3 veces).
            $comprobante->cambiarEstado('por_enviar', 'Reintento manual solicitado', ['intentos' => 0]);
            $this->proceso->emitir($comprobante);
        } else {
            $this->proceso->actualizar($comprobante, forzar: true);
        }
        $auditor->registrar('comprobante.reintento', $comprobante, [], ['numero' => $comprobante->numero()]);

        return response()->json(['data' => self::resumen($comprobante->fresh()), 'message' => 'Reintento realizado.']);
    }
}
