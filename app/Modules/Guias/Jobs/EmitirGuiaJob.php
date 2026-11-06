<?php

namespace App\Modules\Guias\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\GuiaRemision;
use App\Modules\Guias\Services\GuiaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Envía (o reenvía) una guía de remisión a la API. */
class EmitirGuiaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public readonly int $guiaId) {}

    public function backoff(): array
    {
        return [15, 30, 60, 120, 300, 600, 1200];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("guia:{$this->guiaId}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(GuiaService $svc): void
    {
        $g = GuiaRemision::withoutGlobalScopes()->find($this->guiaId);
        if (! $g || $g->esFinal() || $g->estado !== 'por_enviar') {
            return;
        }
        try {
            $r = $svc->emitir($g);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        }
        if ($r === GuiaService::RESULTADO_RENUMERADO) {
            self::dispatch($g->id);
        } elseif ($r === GuiaService::RESULTADO_ENVIADO) {
            ConsultarGuiaJob::dispatch($g->id, 1)->delay(now()->addSeconds(5));
        } elseif ($r === GuiaService::RESULTADO_FINAL && $g->fresh()->estado === 'aceptado') {
            DescargarArchivosGuiaJob::dispatch($g->id);
        }
    }

    public function failed(\Throwable $e): void
    {
        $g = GuiaRemision::withoutGlobalScopes()->find($this->guiaId);
        if ($g && ! $g->esFinal() && $g->estado === 'por_enviar') {
            $g->cambiarEstado('error', 'No se pudo enviar tras varios intentos: '.$e->getMessage(), ['ultimo_error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
