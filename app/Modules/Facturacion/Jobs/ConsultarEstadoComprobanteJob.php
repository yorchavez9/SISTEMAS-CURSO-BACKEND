<?php

namespace App\Modules\Facturacion\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comprobante;
use App\Modules\Facturacion\Services\EmisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Consulta el estado SUNAT con espera creciente (el webhook de la API no está documentado, H-7 del informe).
 * En la Etapa 1 SUNAT beta respondió en segundos; se insiste hasta ~1 hora.
 */
class ConsultarEstadoComprobanteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESPERAS = [5, 10, 20, 30, 60, 120, 300, 600, 900, 1800];

    public int $tries = 3;

    public function __construct(public readonly int $comprobanteId, public readonly int $intento = 1) {}

    public function handle(EmisionService $svc): void
    {
        $c = Comprobante::withoutGlobalScopes()->find($this->comprobanteId);
        if (! $c || ! $c->api_id || $c->esFinal()) {
            return;
        }
        try {
            $final = $svc->consultarEstado($c);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        } catch (SunatApiConnectionException) {
            $final = false;
        }

        if ($final) {
            if ($c->fresh()->estado === 'aceptado') {
                DescargarArchivosComprobanteJob::dispatch($c->id);
            }

            return;
        }
        if ($c->fresh()->estado === 'pendiente_resumen') {
            return; // no hay nada que consultar hasta que se envíe el resumen diario
        }
        $siguiente = self::ESPERAS[$this->intento] ?? null;
        if ($siguiente !== null) {
            self::dispatch($c->id, $this->intento + 1)->delay(now()->addSeconds($siguiente));
        }
    }
}
