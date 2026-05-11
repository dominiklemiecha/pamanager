-- Migration 013: Dati personali estesi dipendente + certificati medici
-- Idempotente, compatibile MySQL 5.7+ / MariaDB.

-- ========== Campi dipendente ==========

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'address');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN address VARCHAR(255) NULL AFTER phone", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'birth_date');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN birth_date DATE NULL AFTER address", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'job_level');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN job_level VARCHAR(50) NULL AFTER birth_date", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'ral_amount');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN ral_amount DECIMAL(10,2) NULL AFTER job_level", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'monthly_salary');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) NULL AFTER ral_amount", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'iban');
SET @sql = IF(@c=0, "ALTER TABLE employees ADD COLUMN iban VARCHAR(34) NULL AFTER monthly_salary", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ========== Certificati medici ==========

CREATE TABLE IF NOT EXISTS medical_certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    issued_at DATE NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    uploaded_by_user_id INT NULL,
    uploaded_by_employee_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_employee (employee_id),
    INDEX idx_valid (valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
