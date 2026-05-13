<?php
/**
 * Dashboard Consulente del lavoro
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$user = Auth::getUser();

$employeeCount = Employee::count(true);
$documentCount = Database::count('documents', 'uploaded_by = ?', [$user['id']]);
$thisMonthDocs = Database::count(
    'documents',
    'uploaded_by = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?',
    [$user['id'], date('n'), date('Y')]
);
$pendingLeave = Database::fetchColumn(
    "SELECT COUNT(*) FROM leave_requests lr
     JOIN employees e ON lr.employee_id = e.id
     WHERE e.company_id = ? AND lr.status = 'pending'",
    [class_exists('Tenant') ? Tenant::currentCompanyId() : 1]
);

$recentDocuments = Database::fetchAll(
    "SELECT d.*, e.first_name, e.last_name, e.fiscal_code
     FROM documents d
     JOIN employees e ON d.employee_id = e.id
     WHERE d.uploaded_by = ?
     ORDER BY d.created_at DESC
     LIMIT 8",
    [$user['id']]
);

$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="dashboard">
    <div class="page-header">
        <div>
            <h1>Benvenuto, <?= e($user['name']) ?></h1>
            <p class="page-subtitle"><?= getItalianDate() ?></p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <a href="employees.php" class="btn btn-secondary btn-sm">Anagrafica</a>
            <a href="documents.php" class="btn btn-primary btn-sm">Carica documenti</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card stat-primary">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $employeeCount ?></span>
                <span class="stat-label">Dipendenti attivi</span>
            </div>
        </div>

        <div class="stat-card stat-info">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $documentCount ?></span>
                <span class="stat-label">Documenti caricati</span>
            </div>
        </div>

        <div class="stat-card stat-success">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/></svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $thisMonthDocs ?></span>
                <span class="stat-label">Caricati questo mese</span>
            </div>
        </div>

        <div class="stat-card stat-warning">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-6h2v6zm0-8h-2V7h2v4z"/></svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= (int)$pendingLeave ?></span>
                <span class="stat-label">Ferie/permessi pendenti</span>
            </div>
        </div>
    </div>

    <section class="dashboard-card dashboard-card-full" style="margin-top:1rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3>Ultimi documenti caricati</h3>
            <a href="documents.php" class="btn btn-sm btn-secondary">Vedi tutti</a>
        </div>

        <?php if (empty($recentDocuments)): ?>
            <p style="padding:2rem;text-align:center;color:var(--muted);">Nessun documento caricato.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table data-table-hover responsive">
                    <thead>
                        <tr>
                            <th>Dipendente</th>
                            <th>Tipo</th>
                            <th>Titolo</th>
                            <th>Periodo</th>
                            <th>Caricato</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentDocuments as $doc): ?>
                        <tr>
                            <td data-label="Dipendente"><?= e($doc['last_name'] . ' ' . $doc['first_name']) ?></td>
                            <td data-label="Tipo">
                                <span class="badge badge-info"><?= e(Document::TYPES[$doc['type']] ?? $doc['type']) ?></span>
                            </td>
                            <td data-label="Titolo"><?= e($doc['title']) ?></td>
                            <td data-label="Periodo"><?= e(getMonthName($doc['month'])) ?> <?= (int)$doc['year'] ?></td>
                            <td data-label="Caricato"><?= e(formatDateTime($doc['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
