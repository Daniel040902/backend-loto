<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', env('PGHOST', '127.0.0.1')),
            'port' => env('DB_PORT', env('PGPORT', '5432')),
            'database' => env('DB_DATABASE', env('PGDATABASE', 'loto')),
            'username' => env('DB_USERNAME', env('PGUSER', 'loto')),
            'password' => env('DB_PASSWORD', env('PGPASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_run' => true,
    ],
];
