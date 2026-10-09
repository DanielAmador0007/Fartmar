<?php

/*
| CORS de la API. Solo el origen del frontend puede llamar desde el navegador
| (el valor por defecto de Laravel permite cualquier origen, "*").
| Varios orígenes separados por coma en CORS_ALLOWED_ORIGINS.
| La API usa token Bearer, no cookies: supports_credentials = false.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Correlation-Id', 'X-Requested-With'],

    'exposed_headers' => ['Idempotent-Replayed', 'X-Correlation-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
