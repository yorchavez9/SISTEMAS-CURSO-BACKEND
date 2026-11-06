<?php

namespace App\Modules\Guias\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\GuiaRemision;
use App\Modules\Guias\Services\GuiaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Guarda XML, CDR y PDF de una guía aceptada. */
class DescargarArchivosGuiaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $guiaId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(GuiaService $svc): void
    {
        $g = GuiaRemision::withoutGlobalScopes()->find($this->guiaId);
        if (! $g || ! $g->api_id) {
            return;
        }
        $faltan = array_values(array_diff(['xml', 'cdr', 'pdf'], array_keys($g->archivos ?? [])));
        if (! $faltan) {
            return;
        }
        try {
            $guardados = $svc->descargarArchivos($g, $faltan);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        }
        if (array_diff($faltan, $guardados)) {
            $this->release(120);
        }
    }
}
