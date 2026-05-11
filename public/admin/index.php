<?php
/**
 * Dashboard Amministratore
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();

// ===== Statistiche principali (scoped per azienda corrente) =====
$__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$employeeCount     = Employee::count(true);
$documentCount     = Document::count();
$unreadComms       = Database::fetchColumn(
    "SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = TRUE AND publish_date <= CURDATE() AND (expire_date IS NULL OR expire_date >= CURDATE())",
    [$__cid]
);
// Commercialisti: globali (NULL company_id) + quelli assegnati a questa azienda
$accountantCount   = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM users WHERE role = 'accountant' AND is_active = TRUE AND (company_id = ? OR company_id IS NULL)",
    [$__cid]
);

// ===== Azioni richieste (inbox amministratore) =====
$pendingResets = (int) Auth::countPendingResetRequests();
$pendingLeaves = 0;
try {
    $pendingLeaves = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'pending'",
        [$__cid]
    );
} catch (Exception $e) {}
$totalActions = $pendingResets + $pendingLeaves;

// ===== Ultime attività (timeline mista, scoped) =====
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
$activities = array_slice($activities, 0, 10);

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'pochi secondi fa';
    if ($diff < 3600) return floor($diff / 60) . ' min fa';
    if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
    if ($diff < 604800) return floor($diff / 86400) . ' giorni fa';
    return date('d M Y', strtotime($datetime));
}

$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-tenant-wizard.php'; ?>
<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<?php if ($totalActions > 0): ?>
    <div class="hero-inbox">
        <div>
            <h2>Hai <?= $totalActions ?> <?= $totalActions === 1 ? 'azione' : 'azioni' ?> in attesa</h2>
            <p>
                <?php
                $bits = [];
                if ($pendingResets > 0) $bits[] = "$pendingResets reset password";
                if ($pendingLeaves > 0) $bits[] = "$pendingLeaves richieste ferie";
                echo implode(' · ', $bits);
                ?>
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <?php if ($pendingResets > 0): ?>
                <a href="password-resets.php" class="hero-cta">Gestisci reset password →</a>
            <?php endif; ?>
            <?php if ($pendingLeaves > 0): ?>
                <a href="<?= PUBLIC_URL ?>/admin/leave-requests.php" class="hero-cta">Gestisci ferie →</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- KPI strip -->
<div class="kpi-strip">
    <div class="kpi">
        <span class="kpi-label">Dipendenti attivi</span>
        <span class="kpi-value"><?= $employeeCount ?></span>
        <a href="employees.php" class="kpi-delta">Gestisci →</a>
    </div>
    <div class="kpi">
        <span class="kpi-label">Documenti caricati</span>
        <span class="kpi-value"><?= $documentCount ?></span>
        <span class="kpi-delta">archivio totale</span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Comunicazioni attive</span>
        <span class="kpi-value"><?= $unreadComms ?></span>
        <a href="communications.php" class="kpi-delta">Gestisci →</a>
    </div>
    <div class="kpi">
        <span class="kpi-label">Commercialisti</span>
        <span class="kpi-value"><?= $accountantCount ?></span>
        <a href="accountant.php" class="kpi-delta">Gestisci →</a>
    </div>
</div>

<!-- Heatmap presenze + azioni rapide (60/40) -->
<div class="heatmap-and-actions">
    <div class="heatmap-and-actions-main">
        <?php
        $heatmapDepartmentId = null;
        $heatmapBaseUrl = PUBLIC_URL . '/admin/index.php';
        $heatmapShowScopeToggle = false;
        include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
        ?>
    </div>
    <aside class="heatmap-and-actions-side">
        <div class="section-heading"><h3>Azioni rapide</h3></div>
        <div class="quick-actions quick-actions-stacked">
            <a href="employees.php?action=new" class="quick-action qa-accent">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                </div>
                <div class="qa-title">Nuovo dipendente</div>
                <div class="qa-sub">Crea account e invia credenziali</div>
            </a>
            <a href="communications.php?action=new" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                </div>
                <div class="qa-title">Nuova comunicazione</div>
                <div class="qa-sub">Pubblica un avviso</div>
            </a>
            <a href="leave-requests.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                </div>
                <div class="qa-title">Richieste ferie</div>
                <div class="qa-sub">Approva o rifiuta</div>
            </a>
            <a href="departments.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10z"/></svg>
                </div>
                <div class="qa-title">Reparti</div>
                <div class="qa-sub">Organizza dipendenti</div>
            </a>
            <a href="smtp-settings.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                </div>
                <div class="qa-title">Configurazione email</div>
                <div class="qa-sub">SMTP per notifiche</div>
            </a>
        </div>
    </aside>
</div>

<!-- Attività recente full-width -->
<section class="card">
    <div class="card-header">
        <h3>Attività recente</h3>
        <span class="text-muted" style="font-size:0.78rem;">ultimi 10 eventi</span>
    </div>
    <div class="card-body">
        <?php if (empty($activities)): ?>
            <div class="empty-state">Nessuna attività recente.</div>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($activities as $a): ?>
                    <li class="activity-item">
                        <div class="activity-icon is-<?= $a['type'] ?>">
                            <?php if ($a['type'] === 'doc'): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                            <?php elseif ($a['type'] === 'comm'): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= $a['title'] ?></div>
                            <div class="activity-meta"><?= htmlspecialchars($a['meta']) ?> · <?= timeAgo($a['date']) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
