-- Migration 016: estende isolation multi-tenant a chat, notifiche, settings, push, audit.
-- Idempotente.

-- =================== APP_SETTINGS (SMTP, branding, ...) ===================
-- Convertiamo PK da (setting_key) a (setting_key, company_id) per scoping per azienda.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_settings' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE app_settings ADD COLUMN company_id INT NOT NULL DEFAULT 1", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Sostituisci PK (drop + add composite)
SET @hasPk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_settings'
              AND INDEX_NAME='PRIMARY' AND COLUMN_NAME='company_id');
SET @sql = IF(@hasPk=0,
    "ALTER TABLE app_settings DROP PRIMARY KEY, ADD PRIMARY KEY (setting_key, company_id)",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =================== CHAT_CONVERSATIONS ===================
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE chat_conversations ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_conv_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =================== CHAT_MESSAGES (denormalizzato per filtri veloci) ===================
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE chat_messages ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_msg_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =================== NOTIFICATIONS ===================
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE notifications ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_notif_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =================== PUSH_SUBSCRIPTIONS ===================
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='push_subscriptions' AND COLUMN_NAME='company_id');
SET @sql = IF(@c=0, "ALTER TABLE push_subscriptions ADD COLUMN company_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_push_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =================== AUDIT LOGS (se esiste) ===================
SET @hasTable = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs');
SET @c = IF(@hasTable=0, 1, (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='company_id'));
SET @sql = IF(@hasTable=1 AND @c=0, "ALTER TABLE audit_logs ADD COLUMN company_id INT NULL AFTER id, ADD INDEX idx_audit_company (company_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
