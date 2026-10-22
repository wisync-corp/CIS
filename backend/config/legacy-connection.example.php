<?php
// Ligação separada somente para consultas de uma cópia de desenvolvimento do sistema legado.
// A restrição SELECT DEVE ser garantida pelos privilégios MySQL do utilizador configurado.
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
    // Configure com certificado CA oficial do fornecedor. Sem CA, manter desligada a consulta de dados reais.
    'options' => env('LEGACY_DB_SSL_CA') ? [
        \PDO::MYSQL_ATTR_SSL_CA => env('LEGACY_DB_SSL_CA'),
        \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ] : [],
];
