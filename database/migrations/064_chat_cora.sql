-- Migration 064: funzioni della chat in stile CRM Connecteed (vista Cora del dipendente).
-- Messaggi: risposta citata, modifica (entro 15 minuti), inoltro, reazioni con emoji
-- e ora di lettura. Conversazioni: "fissa in alto" e "elimina per me" per ciascun utente.
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='reply_to_id');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN reply_to_id INT NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='edited_at');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN edited_at DATETIME NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='forwarded');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN forwarded TINYINT(1) NOT NULL DEFAULT 0", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- {"👍": ["employee:12", "admin:3"], ...}
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='reactions');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN reactions TEXT NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='read_at');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN read_at DATETIME NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS chat_conversation_prefs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    user_id INT NOT NULL,
    pinned_at DATETIME NULL DEFAULT NULL,
    hidden_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_conv_user (conversation_id, user_type, user_id),
    INDEX idx_user (user_type, user_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
