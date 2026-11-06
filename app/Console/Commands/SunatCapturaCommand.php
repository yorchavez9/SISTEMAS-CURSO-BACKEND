<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Etapa 1 — ejecuta tools/api-capture/capture.php con las credenciales de PRUEBAS de una empresa,
 * leídas (y descifradas) desde la base de datos. Las credenciales viajan solo como variables de
 * entorno del proceso hijo: no se escriben en disco ni aparecen en la línea de comandos.
 */
class SunatCapturaCommand extends Command
{
    protected $signature = 'sunat:captura
        {accion : list | dry-run | preflight | setup-series | run | report}
        {--empresa= : ID o RUC de la empresa}
        {--confirm : Confirma operaciones que emiten o crean series}
        {--phases= : Fases de run (R,A,B,C,D,E)}
        {--only= : Escenarios concretos (F-01,B-02...)}
        {--optional : Incluye GRT, retenciones, percepciones, reversiones, liquidación}
        {--regimen : Incluye B-04/B-05 (cambia y restaura el régimen de la empresa en la API)}';

    protected $description = 'Captura real de la API SUNAT (Etapa 1) usando las credenciales de pruebas guardadas en BD';

    public function handle(): int
    {
        $accion = (string) $this->argument('accion');
        $acciones = ['list', 'dry-run', 'preflight', 'setup-series', 'run', 'report'];
        if (! in_array($accion, $acciones, true)) {
            $this->error('Acción inválida. Usa: '.implode(', ', $acciones));

            return self::FAILURE;
        }

        $env = ['SUNAT_API_BASE_URL' => (string) config('services.sunat_api.base_url')];

        if (in_array($accion, ['preflight', 'setup-series', 'run'], true)) {
            $empresa = $this->resolverEmpresa();
            if (! $empresa) {
                return self::FAILURE;
            }
            $cred = $empresa->credencialApi('pruebas');
            if (! $cred) {
                $this->error("La empresa {$empresa->ruc} no tiene credenciales de PRUEBAS. Regístralas en Configuración → Credenciales API.");

                return self::FAILURE;
            }
            if ($cred->verificacion_ok !== true) {
                $this->error('Las credenciales de pruebas no están verificadas (usa "Probar conexión" en Configuración). Estado: '.($cred->verificacion_mensaje ?? 'sin verificar'));

                return self::FAILURE;
            }
            $this->line("Empresa: {$empresa->ruc} — {$empresa->razon_social} · credenciales de pruebas ••••{$cred->api_key_ultimos4}");
            $env['SUNAT_API_KEY'] = $cred->api_key;
            $env['SUNAT_API_SECRET'] = $cred->api_secret;
        }

        $args = [PHP_BINARY, base_path('../tools/api-capture/capture.php'), $accion];
        foreach (['confirm', 'optional', 'regimen'] as $flag) {
            if ($this->option($flag)) {
                $args[] = "--$flag";
            }
        }
        foreach (['phases', 'only'] as $opt) {
            if ($this->option($opt)) {
                $args[] = "--$opt=".$this->option($opt);
            }
        }

        $process = new Process($args, base_path('../tools/api-capture'), $env, null, null);
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        return $process->getExitCode() ?? self::FAILURE;
    }

    private function resolverEmpresa(): ?Empresa
    {
        $ref = $this->option('empresa');
        $empresa = $ref
            ? Empresa::where('id', $ref)->orWhere('ruc', $ref)->first()
            : (Empresa::count() === 1 ? Empresa::first() : null);
        if (! $empresa) {
            $this->error($ref ? "No existe la empresa '$ref'." : 'Indica --empresa=<id|ruc>.');
        }

        return $empresa;
    }
}
