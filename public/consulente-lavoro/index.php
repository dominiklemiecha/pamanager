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

<?php
$__hour = (int) date('H');
$__greeting = $__hour < 12 ? 'Buongiorno' : ($__hour < 18 ? 'Buon pomeriggio' : 'Buonasera');
?>
<div class="cl-banner">
    <div>
        <h2><?= htmlspecialchars($__greeting) ?>, <?= e($user['name']) ?> 👋</h2>
        <p><?= htmlspecialchars(ucfirst(getItalianDate())) ?> · Area Consulente del Lavoro</p>
    </div>
    <div class="cl-banner-actions">
        <a href="employees.php" class="cl-banner-btn cl-banner-btn-ghost">Anagrafica</a>
        <a href="documents.php" class="cl-banner-btn cl-banner-btn-primary">Carica documenti</a>
    </div>
</div>
<style>
.cl-banner {
    background: white;
    border: 1px solid #e6e8f0;
    border-left: 4px solid #0b3aa4;
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 18px;
    display: flex; justify-content: space-between; align-items: center;
    gap: 16px; flex-wrap: wrap;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.cl-banner h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0 0 4px;
    font-size: 20px; font-weight: 700;
    color: #0b3aa4; letter-spacing: -0.02em;
}
.cl-banner p { margin: 0; font-size: 13px; color: #6e7191; }
.cl-banner-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.cl-banner-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px;
    border-radius: 9px;
    font-size: 13px; font-weight: 600;
    text-decoration: none;
    transition: all .12s ease;
    border: 1px solid transparent;
}
.cl-banner-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.cl-banner-btn-primary:hover { background: #082b7b; color: white; text-decoration: none; }
.cl-banner-btn-ghost { background: white; color: #475569; border-color: #e6e8f0; }
.cl-banner-btn-ghost:hover { border-color: #0b3aa4; color: #0b3aa4; text-decoration: none; }
@media (max-width: 600px) {
    .cl-banner { flex-direction: column; align-items: stretch; }
    .cl-banner-btn { flex: 1; justify-content: center; }
}
</style>

<div class="dashboard">

    <div class="cl-kpis">
        <a href="employees.php" class="cl-kpi">
            <div class="cl-kpi-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="cl-kpi-info">
                <div class="cl-kpi-l">Dipendenti</div>
                <div class="cl-kpi-v"><?= (int)$employeeCount ?></div>
                <div class="cl-kpi-s">attivi in anagrafica</div>
            </div>
        </a>

        <a href="documents.php" class="cl-kpi">
            <div class="cl-kpi-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="cl-kpi-info">
                <div class="cl-kpi-l">Documenti caricati</div>
                <div class="cl-kpi-v"><?= (int)$documentCount ?></div>
                <div class="cl-kpi-s">totale storico</div>
            </div>
        </a>

        <div class="cl-kpi">
            <div class="cl-kpi-ic" style="background: rgba(17,186,186,0.10); color: #0c8a8a;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h2M14 14h2M8 18h2"/></svg>
            </div>
            <div class="cl-kpi-info">
                <div class="cl-kpi-l">Questo mese</div>
                <div class="cl-kpi-v"><?= (int)$thisMonthDocs ?></div>
                <div class="cl-kpi-s">documenti caricati a <?= mb_strtolower(getMonthName((int)date('n'))) ?></div>
            </div>
        </div>

        <a href="leave-requests.php?status=pending" class="cl-kpi <?= (int)$pendingLeave > 0 ? 'is-warn' : '' ?>">
            <div class="cl-kpi-ic" style="background: rgba(255,187,85,0.15); color: #b07023;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="cl-kpi-info">
                <div class="cl-kpi-l">Ferie/permessi</div>
                <div class="cl-kpi-v"><?= (int)$pendingLeave ?></div>
                <div class="cl-kpi-s">richieste pendenti</div>
            </div>
        </a>
    </div>
    <style>
    .cl-kpis {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 18px;
    }
    .cl-kpi {
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 14px;
        padding: 18px;
        display: flex; align-items: center; gap: 14px;
        text-decoration: none;
        transition: all .12s ease;
        cursor: default;
    }
    a.cl-kpi { cursor: pointer; }
    a.cl-kpi:hover {
        border-color: #0b3aa4;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(11,58,164,0.08);
        text-decoration: none;
    }
    .cl-kpi.is-warn { border-color: rgba(255,187,85,0.45); background: linear-gradient(180deg, #fffbf3, white); }
    .cl-kpi-ic {
        width: 44px; height: 44px;
        border-radius: 11px;
        background: rgba(11,58,164,0.10); color: #0b3aa4;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .cl-kpi-ic svg { width: 22px; height: 22px; }
    .cl-kpi-info { flex: 1; min-width: 0; }
    .cl-kpi-l {
        font-size: 10px; font-weight: 700; color: #6e7191;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .cl-kpi-v {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 28px; font-weight: 700;
        color: #1e1e2f; line-height: 1.05;
        letter-spacing: -0.02em;
        margin: 2px 0;
    }
    .cl-kpi-s {
        font-size: 11px; color: #94a3b8;
        line-height: 1.4;
    }
    @media (max-width: 1000px) { .cl-kpis { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .cl-kpis { grid-template-columns: 1fr; } }
    </style>

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
