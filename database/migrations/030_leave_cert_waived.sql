-- Migration 030: aggiunge flag certificate_waived per richieste di malattia.
-- Admin puo' decidere di non richiedere il certificato medico per una specifica richiesta,
-- escludendola dagli alert. Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'certificate_waived');
SET @sql = IF(@c = 0, "ALTER TABLE leave_requests ADD COLUMN certificate_waived TINYINT(1) NOT NULL DEFAULT 0 AFTER certificate_name", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
