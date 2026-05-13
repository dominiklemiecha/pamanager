-- Migration 017: tabella documenti generici per dipendente.
-- Separata da `documents` (buste paga/CUD) perche senza vincolo mese/anno.
-- Idempotente.

CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL DEFAULT 1,
    employee_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    visible_to_employee TINYINT(1) NOT NULL DEFAULT 0,
    expires_on DATE NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ed_company_employee (company_id, employee_id),
    INDEX idx_ed_employee_visible (employee_id, visible_to_employee),
    INDEX idx_ed_expires (expires_on),
    CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_ed_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_document_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_document_id INT NOT NULL,
    user_type VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_edd_doc (employee_document_id),
    INDEX idx_edd_user (user_type, user_id),
    CONSTRAINT fk_edd_doc FOREIGN KEY (employee_document_id) REFERENCES employee_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
