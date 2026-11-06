<?php

namespace App\Modules\Empresas\Http\Resources;

use App\Models\EmpresaApiCredencial;

/**
 * Representación SEGURA de las credenciales: nunca incluye api_key ni api_secret,
 * solo los últimos 4 caracteres del API Key y el resultado de la verificación.
 */
class CredencialApiResource
{
    public static function make(string $entorno, ?EmpresaApiCredencial $c): array
    {
        return [
            'entorno' => $entorno,
            'configurada' => $c !== null,
            'api_key_mascara' => $c ? '••••••••'.$c->api_key_ultimos4 : null,
            'api_secret_configurado' => $c !== null,
            'verificado_at' => $c?->verificado_at?->toIso8601String(),
            'verificacion_ok' => $c?->verificacion_ok,
            'verificacion_mensaje' => $c?->verificacion_mensaje,
            'remoto' => $c?->remoto,
            'updated_at' => $c?->updated_at?->toIso8601String(),
        ];
    }
}
