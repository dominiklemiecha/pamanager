-- Migration 039: rendere username e fiscal_code unici per azienda (non globalmente).
-- Permette di avere lo stesso dipendente (o lo stesso username) in aziende diverse
-- dello stesso tenant, e di ricreare un dipendente eliminato in un'altra azienda.
-- Idempotente: usa IF EXISTS / IF NOT EXISTS (MariaDB 10.1+ / MySQL 8.0+).

ALTER TABLE employees DROP INDEX IF EXISTS username;
ALTER TABLE employees DROP INDEX IF EXISTS fiscal_code;

ALTER TABLE employees ADD UNIQUE KEY IF NOT EXISTS unique_company_username (company_id, username);
ALTER TABLE employees ADD UNIQUE KEY IF NOT EXISTS unique_company_fiscal   (company_id, fiscal_code);
