<?php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite', 'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '', 'foreign_key_constraints' => true,
        ],
        'mysql' => [
            'driver' => 'mysql', 'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cis_auth'), 'username' => env('DB_USERNAME', 'cis'),
            'password' => env('DB_PASSWORD', ''), 'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'engine' => null,
        ],
        'mysql_cash_opening_submit' => [
            'driver'=>'mysql','host'=>env('DB_HOST','127.0.0.1'),'port'=>env('DB_PORT','3306'),
            'database'=>env('DB_DATABASE','cis'),
            'username'=>env('CASH_OPENING_SUBMIT_DB_USERNAME','cis_cash_open_submit'),
            'password'=>env('CASH_OPENING_SUBMIT_DB_PASSWORD',''),
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
            'prefix'=>'','strict'=>true,'engine'=>null,
        ],
        'mysql_cash_opening_validate' => [
            'driver'=>'mysql','host'=>env('DB_HOST','127.0.0.1'),'port'=>env('DB_PORT','3306'),
            'database'=>env('DB_DATABASE','cis'),
            'username'=>env('CASH_OPENING_VALIDATE_DB_USERNAME','cis_cash_open_validate'),
            'password'=>env('CASH_OPENING_VALIDATE_DB_PASSWORD',''),
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
            'prefix'=>'','strict'=>true,'engine'=>null,
        ],
        // Escritor financeiro isolado. Deve receber SELECT em contas e
        // SELECT/INSERT em Extracto, SEM UPDATE/DELETE nem ALTER.
        'mysql_cash_ledger' => [
            'driver'=>'mysql','host'=>env('DB_HOST','127.0.0.1'),'port'=>env('DB_PORT','3306'),
            'database'=>env('DB_DATABASE','cis'),
            'username'=>env('CASH_LEDGER_DB_USERNAME','cis_cash_ledger'),
            'password'=>env('CASH_LEDGER_DB_PASSWORD',''),
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
            'prefix'=>'','strict'=>true,'engine'=>null,
        ],
        // Escritor mínimo dos fechos: SELECT contas e Extracto; SELECT/INSERT
        // só na tabela de controlo. Não escrever/alterar Extracto.
        'mysql_cash_closures' => [
            'driver'=>'mysql','host'=>env('DB_HOST','127.0.0.1'),'port'=>env('DB_PORT','3306'),
            'database'=>env('DB_DATABASE','cis'),
            'username'=>env('CASH_CLOSURES_DB_USERNAME','cis_cash_closures'),
            'password'=>env('CASH_CLOSURES_DB_PASSWORD',''),
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
            'prefix'=>'','strict'=>true,'engine'=>null,
        ],
        'mysql_cash_setup' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cis'),
            'username' => env('CASH_SETUP_DB_USERNAME', 'cis_cash_setup'),
            'password' => env('CASH_SETUP_DB_PASSWORD', ''),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'engine' => null,
        ],
    ],
    'migrations' => ['table' => 'migrations'],
    'redis' => ['client' => 'phpredis'],
];
