<?php

return [
    'stateful' => [],
    'guard' => [],
    'expiration' => (int) env('INVENTORY_TOKEN_EXPIRATION', 1440),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [],
];
