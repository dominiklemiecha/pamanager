<?php
/**
 * Migrazione: Reparti e Admin Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration 004</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#1a202c;color:#e2e8f0;} .ok{color:#48bb78;} .err{color:#fc8181;} .skip{color:#ecc94b;}</style>';
    echo '</head><body><h2>Migration 004: Reparti e Admin Reparto</h2><pre>';
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
out("Migrazione 004: Reparti e Admin Reparto");
out("===========================================\n");

// Verifica se già eseguita
try {
    $check = Database::fetchOne("SELECT id FROM migrations WHERE migration = '004_departments'");
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
    'Creazione tabella departments' => "
        CREATE TABLE IF NOT EXISTS departments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL UNIQUE,
            code VARCHAR(20) NOT NULL UNIQUE,
            description TEXT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Inserimento reparti PA default' => "
        INSERT INTO departments (name, code, description) VALUES
            ('Anagrafe', 'ANA', 'Servizi anagrafici e stato civile'),
            ('Tributi', 'TRI', 'Gestione tributi comunali'),
            ('Ufficio Tecnico', 'TEC', 'Lavori pubblici e urbanistica'),
            ('Ragioneria', 'RAG', 'Gestione finanziaria e contabilità'),
            ('Servizi Sociali', 'SOC', 'Assistenza sociale e welfare'),
            ('Polizia Locale', 'POL', 'Vigilanza e sicurezza'),
            ('Segreteria Generale', 'SEG', 'Affari generali e protocollo'),
            ('Ambiente e Territorio', 'AMB', 'Ambiente e pianificazione territoriale'),
            ('Cultura e Sport', 'CUL', 'Attività culturali e sportive'),
            ('Risorse Umane', 'RU', 'Gestione del personale')
    ",

    'Modifica tabella users - aggiunta ruolo admin_reparto' => "
        ALTER TABLE users
            MODIFY COLUMN role ENUM('admin', 'accountant', 'admin_reparto') NOT NULL DEFAULT 'accountant'
    ",

    'Modifica tabella users - aggiunta department_id' => "
        ALTER TABLE users
            ADD COLUMN department_id INT NULL AFTER role,
            ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    ",

    'Modifica tabella employees - aggiunta department_id' => "
        ALTER TABLE employees
            ADD COLUMN department_id INT NULL AFTER department,
            ADD CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    ",

    'Indice su employees.department_id' => "
        CREATE INDEX idx_employees_department ON employees(department_id)
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
        if (strpos($errorMsg, 'already exists') !== false ||
            strpos($errorMsg, 'Duplicate') !== false ||
            strpos($errorMsg, 'Duplicate column') !== false) {
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
        Database::insert('migrations', ['migration' => '004_departments']);
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
