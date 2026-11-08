<?php

namespace App\Modules\Retenciones\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\ComprobanteRetencion;
use App\Models\Serie;
use App\Modules\Clientes\DocumentoIdentidad;
use App\Modules\Facturacion\Services\ProcesoSunat;
use App\Modules\Retenciones\Services\RetencionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Comprobantes de retención (20) y de percepción (40) y su reversión (RR). */
class RetencionController extends Controller
{
    public function __construct(
        private readonly EmpresaActual $empresa,
        private readonly RetencionService $svc,
        private readonly ProcesoSunat $proceso,
    ) {}

    public static function dto(ComprobanteRetencion $c, bool $detalle = false): array
    {
        $esRet = $c->esRetencion();
        $base = [
            'id' => $c->id, 'tipo_documento' => $c->tipo_documento, 'tipo_label' => $esRet ? 'Retención' : 'Percepción',
            'numero' => $c->numero(), 'serie' => $c->serie, 'correlativo' => $c->correlativo, 'fecha_emision' => $c->fecha_emision->toDateString(),
            'regimen' => $c->regimen, 'regimen_label' => RetencionService::regimenes($c->tipo_documento)[$c->regimen][0] ?? $c->regimen,
            'tasa' => (float) $c->tasa,
            'tercero' => ['tipo_doc' => $c->tercero_tipo_doc, 'num_doc' => $c->tercero_num_doc, 'razon_social' => $c->tercero_razon_social, 'direccion' => $c->tercero_direccion],
            'moneda' => $c->moneda, 'importe_documentos' => (float) $c->importe_documentos, 'importe' => (float) $c->importe, 'importe_neto' => (float) $c->importe_neto,
            'estado' => $c->estado, 'sunat_codigo' => $c->sunat_codigo, 'sunat_descripcion' => $c->sunat_descripcion, 'ultimo_error' => $c->ultimo_error,
            'reversion' => $c->reversion_estado ? [
                'estado' => $c->reversion_estado, 'motivo' => $c->reversion_motivo, 'identifier' => $c->reversion_identifier,
                'ticket' => $c->reversion_ticket, 'error' => $c->reversion_error,
            ] : null,
            'tiene_archivos' => $c->api_id !== null,
        ];
        if (! $detalle) {
            return $base;
        }

        return $base + [
            'documentos' => $c->documentos, 'observacion' => $c->observacion, 'usuario' => $c->usuario?->name, 'intentos' => $c->intentos,
            'historial' => $c->historial ?? [],
            // Reversión (RR) permitida: aceptado y sin otra en curso; SUNAT la acepta dentro de su plazo (la API valida).
            'revertible' => $c->estado === 'aceptado' && ! in_array($c->reversion_estado, ['por_enviar', 'enviado', 'aceptado'], true),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'tipo_documento' => ['nullable', Rule::in(['20', '40'])], 'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['por_enviar', 'enviado', 'aceptado', 'rechazado', 'error', 'revertido'])],
        ]);
        $this->proceso->actualizarPendientes($this->empresa->id()); // estados de SUNAT sin cola de trabajos
        $p = ComprobanteRetencion::query()
            ->when($f['tipo_documento'] ?? null, fn ($q, $t) => $q->where('tipo_documento', $t))
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['q'] ?? null, function ($q, $t) {
                $q->where(function ($w) use ($t) {
                    if (preg_match('/^([A-Z0-9]{4})-0*(\d+)$/i', $t, $m)) {
                        $w->where(fn ($x) => $x->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]));
                    }
                    $w->orWhere('tercero_num_doc', 'like', "$t%")->orWhere('tercero_razon_social', 'like', "%$t%");
                });
            })
            ->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => self::dto($c)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    /** Series y regímenes para el formulario. */
    public function opciones(Request $request): JsonResponse
    {
        $tipo = $request->validate(['tipo_documento' => ['required', Rule::in(['20', '40'])]])['tipo_documento'];
        $series = Serie::query()->where('tipo_documento', $tipo)->where('activo', true)->whereNotNull('api_serie_id')
            ->with('establecimiento:id,nombre,cod_local')->orderByDesc('predeterminada')->orderBy('serie')->get()
            ->map(fn ($s) => ['id' => $s->id, 'serie' => $s->serie, 'siguiente' => $s->siguienteNumero(), 'predeterminada' => $s->predeterminada,
                'establecimiento' => $s->establecimiento ? "{$s->establecimiento->cod_local} · {$s->establecimiento->nombre}" : null]);

        return response()->json(['data' => [
            'series' => $series,
            'regimenes' => collect(RetencionService::regimenes($tipo))->map(fn ($r, $k) => ['codigo' => $k, 'descripcion' => $r[0], 'tasa' => $r[1]])->values(),
            'documentos' => collect(ComprobanteRetencion::DOCUMENTOS)->map(fn ($l, $k) => ['codigo' => (string) $k, 'descripcion' => $l])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'tipo_documento' => ['required', Rule::in(['20', '40'])],
            'serie_id' => ['required', 'integer'],
            'regimen' => ['required', 'string', 'size:2'],
            'tercero' => ['required', 'array'],
            'tercero.tipo_doc' => ['required', Rule::in(['6', '1', '4', '7', '0'])],
            'tercero.num_doc' => ['required', 'string', 'max:15'],
            'tercero.razon_social' => ['required', 'string', 'max:500'],
            'tercero.direccion' => ['nullable', 'string', 'max:500'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'documentos' => ['required', 'array', 'min:1', 'max:50'],
            'documentos.*.tipo_doc' => ['required', Rule::in(array_keys(ComprobanteRetencion::DOCUMENTOS))],
            'documentos.*.num_doc' => ['required', 'string', 'regex:/^[A-Z0-9]{1,4}-\d{1,8}$/i'],
            'documentos.*.fecha_emision' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'documentos.*.imp_total' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'documentos.*.fecha_pago' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'documentos.*.num_doc.regex' => 'Usa el formato SERIE-NÚMERO, p. ej. F001-123.',
        ], [
            'tercero.num_doc' => 'número de documento', 'tercero.razon_social' => 'razón social', 'documentos' => 'comprobantes relacionados',
            'documentos.*.num_doc' => 'número del comprobante', 'documentos.*.fecha_emision' => 'fecha de emisión', 'documentos.*.imp_total' => 'importe total',
        ]);
        if ($d['tipo_documento'] === '20' && $d['tercero']['tipo_doc'] !== '6') {
            throw ValidationException::withMessages(['tercero.tipo_doc' => 'La retención se emite a un proveedor con RUC.']);
        }
        if ($err = DocumentoIdentidad::error($d['tercero']['tipo_doc'], $d['tercero']['num_doc'])) {
            throw ValidationException::withMessages(['tercero.num_doc' => $err]);
        }
        $c = $this->svc->crear($this->empresa->get(), $request->user(), $d)->fresh();
        $nombre = $c->esRetencion() ? 'Retención' : 'Percepción';
        $msg = match ($c->estado) {
            'aceptado' => "$nombre {$c->numero()} aceptada por SUNAT.",
            'enviado' => "$nombre {$c->numero()} enviada a SUNAT; esperando su respuesta.",
            'error' => "$nombre {$c->numero()} no se pudo enviar: {$c->ultimo_error}",
            'rechazado' => "$nombre {$c->numero()} rechazada por SUNAT: {$c->sunat_codigo} {$c->sunat_descripcion}",
            default => $c->ultimo_error ? "$nombre {$c->numero()} registrada; aún no se pudo enviar (se reintentará)." : "$nombre {$c->numero()} registrada.",
        };

        return response()->json(['data' => self::dto($c, true), 'message' => $msg], 201);
    }

    public function show(ComprobanteRetencion $retencion): JsonResponse
    {
        $this->proceso->actualizarRetencion($retencion);

        return response()->json(['data' => self::dto($retencion->refresh()->load('usuario:id,name'), true)]);
    }

    public function reintentar(ComprobanteRetencion $retencion, Auditor $auditor): JsonResponse
    {
        if ($retencion->esFinal() && $retencion->reversion_estado !== 'enviado') {
            throw ValidationException::withMessages(['estado' => "El comprobante ya está {$retencion->estado}."]);
        }
        if ($retencion->estado === 'error' && ! $retencion->api_id) {
            $retencion->cambiarEstado('por_enviar', 'Reintento manual solicitado', ['intentos' => 0, 'ultimo_error' => null]);
            $this->proceso->emitirRetencion($retencion);
        } else {
            $this->proceso->actualizarRetencion($retencion, forzar: true);
        }
        $auditor->registrar('retencion.reintento', $retencion, [], ['numero' => $retencion->numero()], $retencion->empresa_id);

        return response()->json(['data' => self::dto($retencion->refresh(), true), 'message' => 'Reintento realizado.']);
    }

    public function revertir(Request $request, ComprobanteRetencion $retencion): JsonResponse
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']])['motivo'];
        $this->svc->revertir($retencion, $request->user(), $motivo);
        $retencion->refresh();
        $msg = match ($retencion->reversion_estado) {
            'error' => "No se pudo revertir: {$retencion->reversion_error}",
            'aceptado' => "{$retencion->numero()} revertido ante SUNAT.",
            default => "Reversión de {$retencion->numero()} enviada a SUNAT; esperando su respuesta.",
        };

        return response()->json(['data' => self::dto($retencion, true), 'message' => $msg], $retencion->reversion_estado === 'error' ? 422 : 200);
    }

    public function archivo(Request $request, ComprobanteRetencion $retencion, string $tipo): Response
    {
        $formato = $request->validate(['formato' => ['nullable', Rule::in(['a4', 'a5', 'ticket-80', 'ticket-58'])]])['formato'] ?? 'a4';
        $a = $this->svc->archivo($retencion, $tipo, $formato);
        abort_unless($a, 404, $tipo === 'cdr' && ! $retencion->esFinal() ? 'El CDR estará disponible cuando SUNAT responda.' : 'El archivo no está disponible en la API.');

        return response($a['contenido'], 200, [
            'Content-Type' => $a['mime'],
            'Content-Disposition' => ($tipo === 'pdf' && $request->boolean('ver') ? 'inline' : 'attachment').'; filename="'.$a['nombre'].'"',
        ]);
    }
}
