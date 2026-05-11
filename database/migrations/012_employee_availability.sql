-- Migration 012: Stato disponibilita dipendenti (Operativo / In chiamata / In riunione)
-- Idempotente, compatibile MySQL 5.7+ e MariaDB.

-- Colonna availability_status
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'availability_status'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE employees ADD COLUMN availability_status ENUM('operative','in_call','in_meeting') NOT NULL DEFAULT 'operative' AFTER photo_path",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Colonna availability_set_at
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'availability_set_at'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE employees ADD COLUMN availability_set_at TIMESTAMP NULL DEFAULT NULL AFTER availability_status",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
