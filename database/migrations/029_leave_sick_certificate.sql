-- Migration 029: campi documentali per richieste di malattia.
-- Aggiunge protocollo (numero) e certificato medico (file separato dall'attachment esistente).
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'protocol_number');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests ADD COLUMN protocol_number VARCHAR(100) NULL AFTER attachment_name", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'certificate_path');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests ADD COLUMN certificate_path VARCHAR(255) NULL AFTER protocol_number", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'certificate_name');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests ADD COLUMN certificate_name VARCHAR(255) NULL AFTER certificate_path", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND INDEX_NAME = 'idx_leave_sick_pending_docs');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests ADD INDEX idx_leave_sick_pending_docs (leave_type, status, created_at)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
