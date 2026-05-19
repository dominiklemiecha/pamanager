-- Migration 034: aggiunge default CCNL a livello azienda.
-- I dipendenti senza ccnl_id ereditano questo valore.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'default_ccnl_id');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN default_ccnl_id INT NULL AFTER hours_per_day, ADD INDEX idx_comp_ccnl (default_ccnl_id)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
