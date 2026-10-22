<?php
// Incorporar como entrada 'mysql_legacy' em config/database.php num projecto Laravel.
// A conta deve ter APENAS SELECT numa base de testes ou réplica.
return [
    'driver' => 'mysql',
    'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
    'port' => env('LEGACY_DB_PORT', '3306'),
    'database' => env('LEGACY_DB_DATABASE', ''),
    'username' => env('LEGACY_DB_USERNAME', ''),
    'password' => env('LEGACY_DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
];
