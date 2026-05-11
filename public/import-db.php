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

// Usiamo l'utente normale del DB (ha GRANT ALL ON dbname.*) — il dump fa DROP TABLE
// e CREATE TABLE per ogni tabella, quindi non serve dropare il database stesso.

echo "[3/4] Pulizia tabelle pre-esistenti (DROP + CREATE saranno nel dump)...\n";

// Import dump
$importCmd = sprintf(
    "mariadb --skip-ssl -h%s -P%s -u%s -p'%s' %s < %s 2>&1",
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    addslashes($pass),
    escapeshellarg($db),
    escapeshellarg($tmpPath)
);
$out2 = shell_exec($importCmd);
@unlink($tmpPath);
echo "[4/4] Import completato\n";
if ($out2 && trim($out2) !== '') echo "       output: $out2\n";

// Verifica conteggi tabelle principali (via PDO, evita problemi shell quoting)
$counts = '';
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    foreach (['employees','users','departments','leave_requests','communications','companies'] as $t) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $counts .= sprintf("  %-20s %d\n", $t, $n);
        } catch (Throwable $e) {
            $counts .= sprintf("  %-20s (tabella mancante)\n", $t);
        }
    }
} catch (Throwable $e) {
    $counts = "Errore PDO: " . $e->getMessage() . "\n";
}
echo "\n=== Conteggi tabelle ===\n";
echo $counts;
echo "\nImport DB completato. Prosegui con import-uploads.php per le foto.\n";
