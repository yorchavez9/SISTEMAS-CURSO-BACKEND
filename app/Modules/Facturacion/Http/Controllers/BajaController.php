<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClientFactory;
use App\Models\ApiSolicitud;
use App\Models\Comunicacion;
use App\Models\Empresa;
use App\Modules\Facturacion\Jobs\ConsultarComunicacionJob;
use App\Modules\Facturacion\Jobs\EnviarComunicacionJob;
use App\Modules\Facturacion\Services\BajaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BajaController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly BajaService $svc) {}

    public static function dto(Comunicacion $c): array
    {
        $c->loadMissing(['items.comprobante', 'usuario:id,name']);

        return [
            'id' => $c->id, 'tipo' => $c->tipo, 'accion' => $c->accion,
            'tipo_label' => $c->tipo === 'RA' ? 'Comunicación de baja' : ($c->accion === 'envio' ? 'Resumen diario de envío' : 'Resumen diario de anulación'),
            'documentos_externos' => (int) $c->documentos_externos,
            'fecha_documentos' => $c->fecha_documentos->toDateString(), 'motivo' => $c->motivo, 'estado' => $c->estado,
            'identifier' => $c->identifier, 'ticket' => $c->ticket, 'sunat_codigo' => $c->sunat_codigo, 'sunat_descripcion' => $c->sunat_descripcion,
            'ultimo_error' => $c->ultimo_error, 'tiene_archivos' => (bool) $c->api_id, 'usuario' => $c->usuario?->name, 'creado' => $c->created_at?->toIso8601String(),
            'comprobantes' => $c->items->map(fn ($i) => ['id' => $i->comprobante_id, 'numero' => $i->comprobante?->numero(), 'total' => (float) $i->comprobante?->total, 'estado' => $i->comprobante?->estado]),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $accion = $request->validate(['accion' => ['sometimes', 'in:anulacion,envio']])['accion'] ?? 'anulacion';
        $p = Comunicacion::query()->where('accion', $accion)->with(['items.comprobante', 'usuario:id,name'])->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $p->getCollection()->map(fn ($c) => self::dto($c)),
            'meta' => ['pagina' => $p->currentPage(), 'ultima_pagina' => $p->lastPage(), 'total' => $p->total(), 'por_pagina' => $p->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'comprobante_ids' => ['required', 'array', 'min:1', 'max:100'],
            'comprobante_ids.*' => ['integer'],
            'motivo' => ['required', 'string', 'min:5', 'max:100'],
        ], [], ['comprobante_ids' => 'comprobantes']);
        $c = $this->svc->solicitar($this->empresa->get(), $request->user(), $d['comprobante_ids'], $d['motivo']);

        return response()->json(['data' => self::dto($c), 'message' => ($c->tipo === 'RA' ? 'Comunicación de baja' : 'Resumen de anulación').' en cola de envío a SUNAT.'], 201);
    }

    public function reintentar(Comunicacion $comunicacion): JsonResponse
    {
        if ($comunicacion->esFinal()) {
            throw ValidationException::withMessages(['estado' => "La comunicación ya está {$comunicacion->estado}."]);
        }
        if ($comunicacion->accion === 'envio' && $comunicacion->estado === 'error' && ! $comunicacion->api_id) {
            // El RC no llegó a crearse: se descarta y se genera uno nuevo con las boletas aún pendientes.
            throw ValidationException::withMessages(['estado' => 'Este resumen no llegó a crearse en la API. Genera uno nuevo desde "Resumen diario".']);
        }
        if ($comunicacion->accion === 'envio' && $comunicacion->api_id && $comunicacion->estado === 'por_enviar') {
            EnviarComunicacionJob::dispatch($comunicacion->id); // creado en la API pero no enviado a SUNAT
        } elseif ($comunicacion->api_id) {
            ConsultarComunicacionJob::dispatch($comunicacion->id, 1);
        } else {
            $comunicacion->update(['estado' => 'por_enviar', 'ultimo_error' => null]);
            EnviarComunicacionJob::dispatch($comunicacion->id);
        }

        return response()->json(['data' => self::dto($comunicacion->fresh()), 'message' => 'Reintento en cola.']);
    }

    /**
     * XML o CDR de un resumen diario (RC) o comunicación de baja (RA), tal como lo devuelve la API:
     * GET /resumenes|/anulaciones/{id}/xml (application/xml) y /cdr (application/zip). Una vez descargado
     * se guarda en almacenamiento privado; el CDR solo se guarda cuando SUNAT ya respondió (estado final).
     */
    public function archivo(Comunicacion $comunicacion, string $tipo, SunatApiClientFactory $factory): Response
    {
        abort_unless($comunicacion->api_id, 404, 'Esta comunicación aún no se ha creado en la API.');
        $empresa = Empresa::findOrFail($comunicacion->empresa_id);
        $nombre = $empresa->ruc.'-'.($comunicacion->identifier ?? "{$comunicacion->tipo}-{$comunicacion->id}");
        $nombre = $tipo === 'cdr' ? "R-$nombre.zip" : "$nombre.xml";
        $ruta = "comunicaciones/{$empresa->id}/$nombre";
        $mime = $tipo === 'cdr' ? 'application/zip' : 'application/xml';

        if (! Storage::disk('local')->exists($ruta)) {
            $endpoint = '/'.($comunicacion->tipo === 'RA' ? 'anulaciones' : 'resumenes')."/{$comunicacion->api_id}/$tipo";
            try {
                $res = $factory->paraEmpresa($empresa)->get($endpoint);
            } catch (SunatApiConnectionException $e) {
                abort(503, 'No se pudo conectar con la API: '.$e->getMessage());
            }
            ApiSolicitud::create([
                'empresa_id' => $empresa->id, 'comprobante_id' => null, 'operacion' => "comunicacion_descargar_$tipo", 'metodo' => 'GET',
                'endpoint' => $endpoint, 'http_status' => $res->status, 'duracion_ms' => $res->durationMs,
                'respuesta' => str_contains($res->contentType, 'json') ? $res->json : null,
            ]);
            if ($res->status !== 200 || $res->raw === '' || str_contains($res->contentType, 'json')) {
                abort(404, $res->mensaje() ?? ($tipo === 'cdr' ? 'El CDR aún no está disponible.' : 'El XML no está disponible.'));
            }
            if ($tipo === 'xml' || $comunicacion->esFinal()) {
                Storage::disk('local')->put($ruta, $res->raw);
            } else {
                return response($res->raw, 200, ['Content-Type' => $mime, 'Content-Disposition' => "attachment; filename=\"$nombre\""]);
            }
        }

        return Storage::disk('local')->download($ruta, $nombre, ['Content-Type' => $mime]);
    }
}
