<?php

namespace App\Integrations\SunatApi;

use App\Integrations\SunatApi\Exceptions\CredencialesNoConfiguradasException;
use App\Models\Empresa;

/** Resuelve las credenciales (cifradas en BD) de una empresa y construye el cliente. */
class SunatApiClientFactory
{
    public function paraEmpresa(Empresa $empresa, ?string $entorno = null): SunatApiClient
    {
        $entorno ??= $empresa->entorno_activo;
        $cred = $empresa->credencialApi($entorno);
        if (! $cred) {
            throw new CredencialesNoConfiguradasException("La empresa {$empresa->ruc} no tiene credenciales API para el entorno '$entorno'.");
        }

        return SunatApiClient::make($cred->api_key, $cred->api_secret, "empresa:{$empresa->id}:{$entorno}", $cred->base_url);
    }
}
