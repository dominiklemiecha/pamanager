-- Migration 049: pausa pranzo aziendale (opzionale).
-- Se configurata, i permessi a ore che la attraversano scalano la sovrapposizione
-- (saldo, export presenze, durata mostrata). NULL = nessuna deduzione.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lunch_break_start');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN lunch_break_start TIME NULL DEFAULT NULL AFTER smart_working_days", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lunch_break_end');
SET @sql = IF(@c = 0, "ALTER TABLE companies ADD COLUMN lunch_break_end TIME NULL DEFAULT NULL AFTER lunch_break_start", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
