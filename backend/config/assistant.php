<?php

/*
| Asistente IA de consulta de inventario (Parte C, docs/supuestos.md S-89…).
| Cambiar de modelo = cambiar variables de entorno; el código no cambia.
*/
return [

    // mock (por defecto: determinista, sin red ni llaves) | ollama | anthropic
    'provider' => env('LLM_PROVIDER', 'mock'),

    // Llamadas al modelo por pregunta (cada una puede pedir herramientas).
    'max_iterations' => 4,

    // Herramientas que se ejecutan como máximo por cada respuesta del modelo.
    'max_tool_calls_per_turn' => 5,

    // Largo máximo de la respuesta final (caracteres).
    'max_answer_length' => 2000,

    // Preguntas por minuto por usuario (además del límite general de la API).
    'rate_limit_per_minute' => (int) env('ASSISTANT_RATE_LIMIT_PER_MINUTE', 10),

    // Proveedores reales: tiempo máximo por llamada (s) y tokens de salida.
    'timeout' => (int) env('LLM_TIMEOUT', 30),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),

    // Porcentaje mínimo de aciertos de `php artisan assistant:eval`.
    'eval_threshold' => (int) env('ASSISTANT_EVAL_THRESHOLD', 90),

    'ollama' => [
        // Desde el contenedor, el Ollama del host está en host.docker.internal.
        'url' => env('OLLAMA_URL', 'http://host.docker.internal:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b-instruct'),
    ],

    'anthropic' => [
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
        // Secreto: solo en el .env real o en el orquestador, nunca en el repo.
        'api_key' => env('LLM_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'version' => '2023-06-01',
    ],

];
