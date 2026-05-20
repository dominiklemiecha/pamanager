-- Migration 036: timbrature entrata/uscita tramite NFC NTAG215 (URL singolo per tutti).

CREATE TABLE IF NOT EXISTS attendance_punches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL DEFAULT 1,
    employee_id INT NOT NULL,
    punch_at DATETIME NOT NULL,
    kind ENUM('in','out') NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'nfc',
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_punch_company (company_id),
    INDEX idx_punch_employee (employee_id, punch_at),
    INDEX idx_punch_date (company_id, punch_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
