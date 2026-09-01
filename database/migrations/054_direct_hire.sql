-- Migration 054: assunzione diretta.
-- L'admin puo' creare dipendente e utenza saltando il flusso consulente
-- (prospetti, approvazione, contratto da firmare). Nuovo stato dedicato.

ALTER TABLE hire_requests
    MODIFY status ENUM(
        'draft',
        'awaiting_prospects',
        'prospects_review',
        'approved',
        'contract_pending',
        'contract_signed',
        'direct_hire',
        'rejected',
        'cancelled'
    ) NOT NULL DEFAULT 'draft';
