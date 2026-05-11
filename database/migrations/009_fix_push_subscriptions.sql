-- Migration 009: Fix push_subscriptions schema
-- In produzione la tabella push_subscriptions è stata creata con uno schema obsoleto
-- (manca la colonna user_type). Le sottoscrizioni esistenti sono inutilizzabili
-- (codice corrente le interroga via user_type/user_id), quindi facciamo drop & recreate.
-- Idempotente: si può eseguire più volte senza danni.

DROP TABLE IF EXISTS push_subscriptions;

CREATE TABLE push_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'accountant', 'employee', 'admin_reparto') NOT NULL,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(100) NOT NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(255)),
    INDEX idx_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
