-- Migration 065: chat come quella interna del CRM Connecteed.
-- Gruppi con nome e foto, lettura per partecipante ("Visto da"), invio senza
-- doppioni (client_id), persone taggate, presenza stile Teams.
-- Idempotente.

-- Conversazioni: tipo, nome, foto, chi l'ha creata
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='conv_type');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN conv_type ENUM('direct','group') NOT NULL DEFAULT 'direct'", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='name');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN name VARCHAR(120) NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='avatar_path');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN avatar_path VARCHAR(500) NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='created_by');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN created_by VARCHAR(60) NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- I gruppi tengono il creatore nei due posti fissi: la chiave unica deve distinguerli
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='group_key');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN group_key VARCHAR(40) NOT NULL DEFAULT ''", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='unique_conv' AND COLUMN_NAME='group_key');
SET @k = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='unique_conv');
SET @sql = IF(@c=0 AND @k>0, "ALTER TABLE chat_conversations DROP INDEX unique_conv, ADD UNIQUE KEY unique_conv (participant1_type, participant1_id, participant2_type, participant2_id, group_key)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Messaggi: id dell'invio scelto dal client e persone taggate
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='client_id');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN client_id VARCHAR(64) NULL DEFAULT NULL, ADD INDEX idx_chat_client (conversation_id, sender_type, sender_id, client_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='mentions');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN mentions TEXT NULL DEFAULT NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Fin dove ha letto ciascun partecipante
CREATE TABLE IF NOT EXISTS chat_reads (
    conversation_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    user_id INT NOT NULL,
    last_read_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id, user_type, user_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presenza stile Teams: segnale ogni 45 secondi dall'app aperta, stato scelto a mano
CREATE TABLE IF NOT EXISTS chat_presence (
    company_id INT NOT NULL,
    user_type VARCHAR(30) NOT NULL,
    user_id INT NOT NULL,
    last_seen_at DATETIME NULL DEFAULT NULL,
    last_active_at DATETIME NULL DEFAULT NULL,
    in_call_until DATETIME NULL DEFAULT NULL,
    manual_status ENUM('occupato','non_disturbare','assente') NULL DEFAULT NULL,
    PRIMARY KEY (company_id, user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
