-- ================================================
-- PAManager - Migration: Security Update
-- ================================================
-- Aggiunge supporto per:
-- - MFA (autenticazione a due fattori)
-- - Security alerts
-- - Audit log migliorato
-- ================================================

-- Aggiungi colonne MFA alla tabella users
ALTER TABLE users
    ADD COLUMN mfa_secret VARCHAR(32) NULL AFTER email,
    ADD COLUMN mfa_enabled BOOLEAN DEFAULT FALSE AFTER mfa_secret,
    ADD COLUMN mfa_backup_codes TEXT NULL AFTER mfa_enabled,
    ADD COLUMN password_changed_at TIMESTAMP NULL AFTER mfa_backup_codes;

-- Aggiungi colonne MFA alla tabella employees
ALTER TABLE employees
    ADD COLUMN mfa_secret VARCHAR(32) NULL AFTER email,
    ADD COLUMN mfa_enabled BOOLEAN DEFAULT FALSE AFTER mfa_secret,
    ADD COLUMN mfa_backup_codes TEXT NULL AFTER mfa_enabled,
    ADD COLUMN password_changed_at TIMESTAMP NULL AFTER mfa_backup_codes;

-- Aggiungi severity all'audit_log
ALTER TABLE audit_log
    ADD COLUMN severity ENUM('info', 'warning', 'critical') DEFAULT 'info' AFTER user_agent,
    ADD COLUMN session_id VARCHAR(128) NULL AFTER severity,
    ADD INDEX idx_severity (severity),
    ADD INDEX idx_session (session_id);

-- Tabella per security alerts
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

-- Tabella per tracciare download documenti
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

-- Tabella per rate limiting (persistente)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    identifier VARCHAR(255) NOT NULL,
    action VARCHAR(50) NOT NULL,
    attempts INT DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rate (identifier, action),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per GDPR consent tracking
CREATE TABLE IF NOT EXISTS gdpr_consents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    consent_type VARCHAR(50) NOT NULL,
    consent_given BOOLEAN NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id),
    INDEX idx_type (consent_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per data retention policy
CREATE TABLE IF NOT EXISTS data_retention_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    action ENUM('archived', 'deleted', 'anonymized') NOT NULL,
    reason VARCHAR(255) NULL,
    performed_by INT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    old_data JSON NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_action (action),
    INDEX idx_performed (performed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vista per statistiche sicurezza
CREATE OR REPLACE VIEW v_security_stats AS
SELECT
    DATE(created_at) as date,
    action,
    severity,
    COUNT(*) as count
FROM audit_log
WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), action, severity;

-- Vista per login stats
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

-- Stored procedure per pulizia dati vecchi (GDPR compliance)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_data()
BEGIN
    -- Elimina log audit più vecchi di 2 anni
    DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);

    -- Elimina rate limits scaduti
    DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY);

    -- Elimina alert risolti più vecchi di 1 anno
    DELETE FROM security_alerts WHERE is_resolved = TRUE AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

    -- Log della pulizia
    INSERT INTO data_retention_log (entity_type, entity_id, action, reason)
    VALUES ('system', 0, 'deleted', 'Automated cleanup - GDPR compliance');
END //
DELIMITER ;

-- Event per pulizia automatica settimanale
CREATE EVENT IF NOT EXISTS cleanup_old_data_event
ON SCHEDULE EVERY 1 WEEK
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_old_data();
