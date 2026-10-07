-- Migration 061: collega un'utenza HR (users, ruolo admin) al dipendente da cui e' nata.
-- Serve al pulsante "Rendi HR" nella scheda dipendente: sapere se il dipendente
-- ha gia' un accesso HR, mostrarlo e poterlo revocare.
-- Idempotente.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='employee_id');
SET @sql = IF(@c=0, "ALTER TABLE users ADD COLUMN employee_id INT NULL AFTER company_id, ADD INDEX idx_users_employee (employee_id)", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_employee');
SET @sql = IF(@c=0, "ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
