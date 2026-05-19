<?php
/**
 * LeaveBalance - calcolo saldo ferie/permessi per dipendente.
 * Ferie in giorni, Permessi in ore.
 */

class LeaveBalance
{
    public const TYPES = ['ferie', 'permesso'];

    private const DAY_KEYS = ['mon','tue','wed','thu','fri','sat','sun'];

    /**
     * Restituisce ['ferie' => [...], 'permesso' => [...]] con totali, usato, residuo.
     */
    public static function getForEmployee(int $employeeId, ?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $result = [];
        foreach (self::TYPES as $type) {
            $result[$type] = self::getOne($employeeId, $year, $type);
        }
        return $result;
    }

    public static function getOne(int $employeeId, int $year, string $type): array
    {
        $row = Database::fetchOne(
            "SELECT entitled, carried_over, manual_used, notes
             FROM employee_leave_balances
             WHERE employee_id = ? AND year = ? AND leave_type = ?",
            [$employeeId, $year, $type]
        );
        $entitled    = (float) ($row['entitled']    ?? 0);
        $carried     = (float) ($row['carried_over'] ?? 0);
        $manualUsed  = (float) ($row['manual_used'] ?? 0);
        $autoUsed    = self::calculateAutoUsed($employeeId, $year, $type);
        $total       = $entitled + $carried;
        $used        = $manualUsed + $autoUsed;
        $residual    = $total - $used;
        $percent     = $total > 0 ? max(0, min(100, ($used / $total) * 100)) : 0;
        return [
            'type'        => $type,
            'unit'        => $type === 'ferie' ? 'giorni' : 'ore',
            'entitled'    => $entitled,
            'carried'     => $carried,
            'manual_used' => $manualUsed,
            'auto_used'   => $autoUsed,
            'total'       => $total,
            'used'        => $used,
            'residual'    => $residual,
            'percent'     => $percent,
            'notes'       => $row['notes'] ?? null,
            'has_row'     => $row !== null && $row !== false,
        ];
    }

    /**
     * Somma usato derivato da leave_requests approved nell'anno.
     */
    public static function calculateAutoUsed(int $employeeId, int $year, string $type): float
    {
        if (!in_array($type, self::TYPES, true)) return 0.0;
        $start = "$year-01-01";
        $end   = "$year-12-31";
        $rows = Database::fetchAll(
            "SELECT start_date, end_date, is_full_day, start_time, end_time
             FROM leave_requests
             WHERE employee_id = ?
               AND leave_type = ?
               AND status = 'approved'
               AND start_date <= ?
               AND end_date >= ?",
            [$employeeId, $type, $end, $start]
        );
        $schedule = self::getSchedule($employeeId);
        $total = 0.0;
        foreach ($rows as $r) {
            $rs = max($r['start_date'], $start);
            $re = min($r['end_date'], $end);
            if ($type === 'ferie') {
                // Ferie: full-day only, conta giorni lavorativi nel range
                $total += self::countWorkingDays($rs, $re, $schedule['days']);
            } else {
                // Permesso: full_day=ore_giorno per ogni working day, altrimenti differenza orari
                if ((int) $r['is_full_day'] === 1) {
                    $days = self::countWorkingDays($rs, $re, $schedule['days']);
                    $total += $days * $schedule['hours'];
                } else {
                    if ($r['start_time'] && $r['end_time']) {
                        $h = self::hoursBetween($r['start_time'], $r['end_time']);
                        // Se intervalla pi� giorni, applica solo se quel giorno e' lavorativo
                        $days = self::countWorkingDays($rs, $re, $schedule['days']);
                        $total += $h * max(1, $days);
                    }
                }
            }
        }
        return round($total, 2);
    }

