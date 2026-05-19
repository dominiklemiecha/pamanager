-- Migration 032: tabella CCNL templates con preset dei principali contratti collettivi italiani.
-- I record con company_id NULL sono "di sistema" (visibili a tutti i tenant).
-- I tenant possono crearne di custom (con company_id valorizzato).

CREATE TABLE IF NOT EXISTS ccnl_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    ferie_days_year DECIMAL(5,2) NOT NULL DEFAULT 26.00,
    permessi_hours_year DECIMAL(6,2) NOT NULL DEFAULT 88.00,
    notes VARCHAR(500) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ccnl_code_company (code, company_id),
    INDEX idx_ccnl_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed preset di sistema (idempotente via INSERT IGNORE su unique key code+company_id)
INSERT IGNORE INTO ccnl_templates (code, name, ferie_days_year, permessi_hours_year, notes, is_system, company_id) VALUES
('commercio_terziario',  'Commercio e Terziario (Confcommercio)',         26.00, 88.00,  '4 settimane ferie + 88h ROL (ex festività incluse variabili)', 1, NULL),
('metalmecc_industria',  'Metalmeccanici Industria',                       26.00, 104.00, '26 gg ferie + 104h tra ROL e ex-festività', 1, NULL),
('metalmecc_artigianato','Metalmeccanici Artigianato',                     26.00, 88.00,  '26 gg ferie + 88h permessi annui',             1, NULL),
('industria_alimentare', 'Industria Alimentare',                           26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('turismo_pubblici_eserc','Turismo / Pubblici Esercizi',                   26.00, 72.00,  '26 gg ferie + 72h permessi (incl. ex festività)', 1, NULL),
('edilizia',             'Edilizia Industria',                             26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('studi_professionali',  'Studi Professionali',                            26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('trasporti_logistica',  'Trasporti, Logistica e Spedizione',              26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('tessile_abbigliamento','Tessile e Abbigliamento',                        26.00, 64.00,  '26 gg ferie + 64h permessi (variabile)',       1, NULL),
('chimica_farmaceutica', 'Chimica e Farmaceutica',                         26.00, 104.00, '26 gg ferie + 104h permessi',                  1, NULL),
('gomma_plastica',       'Gomma e Plastica',                               26.00, 104.00, '26 gg ferie + 104h permessi',                  1, NULL),
('pulizie_multiservizi', 'Pulizie e Servizi Integrati / Multiservizi',     26.00, 72.00,  '26 gg ferie + 72h permessi (varia per livello)', 1, NULL),
('sanita_privata',       'Sanità Privata',                            26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('cooperative_sociali',  'Cooperative Sociali',                            26.00, 88.00,  '26 gg ferie + 88h permessi',                   1, NULL),
('domestico_colf',       'Lavoro Domestico (Colf/Badanti)',                26.00, 0.00,   '26 gg ferie, ROL non previsto',                1, NULL),
('agricolo_operai',      'Agricolo Operai',                                26.00, 64.00,  '26 gg ferie + 64h permessi (varia)',           1, NULL),
('custom',               'Personalizzato (override per dipendente)',       0.00,  0.00,   'Usato quando si configurano valori specifici sul singolo dipendente', 1, NULL);
