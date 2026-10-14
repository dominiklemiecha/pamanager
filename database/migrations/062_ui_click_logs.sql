-- Migration 062: log dei clic dell'utenza HR (ruolo admin) su pulsanti e link.
-- Serve a capire quali funzioni vengono usate di piu'. Report in /superadmin/clic.php.
-- Idempotente.

CREATE TABLE IF NOT EXISTS ui_click_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL,
    user_id INT NOT NULL,
    page VARCHAR(120) NOT NULL,
    area VARCHAR(40) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    label VARCHAR(100) NOT NULL,
    target VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ucl_created (created_at),
    INDEX idx_ucl_company_created (company_id, created_at),
    INDEX idx_ucl_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
