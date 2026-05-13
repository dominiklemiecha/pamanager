-- Migration 019: aggiunge nuovi tipi di assenza all'enum leave_requests.leave_type.
-- Aggiunti: congedo_separazione, congedo_mestruale.
-- Idempotente: ALTER MODIFY non fallisce se l'enum e' gia' esteso.

ALTER TABLE leave_requests
  MODIFY COLUMN leave_type ENUM(
    'ferie',
    'permesso',
    'malattia',
    'permesso_104',
    'congedo_parentale',
    'congedo_separazione',
    'congedo_mestruale',
    'altro',
    'chiusura'
  ) NOT NULL;
