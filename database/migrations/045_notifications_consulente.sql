-- 045: il ruolo consulente_lavoro mancava dagli ENUM di notifiche e push.
-- Tutte le Notification::create verso il consulente (flusso assunzioni) fallivano
-- in silenzio per "Data truncated for column recipient_type".

ALTER TABLE notifications
    MODIFY recipient_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL;

ALTER TABLE push_subscriptions
    MODIFY user_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL;
