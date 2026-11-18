<?php

return [
    'enabled' => env('HTTP_LOGGER_ENABLED', true),
    'channel' => env('HTTP_LOGGER_CHANNEL'),
    'level' => 'info',
    'except' => [],
    'max_body_bytes' => 16384,
    'redacted_fields' => [
        'password', 'password_confirmation', 'current_password',
        'token', 'access_token', 'refresh_token', 'secret', 'api_key',
    ],
    'redacted_headers' => [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
    ],
];
