<?php

namespace App\Modules\Facturacion\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comunicacion;
use App\Modules\Facturacion\Services\BajaService;
use App\Modules\Facturacion\Services\ResumenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Consulta el ticket SUNAT de la baja (GET /anulaciones|resumenes/{id}/estado) con espera creciente. */
class ConsultarComunicacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESPERAS = [10, 20, 30, 60, 120, 300, 600, 900, 1800];

    public int $tries = 3;

    public function __construct(public readonly int $comunicacionId, public readonly int $intento = 1) {}

    public function handle(BajaService $svc, ResumenService $resumenes): void
    {
        $c = Comunicacion::withoutGlobalScopes()->find($this->comunicacionId);
        if (! $c || ! $c->api_id || $c->esFinal()) {
            return;
        }
        try {
            $final = $c->accion === 'envio' ? $resumenes->consultar($c) : $svc->consultar($c);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        } catch (SunatApiConnectionException) {
            $final = false;
        }
        if (! $final && ($espera = self::ESPERAS[$this->intento] ?? null) !== null) {
            self::dispatch($c->id, $this->intento + 1)->delay(now()->addSeconds($espera));
        }
    }
}
