-- Migration 022: allegati nei messaggi di chat.
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='attachment_path');
SET @sql = IF(@c=0,
  "ALTER TABLE chat_messages
   ADD COLUMN attachment_path VARCHAR(500) NULL,
   ADD COLUMN attachment_name VARCHAR(255) NULL,
   ADD COLUMN attachment_size INT NULL,
   ADD COLUMN attachment_mime VARCHAR(100) NULL",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
