-- Migration 057: notifiche delle distribuzioni massive processate in background
-- (batch via AJAX) invece che durante l'upload.
-- Idempotente.

ALTER TABLE employee_documents
    ADD COLUMN IF NOT EXISTS notify_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_to_employee;

ALTER TABLE employee_documents
    ADD COLUMN IF NOT EXISTS notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_pending;

ALTER TABLE employee_documents
    ADD INDEX IF NOT EXISTS idx_ed_notify_pending (company_id, notify_pending);
