<?php
/**
 * Configurazione Database - PRODUZIONE (lemio.it)
 * PAManager
 *
 * IMPORTANTE: rinominare in database.php quando si carica su produzione,
 * sostituendo il file attuale.
 */

$initCommandKey = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
    ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
    : PDO::MYSQL_ATTR_INIT_COMMAND;

return [
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST']     ?? 'localhost',
    'port'      => $_ENV['DB_PORT']     ?? '3306',
    'database'  => $_ENV['DB_DATABASE'] ?? '***RIMOSSO***',
    'username'  => $_ENV['DB_USERNAME'] ?? '***RIMOSSO***',
    'password'  => $_ENV['DB_PASSWORD'] ?? '***RIMOSSO***',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'options'   => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        $initCommandKey              => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
];
