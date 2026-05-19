-- Migration 031: sistema calendario eventi multi-utente.
-- - calendar_events: evento posseduto da un utente
-- - calendar_event_participants: invitati con stato pending/accepted/declined
-- Tabelle prefissate "calendar_" per evitare conflitti con `events` di altre feature.

CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL DEFAULT 1,
    owner_type ENUM('admin','admin_reparto','employee','accountant','consulente_lavoro') NOT NULL,
    owner_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    color VARCHAR(20) NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_calevents_company (company_id),
    INDEX idx_calevents_owner (owner_type, owner_id),
    INDEX idx_calevents_range (company_id, start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_type ENUM('admin','admin_reparto','employee','accountant','consulente_lavoro') NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    INDEX idx_calep_user (user_type, user_id),
    UNIQUE KEY uniq_calevent_user (event_id, user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
