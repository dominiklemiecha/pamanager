-- Migration 020: aggiunge ruolo 'consulente_lavoro' all'enum users.role.
-- Idempotente.

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','accountant','admin_reparto','consulente_lavoro') NOT NULL DEFAULT 'accountant';
