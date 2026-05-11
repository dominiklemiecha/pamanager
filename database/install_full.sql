-- =====================================================================
-- PAManager - INSTALL FULL (schema + migrazioni 001..007 + admin connecteed)
-- Da importare via phpMyAdmin nel database mylemiom_gestionale (vuoto).
-- Compatibile con MySQL/MariaDB su hosting cPanel.
-- NON contiene CREATE DATABASE / USE / DELIMITER / EVENT (incompatibili
-- con shared hosting). Le procedure di cleanup sono OMESSE: si possono
-- creare a mano se servono, oppure pulire via cron.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =====================================================================
-- SCHEMA BASE (database/schema.sql) con modifiche integrate dalle migr.
-- =====================================================================

-- ---------- users (con MFA da 001 + role/dept da 004) ----------
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'accountant', 'admin_reparto') NOT NULL DEFAULT 'accountant',
    department_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    mfa_secret VARCHAR(32) NULL,
    mfa_enabled BOOLEAN DEFAULT FALSE,
    mfa_backup_codes TEXT NULL,
    password_changed_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (is_active),
    INDEX idx_users_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- departments (004) ----------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- ---------- employees (con MFA da 001 + dept da 004) ----------
CREATE TABLE IF NOT EXISTS employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    fiscal_code VARCHAR(16) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NULL,
    mfa_secret VARCHAR(32) NULL,
    mfa_enabled BOOLEAN DEFAULT FALSE,
    mfa_backup_codes TEXT NULL,
    password_changed_at TIMESTAMP NULL,
    phone VARCHAR(20) NULL,
    department VARCHAR(100) NULL,
    department_id INT NULL,
    position VARCHAR(100) NULL,
    hire_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_username (username),
    INDEX idx_fiscal_code (fiscal_code),
    INDEX idx_active (is_active),
    INDEX idx_name (last_name, first_name),
    INDEX idx_employees_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- documents ----------
CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    type ENUM('payslip', 'cud', 'other') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    month TINYINT NOT NULL,
    year SMALLINT NOT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_employee_date (employee_id, year, month),
    INDEX idx_type (type),
    INDEX idx_year_month (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- communications (con is_global + department_id da 006) ----------
CREATE TABLE IF NOT EXISTS communications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
    is_published BOOLEAN DEFAULT TRUE,
    publish_date DATE NOT NULL,
    expire_date DATE NULL,
    attachment_path VARCHAR(500) NULL,
    attachment_name VARCHAR(255) NULL,
    is_global BOOLEAN DEFAULT TRUE,
    department_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_communications_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_published (is_published, publish_date),
    INDEX idx_priority (priority),
    INDEX idx_dates (publish_date, expire_date),
    INDEX idx_communications_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- communication_reads ----------
CREATE TABLE IF NOT EXISTS communication_reads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    communication_id INT NOT NULL,
    employee_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (communication_id) REFERENCES communications(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_read (communication_id, employee_id),
    INDEX idx_employee (employee_id),
    INDEX idx_communication (communication_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- sessions ----------
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NULL,
    employee_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    payload TEXT NOT NULL,
    last_activity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_last_activity (last_activity),
    INDEX idx_user (user_id),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- audit_log (con severity + session_id da 001) ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    session_id VARCHAR(128) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at),
    INDEX idx_severity (severity),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- csrf_tokens ----------
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    session_id VARCHAR(128) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY unique_token (token),
    INDEX idx_session (session_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- api_tokens ----------
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token_hash VARCHAR(64) NOT NULL,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_token (token_hash),
    INDEX idx_user (user_type, user_id),
    INDEX idx_expires (expires_at),
    INDEX idx_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 001 - Tabelle di sicurezza
-- =====================================================================

CREATE TABLE IF NOT EXISTS security_alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alert_type VARCHAR(100) NOT NULL,
    user_type ENUM('admin', 'accountant', 'employee', 'unknown') NOT NULL,
    user_id INT NOT NULL DEFAULT 0,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_by INT NULL,
    resolved_at TIMESTAMP NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (alert_type),
    INDEX idx_user (user_type, user_id),
    INDEX idx_resolved (is_resolved),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_document (document_id),
    INDEX idx_user (user_type, user_id),
    INDEX idx_downloaded (downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    identifier VARCHAR(255) NOT NULL,
    action VARCHAR(50) NOT NULL,
    attempts INT DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rate (identifier, action),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 002 - Password reset
-- =====================================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 003 - GDPR
-- =====================================================================

CREATE TABLE IF NOT EXISTS gdpr_consents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    consent_type VARCHAR(50) NOT NULL,
    consent_given BOOLEAN NOT NULL DEFAULT FALSE,
    consent_text TEXT NULL,
    consent_version VARCHAR(20) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    given_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id),
    INDEX idx_consent_type (consent_type),
    INDEX idx_given_at (given_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_retention_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    action ENUM('archived', 'anonymized', 'deleted', 'exported') NOT NULL,
    reason VARCHAR(255) NULL,
    old_data JSON NULL,
    performed_by INT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_action (action),
    INDEX idx_performed (performed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_access_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    accessed_entity_type VARCHAR(50) NOT NULL,
    accessed_entity_id INT NULL,
    access_type ENUM('view', 'download', 'export', 'print') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id),
    INDEX idx_entity (accessed_entity_type, accessed_entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 004 - Reparti default
-- =====================================================================

INSERT IGNORE INTO departments (name, code, description) VALUES
    ('Anagrafe', 'ANA', 'Servizi anagrafici e stato civile'),
    ('Tributi', 'TRI', 'Gestione tributi comunali'),
    ('Ufficio Tecnico', 'TEC', 'Lavori pubblici e urbanistica'),
    ('Ragioneria', 'RAG', 'Gestione finanziaria e contabilita'),
    ('Servizi Sociali', 'SOC', 'Assistenza sociale e welfare'),
    ('Polizia Locale', 'POL', 'Vigilanza e sicurezza'),
    ('Segreteria Generale', 'SEG', 'Affari generali e protocollo'),
    ('Ambiente e Territorio', 'AMB', 'Ambiente e pianificazione territoriale'),
    ('Cultura e Sport', 'CUL', 'Attivita culturali e sportive'),
    ('Risorse Umane', 'RU', 'Gestione del personale');

-- =====================================================================
-- 005 - Leave requests
-- =====================================================================

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    leave_type ENUM('ferie','permesso','malattia','permesso_104','congedo_parentale','altro') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_full_day BOOLEAN DEFAULT TRUE,
    start_time TIME NULL,
    end_time TIME NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 007 - Chat e notifiche
-- =====================================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee', 'admin_reparto') NOT NULL,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(100) NOT NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(255)),
    INDEX idx_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT NULL,
    notification_id INT NULL,
    status ENUM('sent', 'failed', 'expired') NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscription (subscription_id),
    INDEX idx_notification (notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- VISTE
-- =====================================================================

CREATE OR REPLACE VIEW v_documents_with_employee AS
SELECT
    d.id, d.employee_id, d.type, d.title, d.description,
    d.file_name, d.original_name, d.file_size, d.mime_type,
    d.month, d.year, d.created_at,
    e.fiscal_code, e.first_name, e.last_name,
    CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
    u.name AS uploaded_by_name
FROM documents d
JOIN employees e ON d.employee_id = e.id
JOIN users u ON d.uploaded_by = u.id;

CREATE OR REPLACE VIEW v_active_communications AS
SELECT
    c.*,
    u.name AS author_name,
    (SELECT COUNT(*) FROM communication_reads cr WHERE cr.communication_id = c.id) AS read_count
FROM communications c
JOIN users u ON c.created_by = u.id
WHERE c.is_published = TRUE
    AND c.publish_date <= CURDATE()
    AND (c.expire_date IS NULL OR c.expire_date >= CURDATE());

CREATE OR REPLACE VIEW v_security_stats AS
SELECT
    DATE(created_at) as date,
    action,
    severity,
    COUNT(*) as count
FROM audit_log
WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), action, severity;

CREATE OR REPLACE VIEW v_login_stats AS
SELECT
    DATE(created_at) as date,
    user_type,
    CASE WHEN action = 'login_success' THEN 'success' ELSE 'failed' END as status,
    COUNT(*) as count
FROM audit_log
WHERE action IN ('login_success', 'login_failed')
AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at), user_type, status;

-- =====================================================================
-- ADMIN CONNECTEED (password: Cnctd!Admin#2026$Kp9 - bcrypt cost 12)
-- =====================================================================

INSERT INTO users (username, password_hash, role, name, email, is_active)
VALUES (
    'connecteed',
    '$2y$12$CY.a94aFdo5N.AjnLCxTdeaz02uXz9vN5vpthaxtPEE7sMwjNVqa.',
    'admin',
    'Connecteed Admin',
    'admin@connecteed.it',
    TRUE
) ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    role = 'admin',
    name = VALUES(name),
    email = VALUES(email),
    is_active = TRUE,
    failed_attempts = 0,
    locked_until = NULL;

-- =====================================================================
-- Registra tutte le migrazioni come gia eseguite
-- =====================================================================

INSERT IGNORE INTO migrations (migration) VALUES
    ('001_security_update.sql'),
    ('002_password_reset'),
    ('003_gdpr_tables'),
    ('004_departments'),
    ('005_leave_requests'),
    ('006_communications_department'),
    ('007_chat_system'),
    ('007_push_subscriptions.sql');

SET FOREIGN_KEY_CHECKS = 1;
