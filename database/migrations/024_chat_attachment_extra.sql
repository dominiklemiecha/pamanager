-- Migration 024: completa le colonne allegato chat che la 022 ha potuto saltare.
-- attachment_path/name esistevano gia' nello schema iniziale; size/mime no.
-- Idempotente per singola colonna.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='attachment_size');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN attachment_size INT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='attachment_mime');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN attachment_mime VARCHAR(100) NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
