<?php
/**
 * Migrazione: Sistema Richieste Ferie/Permessi
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 005</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 005: Sistema Richieste Ferie/Permessi</h2><pre>';
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
out("Migrazione 005: Sistema Richieste Ferie/Permessi");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '005_leave_requests'");
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
    'Creazione tabella leave_requests' => "
        CREATE TABLE IF NOT EXISTS leave_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id INT NOT NULL,
            leave_type ENUM('ferie','permesso','malattia','permesso_104','congedo_parentale','altro') NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_full_day BOOLEAN DEFAULT TRUE,
            start_time TIME NULL COMMENT 'Per permessi orari',
            end_time TIME NULL COMMENT 'Per permessi orari',
            reason TEXT NOT NULL,
            notes TEXT NULL,
            attachment_path VARCHAR(500) NULL,
            attachment_name VARCHAR(255) NULL,
            status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            rejection_reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_employee (employee_id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_leave_type (leave_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
        if (strpos($errorMsg, 'already exists') !== false) {
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
        Database::insert('migrations', ['migration' => '005_leave_requests']);
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
