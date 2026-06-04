-- Migration 043: flag per evitare badge "nuovo" sui documenti trasferiti automaticamente
-- (es. id_doc, CF, permesso, C2 caricati durante richiesta di assunzione).

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS notify_employee TINYINT(1) NOT NULL DEFAULT 1;
