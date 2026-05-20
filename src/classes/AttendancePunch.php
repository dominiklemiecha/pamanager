<?php
/**
 * AttendancePunch - timbrature entrata/uscita tramite NFC NTAG215.
 *
 * Una sola carta condivisa per tutta l'azienda: la URL è generica
 * (es. /punch.php) e identifica il dipendente tramite la sessione.
 */
class AttendancePunch
{
    public const COOLDOWN_SECONDS = 30;

    /**
     * Registra una nuova timbratura. Toggle automatico IN/OUT in base all'ultima del giorno.
     * Ritorna ['success' => bool, 'kind' => 'in|out', 'punch_at' => 'YYYY-MM-DD HH:MM:SS', 'error' => ?]
     */
    public static function record(int $employeeId, string $source = 'nfc'): array
    {
        $emp = Database::fetchOne(
            "SELECT id, company_id, first_name, last_name, is_active FROM employees WHERE id = ?",
            [$employeeId]
        );
        if (!$emp) return ['success' => false, 'error' => 'Dipendente non trovato'];
        if (empty($emp['is_active'])) return ['success' => false, 'error' => 'Account non attivo'];

        // Cooldown: evita doppie timbrature involontarie
        $last = self::lastPunch($employeeId);
        if ($last) {
            $diff = time() - strtotime($last['punch_at']);
            if ($diff < self::COOLDOWN_SECONDS) {
                return [
                    'success'  => false,
                    'cooldown' => true,
                    'remaining' => self::COOLDOWN_SECONDS - $diff,
                    'last_kind' => $last['kind'],
                    'last_at'   => $last['punch_at'],
                    'error'    => 'Attendi qualche secondo prima della prossima timbratura.',
                ];
            }
        }

        // Determina IN/OUT: se l'ultima del giorno è IN -> OUT, altrimenti IN
        $today = date('Y-m-d');
        $todayLast = Database::fetchOne(
            "SELECT kind FROM attendance_punches
             WHERE employee_id = ? AND DATE(punch_at) = ?
             ORDER BY punch_at DESC, id DESC LIMIT 1",
            [$employeeId, $today]
        );
        $newKind = ($todayLast && $todayLast['kind'] === 'in') ? 'out' : 'in';

        $now = date('Y-m-d H:i:s');
        $id = Database::insert('attendance_punches', [
            'company_id'  => (int) $emp['company_id'],
            'employee_id' => $employeeId,
            'punch_at'    => $now,
            'kind'        => $newKind,
            'source'      => $source,
            'ip'          => self::getIp(),
            'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        return [
            'success'  => true,
            'id'       => (int) $id,
            'kind'     => $newKind,
            'punch_at' => $now,
            'employee' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
        ];
    }

    public static function lastPunch(int $employeeId): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM attendance_punches WHERE employee_id = ?
             ORDER BY punch_at DESC, id DESC LIMIT 1",
            [$employeeId]
        );
        return $row ?: null;
    }

    public static function todayList(int $employeeId): array
    {
        return Database::fetchAll(
            "SELECT * FROM attendance_punches
             WHERE employee_id = ? AND DATE(punch_at) = CURDATE()
             ORDER BY punch_at ASC",
            [$employeeId]
        );
    }

    /**
     * Riepilogo del giorno: lista timbrature + ore lavorate stimate.
     */
    public static function todaySummary(int $employeeId): array
    {
        $punches = self::todayList($employeeId);
        $totalSeconds = 0;
        $openIn = null;
        foreach ($punches as $p) {
            if ($p['kind'] === 'in') {
                $openIn = strtotime($p['punch_at']);
            } elseif ($p['kind'] === 'out' && $openIn !== null) {
                $totalSeconds += strtotime($p['punch_at']) - $openIn;
                $openIn = null;
            }
        }
        // Se ancora "dentro", calcola fino a ora
        if ($openIn !== null) {
            $totalSeconds += time() - $openIn;
        }
        return [
            'punches' => $punches,
            'is_open' => $openIn !== null,
            'total_seconds' => max(0, $totalSeconds),
        ];
    }

    /**
     * Lista paginata per admin (scope tenant).
     */
    public static function listForCompany(int $companyId, ?string $fromDate = null, ?string $toDate = null, ?int $employeeId = null): array
    {
        $sql = "SELECT p.*, e.first_name, e.last_name, e.photo_path, d.name AS department_name
                FROM attendance_punches p
                JOIN employees e ON e.id = p.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE p.company_id = ?";
        $params = [$companyId];
        if ($fromDate) { $sql .= " AND DATE(p.punch_at) >= ?"; $params[] = $fromDate; }
        if ($toDate)   { $sql .= " AND DATE(p.punch_at) <= ?"; $params[] = $toDate; }
        if ($employeeId) { $sql .= " AND p.employee_id = ?"; $params[] = $employeeId; }
        $sql .= " ORDER BY p.punch_at DESC LIMIT 500";
        return Database::fetchAll($sql, $params);
    }

    public static function getIp(): string
    {
        if (function_exists('getClientIp')) return getClientIp();
        $f = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $f ? trim(explode(',', $f)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip, 0, 45);
    }
}
