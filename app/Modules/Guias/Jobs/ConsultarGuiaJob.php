<?php

namespace App\Modules\Guias\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\GuiaRemision;
use App\Modules\Guias\Services\GuiaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Consulta el estado SUNAT de la guía (GET /guias-remision/{id}/estado) con espera creciente. */
class ConsultarGuiaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESPERAS = [5, 10, 20, 30, 60, 120, 300, 600, 900, 1800];

    public int $tries = 3;

    public function __construct(public readonly int $guiaId, public readonly int $intento = 1) {}

    public function handle(GuiaService $svc): void
    {
        $g = GuiaRemision::withoutGlobalScopes()->find($this->guiaId);
        if (! $g || ! $g->api_id || $g->esFinal() || $g->estado !== 'enviado') {
            return;
        }
        try {
            $terminado = $svc->consultar($g);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        } catch (SunatApiConnectionException) {
            $terminado = false;
        }
        if ($terminado) {
            if ($g->fresh()->estado === 'aceptado') {
                DescargarArchivosGuiaJob::dispatch($g->id);
            }

            return;
        }
        if (($espera = self::ESPERAS[$this->intento] ?? null) !== null) {
            self::dispatch($g->id, $this->intento + 1)->delay(now()->addSeconds($espera));
        }
    }
}
