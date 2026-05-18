-- Migration 027: foto profilo per utenti (admin, accountant, consulente_lavoro).
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='photo_path');
SET @sql = IF(@c=0,
  "ALTER TABLE users ADD COLUMN photo_path VARCHAR(500) NULL AFTER email",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
