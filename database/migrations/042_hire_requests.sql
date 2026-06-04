-- Migration 042: Flusso di assunzione (admin -> consulente -> firma contratto)

CREATE TABLE IF NOT EXISTS hire_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    company_id INT(11) NOT NULL,
    status ENUM(
        'awaiting_prospects',   -- admin ha inviato richiesta, consulente deve caricare prospetti
        'prospects_review',     -- consulente ha caricato, admin deve approvare
        'approved',             -- admin ha approvato i prospetti, employee creato, consulente carica contratto
        'contract_pending',     -- consulente ha caricato contratto, dipendente deve firmare
        'contract_signed',      -- dipendente ha firmato, profilo attivo
        'rejected',             -- admin ha rifiutato i prospetti
        'cancelled'             -- admin ha annullato la richiesta
    ) NOT NULL DEFAULT 'awaiting_prospects',
    created_by_user_id INT(11) NOT NULL,
    assigned_consulente_user_id INT(11) NULL,

    -- Datore di lavoro
    employer_name VARCHAR(255) NOT NULL,

    -- Anagrafica risorsa
    employee_first_name VARCHAR(80) NOT NULL,
    employee_last_name VARCHAR(80) NOT NULL,
    employee_birth_date DATE NOT NULL,
    birth_state VARCHAR(80) NOT NULL,
    birth_city VARCHAR(120) NOT NULL,
    fiscal_code VARCHAR(16) NOT NULL,

    -- Residenza
    residence_address VARCHAR(255) NOT NULL,
    residence_cap VARCHAR(10) NOT NULL,
    residence_city VARCHAR(120) NOT NULL,
    residence_province VARCHAR(80) NOT NULL,

    -- Profilo
    marital_status ENUM('celibe_nubile','coniugato','divorziato','vedovo','unione_civile','separato') NOT NULL,
    education_level ENUM('nessuno','licenza_elementare','licenza_media','diploma','laurea_triennale','laurea_magistrale','dottorato') NOT NULL,

    -- Contratto (tipologia multipla)
    contract_indeterminato TINYINT(1) NOT NULL DEFAULT 0,
    contract_determinato TINYINT(1) NOT NULL DEFAULT 0,
    contract_apprendistato TINYINT(1) NOT NULL DEFAULT 0,
    contract_tirocinio TINYINT(1) NOT NULL DEFAULT 0,
    contract_agevolata TINYINT(1) NOT NULL DEFAULT 0,

    start_date DATE NOT NULL,
    end_date DATE NULL,
    role_description TEXT NOT NULL,
    weekly_hours DECIMAL(5,2) NOT NULL,
    work_days SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL,

    workplace VARCHAR(255) NOT NULL,
    cost_center VARCHAR(120) NULL,
    iban VARCHAR(34) NULL,

    -- Account
    employee_email VARCHAR(150) NOT NULL,
    personal_email VARCHAR(150) NULL,
    generated_username VARCHAR(80) NULL,

    notes TEXT NULL,

    -- Decisione admin
    rejection_reason TEXT NULL,
    decided_at TIMESTAMP NULL DEFAULT NULL,
    decided_by_user_id INT(11) NULL,

    -- Employee creato (dopo approvazione)
    employee_id INT(11) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_company (company_id),
    KEY idx_status (status),
    KEY idx_created_by (created_by_user_id),
    KEY idx_consulente (assigned_consulente_user_id),
    KEY idx_employee (employee_id),
    KEY idx_fiscal (fiscal_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hire_request_files (
    id INT(11) NOT NULL AUTO_INCREMENT,
    hire_request_id INT(11) NOT NULL,
    category ENUM(
        'id_doc',           -- documento di riconoscimento (upload admin)
        'fiscal_code_doc',  -- codice fiscale allegato (upload admin)
        'permit',           -- permesso di soggiorno (upload admin, opt)
        'c2',               -- modello storico C2 (upload admin, opt)
        'prospect',         -- prospetto di assunzione (upload consulente)
        'contract',         -- contratto da firmare (upload consulente)
        'signed_contract',  -- contratto firmato (sistema dopo firma)
        'signature_image'   -- immagine della firma grafica
    ) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT(11) NULL,
    uploaded_by_user_id INT(11) NULL,
    uploaded_by_employee_id INT(11) NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Per signed_contract: dati legali firma
    signed_ip VARCHAR(45) NULL,
    signed_user_agent VARCHAR(255) NULL,
    signature_hash VARCHAR(128) NULL,

    PRIMARY KEY (id),
    KEY idx_request (hire_request_id),
    KEY idx_category (category),
    CONSTRAINT fk_hrf_request FOREIGN KEY (hire_request_id) REFERENCES hire_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
