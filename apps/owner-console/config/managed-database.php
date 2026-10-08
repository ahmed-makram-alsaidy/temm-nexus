<?php

return [
    // Server-only provisioning account. Project connections never use this role.
    'host' => env('PROJECT_DB_HOST', env('DB_HOST', 'postgres')),
    'port' => env('PROJECT_DB_PORT', env('DB_PORT', 5432)),
    'username' => env('PROJECT_DB_ADMIN_USERNAME', env('POSTGRES_USER', 'postgres')),
    'password' => env('PROJECT_DB_ADMIN_PASSWORD', env('POSTGRES_PASSWORD')),
    'sslmode' => env('PROJECT_DB_SSLMODE', 'prefer'),
];
