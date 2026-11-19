<?php

return [
    'enabled' => (bool) env('IFC_AI_ENABLED', false),
    'tutorials_enabled' => (bool) env('IFC_AI_TUTORIALS_ENABLED', true),
    'provider' => 'openai',
    'model' => env('IFC_AI_MODEL', 'gpt-5.4-mini'),
    'api_key' => env('OPENAI_API_KEY'),
    'credential_url' => env('AI_LARAVEL_CREDENTIAL_URL'),
    'request_timeout' => (int) env('OPENAI_TIMEOUT', 20),
    'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 5),
    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 500),
];
