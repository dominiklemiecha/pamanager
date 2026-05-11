-- Migration 015: Multi-tenant per azienda
-- Crea tabella companies + aggiunge company_id a tutte le tabelle dati.
-- Assegna tutti i dati esistenti a company_id=1 ("Azienda" placeholder, admin la rinominera al primo login).

-- ========== Tabella companies ==========
CREATE TABLE IF NOT EXISTS companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(64) NULL,
    logo_path VARCHAR(255) NULL,
    needs_setup TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO companies (id, name, needs_setup, is_active)
SELECT 1, 'Azienda', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE id = 1);

-- ========== Aggiungi company_id alle tabelle dati ==========
-- Helper macro per add-column-if-missing (MySQL non lo ha nativo)
SET @add_col = '';

-- users (NULL = admin globale che vede tutte; not null = utente legato a una)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE users ADD COLUMN company_id INT NULL AFTER role, ADD INDEX idx_user_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- employees
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_emp_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- departments
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE departments ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_dept_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- documents
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE documents ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_doc_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- leave_requests
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leave_requests' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE leave_requests ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_leave_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- communications
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='communications' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE communications ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_comm_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- medical_certificates
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='medical_certificates' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE medical_certificates ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_medcert_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Assicura che gli admin esistenti restino "globali" (company_id NULL = accesso a tutte)
UPDATE users SET company_id = NULL WHERE role = 'admin' AND (company_id = 0 OR company_id IS NULL);
