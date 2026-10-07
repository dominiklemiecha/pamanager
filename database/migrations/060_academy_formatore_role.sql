-- Migration 060: ruolo 'formatore' (Responsabile formazione).
-- Utenza creata da HR per una sola azienda, vede solo il modulo Academy
-- (corsi, partecipanti, registro, modelli e generazione attestati).
-- Estende gli enum dei tipi utente dove serve: login, notifiche, push, reset password.
-- Idempotente: MODIFY con la lista completa.

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','accountant','admin_reparto','consulente_lavoro','formatore') NOT NULL DEFAULT 'accountant';

ALTER TABLE notifications
  MODIFY COLUMN recipient_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro','formatore') NOT NULL;

ALTER TABLE push_subscriptions
  MODIFY COLUMN user_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro','formatore') NOT NULL;

ALTER TABLE password_reset_tokens
  MODIFY COLUMN user_type ENUM('admin','accountant','employee','consulente_lavoro','admin_reparto','formatore') NOT NULL;

ALTER TABLE password_reset_requests
  MODIFY COLUMN user_type ENUM('admin','accountant','employee','consulente_lavoro','admin_reparto','formatore') NOT NULL;
