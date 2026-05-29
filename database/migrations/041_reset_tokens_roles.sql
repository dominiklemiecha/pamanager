-- Migration 041: estende user_type dei reset password a consulente_lavoro e admin_reparto.
-- Prima erano fuori dall'ENUM => la richiesta di reset falliva (INSERT troncato)
-- e quei ruoli non ricevevano l'email di recupero.

ALTER TABLE password_reset_tokens
    MODIFY user_type ENUM('admin','accountant','employee','consulente_lavoro','admin_reparto') NOT NULL;

ALTER TABLE password_reset_requests
    MODIFY user_type ENUM('admin','accountant','employee','consulente_lavoro','admin_reparto') NOT NULL;

-- audit_log: user_type era ENUM ristretto e troncava consulente_lavoro / admin_reparto /
-- 'unknown' (login falliti). VARCHAR e' piu' robusto per i log.
ALTER TABLE audit_log
    MODIFY user_type VARCHAR(32) NULL;
