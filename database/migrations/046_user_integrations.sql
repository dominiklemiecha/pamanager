-- 046: integrazioni esterne per-utente (Wrike per il consulente del lavoro).
-- Token salvato cifrato (AES-256-GCM, vedi classe Wrike), config JSON con
-- host datacenter, cartella di destinazione, toggle assunzioni/chat e mapping
-- conversazione->task per il dedupe della chat.

CREATE TABLE IF NOT EXISTS user_integrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    provider VARCHAR(30) NOT NULL,
    access_token TEXT NULL,
    config TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_error VARCHAR(255) NULL,
    last_success_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_provider (user_id, provider),
    KEY idx_user (user_id),
    CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