    /**
     * Persiste un saldo (insert/update) per (employee, year, type).
     */
    public static function save(
        int $employeeId,
        int $companyId,
        int $year,
        string $type,
        float $entitled,
        float $carried,
        float $manualUsed,
        ?string $notes,
        int $updatedBy
    ): void {
        if (!in_array($type, self::TYPES, true)) return;
        $existing = Database::fetchOne(
            "SELECT id FROM employee_leave_balances WHERE employee_id = ? AND year = ? AND leave_type = ?",
            [$employeeId, $year, $type]
        );
        $data = [
            'entitled'     => $entitled,
            'carried_over' => $carried,
            'manual_used'  => $manualUsed,
            'notes'        => $notes,
            'updated_by'   => $updatedBy,
        ];
        if ($existing) {
            Database::update('employee_leave_balances', $data, 'id = ?', [(int) $existing['id']]);
        } else {
            $data['employee_id'] = $employeeId;
            $data['company_id']  = $companyId;
            $data['year']        = $year;
            $data['leave_type']  = $type;
            Database::insert('employee_leave_balances', $data);
        }
    }

    /**
     * Schedule effettivo: override dipendente o default azienda.
     * Ritorna ['days' => ['mon',...], 'hours' => 8.0].
     */
    public static function getSchedule(int $employeeId): array
    {
        $row = Database::fetchOne(
            "SELECT e.working_days AS e_days, e.hours_per_day AS e_hours, e.company_id,
                    c.working_days AS c_days, c.hours_per_day AS c_hours
             FROM employees e
             LEFT JOIN companies c ON c.id = e.company_id
             WHERE e.id = ?",
            [$employeeId]
        );
        if (!$row) return ['days' => ['mon','tue','wed','thu','fri'], 'hours' => 8.0];
        $daysCsv = !empty($row['e_days']) ? $row['e_days'] : ($row['c_days'] ?? 'mon,tue,wed,thu,fri');
        $hours   = $row['e_hours'] !== null ? (float) $row['e_hours']
                                            : (float) ($row['c_hours'] ?? 8.0);
        $days = array_values(array_filter(array_map('trim', explode(',', $daysCsv))));
        return ['days' => $days, 'hours' => $hours];
    }

    public static function companyDefaults(int $companyId): array
    {
        $row = Database::fetchOne(
            "SELECT working_days, hours_per_day FROM companies WHERE id = ?",
            [$companyId]
        );
        $daysCsv = $row['working_days'] ?? 'mon,tue,wed,thu,fri';
        $days = array_values(array_filter(array_map('trim', explode(',', $daysCsv))));
        return ['days' => $days, 'hours' => (float) ($row['hours_per_day'] ?? 8.0)];
    }

    /**
     * Conta giorni della settimana attivi tra due date YYYY-MM-DD (inclusive).
     * $workingDays: array tipo ['mon','tue',...]
     */
    public static function countWorkingDays(string $from, string $to, array $workingDays): int
    {
        if ($from > $to) return 0;
        $mask = array_flip($workingDays);
        $count = 0;
        $cur = strtotime($from);
        $end = strtotime($to);
        while ($cur <= $end) {
            $dow = self::DAY_KEYS[((int) date('N', $cur)) - 1];
            if (isset($mask[$dow])) $count++;
            $cur += 86400;
        }
        return $count;
    }

    private static function hoursBetween(string $start, string $end): float
    {
        $s = strtotime("1970-01-01 $start");
        $e = strtotime("1970-01-01 $end");
        if ($s === false || $e === false || $e <= $s) return 0.0;
        return round(($e - $s) / 3600, 2);
    }

    public static function dayLabel(string $key): string
    {
        return [
            'mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mer', 'thu' => 'Gio',
            'fri' => 'Ven', 'sat' => 'Sab', 'sun' => 'Dom',
        ][$key] ?? $key;
    }

    public static function allDayKeys(): array
    {
        return self::DAY_KEYS;
    }

    // ==========================================================
    // ACCRUAL: maturazione mensile basata su CCNL del dipendente.
    // ==========================================================

