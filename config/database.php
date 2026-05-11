<?php
/**
 * Configurazione Database
 *
 * I valori di default puntano a PRODUZIONE (lemio.it / mylemiom_gestionale).
 * In locale: creare config/database.local.php con le credenziali XAMPP
 * (NON caricare database.local.php su FTP). Vedi database.local.example.php
 */

$initCommandKey = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
    ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
    : PDO::MYSQL_ATTR_INIT_COMMAND;

// Override locali (file presente solo in dev, mai su prod)
$localOverrides = [];
if (file_exists(__DIR__ . '/database.local.php')) {
    $localOverrides = require __DIR__ . '/database.local.php';
}

$defaults = [
    'driver'    => 'mysql',
    'host'      => 'localhost',
    'port'      => '3306',
    'database'  => 'mylemiom_gestionale',
    'username'  => 'mylemiom_gestuser',
    'password'  => 'C-$py6D}1$u5UBd-',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

// Override da variabili d'ambiente (Docker / Dokploy). Priorita: env > local > default.
$envOverrides = [];
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_NAME',
          'username' => 'DB_USER', 'password' => 'DB_PASS'] as $k => $envKey) {
    $v = getenv($envKey);
    if ($v !== false && $v !== '') $envOverrides[$k] = $v;
}

$merged = array_merge($defaults, $envOverrides, $localOverrides);

$merged['options'] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    $initCommandKey              => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

return $merged;
