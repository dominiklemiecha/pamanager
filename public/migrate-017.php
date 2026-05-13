<?php
/**
 * Endpoint dedicato per migration 017 (employee_documents).
 * Idempotente: usa CREATE TABLE IF NOT EXISTS e registra in tabella `migrations`.
 *
 * USO: https://<host>/migrate-017.php?token=pamanager_migrate_2024
 *
 * NOTA: file temporaneo, eliminare dopo l'esecuzione in produzione.
 */

$expectedToken = getenv('MIGRATION_TOKEN') ?: 'pamanager_migrate_2024';
$providedToken = $_GET['token'] ?? '';

if ($providedToken !== $expectedToken) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>403</title></head><body style="font-family:sans-serif;padding:40px;"><h1>Accesso negato</h1><p>Token mancante o non valido. Uso: <code>?token=xxx</code></p></body></html>');
}

header('Content-Type: text/html; charset=utf-8');

$dbConfig = require __DIR__ . '/../config/database.php';
$migrationFile = __DIR__ . '/../database/migrations/017_employee_documents.sql';
$migrationName = '017_employee_documents.sql';

$log = [];
$log[] = '=== Migration 017 - employee_documents ===';
$log[] = 'Inizio: ' . date('Y-m-d H:i:s');

if (!file_exists($migrationFile)) {
    $log[] = '[ERRORE] File migration non trovato: ' . $migrationFile;
    render($log);
    exit;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? 3306,
        $dbConfig['database'] ?? 'gestionale_pa'
    );
    $pdo = new PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $log[] = '[OK] Connessione DB stabilita';
} catch (PDOException $e) {
    $log[] = '[ERRORE] Connessione DB: ' . $e->getMessage();
    render($log);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        migration VARCHAR(255) NOT NULL UNIQUE,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE migration = ?");
    $stmt->execute([$migrationName]);
    $alreadyRun = (int) $stmt->fetchColumn() > 0;

    if ($alreadyRun) {
        $log[] = '[SKIP] Migration gia eseguita in precedenza.';
    } else {
        $log[] = '[RUN] Eseguo ' . $migrationName . '...';
        $sql = file_get_contents($migrationFile);
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)")->execute([$migrationName]);
        $log[] = '[OK] Migration eseguita con successo.';
    }

    // Verifica schema
    $tables = $pdo->query("SHOW TABLES LIKE 'employee_document%'")->fetchAll(PDO::FETCH_COLUMN);
    $log[] = '';
    $log[] = 'Tabelle trovate: ' . (empty($tables) ? '(nessuna)' : implode(', ', $tables));

    if (in_array('employee_documents', $tables, true)) {
        $cols = $pdo->query("SHOW COLUMNS FROM employee_documents")->fetchAll();
        $log[] = '';
        $log[] = 'Colonne employee_documents:';
        foreach ($cols as $c) {
            $log[] = '  - ' . $c['Field'] . ' (' . $c['Type'] . ')';
        }
    }
} catch (PDOException $e) {
    $log[] = '[ERRORE] ' . $e->getMessage();
}

$log[] = '';
$log[] = 'Fine: ' . date('Y-m-d H:i:s');

render($log);

function render(array $log): void
{
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migration 017</title>';
    echo '<style>body{font-family:Consolas,Monaco,monospace;background:#1a1a2e;color:#eee;padding:20px;line-height:1.6;}';
    echo '.container{max-width:900px;margin:0 auto;background:#16213e;padding:20px;border-radius:8px;}';
    echo 'h1{color:#00d9ff;border-bottom:2px solid #00d9ff;padding-bottom:10px;}';
    echo 'pre{background:#0f0f23;padding:15px;border-radius:4px;white-space:pre-wrap;}</style></head><body>';
    echo '<div class="container"><h1>Migration 017 - employee_documents</h1><pre>';
    foreach ($log as $line) {
        $color = '';
        if (strpos($line, '[OK]') !== false) $color = '#00ff88';
        elseif (strpos($line, '[ERRORE]') !== false) $color = '#ff4444';
        elseif (strpos($line, '[SKIP]') !== false) $color = '#ffaa00';
        elseif (strpos($line, '[RUN]') !== false) $color = '#00d9ff';
        elseif (strpos($line, '===') !== false) $color = '#00d9ff';
        if ($color) echo '<span style="color:' . $color . '">' . htmlspecialchars($line) . '</span>' . "\n";
        else echo htmlspecialchars($line) . "\n";
    }
    echo '</pre></div></body></html>';
}
