<?php

return [
    'host' => env('CLICKHOUSE_HOST', 'localhost'),
    'port' => env('CLICKHOUSE_PORT', 8123),
    'username' => env('CLICKHOUSE_USERNAME', 'event_user'),
    'password' => env('CLICKHOUSE_PASSWORD', 'event_password'),
    'database' => env('CLICKHOUSE_DATABASE', 'events_db'),
];
