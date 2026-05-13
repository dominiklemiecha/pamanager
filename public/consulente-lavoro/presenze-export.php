<?php
/**
 * Export presenze mensili (per consulente paghe)
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$now      = new DateTime('today');
$selMonth = isset($_GET['month']) ? max(1, min(12, (int)$_GET['month'])) : (int)$now->format('n');
$selYear  = isset($_GET['year'])  ? max(2024, min(2099, (int)$_GET['year']))  : (int)$now->format('Y');

// Download diretto se richiesto
if (($_GET['action'] ?? '') === 'download') {
    try {
        $exp = new PresenzeExport($selMonth, $selYear);
        $exp->build();
        $exp->streamToBrowser();
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<h2>Errore generazione file</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<p><a href="presenze-export.php">Torna indietro</a></p>';
        exit;
    }
}

// Conta pending del mese (per warning)
$startDate = sprintf('%04d-%02d-01', $selYear, $selMonth);
$endDate   = date('Y-m-t', strtotime($startDate));
$pendingCount = 0;
try {
    $__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
    $pendingCount = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests
         WHERE company_id = ? AND status = 'pending' AND start_date <= ? AND end_date >= ?",
        [$__cid, $endDate, $startDate]
    );
} catch (Throwable $e) {}

$months = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$pageTitle = 'Export presenze';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<section class="card" style="max-width: 560px; margin: 1.5rem auto;">
    <div class="card-body" style="padding: 1.75rem;">
        <h2 style="margin-top: 0;">Export presenze consulente</h2>
        <p class="text-muted" style="margin-bottom: 1.5rem; font-size: 0.9rem;">Genera il file Excel mensile con ferie, ROL, malattia.</p>

        <form method="get" action="presenze-export.php" id="exportForm" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 140px;">
                <label style="display:block; font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom: 4px;">Mese</label>
                <select name="month" style="width:100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $selMonth ? 'selected' : '' ?>><?= $months[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="flex: 0 0 100px;">
                <label style="display:block; font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom: 4px;">Anno</label>
                <select name="year" style="width:100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                    <?php
                    $thisYear = (int)$now->format('Y');
                    for ($y = $thisYear - 1; $y <= $thisYear + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $selYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <input type="hidden" name="action" value="download">
            <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.2rem; font-size: 0.92rem;">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="vertical-align: middle; margin-right: 6px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Scarica
            </button>
        </form>

        <?php if ($pendingCount > 0): ?>
            <div style="margin-top: 1.25rem; padding: 0.7rem 0.9rem; background:#fef3c7; border-radius:8px; font-size: 0.84rem; color:#854d0e;">
                <strong><?= $pendingCount ?></strong> richieste in attesa per <?= $months[$selMonth] ?>: non saranno incluse.
                <a href="leave-requests.php?status=pending" style="color:#854d0e; text-decoration: underline;">Approva ora &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
