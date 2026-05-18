-- Migration 028: estende ENUM chat per includere consulente_lavoro.
-- Idempotente: ALTER TABLE è sempre sicuro qui anche se già modificato.

ALTER TABLE chat_conversations
  MODIFY COLUMN participant1_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL,
  MODIFY COLUMN participant2_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL;

ALTER TABLE chat_messages
  MODIFY COLUMN sender_type ENUM('admin','accountant','employee','admin_reparto','consulente_lavoro') NOT NULL;
