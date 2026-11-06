<?php

namespace App\Modules\Facturacion\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comunicacion;
use App\Modules\Facturacion\Services\BajaService;
use App\Modules\Facturacion\Services\ResumenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Envía una comunicación de baja (RA), un resumen de anulación (RC) o un resumen diario de envío de boletas (RC). */
class EnviarComunicacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public readonly int $comunicacionId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900, 1800];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("comunicacion:{$this->comunicacionId}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(BajaService $svc, ResumenService $resumenes): void
    {
        $c = Comunicacion::withoutGlobalScopes()->find($this->comunicacionId);
        if (! $c || $c->esFinal() || $c->estado === 'error') {
            return;
        }
        try {
            $pendiente = $c->accion === 'envio' ? $resumenes->enviar($c) : $svc->enviar($c);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        }
        if ($pendiente) {
            ConsultarComunicacionJob::dispatch($c->id, 1)->delay(now()->addSeconds(10));
        }
    }

    public function failed(\Throwable $e): void
    {
        Comunicacion::withoutGlobalScopes()->whereKey($this->comunicacionId)->whereNull('api_id')
            ->update(['estado' => 'error', 'ultimo_error' => mb_substr('No se pudo enviar: '.$e->getMessage(), 0, 1000)]);
    }
}
