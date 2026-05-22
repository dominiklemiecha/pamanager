-- Migration 038: Door Access (apertura porta via NFC + ESP32)

-- 1) UID NFC del dipendente per apertura porta (riusa stessa card della timbratura)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'nfc_uid');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN nfc_uid VARCHAR(20) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_employees_company_nfc_uid');
SET @sql = IF(@c = 0, "CREATE UNIQUE INDEX idx_employees_company_nfc_uid ON employees(company_id, nfc_uid)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Configurazione modulo Door per tenant
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'door_enabled');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN door_enabled TINYINT(1) NOT NULL DEFAULT 0", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'door_api_key');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN door_api_key VARCHAR(64) NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'door_open_duration_ms');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN door_open_duration_ms INT NOT NULL DEFAULT 3000", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Log accessi (tutti i tentativi, anche falliti)
CREATE TABLE IF NOT EXISTS door_access_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  employee_id INT NULL,
  nfc_uid VARCHAR(20) NOT NULL,
  granted TINYINT(1) NOT NULL,
  reason VARCHAR(50) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_door_log_company_date (company_id, created_at),
  INDEX idx_door_log_employee (employee_id),
  INDEX idx_door_log_uid (nfc_uid)
);
