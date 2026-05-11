-- Migration 011: Crea tabella push_logs mancante in produzione
-- Causa: collisione di numerazione 007 (chat_system.php + push_subscriptions.sql), la SQL non è stata eseguita
-- Risultato: tabella push_logs non creata, push-debug.php crasha quando tenta di leggerla
-- Idempotente: usa CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS push_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subscription_id INT NULL,
    notification_id INT NULL,
    status ENUM('sent', 'failed', 'expired') NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscription (subscription_id),
    INDEX idx_notification (notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
