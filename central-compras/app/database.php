<?php
require_once __DIR__ . '/environment.php';

/**
 * PDO connection for PHP 7.3 + MySQL.
 * Configure DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD in the server or local .env.
 */
function databaseConnection()
{
    $host = applicationEnv('DB_HOST', '127.0.0.1');
    $port = applicationEnv('DB_PORT', '3306');
    $name = applicationEnv('DB_NAME', 'central_compras');
    $user = applicationEnv('DB_USER', 'root');
    $password = applicationEnv('DB_PASSWORD', '');
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $user, $password, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
    // DATETIME defaults and SQL functions (NOW/CURRENT_TIMESTAMP) must follow Brasília too.
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

// Carrega também o registrador de erros fatais para todos os módulos que usam PDO.
require_once __DIR__ . '/system_log.php';
