-- Migration 039: rendere username e fiscal_code unici per azienda (non globalmente).
-- Permette di avere lo stesso dipendente (o lo stesso username) in aziende diverse
-- dello stesso tenant, e di ricreare un dipendente eliminato in un'altra azienda.

ALTER TABLE employees DROP INDEX username;
ALTER TABLE employees DROP INDEX fiscal_code;

ALTER TABLE employees
    ADD UNIQUE KEY unique_company_username (company_id, username),
    ADD UNIQUE KEY unique_company_fiscal   (company_id, fiscal_code);
