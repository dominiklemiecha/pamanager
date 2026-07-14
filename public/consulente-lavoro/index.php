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

$__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;

$employeeCount = Employee::count(true);
$documentCount = Database::count('documents', 'uploaded_by = ? AND company_id = ?', [$user['id'], $__cid]);
$thisMonthDocs = Database::count(
    'documents',
    'uploaded_by = ? AND company_id = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?',
    [$user['id'], $__cid, date('n'), date('Y')]
);
$pendingLeave = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM leave_requests lr
     JOIN employees e ON lr.employee_id = e.id
     WHERE e.company_id = ? AND lr.status = 'pending'",
    [$__cid]
);
$approvedLeaveYear = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM leave_requests lr
     JOIN employees e ON lr.employee_id = e.id
     WHERE e.company_id = ? AND lr.status = 'approved' AND YEAR(lr.start_date) = ?",
    [$__cid, (int)date('Y')]
);

$recentDocuments = Database::fetchAll(
    "SELECT d.*, e.first_name, e.last_name, e.fiscal_code
     FROM documents d
     JOIN employees e ON d.employee_id = e.id
     WHERE d.uploaded_by = ? AND d.company_id = ?
     ORDER BY d.created_at DESC
     LIMIT 8",
    [$user['id'], $__cid]
);

// Notifiche recenti (in-app) del consulente — surface dei messaggi come esiti prova assunzione
$__notifs = class_exists('Notification') ? Notification::getByUser('consulente_lavoro', (int)$user['id'], false, 8) : [];
$__notifUnread = 0;
foreach ($__notifs as $__n) { if (empty($__n['is_read'])) $__notifUnread++; }

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

        <a href="leave-requests.php" class="cl-kpi">
            <div class="cl-kpi-ic" style="background: rgba(17,186,186,0.10); color: #0c8a8a;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
            <div class="cl-kpi-info">
                <div class="cl-kpi-l">Ferie/permessi</div>
                <div class="cl-kpi-v"><?= $approvedLeaveYear ?></div>
                <div class="cl-kpi-s">approvate quest'anno</div>
            </div>
        </a>
    </div>

    <?php if ($pendingLeave > 0): ?>
    <div class="cl-pending-alert">
        <div class="cl-pending-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="cl-pending-body">
            <div class="cl-pending-title">
                <?= $pendingLeave ?> richiest<?= $pendingLeave === 1 ? 'a' : 'e' ?> di ferie/permessi in attesa di approvazione
            </div>
            <div class="cl-pending-sub">
                Queste richieste non sono incluse negli export. Contatta l'amministratore per farle approvare o rifiutare.
            </div>
        </div>
        <a href="chat.php" class="cl-pending-cta">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Contatta admin
        </a>
    </div>
    <style>
    .cl-pending-alert {
        display: flex; align-items: center; gap: 14px;
        background: linear-gradient(180deg, #fffbf3, #fff);
        border: 1px solid #f4d68a;
        border-left: 4px solid #d97706;
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 18px;
    }
    .cl-pending-ic {
        width: 40px; height: 40px; border-radius: 10px;
        background: rgba(217,119,6,0.12); color: #b45309;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .cl-pending-ic svg { width: 20px; height: 20px; }
    .cl-pending-body { flex: 1; min-width: 0; }
    .cl-pending-title { font-weight: 700; color: #78350f; font-size: 14px; }
    .cl-pending-sub { font-size: 12.5px; color: #92400e; margin-top: 2px; line-height: 1.4; }
    .cl-pending-cta {
        display: inline-flex; align-items: center; gap: 6px;
        background: #b45309; color: white;
        padding: 8px 14px; border-radius: 8px;
        font-size: 12.5px; font-weight: 600;
        text-decoration: none; flex-shrink: 0;
        transition: background .12s ease;
    }
    .cl-pending-cta:hover { background: #92400e; color: white; text-decoration: none; }
    @media (max-width: 600px) {
        .cl-pending-alert { flex-direction: column; align-items: stretch; text-align: left; }
        .cl-pending-cta { justify-content: center; }
    }
    </style>
    <?php endif; ?>
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

    <section id="notifiche-card" class="dashboard-card dashboard-card-full" style="margin-top:1rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3>Notifiche recenti<?php if ($__notifUnread > 0): ?> <span class="badge badge-warning" style="vertical-align:middle;"><?= $__notifUnread ?> non lette</span><?php endif; ?></h3>
        </div>
        <?php if (empty($__notifs)): ?>
            <p style="padding:2rem;text-align:center;color:var(--muted);">Nessuna notifica.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;">
            <?php foreach ($__notifs as $__n):
                $__unread = empty($__n['is_read']);
                $__href = !empty($__n['link']) ? ($baseUrl . $__n['link']) : null;
                $__isProbation = ($__n['type'] ?? '') === 'probation_decision';
            ?>
                <<?= $__href ? 'a href="' . htmlspecialchars($__href) . '"' : 'div' ?> style="display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;<?= $__unread ? 'background:rgba(11,58,164,0.035);' : '' ?>">
                    <span style="width:34px;height:34px;border-radius:9px;flex:none;display:flex;align-items:center;justify-content:center;background:<?= $__isProbation ? 'rgba(217,119,6,0.12);color:#b45309' : 'rgba(11,58,164,0.10);color:#0b3aa4' ?>;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:13.5px;color:#1e1e2f;">
                            <?= e($__n['title']) ?>
                            <?php if ($__unread): ?><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#0b3aa4;margin-left:6px;vertical-align:middle;"></span><?php endif; ?>
                        </div>
                        <div style="font-size:12.5px;color:#6e7191;line-height:1.45;margin-top:2px;"><?= e($__n['message']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:3px;"><?= e(formatDateTime($__n['created_at'])) ?></div>
                    </div>
                </<?= $__href ? 'a' : 'div' ?>>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

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
