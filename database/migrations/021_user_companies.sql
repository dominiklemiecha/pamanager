-- Migration 021: assegnazione multi-azienda per utenti staff esterni
-- (accountant, consulente_lavoro).
-- Quando un utente ha righe in user_companies, vede SOLO quelle aziende.
-- Altrimenti fallback al singolo users.company_id (retrocompatibile).
-- Idempotente.

CREATE TABLE IF NOT EXISTS user_companies (
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, company_id),
    INDEX idx_uc_company (company_id),
    CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uc_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
