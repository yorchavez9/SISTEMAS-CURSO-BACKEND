<?php

namespace App\Console\Commands;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Modules\Facturacion\Services\ResumenService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Envía el resumen diario de las boletas pendientes de días anteriores (empresas en modo "resumen").
 * Programado cada hora: si una fecha ya tiene su RC activo, se omite.
 */
class EnviarResumenesBoletas extends Command
{
    protected $signature = 'sunat:enviar-resumenes {--empresa= : ID de una empresa concreta}';

    protected $description = 'Genera y envía el resumen diario (RC) de boletas pendientes de fechas anteriores a hoy';

    public function handle(ResumenService $svc): int
    {
        $hoy = Carbon::now('America/Lima')->toDateString();
        $empresas = Empresa::query()->where('activo', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($empresas as $empresa) {
            $fechas = Comprobante::deEmpresa($empresa->id)->where('envio_por_resumen', true)->where('estado', 'pendiente_resumen')
                ->whereDate('fecha_emision', '<', $hoy)->distinct()->orderBy('fecha_emision')->pluck('fecha_emision');
            foreach ($fechas as $f) {
                $fecha = Carbon::parse($f)->toDateString();
                // Responsable: quien registró la primera venta del día (queda en la auditoría como origen automático).
                $userId = Comprobante::deEmpresa($empresa->id)->where('envio_por_resumen', true)->whereDate('fecha_emision', $fecha)
                    ->join('ventas', 'ventas.id', '=', 'comprobantes.venta_id')->orderBy('comprobantes.id')->value('ventas.user_id');
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }
                try {
                    $c = $svc->solicitar($empresa, $user, $fecha);
                    $this->info("{$empresa->ruc} $fecha: resumen #{$c->id} enviado ({$c->items()->count()} boletas).{$c->fresh()->ultimo_error}");
                } catch (ValidationException $e) {
                    $this->line("{$empresa->ruc} $fecha: ".collect($e->errors())->flatten()->first());
                }
            }
        }

        return self::SUCCESS;
    }
}
