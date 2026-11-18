<?php

return [
    'enabled' => env('HTTP_LOGGER_ENABLED', true),
    'channel' => env('HTTP_LOGGER_CHANNEL'),

    'level' => 'info',

    'incoming' => [
        'enabled' => env('HTTP_LOGGER_INCOMING_ENABLED', true),
        'channel' => env('HTTP_LOGGER_INCOMING_CHANNEL'),
    ],

    'outgoing' => [
        'enabled' => env('HTTP_LOGGER_OUTGOING_ENABLED', false),
        'channel' => env('HTTP_LOGGER_OUTGOING_CHANNEL'),
    ],

    'trace_header' => 'X-Trace-Id',
    'request_header' => 'X-Request-Id',
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
