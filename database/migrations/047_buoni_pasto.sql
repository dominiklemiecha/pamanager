-- Migration 047: buoni pasto (ticket restaurant) + giorni smart working ricorrenti.
-- Config azienda: abilitazione, soglia ore minima, regola smart working.
-- Config dipendente: giorni SW ricorrenti + override soglia/regola SW + esclusione.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'buoni_pasto_enabled');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN buoni_pasto_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER hours_per_day", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'buoni_pasto_min_hours');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN buoni_pasto_min_hours DECIMAL(4,2) NOT NULL DEFAULT 6.00 AFTER buoni_pasto_enabled", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'buoni_pasto_sw_eligible');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN buoni_pasto_sw_eligible TINYINT(1) NOT NULL DEFAULT 0 AFTER buoni_pasto_min_hours", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Giorni smart working ricorrenti: CSV 'mon,tue,...' come working_days. NULL = nessuno.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'smart_working_days');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN smart_working_days VARCHAR(30) NULL DEFAULT NULL AFTER hours_per_day", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'buoni_pasto_min_hours_override');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN buoni_pasto_min_hours_override DECIMAL(4,2) NULL DEFAULT NULL AFTER smart_working_days", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'buoni_pasto_sw_eligible_override');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN buoni_pasto_sw_eligible_override TINYINT(1) NULL DEFAULT NULL AFTER buoni_pasto_min_hours_override", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'buoni_pasto_excluded');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN buoni_pasto_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER buoni_pasto_sw_eligible_override", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
