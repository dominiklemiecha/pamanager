-- Migration 035: snapshot del saldo a una data specifica.
-- balance_set_at marca quando admin ha inserito il "residuo ad oggi".
-- Da quella data in poi:
--   - accrual mensile = (annuo/12) * mesi trascorsi dallo snapshot
--   - usato auto-calcolato solo da leave_requests con start_date >= balance_set_at

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_leave_balances' AND COLUMN_NAME = 'balance_set_at');
SET @sql = IF(@c = 0, "ALTER TABLE employee_leave_balances ADD COLUMN balance_set_at DATE NULL AFTER carried_over", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
