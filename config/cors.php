<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Restringe quais origens (front) podem chamar a API. Em produção defina
    | CORS_ALLOWED_ORIGINS com as URLs do front separadas por vírgula.
    | O padrão regex cobre *.sfacco.com.br (white-labels por subdomínio).
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8081'))
    ))),

    'allowed_origins_patterns' => ['/^https:\/\/([a-z0-9-]+\.)?sfacco\.com\.br$/'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
