-- Migration 053: Periodo di prova + alert scadenza + decisione admin.
-- Il periodo di prova vive sul dipendente (vale per nuovo flusso assunzione,
-- crea-diretto e dipendenti storici). 14 giorni prima della fine prova scatta
-- un alert: l'admin conferma o non conferma. Su "non conferma" il dipendente
-- viene disattivato automaticamente ALLA data di fine prova (non subito).

-- probation_end_date: cuore di tutto. NULL = nessun periodo di prova tracciato.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_end_date');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_end_date DATE NULL DEFAULT NULL AFTER hire_date", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- probation_decision: decisione admin. NULL = ancora da decidere.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_decision');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_decision ENUM('confirmed','not_confirmed') NULL DEFAULT NULL AFTER probation_end_date", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- probation_decided_at / _by: quando e chi ha deciso.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_decided_at');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_decided_at DATETIME NULL DEFAULT NULL AFTER probation_decision", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_decided_by_user_id');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_decided_by_user_id INT(11) NULL DEFAULT NULL AFTER probation_decided_at", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- probation_alert_notified_at: idempotenza dell'alert (evita notifiche/email doppie).
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_alert_notified_at');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_alert_notified_at DATETIME NULL DEFAULT NULL AFTER probation_decided_by_user_id", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- probation_deactivated_at: traccia la disattivazione automatica avvenuta a fine prova.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'probation_deactivated_at');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD COLUMN probation_deactivated_at DATETIME NULL DEFAULT NULL AFTER probation_alert_notified_at", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indice per le query di scadenza prova (runChecks gira ad ogni load dashboard admin).
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_probation_end');
SET @sql = IF(@c = 0, "ALTER TABLE employees ADD KEY idx_probation_end (probation_end_date)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
