-- Migration 037: flag per abilitare/disabilitare la timbratura NFC per azienda.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'timbratura_enabled');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN timbratura_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER default_ccnl_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
