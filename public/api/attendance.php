<?php
/**
 * Endpoint presenze/assenze per integrazione CRM (Connecteed).
 * Restituisce, per un intervallo di date, le timbrature e le assenze approvate
 * dei dipendenti di un'azienda.
 *
 * Request:
 *   GET /api/attendance.php?from=YYYY-MM-DD&to=YYYY-MM-DD&company=1
 *   Header: X-CRM-Key: <CRM_API_KEY>
 *
 * Response 200 JSON:
 *   {
 *     "from": "...", "to": "...", "companyId": 1, "today": "...",
 *     "employees": [{
 *        "id", "fiscalCode", "email", "firstName", "lastName", "department",
 *        "presentNow": bool, "onLeaveToday": "ferie"|null,
 *        "days": { "YYYY-MM-DD": { "present": bool, "firstIn": "HH:MM"|null,
 *                    "lastOut": "HH:MM"|null, "seconds": int, "leave": "ferie"|null } }
 *     }]
 *   }
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function att_reply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ─── Auth: chiave API statica (server-to-server) ─────────────────────────────
$expected = getenv('CRM_API_KEY') ?: '';
$provided = $_SERVER['HTTP_X_CRM_KEY'] ?? '';
if ($expected === '' || !hash_equals($expected, (string) $provided)) {
    att_reply(401, ['error' => 'invalid_key']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    att_reply(405, ['error' => 'method_not_allowed']);
}

// ─── Parametri ───────────────────────────────────────────────────────────────
$today = date('Y-m-d');
$isDate = static fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $today;
$to   = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$companyId = (int) ($_GET['company'] ?? (getenv('CRM_DEFAULT_COMPANY_ID') ?: 1));

// ─── Dati ────────────────────────────────────────────────────────────────────
$employees = Database::fetchAll(
    "SELECT e.id, e.fiscal_code, e.email, e.first_name, e.last_name, d.name AS department
       FROM employees e
       LEFT JOIN departments d ON d.id = e.department_id
      WHERE e.company_id = ? AND e.is_active = 1
      ORDER BY e.last_name, e.first_name",
    [$companyId]
);

$punches = Database::fetchAll(
    "SELECT employee_id, punch_at, kind
       FROM attendance_punches
      WHERE company_id = ? AND DATE(punch_at) BETWEEN ? AND ?
      ORDER BY employee_id, punch_at ASC",
    [$companyId, $from, $to]
);

$leaves = Database::fetchAll(
    "SELECT lr.employee_id, lr.leave_type, lr.start_date, lr.end_date, lr.is_full_day
       FROM leave_requests lr
       JOIN employees e ON e.id = lr.employee_id
      WHERE e.company_id = ? AND lr.status = 'approved'
        AND lr.start_date <= ? AND lr.end_date >= ?",
    [$companyId, $to, $from]
);

// Raggruppa le timbrature per dipendente/giorno
$byEmpDay = [];
foreach ($punches as $p) {
    $eid = (int) $p['employee_id'];
    $day = substr($p['punch_at'], 0, 10);
    $byEmpDay[$eid][$day][] = ['kind' => $p['kind'], 'at' => $p['punch_at']];
}

// Assenze per dipendente/giorno (espande l'intervallo)
$leaveByEmpDay = [];
foreach ($leaves as $lv) {
    $eid = (int) $lv['employee_id'];
    $start = max($lv['start_date'], $from);
    $end = min($lv['end_date'], $to);
    $cur = strtotime($start);
    $endTs = strtotime($end);
    while ($cur <= $endTs) {
        $leaveByEmpDay[$eid][date('Y-m-d', $cur)] = $lv['leave_type'];
        $cur += 86400;
    }
}

$out = [];
foreach ($employees as $e) {
    $eid = (int) $e['id'];
    $days = [];
    // Copre ogni giorno dell'intervallo
    $cur = strtotime($from);
    $endTs = strtotime($to);
    while ($cur <= $endTs) {
        $day = date('Y-m-d', $cur);
        $cur += 86400;
        $dayPunches = $byEmpDay[$eid][$day] ?? [];
        $leaveType = $leaveByEmpDay[$eid][$day] ?? null;
        // Calcola secondi lavorati + prima entrata / ultima uscita
        $seconds = 0; $openIn = null; $firstIn = null; $lastOut = null;
        foreach ($dayPunches as $dp) {
            if ($dp['kind'] === 'in') {
                $openIn = strtotime($dp['at']);
                if ($firstIn === null) $firstIn = substr($dp['at'], 11, 5);
            } elseif ($dp['kind'] === 'out') {
                $lastOut = substr($dp['at'], 11, 5);
                if ($openIn !== null) { $seconds += strtotime($dp['at']) - $openIn; $openIn = null; }
            }
        }
        // Se ancora "dentro" oggi, conta fino ad ora
        if ($openIn !== null && $day === $today) { $seconds += time() - $openIn; }
        $days[$day] = [
            'present' => count($dayPunches) > 0,
            'firstIn' => $firstIn,
            'lastOut' => $lastOut,
            'seconds' => max(0, $seconds),
            'openNow' => $openIn !== null && $day === $today,
            'leave'   => $leaveType,
        ];
    }
    $todayCell = $days[$today] ?? null;
    $out[] = [
        'id' => $eid,
        'fiscalCode' => $e['fiscal_code'],
        'email' => $e['email'],
        'firstName' => $e['first_name'],
        'lastName' => $e['last_name'],
        'department' => $e['department'],
        'presentNow' => $todayCell ? (bool) $todayCell['openNow'] : false,
        'onLeaveToday' => $todayCell ? $todayCell['leave'] : null,
        'days' => $days,
    ];
}

att_reply(200, [
    'from' => $from,
    'to' => $to,
    'today' => $today,
    'companyId' => $companyId,
    'employees' => $out,
]);
