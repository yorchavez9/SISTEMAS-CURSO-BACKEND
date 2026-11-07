<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Core\Tenancy\EmpresaActual;
use App\Http\Controllers\Controller;
use App\Modules\Facturacion\Services\ResumenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resumen diario de envío de boletas (modo "resumen" de la empresa). */
class ResumenController extends Controller
{
    public function __construct(private readonly EmpresaActual $empresa, private readonly ResumenService $svc) {}

    public function pendientes(): JsonResponse
    {
        $e = $this->empresa->get();

        return response()->json(['data' => ['modo' => $e->envio_boletas, 'fechas' => $this->svc->pendientes($e)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d']]);
        $c = $this->svc->solicitar($this->empresa->get(), $request->user(), $d['fecha'], permitirHoy: true);

        $c->refresh();

        return response()->json(['data' => BajaController::dto($c), 'message' => "Resumen diario del {$d['fecha']} "
            .($c->ultimo_error ? 'registrado; aún no se pudo enviar (se reintentará).' : 'enviado a SUNAT.')], 201);
    }
}
