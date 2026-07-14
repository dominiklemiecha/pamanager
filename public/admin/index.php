<?php
/**
 * Dashboard Amministratore — markup Factorial-blue (aligned to mockups/admin-dashboard.html)
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();

// ===== Quick approve/reject da dashboard =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['quick_approve', 'quick_reject'], true)) {
    CSRF::verifyOrDie();
    $__lrId = (int)($_POST['id'] ?? 0);
    if ($__lrId > 0) {
        if ($_POST['action'] === 'quick_approve') {
            LeaveRequest::approve($__lrId, (int)$user['id']);
        } else {
            LeaveRequest::reject($__lrId, (int)$user['id'], trim($_POST['reason'] ?? ''));
        }
    }
    header('Location: index.php');
    exit;
}

// ===== Statistiche (scoped per azienda corrente) =====
$__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;

// ===== Periodo di prova: apre alert e disattivazioni programmate (idempotente) =====
if (class_exists('Probation')) {
    Probation::runChecks($__cid);
}

$employeeCount = Employee::count(true);
$documentCount = Document::count();
$unreadComms   = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = TRUE AND publish_date <= CURDATE() AND (expire_date IS NULL OR expire_date >= CURDATE())",
    [$__cid]
);
$accountantCount = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM users WHERE role = 'accountant' AND is_active = TRUE AND (company_id = ? OR company_id IS NULL)",
    [$__cid]
);

// ===== Azioni richieste =====
// Reset password esclusi: li fanno gli utenti in autonomia (self-service),
// non sono più un'azione richiesta all'admin.
$pendingLeaves = 0;
$pendingLeavesList = [];
try {
    $pendingLeaves = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'pending'",
        [$__cid]
    );
    $pendingLeavesList = Database::fetchAll(
        "SELECT lr.id, lr.leave_type AS type, lr.start_date, lr.end_date, e.first_name, e.last_name
         FROM leave_requests lr
         JOIN employees e ON e.id = lr.employee_id
         WHERE lr.company_id = ? AND lr.status = 'pending'
         ORDER BY lr.created_at DESC LIMIT 4",
        [$__cid]
    );
} catch (Exception $e) {}
$totalActions = $pendingLeaves;

// ===== Saluto dinamico per fascia oraria =====
$__hour = (int) date('H');
if ($__hour >= 5 && $__hour < 12) {
    $__greeting = 'Buongiorno';
} elseif ($__hour >= 12 && $__hour < 18) {
    $__greeting = 'Buon pomeriggio';
} else {
    $__greeting = 'Buonasera';
}

// ===== In ufficio oggi =====
$__today = date('Y-m-d');
$todayLeavesByType = ['ferie' => 0, 'malattia' => 0, 'permesso' => 0];
$todayOnLeaveIds = [];
try {
    $rows = Database::fetchAll(
        "SELECT DISTINCT employee_id, type FROM leave_requests
         WHERE company_id = ? AND status = 'approved'
           AND start_date <= ? AND end_date >= ?",
        [$__cid, $__today, $__today]
    );
    foreach ($rows as $r) {
        $todayOnLeaveIds[(int)$r['employee_id']] = true;
        $t = $r['type'];
        if (isset($todayLeavesByType[$t])) $todayLeavesByType[$t]++;
    }
} catch (Throwable $e) {}
$todayPresentCount = max(0, $employeeCount - count($todayOnLeaveIds));
try {
    $todayPresentEmployees = Database::fetchAll(
        "SELECT id, first_name, last_name, photo_path FROM employees
         WHERE company_id = ? AND is_active = TRUE
           AND id NOT IN (
             SELECT employee_id FROM leave_requests
             WHERE company_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?
           )
         ORDER BY last_name, first_name LIMIT 6",
        [$__cid, $__cid, $__today, $__today]
    );
} catch (Throwable $e) {
    $todayPresentEmployees = [];
}
$todayPresentExtra = max(0, $todayPresentCount - count($todayPresentEmployees));

// ===== Eventi in arrivo (compleanni + anniversari lavoro) prossimi 7gg =====
$__upcomingEvents = 0;
$__upcomingBirthdays = 0;
$__upcomingAnniversaries = 0;
try {
    // Compleanni nei prossimi 7 giorni (confronto su MM-DD)
    $__upcomingBirthdays = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM employees
         WHERE company_id = ? AND is_active = TRUE AND birth_date IS NOT NULL
           AND (
               DATE_FORMAT(birth_date, '%m-%d') BETWEEN DATE_FORMAT(CURDATE(), '%m-%d') AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d')
               OR (
                   MONTH(CURDATE()) = 12 AND MONTH(DATE_ADD(CURDATE(), INTERVAL 7 DAY)) = 1
                   AND (DATE_FORMAT(birth_date, '%m-%d') >= DATE_FORMAT(CURDATE(), '%m-%d')
                        OR DATE_FORMAT(birth_date, '%m-%d') <= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d'))
               )
           )",
        [$__cid]
    );
    // Anniversari di lavoro (hire_date dello stesso mese-giorno entro 7 gg, escluso anno corrente)
    $__upcomingAnniversaries = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM employees
         WHERE company_id = ? AND is_active = TRUE AND hire_date IS NOT NULL
           AND YEAR(hire_date) < YEAR(CURDATE())
           AND DATE_FORMAT(hire_date, '%m-%d') BETWEEN DATE_FORMAT(CURDATE(), '%m-%d') AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d')",
        [$__cid]
    );
    $__upcomingEvents = $__upcomingBirthdays + $__upcomingAnniversaries;
} catch (Throwable $e) {}

// ===== Da approvare totali =====
$__totalApprovals = $pendingLeaves;
$__absentToday = count($todayOnLeaveIds);

// ===== Ultime attività =====
$activities = [];

$recentEmployees = Database::fetchAll(
    "SELECT id, first_name, last_name, created_at FROM employees WHERE company_id = ? ORDER BY created_at DESC LIMIT 4",
    [$__cid]
);
foreach ($recentEmployees as $e) {
    $activities[] = [
        'type'  => 'user',
        'date'  => $e['created_at'],
        'title' => 'Nuovo dipendente: <strong>' . htmlspecialchars($e['last_name'] . ' ' . $e['first_name']) . '</strong>',
        'meta'  => 'aggiunto al sistema',
    ];
}

$recentDocs = Database::fetchAll(
    "SELECT d.id, d.type, d.title, d.month, d.year, d.created_at, e.first_name, e.last_name, u.name as uploader
     FROM documents d
     JOIN employees e ON d.employee_id = e.id
     JOIN users u ON d.uploaded_by = u.id
     WHERE d.company_id = ?
     ORDER BY d.created_at DESC LIMIT 6",
    [$__cid]
);
foreach ($recentDocs as $d) {
    $activities[] = [
        'type'  => 'doc',
        'date'  => $d['created_at'],
        'title' => htmlspecialchars($d['title']) . ' per <strong>' . htmlspecialchars($d['last_name'] . ' ' . $d['first_name']) . '</strong>',
        'meta'  => 'caricato da ' . htmlspecialchars($d['uploader']),
    ];
}

$recentComms = Database::fetchAll(
    "SELECT c.id, c.title, c.created_at, u.name as author
     FROM communications c
     JOIN users u ON c.created_by = u.id
     WHERE c.company_id = ?
     ORDER BY c.created_at DESC LIMIT 4",
    [$__cid]
);
foreach ($recentComms as $c) {
    $activities[] = [
        'type'  => 'comm',
        'date'  => $c['created_at'],
        'title' => 'Comunicazione: <strong>' . htmlspecialchars($c['title']) . '</strong>',
        'meta'  => 'pubblicata da ' . htmlspecialchars($c['author']),
    ];
}

// Richieste ferie/permessi (create + approvate/rifiutate)
$__typeLabels = [
    'ferie' => 'ferie', 'permesso' => 'permesso', 'malattia' => 'malattia',
    'permesso_104' => 'permesso L.104', 'congedo_parentale' => 'congedo parentale',
    'congedo_separazione' => 'congedo separazione', 'congedo_mestruale' => 'congedo',
    'altro' => 'assenza', 'chiusura' => 'chiusura', 'smart_working' => 'smart working',
];
try {
    $recentLeaves = Database::fetchAll(
        "SELECT lr.id, lr.leave_type, lr.status, lr.start_date, lr.end_date,
                lr.created_at, lr.approved_at, lr.created_by_admin,
                e.first_name, e.last_name,
                u.name AS approver_name
         FROM leave_requests lr
         JOIN employees e ON lr.employee_id = e.id
         LEFT JOIN users u ON lr.approved_by = u.id
         WHERE lr.company_id = ?
         ORDER BY GREATEST(lr.created_at, COALESCE(lr.approved_at, lr.created_at)) DESC
         LIMIT 8",
        [$__cid]
    );
} catch (Throwable $e) { $recentLeaves = []; }
foreach ($recentLeaves as $lr) {
    $__name = htmlspecialchars($lr['last_name'] . ' ' . $lr['first_name']);
    $__type = $__typeLabels[$lr['leave_type']] ?? $lr['leave_type'];
    $__range = date('d M', strtotime($lr['start_date']));
    if ($lr['start_date'] !== $lr['end_date']) {
        $__range .= ' - ' . date('d M', strtotime($lr['end_date']));
    }
    // Evento "approvazione/rifiuto" se presente
    if (!empty($lr['approved_at']) && in_array($lr['status'], ['approved','rejected'], true)) {
        $activities[] = [
            'type'  => $lr['status'] === 'approved' ? 'leave_ok' : 'leave_ko',
            'date'  => $lr['approved_at'],
            'title' => ($lr['status'] === 'approved' ? 'Approvata' : 'Rifiutata') . ' richiesta ' . $__type . ' di <strong>' . $__name . '</strong>',
            'meta'  => $__range . ' · da ' . htmlspecialchars($lr['approver_name'] ?? 'admin'),
        ];
    }
    // Evento "creazione" se non creata dall'admin (è una richiesta del dipendente)
    if (empty($lr['created_by_admin'])) {
        $activities[] = [
            'type'  => 'leave_new',
            'date'  => $lr['created_at'],
            'title' => 'Nuova richiesta ' . $__type . ' da <strong>' . $__name . '</strong>',
            'meta'  => $__range,
        ];
    }
}

usort($activities, fn($a, $b) => strcmp($b['date'], $a['date']));
$activities = array_slice($activities, 0, 8);

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'pochi secondi fa';
    if ($diff < 3600) return floor($diff / 60) . ' min fa';
    if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
    if ($diff < 604800) return floor($diff / 86400) . ' giorni fa';
    return date('d M Y', strtotime($datetime));
}

function leaveTypeLabel(string $t): string {
    return match ($t) {
        'ferie'    => 'Ferie',
        'malattia' => 'Malattia',
        'permesso' => 'Permesso',
        default    => ucfirst($t),
    };
}
function leaveDaysCount(string $start, string $end): int {
    $s = new DateTime($start);
    $e = new DateTime($end);
    return $s->diff($e)->days + 1;
}
function initials(string $name): string {
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $p) {
        if ($p !== '') $out .= mb_substr($p, 0, 1);
        if (mb_strlen($out) >= 2) break;
    }
    return mb_strtoupper($out);
}

$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-tenant-wizard.php'; ?>
<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<?php if (($_GET['probation'] ?? '') === 'confirmed'): ?>
    <div class="alert alert-success">Periodo di prova confermato. Il consulente è stato avvisato.</div>
<?php elseif (($_GET['probation'] ?? '') === 'not_confirmed'): ?>
    <div class="alert alert-success">Decisione registrata: prova non superata. Il consulente è stato avvisato; la disattivazione avverrà alla data di fine prova.</div>
<?php elseif (($_GET['probation'] ?? '') === 'err'): ?>
    <div class="alert alert-danger">Impossibile registrare la decisione sul periodo di prova.</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/widget-probation-alerts.php'; ?>

<!-- Welcome card -->
<div class="welcome-card">
    <div>
        <h2><?= $__greeting ?>, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?> 👋</h2>
        <p>
            <?php if ($totalActions > 0): ?>
                Hai <?= $pendingLeaves ?> <?= $pendingLeaves === 1 ? 'richiesta in attesa' : 'richieste in attesa' ?>.
            <?php else: ?>
                Tutto in ordine. Nessuna azione richiesta al momento.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($pendingLeaves > 0): ?>
        <a href="<?= $baseUrl ?>/admin/leave-requests.php" class="btn btn-lg">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            Gestisci richieste
        </a>
    <?php else: ?>
        <a href="<?= $baseUrl ?>/admin/employees.php?action=new" class="btn btn-lg">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Nuovo dipendente
        </a>
    <?php endif; ?>
</div>

<?php /*
<!-- Stats — rimosse: duplicavano "Da approvare" + "In ufficio oggi" della colonna destra -->
<section class="stats">
    <a href="<?= $baseUrl ?>/admin/leave-requests.php" class="stat stat-accent-blue">
        <div class="stat-label">
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
            Da approvare
        </div>
        <div class="stat-value"><?= $__totalApprovals ?></div>
        <span class="stat-trend">
            <?php if ($__totalApprovals > 0): ?>
                <?= $pendingLeaves ?> ferie<?= $pendingResets > 0 ? ' · ' . $pendingResets . ' reset' : '' ?> <span aria-hidden="true">→</span>
            <?php else: ?>
                Tutto approvato <span aria-hidden="true">→</span>
            <?php endif; ?>
        </span>
        <div class="stat-bg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
    </a>
    <a href="<?= $baseUrl ?>/admin/employees.php" class="stat stat-accent-indigo">
        <div class="stat-label">
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
            Oggi in ufficio
        </div>
        <div class="stat-value"><?= $todayPresentCount ?><span style="color:var(--muted);font-weight:500;font-size:0.45em;letter-spacing:0;">&nbsp;/&nbsp;<?= $employeeCount ?></span></div>
        <span class="stat-trend">
            <?php if ($__absentToday > 0): ?>
                <?= $__absentToday ?> assenti<span aria-hidden="true">&nbsp;→</span>
            <?php else: ?>
                Nessun assente <span aria-hidden="true">→</span>
            <?php endif; ?>
        </span>
        <div class="stat-bg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    </a>
    <a href="<?= $baseUrl ?>/admin/employees.php" class="stat stat-accent-sky">
        <div class="stat-label">
            <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4z"/></svg></span>
            Eventi in arrivo
        </div>
        <div class="stat-value"><?= $__upcomingEvents ?></div>
        <span class="stat-trend">
            <?php if ($__upcomingEvents > 0): ?>
                <?php
                $__pieces = [];
                if ($__upcomingBirthdays > 0) $__pieces[] = $__upcomingBirthdays . ' compleann' . ($__upcomingBirthdays === 1 ? 'o' : 'i');
                if ($__upcomingAnniversaries > 0) $__pieces[] = $__upcomingAnniversaries . ' anniversar' . ($__upcomingAnniversaries === 1 ? 'io' : 'i');
                echo implode(' · ', $__pieces);
                ?>
                <span aria-hidden="true">&nbsp;→</span>
            <?php else: ?>
                Prossimi 7 giorni <span aria-hidden="true">→</span>
            <?php endif; ?>
        </span>
        <div class="stat-bg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15 8L21 9L17 14L18 21L12 18L6 21L7 14L3 9L9 8z"/></svg></div>
    </a>
</section>
*/ ?>

