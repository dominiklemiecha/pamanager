-- Migration 059: modulo Academy (corsi interni con materiali, frequenza tracciata e attestati).
--  - academy_courses: corso dell'azienda (titolo, ore, scadenza facoltativa, modello attestato)
--  - academy_materials: file caricati o link esterni del corso
--  - academy_assignments: corso assegnato a un dipendente (inizio, completamento, solleciti)
--  - academy_material_views: prima/ultima apertura di ogni materiale per assegnazione
--  - academy_events: registro append-only con catena di hash (prova di frequenza)
--  - academy_certificate_templates: modelli attestato disegnati nell'editor (layout_json)
--  - academy_certificates: attestati emessi, PDF salvato una volta con hash e token di verifica
-- Idempotente.

CREATE TABLE IF NOT EXISTS academy_certificate_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    orientation ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
    layout_json LONGTEXT NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_act_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    duration_hours DECIMAL(6,2) NULL,
    due_date DATE NULL,
    certificate_template_id INT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ac_company (company_id, status),
    CONSTRAINT fk_ac_template FOREIGN KEY (certificate_template_id)
        REFERENCES academy_certificate_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_materials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    course_id INT NOT NULL,
    kind ENUM('file','link') NOT NULL,
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT NULL,
    url VARCHAR(1000) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_am_course (course_id, sort_order),
    CONSTRAINT fk_am_course FOREIGN KEY (course_id) REFERENCES academy_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    course_id INT NOT NULL,
    employee_id INT NOT NULL,
    assigned_by_user_id INT NULL,
    assigned_at DATETIME NOT NULL,
    notify_pending TINYINT(1) NOT NULL DEFAULT 0,
    notify_email TINYINT(1) NOT NULL DEFAULT 1,
    started_at DATETIME NULL,
    started_ip VARCHAR(45) NULL,
    completed_at DATETIME NULL,
    completed_ip VARCHAR(45) NULL,
    completed_user_agent VARCHAR(500) NULL,
    declaration_text TEXT NULL,
    reminder_count INT NOT NULL DEFAULT 0,
    last_reminder_at DATETIME NULL,
    due_reminder_sent_at DATETIME NULL,
    UNIQUE KEY uq_aa_course_employee (course_id, employee_id),
    INDEX idx_aa_company (company_id, course_id),
    INDEX idx_aa_employee (employee_id),
    INDEX idx_aa_notify (company_id, notify_pending),
    CONSTRAINT fk_aa_course FOREIGN KEY (course_id) REFERENCES academy_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_material_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT NOT NULL,
    material_id INT NOT NULL,
    first_opened_at DATETIME NOT NULL,
    last_opened_at DATETIME NOT NULL,
    open_count INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_amv (assignment_id, material_id),
    CONSTRAINT fk_amv_assignment FOREIGN KEY (assignment_id) REFERENCES academy_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_amv_material FOREIGN KEY (material_id) REFERENCES academy_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro eventi: solo INSERT. Niente FK verso assegnazioni/corsi, cosi il registro
-- sopravvive anche se un corso viene rimosso. hash = sha256(prev_hash + dati riga).
CREATE TABLE IF NOT EXISTS academy_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    course_id INT NULL,
    assignment_id INT NULL,
    employee_id INT NULL,
    material_id INT NULL,
    event VARCHAR(40) NOT NULL,
    actor_type VARCHAR(30) NOT NULL,
    actor_id INT NOT NULL DEFAULT 0,
    details VARCHAR(1000) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    prev_hash CHAR(64) NULL,
    hash CHAR(64) NOT NULL,
    INDEX idx_ae_company (company_id, id),
    INDEX idx_ae_assignment (assignment_id, id),
    INDEX idx_ae_course (course_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    assignment_id INT NOT NULL,
    number VARCHAR(40) NOT NULL,
    seq_year INT NOT NULL,
    seq_number INT NOT NULL,
    issued_at DATETIME NOT NULL,
    issued_by_user_id INT NULL,
    file_path VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    verify_token CHAR(32) NOT NULL,
    employee_downloaded_at DATETIME NULL,
    UNIQUE KEY uq_acert_assignment (assignment_id),
    UNIQUE KEY uq_acert_number (company_id, number),
    UNIQUE KEY uq_acert_seq (company_id, seq_year, seq_number),
    UNIQUE KEY uq_acert_token (verify_token),
    CONSTRAINT fk_acert_assignment FOREIGN KEY (assignment_id) REFERENCES academy_assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
