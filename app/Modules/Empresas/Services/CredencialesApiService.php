<?php

namespace App\Modules\Empresas\Services;

use App\Core\Auditoria\Auditor;
use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\SunatApiClient;
use App\Models\Empresa;
use App\Models\EmpresaApiCredencial;
use Illuminate\Support\Facades\DB;

class CredencialesApiService
{
    /** Entorno remoto (atributo "entorno" de la empresa en la API) esperado para cada entorno del sistema. */
    private const ENTORNO_REMOTO_ESPERADO = ['pruebas' => 'beta', 'produccion' => 'production'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Guarda (cifradas) las credenciales. Si $apiSecret es null se conserva el secreto existente.
     */
    /**
     * @param  string|null  $apiKey  null = conservar el guardado (solo al actualizar)
     * @param  string|false|null  $baseUrl  false = no se envió (sin cambios); null o '' = usar la del .env
     */
    public function guardar(Empresa $empresa, string $entorno, ?string $apiKey, #[\SensitiveParameter] ?string $apiSecret, ?int $userId, string|false|null $baseUrl = false): EmpresaApiCredencial
    {
        return DB::transaction(function () use ($empresa, $entorno, $apiKey, $apiSecret, $userId, $baseUrl) {
            $cred = EmpresaApiCredencial::query()
                ->where('empresa_id', $empresa->id)->where('entorno', $entorno)->lockForUpdate()->first();
            $nueva = $cred === null;

            if ($nueva && $apiSecret === null) {
                throw new \InvalidArgumentException('El API Secret es obligatorio al registrar credenciales.');
            }
            if ($nueva && $apiKey === null) {
                throw new \InvalidArgumentException('El API Key es obligatorio al registrar credenciales.');
            }
            $apiKey ??= $cred->api_key;

            $cred ??= new EmpresaApiCredencial(['empresa_id' => $empresa->id, 'entorno' => $entorno]);
            $cambioKey = $nueva || $cred->api_key !== $apiKey;
            $cambioSecret = $apiSecret !== null && ($nueva || $cred->api_secret !== $apiSecret);
            $nuevaUrl = $baseUrl === false ? $cred->base_url : (rtrim((string) $baseUrl, '/') ?: null);
            $cambioUrl = $nuevaUrl !== $cred->base_url;
            $cred->base_url = $nuevaUrl;

            $cred->api_key = $apiKey;
            if ($apiSecret !== null) {
                $cred->api_secret = $apiSecret;
            }
            $cred->api_key_ultimos4 = mb_substr($apiKey, -4);
            $cred->updated_by = $userId;
            if ($cambioKey || $cambioSecret || $cambioUrl) {
                // Credenciales nuevas: la verificación anterior deja de ser válida.
                $cred->fill(['verificado_at' => null, 'verificacion_ok' => null, 'verificacion_mensaje' => null, 'remoto' => null]);
            }
            $cred->save();

            $this->auditor->registrar(
                $nueva ? 'credenciales_api.creadas' : 'credenciales_api.actualizadas',
                $cred,
                [],
                ['entorno' => $entorno, 'api_key' => '***'.$cred->api_key_ultimos4, 'api_secret_cambiado' => $cambioSecret, 'base_url' => $cred->base_url],
                $empresa->id,
            );

            return $cred;
        });
    }

    /**
     * Prueba las credenciales contra la API (solo lectura: GET /empresa, /suscripcion, /suscripcion/uso)
     * y valida que pertenezcan al mismo RUC y al entorno remoto correcto.
     */
    public function verificar(Empresa $empresa, EmpresaApiCredencial $cred): EmpresaApiCredencial
    {
        $client = SunatApiClient::make($cred->api_key, $cred->api_secret, "empresa:{$empresa->id}:{$cred->entorno}", $cred->base_url);
        $ok = false;
        $remoto = null;

        try {
            $emp = $client->get('/empresa');
            if ($emp->status === 401 || $emp->status === 403) {
                $mensaje = 'Credenciales rechazadas por la API: '.($emp->mensaje() ?? "HTTP {$emp->status}");
            } elseif (! $emp->ok()) {
                $mensaje = 'La API respondió con error: '.($emp->mensaje() ?? "HTTP {$emp->status}");
            } else {
                $susc = $client->get('/suscripcion');
                $uso = $client->get('/suscripcion/uso');
                $remoto = [
                    'ruc' => (string) $emp->buscar('ruc'),
                    'razon_social' => $emp->buscar('razon_social'),
                    'entorno' => $emp->buscar('entorno', 'environment', 'ambiente'),
                    'tax_regime' => $emp->buscar('tax_regime', 'regimen'),
                    'tasa_igv_vigente' => $emp->buscar('tasa_igv_vigente'),
                    'plan' => $susc->ok() ? $susc->buscar('plan_slug', 'slug', 'plan', 'nombre') : null,
                    'uso' => $uso->ok() && is_array($uso->datos()) ? $uso->datos() : null,
                ];
                [$ok, $mensaje] = $this->evaluar($empresa, $cred->entorno, $remoto);
            }
        } catch (SunatApiConnectionException $e) {
            $mensaje = $e->getMessage();
        }

        $cred->forceFill([
            'verificado_at' => now(),
            'verificacion_ok' => $ok,
            'verificacion_mensaje' => mb_substr($mensaje, 0, 500),
            'remoto' => $remoto,
        ])->save();

        $this->auditor->registrar('credenciales_api.verificadas', $cred, [], ['entorno' => $cred->entorno, 'ok' => $ok, 'mensaje' => $mensaje], $empresa->id);

        return $cred;
    }

    /** @return array{0: bool, 1: string} */
    private function evaluar(Empresa $empresa, string $entorno, array $remoto): array
    {
        if ($remoto['ruc'] !== '' && $remoto['ruc'] !== $empresa->ruc) {
            return [false, "Las credenciales pertenecen al RUC {$remoto['ruc']}, no a {$empresa->ruc}."];
        }
        $esperado = self::ENTORNO_REMOTO_ESPERADO[$entorno];
        $actual = is_string($remoto['entorno']) ? strtolower($remoto['entorno']) : null;
        if ($actual === null) {
            return [true, 'Conexión correcta. Advertencia: la API no informó el entorno (beta/production); verifícalo antes de emitir.'];
        }
        if ($actual !== $esperado) {
            return [false, "Estas credenciales están en entorno '{$actual}' de la API, pero se registraron como '{$entorno}' (se esperaba '{$esperado}')."];
        }

        return [true, "Conexión correcta. Entorno remoto '{$actual}'."];
    }

    public function eliminar(Empresa $empresa, EmpresaApiCredencial $cred): void
    {
        $this->auditor->registrar('credenciales_api.eliminadas', $cred, ['entorno' => $cred->entorno, 'api_key' => '***'.$cred->api_key_ultimos4], [], $empresa->id);
        $cred->delete();
    }
}
