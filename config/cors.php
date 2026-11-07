<?php

/*
 * CORS para el SPA. En desarrollo el SPA usa el proxy de Vite (mismo origen) y esto no interviene.
 * En producción con el frontend en otro (sub)dominio, FRONTEND_URL debe ser su origen exacto
 * (p. ej. https://sistemacurso.kodevo.es); se admiten varios separados por coma.
 * supports_credentials = true porque la sesión de Sanctum viaja en cookie.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn ($o) => rtrim(trim($o), '/'),
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5174'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,
];
