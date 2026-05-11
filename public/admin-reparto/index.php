<?php
/**
 * Dashboard Admin Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = $user['department_id'] ?? null;

if (!$departmentId) {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<h2>Nessun reparto assegnato</h2>';
    echo '<p>Contatta l\'amministratore per essere assegnato a un reparto.</p>';
    echo '</div>';
    exit;
}

$department = Department::getById($departmentId);
$employees = Department::getEmployees($departmentId, true);

// Statistiche richieste ferie
$leaveStats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total' => 0
];

$__arCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
try {
    $stats = Database::fetchAll(
        "SELECT lr.status, COUNT(*) as count
         FROM leave_requests lr
         JOIN employees e ON lr.employee_id = e.id
         WHERE e.company_id = ? AND e.department_id = ?
         GROUP BY lr.status",
        [$__arCid, $departmentId]
    );
    foreach ($stats as $stat) {
        $leaveStats[$stat['status']] = (int) $stat['count'];
        $leaveStats['total'] += (int) $stat['count'];
    }
} catch (Exception $e) {
    // Tabella potrebbe non esistere ancora
}

// Richieste recenti
$recentRequests = [];
try {
    $recentRequests = Database::fetchAll(
        "SELECT lr.*, e.first_name, e.last_name, e.photo_path
         FROM leave_requests lr
         JOIN employees e ON lr.employee_id = e.id
         WHERE e.company_id = ? AND e.department_id = ?
         ORDER BY lr.created_at DESC
         LIMIT 5",
        [$__arCid, $departmentId]
    );
} catch (Exception $e) {
    // Tabella potrebbe non esistere ancora
}

$pageTitle = 'Dashboard - ' . htmlspecialchars($department['name']);
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<div class="dashboard">
    <!-- Benvenuto e Azioni Rapide -->
    <div class="dashboard-header">
        <div class="welcome-section">
            <h2>Benvenuto, <?= e($user['name']) ?></h2>
            <p class="welcome-date"><?= getItalianDate() ?> - Reparto <?= e($department['name']) ?></p>
        </div>
        <div class="quick-actions-inline">
            <a href="leave-requests.php" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                Gestisci Richieste
            </a>
            <a href="employees.php" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/>
                </svg>
                Dipendenti
            </a>
            <a href="chat.php" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                Chat
            </a>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="stats-grid">
        <div class="stat-card stat-primary">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= count($employees) ?></span>
                <span class="stat-label">Dipendenti Reparto</span>
            </div>
            <a href="employees.php" class="stat-action">Gestisci &rarr;</a>
        </div>

        <div class="stat-card stat-warning">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $leaveStats['pending'] ?></span>
                <span class="stat-label">Richieste in Attesa</span>
            </div>
            <a href="leave-requests.php?status=pending" class="stat-action">Approva &rarr;</a>
        </div>

        <div class="stat-card stat-success">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $leaveStats['approved'] ?></span>
                <span class="stat-label">Approvate</span>
            </div>
            <a href="leave-requests.php?status=approved" class="stat-action">Vedi &rarr;</a>
        </div>

        <div class="stat-card stat-info">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $leaveStats['total'] ?></span>
                <span class="stat-label">Totale Richieste</span>
            </div>
            <a href="leave-requests.php" class="stat-action">Vedi tutte &rarr;</a>
        </div>
    </div>

    <!-- Heatmap presenze + azioni rapide (60/40) -->
    <div class="heatmap-and-actions">
        <div class="heatmap-and-actions-main">
            <?php
            $scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
            $heatmapDepartmentId = $scope === 'mine' ? $departmentId : null;
            $heatmapBaseUrl = PUBLIC_URL . '/admin-reparto/index.php';
            $heatmapShowScopeToggle = true;
            $heatmapMyDepartmentId = $departmentId;
            $heatmapDefaultScope = 'mine';
            include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
            ?>
        </div>
        <aside class="heatmap-and-actions-side">
            <div class="section-heading"><h3>Azioni rapide</h3></div>
            <div class="quick-actions quick-actions-stacked">
                <a href="leave-requests.php" class="quick-action qa-accent">
                    <div class="qa-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    </div>
                    <div class="qa-title">Richieste ferie</div>
                    <div class="qa-sub">Approva o rifiuta</div>
                </a>
                <a href="employees.php" class="quick-action">
                    <div class="qa-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/></svg>
                    </div>
                    <div class="qa-title">Dipendenti</div>
                    <div class="qa-sub">Gestisci reparto</div>
                </a>
                <a href="communications.php" class="quick-action">
                    <div class="qa-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    </div>
                    <div class="qa-title">Comunicazioni</div>
                    <div class="qa-sub">Avvisi del reparto</div>
                </a>
                <a href="chat.php" class="quick-action">
                    <div class="qa-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    </div>
                    <div class="qa-title">Chat</div>
                    <div class="qa-sub">Contatta i dipendenti</div>
                </a>
            </div>
        </aside>
    </div>

    <!-- Sezione principale -->
    <div class="dashboard-content">
        <!-- Richieste Recenti -->
        <section class="dashboard-card">
            <div class="card-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                    </svg>
                    Richieste Recenti
                </h3>
                <a href="leave-requests.php" class="btn btn-sm btn-link">Vedi tutte</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentRequests)): ?>
                    <div class="empty-state-small">
                        <p>Nessuna richiesta recente</p>
                    </div>
                <?php else: ?>
                    <div class="employee-list">
                        <?php
                        $leaveTypes = LeaveRequest::LEAVE_TYPES;
                        foreach ($recentRequests as $req):
                            $statusClass = match($req['status']) {
                                'pending' => 'badge-warning',
                                'approved' => 'badge-success',
                                'rejected' => 'badge-danger',
                                default => 'badge-gray'
                            };
                        ?>
                            <div class="employee-item">
                                <?= employeeAvatarHtml($req, 'employee-avatar') ?>
                                <div class="employee-info">
                                    <span class="employee-name"><?= e($req['last_name'] . ' ' . $req['first_name']) ?></span>
                                    <span class="employee-meta">
                                        <?= $leaveTypes[$req['leave_type']] ?? $req['leave_type'] ?>
                                        <span class="separator">|</span>
                                        <?= formatDate($req['start_date']) ?>
                                        <?php if ($req['start_date'] !== $req['end_date']): ?>
                                            - <?= formatDate($req['end_date']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="employee-stats">
                                    <span class="badge <?= $statusClass ?>">
                                        <?= LeaveRequest::STATUSES[$req['status']] ?? ucfirst($req['status']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Dipendenti Reparto -->
        <section class="dashboard-card">
            <div class="card-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/>
                    </svg>
                    Dipendenti del Reparto
                </h3>
                <a href="employees.php" class="btn btn-sm btn-link">Gestisci</a>
            </div>
            <div class="card-body">
                <?php if (empty($employees)): ?>
                    <div class="empty-state-small">
                        <p>Nessun dipendente assegnato</p>
                    </div>
                <?php else: ?>
                    <div class="employee-list">
                        <?php foreach (array_slice($employees, 0, 8) as $emp): ?>
                            <div class="employee-item">
                                <?= employeeAvatarHtml($emp, 'employee-avatar') ?>
                                <div class="employee-info">
                                    <span class="employee-name"><?= e($emp['last_name'] . ' ' . $emp['first_name']) ?></span>
                                    <span class="employee-meta">
                                        <code><?= e($emp['fiscal_code']) ?></code>
                                    </span>
                                </div>
                                <div class="employee-stats">
                                    <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $emp['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($employees) > 8): ?>
                            <div class="text-center" style="padding: 0.75rem;">
                                <a href="employees.php" class="btn btn-sm btn-secondary">Vedi tutti (<?= count($employees) ?>)</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
