-- Migration 050: nuovo tipo richiesta 'smart_working'.
-- Il dipendente richiede giornate di smart working come i permessi; nell'export
-- compare col codice SW e ai fini buoni pasto segue la regola sw_eligible aziendale.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'leave_type' AND COLUMN_TYPE LIKE '%smart_working%');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests MODIFY COLUMN leave_type ENUM('ferie','permesso','malattia','permesso_104','congedo_parentale','congedo_separazione','congedo_mestruale','altro','chiusura','smart_working') NOT NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
