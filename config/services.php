<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | API de facturación electrónica SUNAT (kodevo).
    | Solo la URL y el timeout viven aquí: las credenciales (X-Api-Key / X-Api-Secret)
    | se ingresan desde Configuración → Credenciales API y se guardan cifradas por empresa.
    */
    'sunat_api' => [
        'base_url' => env('SUNAT_API_BASE_URL', 'https://sunat.kodevo.es/api/v1'),
        'timeout' => (int) env('SUNAT_API_TIMEOUT', 60),
        'connect_timeout' => (int) env('SUNAT_API_CONNECT_TIMEOUT', 15),
        // La API permite 30 req/min por cuenta (Etapa 1, H-2). Se deja margen.
        'rate_limit_por_minuto' => (int) env('SUNAT_API_RATE_LIMIT', 25),
        // Sin cola de trabajos las llamadas ocurren dentro de la solicitud del usuario: se espera poco por un turno
        // y, si no hay, el documento queda pendiente y se reintenta al consultarlo o con sunat:sincronizar.
        'rate_limit_espera_max' => (int) env('SUNAT_API_RATE_WAIT_MAX', 5),
        // Enviar a la API en la misma solicitud al registrar (venta, nota, baja, resumen, guía). Las pruebas lo apagan.
        'envio_inmediato' => (bool) env('SUNAT_ENVIO_INMEDIATO', true),
    ],

];
