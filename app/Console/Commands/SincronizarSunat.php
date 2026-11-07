<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Modules\Facturacion\Services\ProcesoSunat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Sincronización en segundo plano con la API de facturación (reemplaza a la cola de trabajos):
 * reenvía lo que no llegó a la API, consulta el estado de lo que espera respuesta de SUNAT y guarda
 * localmente XML/CDR/PDF de lo aceptado. Lo que el usuario está viendo ya se actualiza al consultarlo;
 * esto cubre lo que nadie abre. Programado cada minuto (cron `schedule:run`).
 */
class SincronizarSunat extends Command
{
    protected $signature = 'sunat:sincronizar {--empresa= : ID de una empresa concreta} {--max=10 : Documentos por tipo y empresa}';

    protected $description = 'Envía pendientes, consulta estados de SUNAT y descarga XML/CDR/PDF (sin cola de trabajos)';

    public function handle(ProcesoSunat $proceso): int
    {
        $empresas = Empresa::query()->where('activo', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))->pluck('id');
        foreach ($empresas as $id) {
            Cache::forget("sunat:pendientes:$id"); // el comando no espera la pausa de las pantallas
            $proceso->actualizarPendientes($id, (int) $this->option('max'), conArchivos: true);
        }
        $this->info('Empresas sincronizadas: '.$empresas->count());

        return self::SUCCESS;
    }
}
