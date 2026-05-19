-- Migration 033: collegamento employee → CCNL + tracking idempotenza accrual mensile.
-- - employees.ccnl_id FK opzionale a ccnl_templates
-- - employees.ferie_year_override / permessi_year_override per override per singolo dipendente
-- - employee_leave_balances.accrual_last_month (YYYY-MM) per evitare doppia applicazione

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'ccnl_id');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN ccnl_id INT NULL AFTER department_id, ADD INDEX idx_emp_ccnl (ccnl_id)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'ferie_year_override');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN ferie_year_override DECIMAL(5,2) NULL AFTER ccnl_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'permessi_year_override');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN permessi_year_override DECIMAL(6,2) NULL AFTER ferie_year_override", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_leave_balances' AND COLUMN_NAME = 'accrual_last_month');
SET @sql = IF(@c = 0, "ALTER TABLE employee_leave_balances ADD COLUMN accrual_last_month VARCHAR(7) NULL AFTER manual_used, ADD INDEX idx_elb_accrual (accrual_last_month)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
