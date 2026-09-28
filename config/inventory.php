<?php

return [
    'api_rate_limit' => (int) env('INVENTORY_API_RATE_LIMIT', 60),
    'login_rate_limit' => (int) env('INVENTORY_LOGIN_RATE_LIMIT', 5),
    'login_ip_rate_limit' => (int) env('INVENTORY_LOGIN_IP_RATE_LIMIT', 20),
    'seed_user_email' => env('INVENTORY_SEED_USER_EMAIL'),
    'seed_user_password' => env('INVENTORY_SEED_USER_PASSWORD'),
];
