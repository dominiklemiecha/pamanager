-- Migration 055: i dipendenti assunti quest'anno non devono ritrovarsi ferie e
-- permessi maturati da gennaio. L'accrual proporzionale partiva sempre da inizio
-- anno: si azzera il saldo e lo si fa ripartire dalla data di assunzione
-- (l'accrual lazy ricalcola i ratei corretti alla prima apertura del saldo).
-- Toccate SOLO le righe senza snapshot manuale (balance_set_at NULL).

UPDATE employee_leave_balances b
JOIN employees e ON e.id = b.employee_id
SET b.entitled           = 0,
    b.carried_over       = 0,
    b.balance_set_at     = e.hire_date,
    b.accrual_last_month = DATE_FORMAT(e.hire_date, '%Y-%m')
WHERE b.year = YEAR(CURDATE())
  AND b.balance_set_at IS NULL
  AND e.hire_date IS NOT NULL
  AND YEAR(e.hire_date) = YEAR(CURDATE());
