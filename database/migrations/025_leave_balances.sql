-- Migration 025: saldo ferie/permessi per dipendente + giorni lavorativi.
-- Idempotente.

-- ========== companies: giorni lavorativi default + ore/giorno ==========
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='working_days');
SET @sql = IF(@c=0,
  "ALTER TABLE companies ADD COLUMN working_days SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL DEFAULT 'mon,tue,wed,thu,fri'",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='hours_per_day');
SET @sql = IF(@c=0,
  "ALTER TABLE companies ADD COLUMN hours_per_day DECIMAL(4,2) NOT NULL DEFAULT 8.00",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ========== employees: override (NULL = usa default azienda) ==========
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='working_days');
SET @sql = IF(@c=0,
  "ALTER TABLE employees ADD COLUMN working_days SET('mon','tue','wed','thu','fri','sat','sun') NULL",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='hours_per_day');
SET @sql = IF(@c=0,
  "ALTER TABLE employees ADD COLUMN hours_per_day DECIMAL(4,2) NULL",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ========== tabella saldi ==========
CREATE TABLE IF NOT EXISTS employee_leave_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL DEFAULT 1,
    employee_id INT NOT NULL,
    year SMALLINT NOT NULL,
    leave_type ENUM('ferie','permesso') NOT NULL,
    entitled DECIMAL(6,2) NOT NULL DEFAULT 0,
    carried_over DECIMAL(6,2) NOT NULL DEFAULT 0,
    manual_used DECIMAL(6,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_balance (employee_id, year, leave_type),
    INDEX idx_balance_company (company_id),
    INDEX idx_balance_year (year),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
