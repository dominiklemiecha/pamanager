<?php
/**
 * Endpoint upload + import SQL dump (una tantum per migrazione produzione → Dokploy).
 *
 * USO (da locale):
 *   curl -F "file=@tools/prod-dump.sql.sql" \
 *        "https://hr.connecteed.com/import-db.php?token=<MIGRATION_TOKEN>"
 *
 * SICUREZZA: token-protected, da rimuovere dopo l'uso.
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

// === Token check ===
$expectedToken = getenv('MIGRATION_TOKEN') ?: '';
$providedToken = $_GET['token'] ?? '';
if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die("Access denied. Use ?token=<MIGRATION_TOKEN>\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo "Usage:\n  curl -F \"file=@prod-dump.sql\" \"" . ($_SERVER['REQUEST_SCHEME'] ?? 'https') . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . "?token=<TOKEN>\"\n";
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die("Upload error: " . $file['error'] . "\n");
}

echo "[1/4] File ricevuto: " . $file['name'] . " (" . round($file['size']/1024) . " KB)\n";

// Pre-processing: MySQL 8 → MariaDB compat
$tmpPath = '/tmp/import-' . bin2hex(random_bytes(4)) . '.sql';
$src = file_get_contents($file['tmp_name']);
$src = str_replace(['utf8mb4_0900_ai_ci', 'utf8mb3_general_ci'], ['utf8mb4_unicode_ci', 'utf8_general_ci'], $src);
file_put_contents($tmpPath, $src);
unset($src);
echo "[2/4] Pre-processing collation MySQL 8 -> MariaDB OK\n";

// Carica config DB
$dbConfig = require __DIR__ . '/../config/database.php';
$host = $dbConfig['host'];
$port = $dbConfig['port'];
$user = $dbConfig['username'];
$pass = $dbConfig['password'];
$db   = $dbConfig['database'];

$rootPass = getenv('DB_ROOT_PASS') ?: '';
if (!$rootPass) {
    @unlink($tmpPath);
    http_response_code(500);
    die("ERR: DB_ROOT_PASS env var non impostata, impossibile dropare e ricreare il DB.\n");
}

// Drop + ricrea schema
$dropCmd = sprintf(
    "mariadb --skip-ssl -h%s -P%s -uroot -p'%s' -e %s 2>&1",
    escapeshellarg($host),
    escapeshellarg($port),
    addslashes($rootPass),
    escapeshellarg("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON `$db`.* TO '$user'@'%';")
);
$out1 = shell_exec($dropCmd);
echo "[3/4] DB '$db' droppato e ricreato\n";
if ($out1) echo "       output: $out1\n";

// Import dump
$importCmd = sprintf(
    "mariadb --skip-ssl -h%s -P%s -uroot -p'%s' %s < %s 2>&1",
    escapeshellarg($host),
    escapeshellarg($port),
    addslashes($rootPass),
    escapeshellarg($db),
    escapeshellarg($tmpPath)
);
$out2 = shell_exec($importCmd);
@unlink($tmpPath);
echo "[4/4] Import completato\n";
if ($out2 && trim($out2) !== '') echo "       output: $out2\n";

// Verifica conteggi tabelle principali
$countsCmd = sprintf(
    "mariadb --skip-ssl -h%s -P%s -u%s -p'%s' %s -e %s 2>&1",
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    addslashes($pass),
    escapeshellarg($db),
    escapeshellarg("SELECT 'employees' AS tab, COUNT(*) AS n FROM employees UNION ALL SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'departments', COUNT(*) FROM departments UNION ALL SELECT 'leave_requests', COUNT(*) FROM leave_requests UNION ALL SELECT 'communications', COUNT(*) FROM communications UNION ALL SELECT 'companies', COUNT(*) FROM companies;")
);
$counts = shell_exec($countsCmd);
echo "\n=== Conteggi tabelle ===\n";
echo $counts;
echo "\nImport DB completato. Prosegui con import-uploads.php per le foto.\n";