    /**
     * Restituisce il totale annuo (giorni o ore) maturabili dal dipendente per il tipo.
     * Logica: override su employees se presente, altrimenti CCNL, altrimenti 0.
     */
    public static function getAnnualForEmployee(int $employeeId, string $type): float
    {
        $row = Database::fetchOne(
            "SELECT e.ferie_year_override, e.permessi_year_override, e.ccnl_id,
                    c.ferie_days_year, c.permessi_hours_year
             FROM employees e
             LEFT JOIN ccnl_templates c ON c.id = e.ccnl_id
             WHERE e.id = ?",
            [$employeeId]
        );
        if (!$row) return 0.0;
        if ($type === 'ferie') {
            if ($row['ferie_year_override'] !== null) return (float) $row['ferie_year_override'];
            return (float) ($row['ferie_days_year'] ?? 0);
        }
        if ($type === 'permesso') {
            if ($row['permessi_year_override'] !== null) return (float) $row['permessi_year_override'];
            return (float) ($row['permessi_hours_year'] ?? 0);
        }
        return 0.0;
    }

    /**
     * Applica/garantisce l'accrual fino al mese corrente per un dipendente.
     * Idempotente: usa accrual_last_month (YYYY-MM) come marker.
     * - Al cambio anno, carry-over automatico dall'anno precedente.
     */
    public static function ensureCurrentYearAccrual(int $employeeId, int $companyId): void
    {
        $now = new DateTime('today');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $monthKey = sprintf('%04d-%02d', $year, $month);

        foreach (self::TYPES as $type) {
            $annual = self::getAnnualForEmployee($employeeId, $type);
            if ($annual <= 0) continue;

            $row = Database::fetchOne(
                "SELECT id, entitled, carried_over, accrual_last_month
                 FROM employee_leave_balances
                 WHERE employee_id = ? AND year = ? AND leave_type = ?",
                [$employeeId, $year, $type]
            );

            // Calcolo entitled target = (annual/12) * mesi trascorsi (gen..mese_corrente inclusi)
            $targetEntitled = round(($annual / 12.0) * $month, 2);

            // Se non c'è ancora la riga dell'anno: crea + applica carry-over dall'anno precedente
            if (!$row) {
                $prev = self::getOne($employeeId, $year - 1, $type);
                $prevResidual = max(0, $prev['residual']);
                Database::insert('employee_leave_balances', [
                    'employee_id'        => $employeeId,
                    'company_id'         => $companyId,
                    'year'               => $year,
                    'leave_type'         => $type,
                    'entitled'           => $targetEntitled,
                    'carried_over'       => $prevResidual,
                    'manual_used'        => 0,
                    'accrual_last_month' => $monthKey,
                ]);
                continue;
            }

            // Riga esiste: aggiorna entitled e marker se siamo in un mese successivo
            if ($row['accrual_last_month'] !== $monthKey) {
                Database::update('employee_leave_balances', [
                    'entitled'           => $targetEntitled,
                    'accrual_last_month' => $monthKey,
                ], 'id = ?', [(int) $row['id']]);
            }
        }
    }

    /**
     * Esegue ensureCurrentYearAccrual per tutti i dipendenti attivi della tenant.
     * Idempotente.
     */
    public static function ensureCurrentYearAccrualForCompany(int $companyId): int
    {
        $emps = Database::fetchAll(
            "SELECT id FROM employees WHERE company_id = ? AND is_active = TRUE",
            [$companyId]
        );
        foreach ($emps as $e) {
            try {
                self::ensureCurrentYearAccrual((int) $e['id'], $companyId);
            } catch (Throwable $err) {
                error_log('Accrual employee ' . $e['id'] . ' failed: ' . $err->getMessage());
            }
        }
        return count($emps);
    }

    /**
     * Lista CCNL disponibili per la tenant (di sistema + custom della company).
     */
    public static function availableCcnls(int $companyId): array
    {
        return Database::fetchAll(
            "SELECT id, code, name, ferie_days_year, permessi_hours_year
             FROM ccnl_templates
             WHERE company_id IS NULL OR company_id = ?
             ORDER BY is_system DESC, name ASC",
            [$companyId]
        );
    }
}
