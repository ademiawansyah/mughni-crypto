<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LM Studio Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the local LM Studio AI inference server used to generate
    | trading signals. All values can be overridden via environment variables.
    |
    */
    'lm_studio' => [
        'base_url' => env('LM_STUDIO_BASE_URL', 'http://127.0.0.1:1234'),
        'model' => env('LM_STUDIO_MODEL', 'qwen3.5-2b'),
        'timeout' => (int) env('LM_STUDIO_TIMEOUT', 30),
    ],

];
