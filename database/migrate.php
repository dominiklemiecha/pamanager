<?php
/**
 * Script di migrazione database
 * PAManager - Comune
 *
 * Esegue tutte le migrazioni SQL in ordine
 *
 * USO:
 * - Da browser: http://localhost/gestionalepa/public/migrate.php?token=xxx
 * - Da CLI: php migrate.php
 *
 * ATTENZIONE: Eseguire solo una volta in produzione!
 */

// Impedisci accesso non autorizzato in produzione
$isCli = php_sapi_name() === 'cli';

if (!$isCli && !defined('MIGRATION_AUTHORIZED')) {
    // Verifica token di sicurezza per esecuzione da browser
    $expectedToken = getenv('MIGRATION_TOKEN') ?: 'pamanager_migrate_2024';
    $providedToken = $_GET['token'] ?? '';

    if ($providedToken !== $expectedToken) {
        http_response_code(403);
        die('Accesso negato. Fornire token: ?token=xxx');
    }
}

// Carica configurazione
$dbConfig = require __DIR__ . '/../config/database.php';

// Estrai configurazione
$DB_HOST = $dbConfig['host'] ?? 'localhost';
$DB_PORT = $dbConfig['port'] ?? 3306;
$DB_NAME = $dbConfig['database'] ?? 'gestionale_pa';
$DB_USER = $dbConfig['username'] ?? 'root';
$DB_PASS = $dbConfig['password'] ?? '';

// Classe semplice per migrazioni
class Migrator
{
    private PDO $pdo;
    private string $migrationsPath;
    private array $log = [];

    public function __construct(PDO $pdo, string $migrationsPath)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = $migrationsPath;
    }

    /**
     * Esegue tutte le migrazioni
     */
    public function run(): array
    {
        $this->log('=== PAManager Database Migration ===');
        $this->log('Inizio: ' . date('Y-m-d H:i:s'));
        $this->log('');

        // Crea tabella migrazioni se non esiste
        $this->createMigrationsTable();

        // Ottieni migrazioni già eseguite
        $executed = $this->getExecutedMigrations();

        // Trova file di migrazione
        $files = glob($this->migrationsPath . '/*.sql');
        sort($files);

        if (empty($files)) {
            $this->log('Nessun file di migrazione trovato.');
            return $this->log;
        }

        $this->log('Trovate ' . count($files) . ' migrazioni');
        $this->log('');

        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, $executed)) {
                $this->log("[SKIP] {$filename} - già eseguita");
                $skipped++;
                continue;
            }

            $this->log("[RUN] {$filename}");

            try {
                $this->executeMigration($file, $filename);
                $this->log("  ✓ Completata");
                $success++;
            } catch (Exception $e) {
                $this->log("  ✗ ERRORE: " . $e->getMessage());
                $failed++;

                // Interrompi in caso di errore
                $this->log('');
                $this->log('Migrazione interrotta per errore.');
                break;
            }
        }

        $this->log('');
        $this->log('=== Riepilogo ===');
        $this->log("Eseguite: {$success}");
        $this->log("Saltate: {$skipped}");
        $this->log("Fallite: {$failed}");
        $this->log('');
        $this->log('Fine: ' . date('Y-m-d H:i:s'));

        return $this->log;
    }

    /**
     * Crea tabella per tracciare migrazioni
     */
    private function createMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    /**
     * Ottieni lista migrazioni già eseguite
     */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Esegue una singola migrazione
     */
    private function executeMigration(string $file, string $filename): void
    {
        $sql = file_get_contents($file);

        if (empty(trim($sql))) {
            throw new Exception('File vuoto');
        }

        // Rimuovi DELIMITER per compatibilità
        // MySQL da PHP non supporta DELIMITER, quindi gestiamo le procedure separatamente
        if (strpos($sql, 'DELIMITER') !== false) {
            $this->executeWithDelimiter($sql);
        } else {
            // Esegui statement multipli
            $this->pdo->exec($sql);
        }

        // Registra migrazione completata
        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->execute([$filename]);
    }

    /**
     * Esegue SQL con DELIMITER (stored procedures)
     */
    private function executeWithDelimiter(string $sql): void
    {
        // Estrai e esegui parti prima/dopo le procedure
        $parts = preg_split('/DELIMITER\s+\/\/|DELIMITER\s+;/', $sql);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            // Se contiene END //, è una procedura
            if (strpos($part, 'END //') !== false) {
                // Rimuovi // finale e esegui
                $part = str_replace('//', '', $part);
                $statements = explode('END', $part);

                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if (empty($stmt)) continue;
                    if (strpos($stmt, 'CREATE') !== false || strpos($stmt, 'DROP') !== false) {
                        $this->pdo->exec($stmt . ' END');
                    }
                }
            } else {
                // Esegui normalmente, statement per statement
                $statements = array_filter(
                    array_map('trim', explode(';', $part)),
                    fn($s) => !empty($s) && $s !== '//'
                );

                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        $this->pdo->exec($stmt);
                    }
                }
            }
        }
    }

    /**
     * Aggiunge messaggio al log
     */
    private function log(string $message): void
    {
        $this->log[] = $message;
    }
}

// Connessione database
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $DB_HOST,
        $DB_PORT,
        $DB_NAME
    );

    $initKey = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
        : PDO::MYSQL_ATTR_INIT_COMMAND;

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        $initKey => "SET NAMES utf8mb4"
    ]);
} catch (PDOException $e) {
    $errorMsg = 'Errore connessione database: ' . $e->getMessage();
    if ($isCli) {
        die($errorMsg . PHP_EOL);
    } else {
        die('<div style="color:red;font-family:monospace;padding:20px;">' . htmlspecialchars($errorMsg) . '</div>');
    }
}

// Esegui migrazioni
$migrator = new Migrator($pdo, __DIR__ . '/migrations');
$log = $migrator->run();

// Output
if ($isCli) {
    // Output CLI
    foreach ($log as $line) {
        echo $line . PHP_EOL;
    }
} else {
    // Output HTML
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PAManager - Database Migration</title>
        <style>
            body {
                font-family: 'Consolas', 'Monaco', monospace;
                background: #1a1a2e;
                color: #eee;
                padding: 20px;
                line-height: 1.6;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                background: #16213e;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            }
            h1 {
                color: #00d9ff;
                border-bottom: 2px solid #00d9ff;
                padding-bottom: 10px;
            }
            pre {
                background: #0f0f23;
                padding: 15px;
                border-radius: 4px;
                overflow-x: auto;
                white-space: pre-wrap;
            }
            .success { color: #00ff88; }
            .skip { color: #ffaa00; }
            .error { color: #ff4444; }
            .info { color: #00d9ff; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>PAManager - Database Migration</h1>
            <pre><?php
                foreach ($log as $line) {
                    if (strpos($line, '✓') !== false) {
                        echo '<span class="success">' . htmlspecialchars($line) . '</span>' . "\n";
                    } elseif (strpos($line, '✗') !== false || strpos($line, 'ERRORE') !== false) {
                        echo '<span class="error">' . htmlspecialchars($line) . '</span>' . "\n";
                    } elseif (strpos($line, 'SKIP') !== false) {
                        echo '<span class="skip">' . htmlspecialchars($line) . '</span>' . "\n";
                    } elseif (strpos($line, '===') !== false) {
                        echo '<span class="info">' . htmlspecialchars($line) . '</span>' . "\n";
                    } else {
                        echo htmlspecialchars($line) . "\n";
                    }
                }
            ?></pre>
        </div>
    </body>
    </html>
    <?php
}
