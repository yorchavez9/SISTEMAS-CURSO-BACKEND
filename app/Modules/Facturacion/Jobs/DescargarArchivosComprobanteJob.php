<?php

namespace App\Modules\Facturacion\Jobs;

use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use App\Models\Comprobante;
use App\Modules\Facturacion\Services\EmisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Guarda XML, CDR y PDF del comprobante aceptado en el almacenamiento privado. */
class DescargarArchivosComprobanteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $comprobanteId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(EmisionService $svc): void
    {
        $c = Comprobante::withoutGlobalScopes()->find($this->comprobanteId);
        if (! $c || ! $c->api_id) {
            return;
        }
        $faltan = array_values(array_diff(['xml', 'cdr', 'pdf'], $c->archivos()->pluck('tipo')->all()));
        if (! $faltan) {
            return;
        }
        try {
            $guardados = $svc->descargarArchivos($c, $faltan);
        } catch (SunatApiRateLimitException $e) {
            $this->release($e->reintentarEnSeg);

            return;
        }
        if (count($guardados) < count($faltan)) {
            $this->release(120); // p. ej. el CDR aún no está disponible
        }
    }
}
