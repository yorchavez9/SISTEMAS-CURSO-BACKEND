<?php

namespace App\Modules\Guias\Http\Controllers;

use App\Core\Auditoria\Auditor;
use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Establecimiento;
use App\Models\GuiaRemision;
use App\Models\Serie;
use App\Modules\Clientes\DocumentoIdentidad;
use App\Modules\Facturacion\Services\ProcesoSunat;
use App\Modules\Guias\Services\GuiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuiaController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly GuiaService $svc, private readonly ProcesoSunat $proceso) {}

    public static function dto(GuiaRemision $g, bool $detalle = false): array
    {
        $out = [
            'id' => $g->id, 'numero' => $g->numero(), 'fecha_emision' => $g->fecha_emision->toDateString(), 'fecha_traslado' => $g->fecha_traslado->toDateString(),
            'cod_traslado' => $g->cod_traslado, 'motivo' => GuiaRemision::MOTIVOS[$g->cod_traslado] ?? $g->cod_traslado,
            'mod_traslado' => $g->mod_traslado, 'modalidad' => GuiaRemision::MODALIDADES[$g->mod_traslado] ?? $g->mod_traslado,
            'destinatario' => ['tipo_doc' => $g->destinatario_tipo_doc, 'num_doc' => $g->destinatario_num_doc, 'razon_social' => $g->destinatario_razon_social],
            'llegada' => ['ubigeo' => $g->llegada_ubigeo, 'direccion' => $g->llegada_direccion],
            'estado' => $g->estado, 'sunat_codigo' => $g->sunat_codigo, 'sunat_descripcion' => $g->sunat_descripcion, 'ultimo_error' => $g->ultimo_error,
            'comprobante' => $g->comprobante ? ['id' => $g->comprobante->id, 'numero' => $g->comprobante->numero()] : null,
        ];
        if ($detalle) {
            $p = $g->payload;
            $out += [
                'partida' => ['ubigeo' => $g->partida_ubigeo, 'direccion' => $g->partida_direccion],
                'peso_total' => (float) $g->peso_total, 'und_peso_total' => $g->und_peso_total, 'num_bultos' => $g->num_bultos,
                'transportista' => $p['transportista'] ?? null, 'vehiculo' => $p['vehiculo'] ?? null,
                'conductores' => isset($p['conductor']) ? [$p['conductor']] : ($p['conductores'] ?? []),
                'vehiculo_m1l' => in_array('SUNAT_Envio_IndicadorTrasladoVehiculoM1L', $p['indicadores'] ?? [], true),
                'items' => $p['items'], 'historial' => array_reverse($g->historial ?? []), 'ticket' => $g->ticket,
                'archivos' => array_keys($g->archivos ?? []), 'usuario' => $g->usuario?->name, 'intentos' => $g->intentos,
            ];
        }

        return $out;
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(['por_enviar', 'enviado', 'aceptado', 'rechazado', 'error'])]]);
        $this->proceso->actualizarPendientes($this->empresa->id()); // estados de SUNAT sin cola de trabajos
        $p = GuiaRemision::query()->with('comprobante')
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['q'] ?? null, function ($q, $t) {
                $q->where(function ($w) use ($t) {
                    if (preg_match('/^([A-Z0-9]{4})-(\d+)$/i', $t, $m)) {
                        $w->where(fn ($x) => $x->where('serie', strtoupper($m[1]))->where('correlativo', (int) $m[2]));
                    }
                    $w->orWhere('destinatario_num_doc', 'like', "$t%")->orWhere('destinatario_razon_social', 'like', "%$t%");
                });
            })
            ->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($g) => self::dto($g)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function show(GuiaRemision $guia): JsonResponse
    {
        $this->proceso->actualizarGuia($guia);

        return response()->json(['data' => self::dto($guia->refresh()->load(['comprobante', 'usuario:id,name']), true)]);
    }

    /** Datos para el formulario; con ?comprobante_id= precarga destinatario, llegada y bienes de la venta. */
    public function opciones(Request $request): JsonResponse
    {
        $e = $this->empresa->get();
        $series = Serie::query()->where('tipo_documento', '09')->where('activo', true)->whereNotNull('api_serie_id')->with('establecimiento:id,nombre,cod_local')
            ->orderByDesc('predeterminada')->orderBy('serie')->get()->map(fn ($s) => ['id' => $s->id, 'serie' => $s->serie, 'establecimiento_id' => $s->establecimiento_id,
                'establecimiento' => $s->establecimiento ? "{$s->establecimiento->nombre} ({$s->establecimiento->cod_local})" : null]);
        $establecimientos = Establecimiento::query()->orderBy('cod_local')->get(['id', 'nombre', 'cod_local', 'ubigeo', 'direccion']);

        $prefill = null;
        if ($id = $request->integer('comprobante_id')) {
            $c = Comprobante::query()->whereIn('tipo_documento', ['01', '03'])->findOrFail($id);
            $cliente = $c->venta?->cliente_id ? Cliente::query()->find($c->venta->cliente_id) : null;
            $items = DB::table('venta_detalles')->leftJoin('productos', 'productos.id', '=', 'venta_detalles.producto_id')
                ->where('venta_detalles.venta_id', $c->venta_id)->where(fn ($q) => $q->whereNull('productos.tipo')->orWhere('productos.tipo', 'bien'))
                ->get(['venta_detalles.codigo', 'venta_detalles.descripcion', 'venta_detalles.unidad', 'venta_detalles.cantidad'])
                ->map(fn ($i) => ['codigo' => $i->codigo, 'descripcion' => $i->descripcion, 'unidad' => $i->unidad, 'cantidad' => (float) $i->cantidad]);
            $prefill = [
                'comprobante' => ['id' => $c->id, 'numero' => $c->numero()],
                'destinatario' => ['tipo_doc' => $c->cliente_tipo_doc, 'num_doc' => $c->cliente_num_doc, 'razon_social' => $c->cliente_razon_social],
                'llegada' => ['ubigeo' => $cliente?->ubigeo ?? '', 'direccion' => $c->cliente_direccion ?? $cliente?->direccion ?? ''],
                'items' => $items,
            ];
        }

        return response()->json(['data' => [
            'series' => $series, 'establecimientos' => $establecimientos,
            'motivos' => collect(GuiaRemision::MOTIVOS)->map(fn ($l, $c) => ['codigo' => $c, 'label' => $l])->values(),
            'modalidades' => collect(GuiaRemision::MODALIDADES)->map(fn ($l, $c) => ['codigo' => $c, 'label' => $l])->values(),
            'prefill' => $prefill, 'empresa_ruc' => $e->ruc,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'serie_id' => ['required', 'integer'],
            'comprobante_id' => ['nullable', 'integer'],
            'fecha_traslado' => ['required', 'date_format:Y-m-d'],
            'cod_traslado' => ['required', Rule::in(array_keys(GuiaRemision::MOTIVOS))],
            'mod_traslado' => ['required', Rule::in(array_keys(GuiaRemision::MODALIDADES))],
            'peso_total' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'und_peso_total' => ['nullable', Rule::in(['KGM', 'TNE'])],
            'num_bultos' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'destinatario' => ['required_unless:cod_traslado,04', 'nullable', 'array'],
            'destinatario.tipo_doc' => ['required_unless:cod_traslado,04', Rule::in(['1', '4', '6', '7', 'A'])],
            'destinatario.num_doc' => ['required_unless:cod_traslado,04', 'string', 'max:15'],
            'destinatario.razon_social' => ['required_unless:cod_traslado,04', 'string', 'max:250'],
            'establecimiento_destino_id' => ['required_if:cod_traslado,04', 'nullable', 'integer'],
            'partida.ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'partida.direccion' => ['nullable', 'string', 'max:250'],
            'llegada.ubigeo' => ['required_unless:cod_traslado,04', 'nullable', 'regex:/^\d{6}$/'],
            'llegada.direccion' => ['required_unless:cod_traslado,04', 'nullable', 'string', 'min:5', 'max:250'],
            'fecha_entrega_transportista' => ['nullable', 'date_format:Y-m-d'],
            'transportista' => ['required_if:mod_traslado,01', 'nullable', 'array'],
            'transportista.num_doc' => ['required_if:mod_traslado,01', 'nullable', 'digits:11'],
            'transportista.razon_social' => ['required_if:mod_traslado,01', 'nullable', 'string', 'max:250'],
            'transportista.nro_mtc' => ['nullable', 'string', 'max:20'],
            'vehiculo_m1l' => ['boolean'],
            'vehiculo.placa' => ['nullable', 'regex:/^[A-Za-z0-9]{6,8}$/'],
            'vehiculo.secundarias' => ['nullable', 'array', 'max:2'],
            'vehiculo.secundarias.*' => ['regex:/^[A-Za-z0-9]{6,8}$/'],
            'conductores' => ['nullable', 'array', 'max:3'],
            'conductores.*.tipo_doc' => ['required', Rule::in(['1', '4', '7'])],
            'conductores.*.num_doc' => ['required', 'string', 'max:15'],
            'conductores.*.nombres' => ['required', 'string', 'max:100'],
            'conductores.*.apellidos' => ['required', 'string', 'max:100'],
            'conductores.*.licencia' => ['required', 'string', 'min:9', 'max:10'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.codigo' => ['nullable', 'string', 'max:30'],
            'items.*.descripcion' => ['required', 'string', 'max:250'],
            'items.*.unidad' => ['required', 'string', 'max:3'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'llegada.ubigeo.regex' => 'El ubigeo debe tener 6 dígitos.', 'partida.ubigeo.regex' => 'El ubigeo debe tener 6 dígitos.',
            'vehiculo.placa.regex' => 'La placa debe tener de 6 a 8 letras o números, sin guiones.',
        ], ['llegada.ubigeo' => 'ubigeo de llegada', 'llegada.direccion' => 'dirección de llegada', 'conductores.*.licencia' => 'licencia']);

        // Reglas de la modalidad (ejemplos GRR-01, GRR-02/05 y GRR-03).
        if ($d['mod_traslado'] === '01' && ($m = DocumentoIdentidad::errorRuc($d['transportista']['num_doc']))) {
            throw ValidationException::withMessages(['transportista.num_doc' => $m]);
        }
        if ($d['mod_traslado'] === '02' && empty($d['vehiculo_m1l'])) {
            if (empty($d['vehiculo']['placa'])) {
                throw ValidationException::withMessages(['vehiculo.placa' => 'Ingresa la placa del vehículo (o marca "vehículo M1/L").']);
            }
            if (empty($d['conductores'])) {
                throw ValidationException::withMessages(['conductores' => 'Registra al menos un conductor (o marca "vehículo M1/L").']);
            }
        }
        foreach ($d['conductores'] ?? [] as $i => $c) {
            if ($m = DocumentoIdentidad::error($c['tipo_doc'], $c['num_doc'])) {
                throw ValidationException::withMessages(["conductores.$i.num_doc" => $m]);
            }
        }
        if (! empty($d['destinatario']['tipo_doc']) && $d['cod_traslado'] !== '04' && ($m = DocumentoIdentidad::error($d['destinatario']['tipo_doc'], $d['destinatario']['num_doc']))) {
            throw ValidationException::withMessages(['destinatario.num_doc' => $m]);
        }

        $g = $this->svc->crear($this->empresa->get(), $request->user(), $d);

        return response()->json(['data' => self::dto($g->fresh(['comprobante']), true), 'message' => "Guía {$g->numero()} ".($g->fresh()->ultimo_error ? 'registrada; aún no se pudo enviar (se reintentará).' : 'enviada a SUNAT.')], 201);
    }

    /** Reenvía (error reenviable o falla de envío) o vuelve a consultar el estado. */
    public function reintentar(GuiaRemision $guia, Auditor $auditor): JsonResponse
    {
        if ($guia->esFinal()) {
            throw ValidationException::withMessages(['estado' => "La guía ya está {$guia->estado}."]);
        }
        if ($guia->estado === 'enviado') {
            $this->proceso->actualizarGuia($guia, forzar: true);
        } else {
            $guia->cambiarEstado('por_enviar', $guia->api_id ? 'Reenvío a SUNAT solicitado' : 'Reintento de envío solicitado', ['ultimo_error' => null, 'intentos' => 0]);
            $this->proceso->emitirGuia($guia);
        }
        $auditor->registrar('guia.reintento', $guia, [], ['numero' => $guia->numero()], $guia->empresa_id);

        return response()->json(['data' => self::dto($guia->fresh(['comprobante']), true), 'message' => 'Reintento realizado.']);
    }

    /** Archivos guardados; el PDF de una guía aún no aceptada se obtiene en el momento desde la API (vista previa). */
    public function archivo(GuiaRemision $guia, string $tipo): StreamedResponse|Response
    {
        $mime = ['xml' => 'application/xml', 'cdr' => 'application/zip', 'pdf' => 'application/pdf'][$tipo];
        if (! ($guia->archivos[$tipo] ?? null) && $guia->api_id && $guia->estado === 'aceptado') {
            $this->proceso->descargarArchivosGuia($guia, [$tipo]); // sin cola: se trae de la API en el momento
            $guia->refresh();
        }
        if ($a = ($guia->archivos[$tipo] ?? null)) {
            return Storage::disk('local')->download($a['path'], basename($a['path']), ['Content-Type' => $mime]);
        }
        if ($tipo === 'pdf' && $guia->api_id && ($res = $this->svc->obtenerArchivo($guia, 'pdf'))) {
            return response($res->raw, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$this->empresa->get()->ruc."-09-{$guia->serie}-{$guia->correlativo}.pdf\""]);
        }
        abort(404, $guia->estado === 'aceptado' ? 'El archivo aún no está disponible. Intenta en unos segundos.' : 'El XML y el CDR estarán disponibles cuando SUNAT acepte la guía.');
    }
}
