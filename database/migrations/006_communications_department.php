<?php
/**
 * Migrazione: Comunicazioni per Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 006</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 006: Comunicazioni per Reparto</h2><pre>';
}

function out($msg, $type = '') {
    global $isCli;
    $prefix = match($type) {
        'ok' => '[OK] ',
        'err' => '[ERRORE] ',
        'skip' => '[SKIP] ',
        default => ''
    };
    if ($isCli) {
        echo $prefix . $msg . "\n";
    } else {
        $class = $type ?: 'info';
        echo "<span class='{$class}'>{$prefix}" . htmlspecialchars($msg) . "</span>\n";
    }
}

out("===========================================");
out("Migrazione 006: Comunicazioni per Reparto");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '006_communications_department'");
    if ($check) {
        out("Migrazione già eseguita", 'skip');
        if (!$isCli) echo '</pre></body></html>';
        exit;
    }
} catch (Exception $e) {
    // Tabella migrations potrebbe non esistere
}

// Query da eseguire
$queries = [
    'Aggiunta colonna is_global a communications' => "
        ALTER TABLE communications
            ADD COLUMN is_global BOOLEAN DEFAULT TRUE AFTER attachment_name
    ",

    'Aggiunta colonna department_id a communications' => "
        ALTER TABLE communications
            ADD COLUMN department_id INT NULL AFTER is_global,
            ADD CONSTRAINT fk_communications_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    ",

    'Indice su communications.department_id' => "
        CREATE INDEX idx_communications_department ON communications(department_id)
    "
];

$success = true;

foreach ($queries as $name => $sql) {
    out("Esecuzione: {$name}...");
    try {
        Database::query($sql);
        out("  Completato", 'ok');
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        if (strpos($errorMsg, 'Duplicate column') !== false ||
            strpos($errorMsg, 'already exists') !== false) {
            out("  Già esistente", 'skip');
        } else {
            out("  " . $errorMsg, 'err');
            $success = false;
        }
    }
}

// Registra migrazione
if ($success) {
    try {
        Database::insert('migrations', ['migration' => '006_communications_department']);
        out("\nMigrazione registrata", 'ok');
    } catch (Exception $e) {
        out("\nErrore registrazione: " . $e->getMessage(), 'err');
    }
}

out("\n===========================================");
out($success ? "Migrazione completata con successo!" : "Migrazione completata con errori", $success ? 'ok' : 'err');
out("===========================================");

if (!$isCli) {
    echo '</pre>';
    echo '<br><a href="' . PUBLIC_URL . '/admin/" style="color:#90cdf4;">Vai all\'admin</a>';
    echo '</body></html>';
}
