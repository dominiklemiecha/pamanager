-- Migration 048: buoni pasto v2.
-- Giorni smart working ricorrenti anche a livello azienda (default, override su employees)
-- + toggle per rendere opzionale la soglia ore minima.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'smart_working_days');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN smart_working_days VARCHAR(30) NULL DEFAULT NULL AFTER hours_per_day", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'buoni_pasto_min_hours_enabled');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN buoni_pasto_min_hours_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER buoni_pasto_enabled", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
