<?php

namespace App\Modules\Facturacion\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comprobante;
use App\Modules\Facturacion\Services\EmisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Envía un comprobante a la API. Reintentos con espera creciente ante fallas de red o 5xx. */
class EmitirComprobanteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public readonly int $comprobanteId) {}

    /** @return list<int> segundos entre reintentos */
    public function backoff(): array
    {
        return [15, 30, 60, 120, 300, 600, 1200];
    }

    public function middleware(): array
    {
        // Nunca dos envíos simultáneos del mismo comprobante.
        return [(new WithoutOverlapping("comprobante:{$this->comprobanteId}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(EmisionService $svc): void
    {
        $c = Comprobante::withoutGlobalScopes()->find($this->comprobanteId);
        if (! $c || $c->esFinal() || $c->estado === 'error') {
            return;
        }
        try {
            $r = $svc->emitir($c);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        }
        if ($r === EmisionService::RESULTADO_RENUMERADO) {
            self::dispatch($c->id); // reintento inmediato con el nuevo número

            return;
        }
        if ($r === EmisionService::RESULTADO_ENVIADO && $c->fresh()->estado === 'pendiente_resumen') {
            return; // registrada en la API; su estado SUNAT llegará con el resumen diario
        }
        if ($r === EmisionService::RESULTADO_ENVIADO) {
            ConsultarEstadoComprobanteJob::dispatch($c->id, 1)->delay(now()->addSeconds(5));
        } elseif ($r === EmisionService::RESULTADO_FINAL && $c->fresh()->estado === 'aceptado') {
            DescargarArchivosComprobanteJob::dispatch($c->id);
        }
    }

    public function failed(\Throwable $e): void
    {
        $c = Comprobante::withoutGlobalScopes()->find($this->comprobanteId);
        if ($c && ! $c->api_id && ! $c->esFinal()) {
            $c->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.$e->getMessage(), ['ultimo_error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