<!-- Top row: Heatmap (65%) + Attività recente (35%) -->
<div class="dashboard-top">
<?php
$heatmapDepartmentId = null;
$heatmapBaseUrl = PUBLIC_URL . '/admin/index.php';
$heatmapShowScopeToggle = false;
include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
?>
    <div class="card activity-card-compact">
        <div class="card-h">
            <h3>Attività recente</h3>
        </div>
        <div class="card-b" style="padding-top: var(--sp-2); padding-bottom: var(--sp-2); flex:1; overflow-y:auto;">
            <?php if (empty($activities)): ?>
                <div class="empty">
                    <h3>Nessuna attività</h3>
                    <p>Quando inizierai a usare il sistema le novità appariranno qui.</p>
                </div>
            <?php else: foreach ($activities as $a): ?>
                <div class="activity-item">
                    <div class="activity-icon" style="
                        <?php if ($a['type'] === 'user'): ?>background:var(--success-50); color:var(--success-600);<?php endif; ?>
                        <?php if ($a['type'] === 'comm'): ?>background:var(--warning-50); color:var(--warning-600);<?php endif; ?>
                        <?php if ($a['type'] === 'leave_new'): ?>background:rgba(11,58,164,0.10); color:#0b3aa4;<?php endif; ?>
                        <?php if ($a['type'] === 'leave_ok'): ?>background:var(--success-50); color:var(--success-600);<?php endif; ?>
                        <?php if ($a['type'] === 'leave_ko'): ?>background:var(--danger-50); color:var(--danger-600);<?php endif; ?>
                    ">
                        <?php if ($a['type'] === 'doc'): ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                        <?php elseif ($a['type'] === 'comm'): ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                        <?php elseif ($a['type'] === 'leave_new'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <?php elseif ($a['type'] === 'leave_ok'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        <?php elseif ($a['type'] === 'leave_ko'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="activity-text">
                        <p class="title"><?= $a['title'] ?></p>
                        <span class="meta"><?= htmlspecialchars($a['meta']) ?> · <?= timeAgo($a['date']) ?></span>
                    </div>
                    <?php if ($a['type'] === 'doc'): ?>
                        <span class="badge badge-primary">Doc</span>
                    <?php elseif ($a['type'] === 'user'): ?>
                        <span class="badge badge-primary">New</span>
                    <?php elseif ($a['type'] === 'leave_new'): ?>
                        <span class="badge badge-warning">In attesa</span>
                    <?php elseif ($a['type'] === 'leave_ok'): ?>
                        <span class="badge badge-success">Approvata</span>
                    <?php elseif ($a['type'] === 'leave_ko'): ?>
                        <span class="badge badge-danger">Rifiutata</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
