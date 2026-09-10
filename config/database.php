<?php
/**
 * Configurazione Database
 *
 * Le credenziali arrivano SEMPRE dall'ambiente (Docker / Dokploy):
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
 * In sviluppo si puo' usare config/database.local.php (mai committato).
 * Nessuna credenziale hardcoded in questo file.
 */

$initCommandKey = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
    ? constant('Pdo\Mysql::ATTR_INIT_COMMAND')
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
    'database'  => '',
    'username'  => '',
    'password'  => '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

// Override da variabili d'ambiente (Docker / Dokploy). Priorita: local > env > default.
$envOverrides = [];
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_NAME',
          'username' => 'DB_USER', 'password' => 'DB_PASS'] as $k => $envKey) {
    $v = getenv($envKey);
    if ($v === false || $v === '') {
        $v = $_ENV[$envKey] ?? '';
    }
    if ($v !== '') $envOverrides[$k] = $v;
}

// Compatibilita' con i nomi usati in .env (DB_DATABASE / DB_USERNAME / DB_PASSWORD)
foreach (['database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $k => $envKey) {
    if (!isset($envOverrides[$k])) {
        $v = getenv($envKey);
        if ($v === false || $v === '') {
            $v = $_ENV[$envKey] ?? '';
        }
        if ($v !== '') $envOverrides[$k] = $v;
    }
}

$merged = array_merge($defaults, $envOverrides, $localOverrides);

if ($merged['database'] === '' || $merged['username'] === '') {
    error_log('CONFIG ERROR: credenziali database non configurate (DB_NAME / DB_USER).');
}

$merged['options'] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    $initCommandKey              => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

return $merged;
