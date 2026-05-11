<?php
/**
 * Migrazione: Sistema Chat e Notifiche
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 007</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 007: Sistema Chat e Notifiche</h2><pre>';
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
out("Migrazione 007: Sistema Chat e Notifiche");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '007_chat_system'");
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
    'Creazione tabella chat_conversations' => "
        CREATE TABLE IF NOT EXISTS chat_conversations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            participant1_type ENUM('admin','accountant','employee','admin_reparto') NOT NULL,
            participant1_id INT NOT NULL,
            participant2_type ENUM('admin','accountant','employee','admin_reparto') NOT NULL,
            participant2_id INT NOT NULL,
            last_message_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_conv (participant1_type, participant1_id, participant2_type, participant2_id),
            INDEX idx_participant1 (participant1_type, participant1_id),
            INDEX idx_participant2 (participant2_type, participant2_id),
            INDEX idx_last_message (last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Creazione tabella chat_messages' => "
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            sender_type ENUM('admin','accountant','employee','admin_reparto') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            attachment_path VARCHAR(500) NULL,
            attachment_name VARCHAR(255) NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            INDEX idx_conversation (conversation_id),
            INDEX idx_sender (sender_type, sender_id),
            INDEX idx_read (is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Creazione tabella notifications' => "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            recipient_type ENUM('admin','accountant','employee','admin_reparto') NOT NULL,
            recipient_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient_type, recipient_id),
            INDEX idx_read (is_read),
            INDEX idx_type (type),
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
        Database::insert('migrations', ['migration' => '007_chat_system']);
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
