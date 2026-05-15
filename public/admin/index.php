<?php
/**
 * Dashboard Amministratore — markup Factorial-blue (aligned to mockups/admin-dashboard.html)
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();

// ===== Statistiche (scoped per azienda corrente) =====
$__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
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
$pendingResets = (int) Auth::countPendingResetRequests();
$pendingLeaves = 0;
$pendingLeavesList = [];
try {
    $pendingLeaves = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'pending'",
        [$__cid]
    );
    $pendingLeavesList = Database::fetchAll(
        "SELECT lr.id, lr.type, lr.start_date, lr.end_date, e.first_name, e.last_name
         FROM leave_requests lr
         JOIN employees e ON e.id = lr.employee_id
         WHERE lr.company_id = ? AND lr.status = 'pending'
         ORDER BY lr.created_at DESC LIMIT 4",
        [$__cid]
    );
} catch (Exception $e) {}
$totalActions = $pendingResets + $pendingLeaves;

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

usort($activities, fn($a, $b) => strcmp($b['date'], $a['date']));
$activities = array_slice($activities, 0, 6);

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

<!-- Welcome card -->
<div class="welcome-card">
    <div>
        <h2>Buongiorno, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?> 👋</h2>
        <p>
            <?php if ($totalActions > 0): ?>
                Hai <?= $pendingLeaves ?> <?= $pendingLeaves === 1 ? 'richiesta in attesa' : 'richieste in attesa' ?>
                <?php if ($pendingResets > 0): ?> e <?= $pendingResets ?> reset password<?php endif; ?>.
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

<!-- Stats -->
<section class="stats">
    <div class="stat">
        <span class="stat-label">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/></svg>
            Dipendenti attivi
        </span>
        <span class="stat-value"><?= $employeeCount ?></span>
        <a href="employees.php" class="stat-trend flat" style="text-decoration:none;">Gestisci →</a>
    </div>
    <div class="stat">
        <span class="stat-label">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
            Ferie in attesa
        </span>
        <span class="stat-value"><?= $pendingLeaves ?></span>
        <span class="stat-trend flat">In coda</span>
    </div>
    <div class="stat">
        <span class="stat-label">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
            Documenti caricati
        </span>
        <span class="stat-value"><?= $documentCount ?></span>
        <span class="stat-trend flat">archivio totale</span>
    </div>
    <div class="stat">
        <span class="stat-label">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
            Comunicazioni attive
        </span>
        <span class="stat-value"><?= $unreadComms ?></span>
        <a href="communications.php" class="stat-trend flat" style="text-decoration:none;">Gestisci →</a>
    </div>
</section>

<!-- Heatmap presenze (full width) -->
<?php
$heatmapDepartmentId = null;
$heatmapBaseUrl = PUBLIC_URL . '/admin/index.php';
$heatmapShowScopeToggle = false;
include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
?>

<!-- Dashboard grid: Attività + Da approvare -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-h">
            <h3>Attività recente</h3>
            <a href="#" style="font-size:var(--text-sm); color:var(--primary-600);">Vedi tutto →</a>
        </div>
        <div class="card-b" style="padding-top: var(--sp-2); padding-bottom: var(--sp-2);">
            <?php if (empty($activities)): ?>
                <div class="empty">
                    <h3>Nessuna attività</h3>
                    <p>Quando inizierai a usare il sistema le novità appariranno qui.</p>
                </div>
            <?php else: foreach ($activities as $a): ?>
                <div class="activity-item">
                    <div class="activity-icon <?= $a['type'] === 'user' ? 'is-user' : ($a['type'] === 'comm' ? 'is-comm' : '') ?>" style="
                        <?php if ($a['type'] === 'user'): ?>background:var(--success-50); color:var(--success-600);<?php endif; ?>
                        <?php if ($a['type'] === 'comm'): ?>background:var(--warning-50); color:var(--warning-600);<?php endif; ?>
                    ">
                        <?php if ($a['type'] === 'doc'): ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                        <?php elseif ($a['type'] === 'comm'): ?>
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
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
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div>
        <div class="card" style="margin-bottom: var(--sp-4);">
            <div class="card-h">
                <h3>Da approvare</h3>
                <?php if ($pendingLeaves > 0): ?>
                    <span class="badge badge-warning"><?= $pendingLeaves ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($pendingLeavesList)): ?>
                <div class="card-b">
                    <div class="empty" style="padding: var(--sp-6) var(--sp-3);">
                        <p>Nessuna richiesta in attesa.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($pendingLeavesList as $lr):
                    $__fullName = $lr['first_name'] . ' ' . $lr['last_name'];
                    $__days = leaveDaysCount($lr['start_date'], $lr['end_date']);
                    $__rangeStart = date('d M', strtotime($lr['start_date']));
                    $__rangeEnd   = date('d M', strtotime($lr['end_date']));
                ?>
                    <div class="pending-item" style="display:flex; align-items:center; gap:var(--sp-3); padding:var(--sp-3) var(--sp-4); border-bottom:1px solid var(--border);">
                        <div class="av av-sm"><?= initials($__fullName) ?></div>
                        <div class="pending-info" style="flex:1; min-width:0;">
                            <p class="name" style="font-size:var(--text-sm); color:var(--ink); font-weight:500; margin:0;"><?= htmlspecialchars($__fullName) ?></p>
                            <p class="desc" style="font-size:var(--text-xs); color:var(--muted); margin:2px 0 0;"><?= leaveTypeLabel($lr['type']) ?> · <?= $__rangeStart ?>-<?= $__rangeEnd ?> · <?= $__days ?> gg</p>
                        </div>
                        <a href="<?= $baseUrl ?>/admin/leave-requests.php" class="btn btn-sm btn-ghost" title="Vedi">→</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-h">
                <h3>Panoramica</h3>
            </div>
            <div class="card-b">
                <div style="display:flex; flex-direction:column; gap:var(--sp-3); font-size:var(--text-sm); color:var(--ink-2);">
                    <div style="display:flex; justify-content:space-between;">
                        <span>Dipendenti</span>
                        <strong><?= $employeeCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Commercialisti</span>
                        <strong><?= $accountantCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Comunicazioni attive</span>
                        <strong><?= $unreadComms ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
