<?php

/*
| Parámetros de negocio de FARTMAR (ver docs/supuestos.md).
*/
return [

    // Zona horaria usada para calcular "hoy" en vencimientos (S-13).
    // Las fechas y horas se guardan en UTC.
    'business_timezone' => env('BUSINESS_TIMEZONE', 'America/Bogota'),

    // RN-11: días de anticipación para alertar vencimientos.
    'alert_expiry_days' => (int) env('ALERT_EXPIRY_DAYS', 90),

    // Clave HMAC para document_hash de pacientes (búsqueda exacta sin
    // descifrar). Si no se define, se usa APP_KEY. Cambiarla invalida los
    // hashes existentes (habría que recalcularlos).
    'patient_hash_key' => env('PATIENT_HASH_KEY'),

    // Límite de solicitudes por minuto por usuario autenticado en /api/v1
    // (S-47). Frena scripts o clientes en bucle sin afectar el uso normal.
    'api_rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),

    // Rol de BD con el que corre la app en producción (no dueño de las
    // tablas, sin TRUNCATE). Lo crea `php artisan db:provision-app-role`
    // tras las migraciones (S-101). Vacío = la app usa el usuario dueño
    // (desarrollo y pruebas).
    'db_app_role' => [
        'username' => env('DB_APP_USERNAME'),
        'password' => env('DB_APP_PASSWORD'),
    ],

];
