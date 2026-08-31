<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Проект работает только на PostgreSQL: SKIP LOCKED, partial unique,
    | pg_advisory_xact_lock и отложенные constraint-триггеры — несущие элементы
    | схемы, а не оптимизация. Другие драйверы намеренно не сконфигурированы,
    | чтобы их нельзя было включить случайно.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5434'),
            'database' => env('DB_DATABASE', 'gamestore'),
            'username' => env('DB_USERNAME', 'gamestore'),
            'password' => env('DB_PASSWORD', 'gamestore'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        /*
        | Второе соединение к той же БД. Нужно состязательным тестам: чтобы доказать
        | взаимное исключение, два конкурента обязаны сидеть на РАЗНЫХ соединениях —
        | внутри одного PDO-коннекта гонки не существует (CLAUDE.md §6.3).
        */
        'pgsql_rival' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5434'),
            'database' => env('DB_DATABASE', 'gamestore'),
            'username' => env('DB_USERNAME', 'gamestore'),
            'password' => env('DB_PASSWORD', 'gamestore'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Отдельные БД для кэша и очередей: FLUSHDB в тестовой обвязке не должен
    | сносить очередь вместе с кэшем.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'gamestore'), '_').'_database_'),
            'persistent' => (bool) env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6381'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6381'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
