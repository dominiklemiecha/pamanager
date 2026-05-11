<?php
/**
 * Migrazione: GDPR Compliance Tables
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 003</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 003: GDPR Compliance Tables</h2><pre>';
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
out("Migrazione 003: GDPR Compliance Tables");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '003_gdpr_tables'");
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
    'Tabella gdpr_consents (consensi GDPR)' => "
        CREATE TABLE IF NOT EXISTS gdpr_consents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
            user_id INT NOT NULL,
            consent_type VARCHAR(50) NOT NULL COMMENT 'privacy_policy, terms, marketing, etc.',
            consent_given BOOLEAN NOT NULL DEFAULT FALSE,
            consent_text TEXT NULL COMMENT 'Testo del consenso al momento della firma',
            consent_version VARCHAR(20) NULL COMMENT 'Versione del documento',
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            given_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_type, user_id),
            INDEX idx_consent_type (consent_type),
            INDEX idx_given_at (given_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Tabella data_retention_log (log retention dati)' => "
        CREATE TABLE IF NOT EXISTS data_retention_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL COMMENT 'employees, documents, communications, etc.',
            entity_id INT NULL,
            action ENUM('archived', 'anonymized', 'deleted', 'exported') NOT NULL,
            reason VARCHAR(255) NULL COMMENT 'Motivo operazione',
            old_data JSON NULL COMMENT 'Dati prima della modifica',
            performed_by INT NULL COMMENT 'ID utente che ha eseguito',
            performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_action (action),
            INDEX idx_performed (performed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Tabella data_access_log (log accesso dati personali)' => "
        CREATE TABLE IF NOT EXISTS data_access_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
            user_id INT NOT NULL,
            accessed_entity_type VARCHAR(50) NOT NULL COMMENT 'Tipo entità acceduta',
            accessed_entity_id INT NULL,
            access_type ENUM('view', 'download', 'export', 'print') NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_type, user_id),
            INDEX idx_entity (accessed_entity_type, accessed_entity_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Tabella gdpr_requests (richieste GDPR)' => "
        CREATE TABLE IF NOT EXISTS gdpr_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
            user_id INT NOT NULL,
            request_type ENUM('access', 'rectification', 'erasure', 'portability', 'restriction', 'objection') NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'rejected') DEFAULT 'pending',
            request_details TEXT NULL,
            response_details TEXT NULL,
            handled_by INT NULL,
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            INDEX idx_user (user_type, user_id),
            INDEX idx_status (status),
            INDEX idx_type (request_type)
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
        Database::insert('migrations', ['migration' => '003_gdpr_tables']);
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
