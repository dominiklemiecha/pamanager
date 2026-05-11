<?php
/**
 * Dashboard Dipendente
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$employeeDeptId = $employee['department_id'] ?? null;

$allDocs = Document::getByEmployee($employee['id']);
$docStats = ['payslip' => 0, 'cud' => 0, 'other' => 0, 'total' => count($allDocs)];
foreach ($allDocs as $d) {
    if (isset($docStats[$d['type']])) $docStats[$d['type']]++;
}

$unreadCount = Communication::countUnread($employee['id'], $employeeDeptId);
$recentDocuments = array_slice($allDocs, 0, 4);
$communications  = Communication::getActive($employee['id']);
$recentComms     = array_slice($communications, 0, 4);

// Ultima busta paga
$latestPayslip = null;
foreach ($allDocs as $d) {
    if ($d['type'] === 'payslip') { $latestPayslip = $d; break; }
}

$monthNames = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
               7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];

function timeAgoEmp(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'pochi secondi fa';
    if ($diff < 3600) return floor($diff / 60) . ' min fa';
    if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
    if ($diff < 604800) return floor($diff / 86400) . ' giorni fa';
    return date('d M Y', strtotime($datetime));
}

$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<div class="welcome-banner">
    <div>
        <h2>Ciao <?= htmlspecialchars($employee['first_name']) ?> 👋</h2>
        <p class="welcome-date"><?= getItalianDate() ?></p>
    </div>
</div>

<!-- HERO: ultima busta paga o stato -->
<?php if ($latestPayslip): ?>
    <div class="hero-inbox">
        <div>
            <h2>Ultima busta paga disponibile</h2>
            <p>
                <?= $monthNames[(int)$latestPayslip['month']] ?> <?= $latestPayslip['year'] ?>
                · caricata <?= timeAgoEmp($latestPayslip['created_at']) ?>
            </p>
        </div>
        <a href="<?= PUBLIC_URL ?>/employee/documents.php" class="hero-cta">Scarica documenti →</a>
    </div>
<?php elseif ($unreadCount > 0): ?>
    <div class="hero-inbox">
        <div>
            <h2>Hai <?= $unreadCount ?> <?= $unreadCount === 1 ? 'comunicazione' : 'comunicazioni' ?> da leggere</h2>
            <p>Resta aggiornato sulle ultime notizie aziendali</p>
        </div>
        <a href="<?= PUBLIC_URL ?>/employee/communications.php" class="hero-cta">Leggi ora →</a>
    </div>
<?php else: ?>
    <div class="hero-inbox is-clear">
        <div>
            <h2>✓ Sei aggiornato</h2>
            <p>Nessuna comunicazione o documento da consultare al momento.</p>
        </div>
    </div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi-strip">
    <div class="kpi">
        <span class="kpi-label">Buste paga</span>
        <span class="kpi-value"><?= $docStats['payslip'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">CUD</span>
        <span class="kpi-value"><?= $docStats['cud'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Altri documenti</span>
        <span class="kpi-value"><?= $docStats['other'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Da leggere</span>
        <span class="kpi-value"><?= $unreadCount ?></span>
    </div>
</div>

<!-- Heatmap presenze settimanali (colleghi del reparto) + azioni rapide -->
<div class="heatmap-and-actions">
    <div class="heatmap-and-actions-main">
        <?php
        // Default: tutti i dipendenti. Toggle "Mio reparto / Tutti" disponibile.
        $scope = ($_GET['scope'] ?? 'all') === 'mine' ? 'mine' : 'all';
        $heatmapDepartmentId = ($scope === 'mine' && $employeeDeptId) ? $employeeDeptId : null;
        $heatmapBaseUrl = PUBLIC_URL . '/employee/index.php';
        $heatmapShowScopeToggle = !empty($employeeDeptId);
        $heatmapMyDepartmentId = $employeeDeptId;
        $heatmapDefaultScope = 'all';
        include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
        ?>
    </div>
    <aside class="heatmap-and-actions-side">
        <div class="section-heading"><h3>Azioni rapide</h3></div>
        <div class="quick-actions quick-actions-stacked">
            <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new" class="quick-action qa-accent">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/></svg>
                </div>
                <div class="qa-title">Richiedi ferie</div>
                <div class="qa-sub">Nuova richiesta</div>
            </a>
            <a href="<?= PUBLIC_URL ?>/employee/chat.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                </div>
                <div class="qa-title">Apri chat</div>
                <div class="qa-sub">Contatta l'ufficio</div>
            </a>
            <a href="<?= PUBLIC_URL ?>/employee/documents.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                </div>
                <div class="qa-title">Documenti</div>
                <div class="qa-sub">Buste paga e CUD</div>
            </a>
            <a href="<?= PUBLIC_URL ?>/employee/change-password.php" class="quick-action">
                <div class="qa-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6z"/></svg>
                </div>
                <div class="qa-title">Cambia password</div>
                <div class="qa-sub">Aggiorna credenziali</div>
            </a>
        </div>
    </aside>
</div>

<div class="dashboard-grid">
    <!-- Ultimi documenti -->
    <section class="card">
        <div class="card-header">
            <h3>Ultimi documenti</h3>
            <a href="<?= PUBLIC_URL ?>/employee/documents.php" class="btn btn-link btn-sm">Vedi tutti →</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentDocuments)): ?>
                <div class="empty-state">Nessun documento disponibile.</div>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentDocuments as $d):
                        $typeLabels = ['payslip' => 'Busta Paga', 'cud' => 'CUD', 'other' => 'Documento'];
                        $typeLabel = $typeLabels[$d['type']] ?? 'Documento';
                        $period = $monthNames[(int)$d['month']] . ' ' . $d['year'];
                    ?>
                        <li class="activity-item">
                            <div class="activity-icon is-doc">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($d['title']) ?></div>
                                <div class="activity-meta">
                                    <?= htmlspecialchars($typeLabel) ?> · <?= htmlspecialchars($period) ?>
                                    · <?= timeAgoEmp($d['created_at']) ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <!-- Comunicazioni -->
    <section class="card">
        <div class="card-header">
            <h3>Comunicazioni</h3>
            <a href="<?= PUBLIC_URL ?>/employee/communications.php" class="btn btn-link btn-sm">Vedi tutte →</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentComms)): ?>
                <div class="empty-state">Nessuna comunicazione.</div>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentComms as $c): ?>
                        <li class="activity-item">
                            <div class="activity-icon is-comm">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($c['title']) ?></div>
                                <div class="activity-meta">
                                    <?php if (!empty($c['priority']) && $c['priority'] === 'urgent'): ?>
                                        <span class="badge badge-danger badge-sm">Urgente</span>
                                    <?php endif; ?>
                                    <?= timeAgoEmp($c['created_at'] ?? $c['publish_date']) ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
