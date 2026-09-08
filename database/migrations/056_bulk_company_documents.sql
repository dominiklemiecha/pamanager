-- Migration 056: distribuzione massiva di un documento a tutti i dipendenti attivi.
-- Le righe generate condividono lo stesso file su disco e sono raggruppate da bulk_group.
-- Idempotente.

ALTER TABLE employee_documents
    ADD COLUMN IF NOT EXISTS bulk_group VARCHAR(64) NULL AFTER company_id;

ALTER TABLE employee_documents
    ADD INDEX IF NOT EXISTS idx_ed_bulk_group (company_id, bulk_group);
