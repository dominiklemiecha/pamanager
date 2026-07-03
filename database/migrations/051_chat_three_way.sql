-- Migration 051: chat a tre (dipendente + admin + consulente lavoro).
-- Terzo partecipante opzionale sulla conversazione (invitato da admin/consulente)
-- + flag is_internal sui messaggi: visibili solo allo staff, mai al dipendente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_conversations' AND COLUMN_NAME = 'participant3_type');
SET @sql = IF(@c = 0, "ALTER TABLE chat_conversations ADD COLUMN participant3_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NULL DEFAULT NULL AFTER participant2_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_conversations' AND COLUMN_NAME = 'participant3_id');
SET @sql = IF(@c = 0, "ALTER TABLE chat_conversations ADD COLUMN participant3_id INT NULL DEFAULT NULL AFTER participant3_type", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'is_internal');
SET @sql = IF(@c = 0, "ALTER TABLE chat_messages ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
