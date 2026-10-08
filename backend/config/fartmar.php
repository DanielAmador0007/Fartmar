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

];
