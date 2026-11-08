<?php

namespace App\Integrations\SunatApi;

use App\Integrations\SunatApi\Exceptions\SunatApiConnectionException;
use App\Integrations\SunatApi\Exceptions\SunatApiRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Único punto de salida HTTP hacia la API de facturación.
 * - Autenticación según la colección Postman: headers X-Api-Key y X-Api-Secret.
 * - Respeta el límite de 30 req/min por cuenta (presupuesto local + reintento ante HTTP 429).
 * - No registra ni expone credenciales.
 */
class SunatApiClient
{
    public const MAX_REINTENTOS_429 = 3;

    private readonly string $cuenta;

    public function __construct(
        private readonly string $apiKey,
        #[\SensitiveParameter] private readonly string $apiSecret,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 15,
        ?string $cuenta = null,
        private readonly ?LimitadorSolicitudes $limitador = null,
    ) {
        // Identificador del presupuesto de solicitudes: por empresa, o por API key (hash) si no se indica.
        $this->cuenta = $cuenta ?? 'key:'.substr(hash('sha256', $apiKey), 0, 16);
    }

    /** @param  string|null  $baseUrl  URL base guardada en las credenciales de la empresa; null = la del .env */
    public static function make(string $apiKey, #[\SensitiveParameter] string $apiSecret, ?string $cuenta = null, ?string $baseUrl = null): self
    {
        return new self(
            $apiKey,
            $apiSecret,
            rtrim($baseUrl ?: (string) config('services.sunat_api.base_url'), '/'),
            (int) config('services.sunat_api.timeout'),
            (int) config('services.sunat_api.connect_timeout'),
            $cuenta,
            LimitadorSolicitudes::desdeConfig(),
        );
    }

    public function get(string $path, array $query = []): SunatApiResponse
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $body = []): SunatApiResponse
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    public function put(string $path, array $body = []): SunatApiResponse
    {
        return $this->send('PUT', $path, ['json' => $body]);
    }

    public function delete(string $path): SunatApiResponse
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * POST multipart/form-data (logo, certificado).
     *
     * @param  list<array{name: string, contents: string, filename?: string}>  $partes
     */
    public function postMultipart(string $path, array $partes): SunatApiResponse
    {
        return $this->send('POST', $path, ['multipart' => $partes], multipart: true);
    }

    private function request(bool $multipart = false): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl);
        if ($multipart) {
            $req->asMultipart();
        }

        return $req
            ->acceptJson()
            ->withHeaders(['X-Api-Key' => $this->apiKey, 'X-Api-Secret' => $this->apiSecret])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    private function send(string $method, string $path, array $options, bool $multipart = false): SunatApiResponse
    {
        for ($intento = 0; ; $intento++) {
            if ($this->limitador && ! $this->limitador->reservar($this->cuenta)) {
                throw new SunatApiRateLimitException('Límite de solicitudes a la API de facturación agotado. Se reintentará.', 60);
            }

            $started = microtime(true);
            try {
                $response = $this->request($multipart)->send($method, ltrim($path, '/'), $options);
            } catch (ConnectionException $e) {
                throw new SunatApiConnectionException('No se pudo conectar con la API de facturación: '.$e->getMessage(), previous: $e);
            }
            $res = SunatApiResponse::fromHttp($response, (int) round((microtime(true) - $started) * 1000));

            // Un 429 se rechaza antes de procesar: reintentar (incluso un POST) no duplica documentos.
            if ($res->status !== 429) {
                return $res;
            }
            $espera = max(5, min(90, (int) ($response->header('Retry-After') ?: 20)));
            if ($intento >= self::MAX_REINTENTOS_429) {
                throw new SunatApiRateLimitException('La API de facturación rechazó por exceso de solicitudes (HTTP 429).', $espera);
            }
            Sleep::for($espera)->seconds();
        }
    }
}
