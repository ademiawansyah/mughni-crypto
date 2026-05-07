<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the local Ollama AI inference server used to generate
    | trading signals. All values can be overridden via environment variables.
    |
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 30),
    ],

];
