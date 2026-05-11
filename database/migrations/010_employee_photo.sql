-- Migration 010: Aggiunge colonna photo_path a employees per foto profilo
-- Idempotente tramite check INFORMATION_SCHEMA + SET @sql + PREPARE/EXECUTE
-- Compatibile MySQL 5.7+ e MariaDB.

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'photo_path'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE employees ADD COLUMN photo_path VARCHAR(255) NULL AFTER email',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
