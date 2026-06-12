-- 044: Richiesta assunzione = richiesta di simulazione costo/netto.
--  - Nuovo stato 'draft' (bozza salvabile parzialmente prima dell'invio al consulente)
--  - Obbligatori solo i campi funzionali alla simulazione (durata contratto, ore settimanali,
--    mansioni): tutti gli altri campi diventano NULLabili
--  - Tracking invii: last_sent_at / send_count / updated_after_send (re-invio dopo modifiche)

ALTER TABLE hire_requests
    MODIFY status ENUM(
        'draft',
        'awaiting_prospects',
        'prospects_review',
        'approved',
        'contract_pending',
        'contract_signed',
        'rejected',
        'cancelled'
    ) NOT NULL DEFAULT 'draft',
    MODIFY employee_first_name VARCHAR(80) NULL,
    MODIFY employee_last_name VARCHAR(80) NULL,
    MODIFY employee_birth_date DATE NULL,
    MODIFY birth_state VARCHAR(80) NULL,
    MODIFY birth_city VARCHAR(120) NULL,
    MODIFY fiscal_code VARCHAR(16) NULL,
    MODIFY residence_address VARCHAR(255) NULL,
    MODIFY residence_cap VARCHAR(10) NULL,
    MODIFY residence_city VARCHAR(120) NULL,
    MODIFY residence_province VARCHAR(80) NULL,
    MODIFY marital_status ENUM('celibe_nubile','coniugato','divorziato','vedovo','unione_civile','separato') NULL,
    MODIFY education_level ENUM('nessuno','licenza_elementare','licenza_media','diploma','laurea_triennale','laurea_magistrale','dottorato') NULL,
    MODIFY start_date DATE NULL,
    MODIFY role_description TEXT NULL,
    MODIFY weekly_hours DECIMAL(5,2) NULL,
    MODIFY work_days SET('mon','tue','wed','thu','fri','sat','sun') NULL,
    MODIFY workplace VARCHAR(255) NULL,
    MODIFY employee_email VARCHAR(150) NULL,
    ADD COLUMN last_sent_at TIMESTAMP NULL DEFAULT NULL AFTER notes,
    ADD COLUMN send_count INT NOT NULL DEFAULT 0 AFTER last_sent_at,
    ADD COLUMN updated_after_send TINYINT(1) NOT NULL DEFAULT 0 AFTER send_count;

-- Backfill: le richieste esistenti (tutte gia' inviate) risultano inviate alla creazione
UPDATE hire_requests SET send_count = 1, last_sent_at = created_at WHERE status <> 'draft';
