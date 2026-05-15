-- ================================================
-- PAManager - Schema Database
-- ================================================
-- Eseguire questo script per creare il database
-- MySQL 8.0+
-- ================================================

-- Crea database se non esiste
CREATE DATABASE IF NOT EXISTS gestionale_pa
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE gestionale_pa;

-- ================================================
-- Tabella utenti (admin e commercialista)
-- ================================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'accountant') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- Tabella dipendenti (con credenziali di accesso)
-- ================================================
CREATE TABLE IF NOT EXISTS employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    fiscal_code VARCHAR(16) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    department VARCHAR(100) NULL,
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
    INDEX idx_username (username),
    INDEX idx_fiscal_code (fiscal_code),
    INDEX idx_active (is_active),
    INDEX idx_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- Tabella documenti
-- ================================================
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

-- ================================================
-- Tabella comunicazioni
-- ================================================
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
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_published (is_published, publish_date),
    INDEX idx_priority (priority),
    INDEX idx_dates (publish_date, expire_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- Tabella lettura comunicazioni
-- ================================================
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

-- ================================================
-- Tabella sessioni
-- ================================================
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

-- ================================================
-- Tabella log accessi (audit)
-- ================================================
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- Tabella token CSRF
-- ================================================
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

-- ================================================
-- Tabella token API (per JWT blacklist)
-- ================================================
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

-- ================================================
-- Inserimento admin di default
-- Password: Admin123! (cambiare al primo accesso)
-- ================================================
INSERT INTO users (username, password_hash, role, name, email, is_active)
VALUES (
    'admin',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4o4aCuyhHG1MfJ2e',
    'admin',
    'Amministratore Sistema',
    'admin@comune.example.it',
    TRUE
) ON DUPLICATE KEY UPDATE id=id;

-- ================================================
-- Stored procedure per pulizia sessioni scadute
-- ================================================
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE last_activity < (UNIX_TIMESTAMP() - 1800);
    DELETE FROM csrf_tokens WHERE expires_at < NOW();
    DELETE FROM api_tokens WHERE expires_at < NOW() AND revoked_at IS NULL;
END //
DELIMITER ;

-- ================================================
-- Event per pulizia automatica (opzionale)
-- Richiede: SET GLOBAL event_scheduler = ON;
-- ================================================
CREATE EVENT IF NOT EXISTS cleanup_sessions_event
ON SCHEDULE EVERY 1 HOUR
DO CALL cleanup_expired_sessions();

-- ================================================
-- Vista per documenti con info dipendente
-- ================================================
CREATE OR REPLACE VIEW v_documents_with_employee AS
SELECT
    d.id,
    d.employee_id,
    d.type,
    d.title,
    d.description,
    d.file_name,
    d.original_name,
    d.file_size,
    d.mime_type,
    d.month,
    d.year,
    d.created_at,
    e.fiscal_code,
    e.first_name,
    e.last_name,
    CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
    u.name AS uploaded_by_name
FROM documents d
JOIN employees e ON d.employee_id = e.id
JOIN users u ON d.uploaded_by = u.id;

-- ================================================
-- Vista per comunicazioni attive
-- ================================================
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
