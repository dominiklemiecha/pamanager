<?php
/**
 * API Presenze/Assenze per integrazione CRM esterno
 * PAManager / ConnecteedHR
 *
 * Espone, in sola lettura, timbrature e assenze approvate dei dipendenti attivi
 * di UNA SOLA azienda (per default Connecteed, configurabile via CRM_COMPANY_SLUG).
 * Lo scope azienda è forzato lato server: un eventuale company del token viene ignorato.
 *
 * Auth: JWT (stesso di auth.php). Ottieni il token con:
 *   POST /api/auth.php  {"action":"login","username":"...","password":"..."}
 * poi chiama questo endpoint con  Authorization: Bearer <token>
 *
 * Request (solo GET):
 *   GET /api/attendance.php                          → oggi
 *   GET /api/attendance.php?range=week               → lunedì→domenica della settimana corrente
 *   GET /api/attendance.php?from=2026-08-01&to=2026-08-03
 *   GET /api/attendance.php?employee_id=12           → filtro su un singolo dipendente
 *
 * Response 200:
 *   {
 *     "success": true,
 *     "company": {"id":1,"name":"Connecteed","slug":"connecteed","timbratura_enabled":true},
 *     "range": {"from":"...","to":"...","days":[...],"today":"..."},
 *     "count": 12,
 *     "employees": [{
 *        "id", "fiscal_code", "email", "full_name", "first_name", "last_name", "department",
 *        "today": {...cella del giorno corrente o null...},
 *        "totals": {"worked_seconds":int,"worked_hours":float,"days_present":int,"days_absent":int},
 *        "days": [{
 *          "date":"YYYY-MM-DD", "status":"present|absent|none",
 *          "leave_type":"ferie|permesso|...|null", "leave_label":"Ferie|...|null",
 *          "leave_full_day":bool|null, "leave_from":"HH:MM"|null, "leave_to":"HH:MM"|null,
 *          "first_in":"HH:MM"|null, "last_out":"HH:MM"|null, "open_now":bool,
 *          "worked_seconds":int, "worked_hours":float,
 *          "punches":[{"kind":"in|out","at":"YYYY-MM-DD HH:MM:SS","time":"HH:MM"}]
 *        }]
 *     }]
 *   }
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

// ─── Solo GET ────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Metodo non consentito', 405);
}

// ─── Auth JWT ────────────────────────────────────────────────────────────────
$auth = requireAuth();

// Solo utenti staff: un token dipendente non può leggere le presenze di tutti
if (($auth['user_type'] ?? null) !== 'user') {
    apiError('Non autorizzato', 403);
}
$role = $auth['role'] ?? '';
if (!in_array($role, ['admin', 'accountant'], true)) {
    apiError('Non autorizzato', 403);
}

// ─── Azienda: scope forzato (default Connecteed), mai preso dal token ─────────
$companySlug = $_ENV['CRM_COMPANY_SLUG'] ?? (getenv('CRM_COMPANY_SLUG') ?: 'connecteed');
$companySlug = trim((string) $companySlug);
if ($companySlug === '') {
    $companySlug = 'connecteed';
}

$company = Database::fetchOne(
    "SELECT id, name, slug, timbratura_enabled
       FROM companies
      WHERE is_active = 1 AND (slug = ? OR name LIKE ?)
      ORDER BY (slug = ?) DESC, id ASC
      LIMIT 1",
    [$companySlug, $companySlug, $companySlug]
);

if (!$company) {
    apiError('Azienda non trovata per slug "' . $companySlug . '"', 404);
}
$companyId = (int) $company['id'];

// ─── Isolamento multi-tenant: l'utente deve avere accesso a questa azienda ────
$user = Database::fetchOne("SELECT id, company_id FROM users WHERE id = ? AND is_active = 1", [(int) ($auth['user_id'] ?? 0)]);
if (!$user) {
    apiError('Utente non valido', 401);
}
$extraCompanies = array_map(
    static fn($r) => (int) $r['company_id'],
    Database::fetchAll("SELECT company_id FROM user_companies WHERE user_id = ?", [(int) $user['id']])
);
$isGlobalAdmin = $user['company_id'] === null && empty($extraCompanies);
$hasAccess = $isGlobalAdmin
    || (int) $user['company_id'] === $companyId
    || in_array($companyId, $extraCompanies, true);
if (!$hasAccess) {
    apiError('Non autorizzato su questa azienda', 403);
}

// ─── Intervallo date ─────────────────────────────────────────────────────────
$today = date('Y-m-d');
$isDate = static function ($s): bool {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
};

$range = strtolower(trim((string) ($_GET['range'] ?? '')));
if ($range === 'week') {
    $from = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $to   = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
} elseif ($range === 'today' || $range === '') {
    $from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $today;
    $to   = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $from;
} else {
    apiError('Parametro range non valido (ammessi: today, week)');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
// Limite di sicurezza: max 92 giorni per richiesta
if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
    apiError('Intervallo troppo ampio (max 92 giorni)');
}

$employeeFilter = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;

// Elenco dei giorni dell'intervallo
$days = [];
for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
    $days[] = date('Y-m-d', $ts);
}

// ─── Dipendenti attivi dell'azienda ──────────────────────────────────────────
$empSql = "SELECT e.id, e.fiscal_code, e.email, e.first_name, e.last_name, d.name AS department_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
            WHERE e.company_id = ? AND e.is_active = 1";
$empParams = [$companyId];
if ($employeeFilter) {
    $empSql .= " AND e.id = ?";
    $empParams[] = $employeeFilter;
}
$empSql .= " ORDER BY e.last_name, e.first_name";
$employees = Database::fetchAll($empSql, $empParams);

// ─── Timbrature (classe esistente, scope tenant) ─────────────────────────────
$punches = AttendancePunch::listForCompany($companyId, $from, $to, $employeeFilter);

$punchesByEmpDay = [];
foreach ($punches as $p) {
    $eid = (int) $p['employee_id'];
    $day = substr((string) $p['punch_at'], 0, 10);
    $punchesByEmpDay[$eid][$day][] = [
        'kind' => $p['kind'],
        'at'   => $p['punch_at'],
        'time' => substr((string) $p['punch_at'], 11, 5),
    ];
}
// listForCompany ordina DESC: rimetti in ordine cronologico per il calcolo ore
foreach ($punchesByEmpDay as $eid => $byDay) {
    foreach ($byDay as $day => $list) {
        usort($list, static fn($a, $b) => strcmp($a['at'], $b['at']));
        $punchesByEmpDay[$eid][$day] = $list;
    }
}

// ─── Assenze approvate che intersecano l'intervallo ──────────────────────────
$leaveSql = "SELECT lr.employee_id, lr.leave_type, lr.start_date, lr.end_date,
                    lr.is_full_day, lr.start_time, lr.end_time
               FROM leave_requests lr
               JOIN employees e ON e.id = lr.employee_id
              WHERE e.company_id = ? AND e.is_active = 1
                AND lr.status = 'approved'
                AND lr.start_date <= ? AND lr.end_date >= ?";
$leaveParams = [$companyId, $to, $from];
if ($employeeFilter) {
    $leaveSql .= " AND lr.employee_id = ?";
    $leaveParams[] = $employeeFilter;
}
$leaveSql .= " ORDER BY lr.start_date ASC, lr.id ASC";
$leaves = Database::fetchAll($leaveSql, $leaveParams);

// Privacy L.104: il tipo reale è visibile solo ad admin (HR) e accountant
$maskL104 = !in_array($role, ['admin', 'accountant'], true);

$leaveByEmpDay = [];
foreach ($leaves as $lv) {
    $eid = (int) $lv['employee_id'];
    $start = max($lv['start_date'], $from);
    $end   = min($lv['end_date'], $to);
    for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) {
        $day = date('Y-m-d', $ts);
        // Prima assenza trovata per quel giorno (già ordinate per data/id)
        if (!isset($leaveByEmpDay[$eid][$day])) {
            $leaveByEmpDay[$eid][$day] = $lv;
        }
    }
}

// ─── Composizione risposta ───────────────────────────────────────────────────
$result = [];
foreach ($employees as $e) {
    $eid = (int) $e['id'];
    $dayCells = [];
    $totalSeconds = 0;
    $daysPresent = 0;
    $daysAbsent = 0;

    foreach ($days as $day) {
        $dayPunches = $punchesByEmpDay[$eid][$day] ?? [];
        $leave = $leaveByEmpDay[$eid][$day] ?? null;

        // Ore lavorate: somma delle coppie in→out
        $seconds = 0;
        $openIn = null;
        $firstIn = null;
        $lastOut = null;
        foreach ($dayPunches as $dp) {
            if ($dp['kind'] === 'in') {
                $openIn = strtotime($dp['at']);
                if ($firstIn === null) $firstIn = $dp['time'];
            } elseif ($dp['kind'] === 'out') {
                $lastOut = $dp['time'];
                if ($openIn !== null) {
                    $seconds += strtotime($dp['at']) - $openIn;
                    $openIn = null;
                }
            }
        }
        $openNow = ($openIn !== null && $day === $today);
        // Se è ancora "dentro" oggi, conta fino ad adesso
        if ($openNow && time() > $openIn) {
            $seconds += time() - $openIn;
        }
        $seconds = max(0, $seconds);

        // Tipo assenza (con mascheratura L.104)
        $leaveType = $leave['leave_type'] ?? null;
        $leaveLabel = null;
        if ($leaveType !== null) {
            if ($maskL104 && $leaveType === 'permesso_104') {
                $leaveType = 'permesso';
            }
            $leaveLabel = LeaveRequest::LEAVE_TYPES[$leaveType] ?? $leaveType;
        }

        // Stato: lo smart working approvato NON è un'assenza (lavora da remoto)
        if ($leaveType !== null && $leaveType !== 'smart_working') {
            $status = 'absent';
        } elseif (!empty($dayPunches) || $leaveType === 'smart_working') {
            $status = 'present';
        } else {
            $status = 'none';
        }

        if ($status === 'present') $daysPresent++;
        if ($status === 'absent') $daysAbsent++;
        $totalSeconds += $seconds;

        $dayCells[] = [
            'date'           => $day,
            'status'         => $status,
            'leave_type'     => $leaveType,
            'leave_label'    => $leaveLabel,
            'leave_full_day' => $leave ? (bool) $leave['is_full_day'] : null,
            'leave_from'     => ($leave && $leave['start_time']) ? substr($leave['start_time'], 0, 5) : null,
            'leave_to'       => ($leave && $leave['end_time']) ? substr($leave['end_time'], 0, 5) : null,
            'first_in'       => $firstIn,
            'last_out'       => $lastOut,
            'open_now'       => $openNow,
            'worked_seconds' => $seconds,
            'worked_hours'   => round($seconds / 3600, 2),
            'punches'        => $dayPunches,
        ];
    }

    $todayCell = null;
    foreach ($dayCells as $cell) {
        if ($cell['date'] === $today) { $todayCell = $cell; break; }
    }

    $result[] = [
        'id'          => $eid,
        'fiscal_code' => $e['fiscal_code'],
        'email'       => $e['email'],
        'full_name'   => trim($e['first_name'] . ' ' . $e['last_name']),
        'first_name'  => $e['first_name'],
        'last_name'   => $e['last_name'],
        'department'  => $e['department_name'],
        'today'       => $todayCell,
        'totals'      => [
            'worked_seconds' => $totalSeconds,
            'worked_hours'   => round($totalSeconds / 3600, 2),
            'days_present'   => $daysPresent,
            'days_absent'    => $daysAbsent,
        ],
        'days'        => $dayCells,
    ];
}

apiResponse([
    'success' => true,
    'company' => [
        'id'                 => $companyId,
        'name'               => $company['name'],
        'slug'               => $company['slug'],
        'timbratura_enabled' => (bool) $company['timbratura_enabled'],
    ],
    'range' => [
        'from'  => $from,
        'to'    => $to,
        'today' => $today,
        'days'  => $days,
    ],
    'count'     => count($result),
    'employees' => $result,
]);
