-- Migration 058: nome e codice reparto univoci per azienda, non piu globali.
-- install_full.sql creava UNIQUE(name) e UNIQUE(code) su tutta la tabella: un'azienda
-- non poteva creare un reparto con lo stesso nome di un'altra (es. "Amministrazione").
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND INDEX_NAME='name');
SET @sql = IF(@c>0, "ALTER TABLE departments DROP INDEX `name`", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND INDEX_NAME='code');
SET @sql = IF(@c>0, "ALTER TABLE departments DROP INDEX `code`", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND INDEX_NAME='uniq_dept_company_name');
SET @sql = IF(@c=0, "ALTER TABLE departments ADD UNIQUE KEY uniq_dept_company_name (company_id, name)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='departments' AND INDEX_NAME='uniq_dept_company_code');
SET @sql = IF(@c=0, "ALTER TABLE departments ADD UNIQUE KEY uniq_dept_company_code (company_id, code)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
