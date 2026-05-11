<?php
/**
 * Migrazione: Password Reset System
 * PAManager - Comune
 *
 * Esegui: http://localhost/app-gestionali/gestionalepa/database/migrations/002_password_reset.php
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 002</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 002: Password Reset System</h2><pre>';
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
out("Migrazione 002: Password Reset System");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '002_password_reset'");
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
    'Tabella password_reset_tokens' => "
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user (user_type, user_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Tabella password_reset_requests' => "
        CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
            user_id INT NOT NULL,
            status ENUM('pending', 'sent', 'completed', 'expired', 'rejected') DEFAULT 'pending',
            email_sent BOOLEAN DEFAULT FALSE,
            token_id INT NULL,
            requested_ip VARCHAR(45) NOT NULL,
            resolved_by INT NULL,
            resolved_at TIMESTAMP NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_user (user_type, user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Tabella migrations (se non esiste)' => "
        CREATE TABLE IF NOT EXISTS migrations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

$success = true;

foreach ($queries as $name => $sql) {
    out("Creazione: {$name}...");
    try {
        Database::query($sql);
        out("  Completato", 'ok');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            out("  Già esistente", 'skip');
        } else {
            out("  " . $e->getMessage(), 'err');
            $success = false;
        }
    }
}

// Registra migrazione
if ($success) {
    try {
        Database::insert('migrations', ['migration' => '002_password_reset']);
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
