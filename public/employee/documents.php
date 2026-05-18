<?php
/**
 * Visualizzazione Documenti - Dipendente
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();

// Gestione download documento personale (EmployeeDocument)
if (isset($_GET['download_personal'])) {
    $docId = (int) $_GET['download_personal'];
    $result = EmployeeDocument::download($docId);
    if ($result['success']) {
        $doc = $result['document'];
        $filePath = $result['file_path'];
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
        setDownloadHeaders($downloadName, $doc['mime_type'], filesize($filePath));
        if (ob_get_level()) { ob_end_clean(); }
        readfile($filePath);
        exit;
    }
    $error = $result['error'];
}

// Gestione download
if (isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = Document::download($docId, true); // con watermark

    if ($result['success']) {
        $doc = $result['document'];
        $filePath = $result['file_path'] ?? $doc['file_path'];
        $tempFile = $result['temp_file'] ?? null;

        if (!file_exists($filePath)) {
            $error = 'File non trovato sul server';
        } else {
            $downloadName = $doc['original_name'] ?? $doc['file_name'];
            $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);

            setDownloadHeaders($downloadName, $doc['mime_type'], filesize($filePath));

            if (ob_get_level()) {
                ob_end_clean();
            }

            readfile($filePath);

            // Pulisci file temporaneo watermark
            if ($tempFile) {
                Document::cleanupTempFile($tempFile);
            }
            exit;
        }
    } else {
        $error = $result['error'];
    }
}

// Filtri
$filterYear = !empty($_GET['year']) ? (int) $_GET['year'] : null;
$filterType = !empty($_GET['type']) ? $_GET['type'] : null;

// Anni disponibili
$availableYears = Document::getAvailableYears($employee['id']);

// Conta documenti non letti
$unreadCount = Document::getUnreadCountForEmployee($employee['id']);

// Carica documenti con stato download
$documents = Document::getByEmployeeWithStatus($employee['id'], $filterYear, null, $filterType);

// Carica documenti personali (visibili)
$personalDocs = EmployeeDocument::getByEmployee((int) $employee['id'], true);

// Raggruppa per anno/mese
$groupedDocs = [];
foreach ($documents as $doc) {
    $key = $doc['year'] . '-' . str_pad($doc['month'], 2, '0', STR_PAD_LEFT);
    if (!isset($groupedDocs[$key])) {
        $groupedDocs[$key] = ['year' => $doc['year'], 'month' => $doc['month'], 'documents' => []];
    }
    $groupedDocs[$key]['documents'][] = $doc;
}
krsort($groupedDocs);

$pageTitle = 'I Miei Documenti';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<style>
/* Notification Banner */
.notification-banner {
    background: linear-gradient(135deg, #4299e1 0%, #0b3aa4 100%);
    color: white;
    padding: 1rem 1.25rem;
    border-radius: 10px;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.notification-banner .notif-icon {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-banner .notif-icon svg {
    width: 24px;
    height: 24px;
}

.notification-banner .notif-content {
    flex: 1;
}

.notification-banner .notif-title {
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
}

.notification-banner .notif-text {
    font-size: 0.8rem;
    opacity: 0.9;
}

.notification-banner .notif-badge {
    background: #fff;
    color: #0b3aa4;
    font-size: 1.25rem;
    font-weight: 700;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Unread Document Indicator */
.doc-card.unread {
    border-left: 4px solid #4299e1;
    position: relative;
}

.doc-card.unread::before {
    content: 'NUOVO';
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: #4299e1;
    color: white;
    font-size: 0.55rem;
    font-weight: 700;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    letter-spacing: 0.5px;
}

.doc-card.downloaded {
    opacity: 0.85;
}

.doc-card .download-status {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.65rem;
    margin-top: 0.25rem;
}

.doc-card .download-status.new {
    color: #0b3aa4;
    font-weight: 600;
}

.doc-card .download-status.downloaded {
    color: #0b3aa4;
}

.doc-card .download-status svg {
    width: 12px;
    height: 12px;
}

/* Filters */
.filters-card {
    background: white;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filters-card label {
    font-size: 0.75rem;
    color: #4a5568;
    font-weight: 500;
}

.filters-card select {
    padding: 0.4rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.8rem;
    background: white;
    min-width: 130px;
}

.filters-card .btn {
    padding: 0.4rem 0.85rem;
    font-size: 0.75rem;
}

.filter-tags {
    display: flex;
    gap: 0.4rem;
    margin-left: auto;
}

.filter-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: #eef3fb;
    color: #082b7b;
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.7rem;
}

.info-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #edf2f7;
    color: #4a5568;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    text-decoration: none;
    cursor: pointer;
    border: none;
    margin-left: auto;
    transition: all 0.2s;
}

.info-btn:hover {
    background: #e2e8f0;
}

.info-btn svg {
    width: 14px;
    height: 14px;
}

/* Empty State */
.empty-box {
    background: white;
    border-radius: 10px;
    padding: 3rem 1.5rem;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.empty-box svg {
    width: 50px;
    height: 50px;
    color: #cbd5e0;
    margin-bottom: 0.75rem;
}

.empty-box h3 {
    color: #4a5568;
    margin: 0 0 0.35rem;
    font-size: 1rem;
}

.empty-box p {
    color: #718096;
    margin: 0;
    font-size: 0.8rem;
}

/* Year Section */
.year-section {
    margin-bottom: 1.5rem;
}

.year-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.4rem;
    border-bottom: 2px solid #e2e8f0;
}

.year-header h2 {
    font-size: 0.95rem;
    margin: 0;
    color: #2d3748;
}

.year-header .count {
    background: #edf2f7;
    color: #4a5568;
    font-size: 0.65rem;
    padding: 0.2rem 0.5rem;
    border-radius: 8px;
}

/* Document Grid */
.docs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
    gap: 0.75rem;
}

.doc-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: transform 0.2s, box-shadow 0.2s;
}

.doc-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.doc-card-top {
    padding: 1rem;
    display: flex;
    gap: 0.75rem;
}

.doc-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.doc-card-icon svg {
    width: 20px;
    height: 20px;
}

.doc-card-icon.payslip { background: #eef3fb; color: #082b7b; }
.doc-card-icon.cud { background: #fefcbf; color: #975a16; }
.doc-card-icon.other { background: #e2e8f0; color: #4a5568; }
.doc-card-icon.personal { background: #d6e2f4; color: #082b7b; }

.doc-card-info {
    flex: 1;
    min-width: 0;
}

.doc-card-info .type-badge {
    display: inline-block;
    font-size: 0.55rem;
    padding: 0.15rem 0.4rem;
    border-radius: 3px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.type-badge.payslip { background: #eef3fb; color: #082b7b; }
.type-badge.cud { background: #fefcbf; color: #975a16; }
.type-badge.other { background: #e2e8f0; color: #4a5568; }
.type-badge.personal { background: #d6e2f4; color: #082b7b; }

.doc-card-info h3 {
    font-size: 0.85rem;
    margin: 0 0 0.2rem;
    color: #2d3748;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
}

.doc-card-info .period {
    font-size: 0.75rem;
    color: #718096;
}

.doc-card-bottom {
    padding: 0.6rem 1rem;
    background: #f7fafc;
    border-top: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.doc-card-bottom .meta {
    font-size: 0.65rem;
    color: #a0aec0;
}

.doc-card-bottom .dl-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #0b3aa4;
    color: white;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.75rem;
    font-weight: 500;
    transition: background 0.2s;
}

.doc-card-bottom .dl-btn:hover {
    background: #082b7b;
}

.doc-card-bottom .dl-btn svg {
    width: 14px;
    height: 14px;
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-overlay.active {
    display: flex;
}

.modal {
    background: white;
    border-radius: 12px;
    max-width: 500px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
    margin: 0;
    font-size: 1rem;
    color: #2d3748;
}

.modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.25rem;
    color: #718096;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    color: #2d3748;
}

.modal-body {
    padding: 1.25rem;
}

.info-item {
    padding: 0.75rem;
    background: #f7fafc;
    border-radius: 8px;
    margin-bottom: 0.75rem;
    border-left: 3px solid;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-item.payslip { border-color: #0b3aa4; }
.info-item.cud { border-color: #d97706; }
.info-item.other { border-color: #718096; }

/* Responsive */
@media (max-width: 480px) {
    .filters-card {
        flex-direction: column;
        align-items: stretch;
    }

    .filters-card select {
        width: 100%;
        min-width: auto;
    }

    .filter-tags {
        margin-left: 0;
        flex-wrap: wrap;
    }

    .info-btn {
        margin-left: 0;
        width: 100%;
        justify-content: center;
    }

    .doc-card-bottom {
        flex-direction: column;
        gap: 0.5rem;
        align-items: stretch;
    }

    .doc-card-bottom .dl-btn {
        justify-content: center;
    }
}

.info-item h4 {
    margin: 0 0 0.25rem;
    font-size: 0.85rem;
    color: #2d3748;
}

.info-item p {
    margin: 0;
    font-size: 0.75rem;
    color: #718096;
    line-height: 1.5;
}
</style>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($unreadCount > 0): ?>
    <div class="notification-banner">
        <div class="notif-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/>
            </svg>
        </div>
        <div class="notif-content">
            <div class="notif-title">Hai nuovi documenti!</div>
            <div class="notif-text">
                <?php if ($unreadCount === 1): ?>
                    C'è 1 documento che non hai ancora scaricato
                <?php else: ?>
                    Ci sono <?php echo $unreadCount; ?> documenti che non hai ancora scaricato
                <?php endif; ?>
            </div>
        </div>
        <div class="notif-badge"><?php echo $unreadCount; ?></div>
    </div>
<?php endif; ?>

<!-- Banner -->
<?php
$__docTotal = is_array($documents) ? count($documents) : 0;
$__unreadDocs = 0;
foreach ($documents as $__d) { if (empty($__d['is_downloaded'])) $__unreadDocs++; }
?>
<div class="emp-banner">
    <div>
        <h2>I tuoi documenti</h2>
        <p>
            <?php if ($__docTotal === 0): ?>
                Nessun documento disponibile.
            <?php else: ?>
                <strong><?= $__docTotal ?></strong> document<?= $__docTotal === 1 ? 'o' : 'i' ?> disponibili<?php if ($__unreadDocs > 0): ?> · <strong style="color:#d97706;"><?= $__unreadDocs ?> da scaricare</strong><?php endif; ?>.
            <?php endif; ?>
        </p>
    </div>
</div>

<style>
.emp-banner {
    background: white;
    border: 1px solid #e6e8f0;
    border-left: 4px solid #0b3aa4;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.emp-banner h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0 0 4px;
    font-size: 19px; font-weight: 700;
    color: #0b3aa4; letter-spacing: -0.02em;
}
.emp-banner p { margin: 0; font-size: 13px; color: #6e7191; }
</style>

<!-- Filtri minimal -->
<div class="docs-filters">
    <div class="docs-tabs">
        <?php
        $__tabs = [['','Tutti']];
        foreach (Document::TYPES as $k => $l) $__tabs[] = [$k, $l];
        foreach ($__tabs as [$key, $lbl]):
            $url = 'documents.php' . ($key !== '' ? '?type=' . urlencode($key) : '') . ($filterYear ? ($key !== '' ? '&' : '?') . 'year=' . (int)$filterYear : '');
        ?>
            <a href="<?= e($url) ?>" class="docs-tab <?= ($filterType ?? '') === $key ? 'active' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="docs-filters-right">
        <form method="GET" class="docs-year-form">
            <?php if (!empty($filterType)): ?>
                <input type="hidden" name="type" value="<?= e($filterType) ?>">
            <?php endif; ?>
            <select name="year" onchange="this.form.submit()">
                <option value="">Tutti gli anni</option>
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= $y['year'] ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>><?= $y['year'] ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <button type="button" class="docs-info-btn" onclick="openInfoModal()" title="Info documenti">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        </button>
    </div>
</div>

<style>
.docs-filters {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 10px 14px;
    margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; flex-wrap: wrap;
}
.docs-tabs {
    display: inline-flex; gap: 2px;
    background: #f1f5f9;
    border-radius: 999px;
    padding: 4px;
    flex-wrap: wrap;
}
.docs-tab {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    color: #6e7191; text-decoration: none;
    transition: all .12s ease;
    white-space: nowrap;
}
.docs-tab:hover { color: #0b3aa4; }
.docs-tab.active {
    background: white;
    color: #0b3aa4;
    box-shadow: 0 1px 3px rgba(15,23,42,0.08);
}
.docs-filters-right { display: inline-flex; align-items: center; gap: 8px; }
.docs-year-form select {
    padding: 7px 12px;
    border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 12px; font-weight: 600;
    color: #475569; background: white; cursor: pointer;
}
.docs-info-btn {
    width: 32px; height: 32px;
    border: 1px solid #e6e8f0; background: white;
    border-radius: 8px; cursor: pointer;
    color: #6e7191;
    display: inline-flex; align-items: center; justify-content: center;
}
.docs-info-btn:hover { border-color: #0b3aa4; color: #0b3aa4; }

/* ===== Nuovo grid documenti ===== */
.docs-month-h {
    display: flex; align-items: baseline; gap: 10px;
    margin: 20px 0 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e6e8f0;
}
.docs-month-h h2 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 14px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: #475569; margin: 0;
}
.docs-month-h .count {
    font-size: 11px; font-weight: 700;
    color: #94a3b8;
    background: #f1f5f9;
    padding: 2px 9px; border-radius: 999px;
}
.docs-grid-new {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
}
.docs-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    transition: all .12s ease;
    text-decoration: none;
    position: relative;
}
.docs-row:hover {
    border-color: #0b3aa4;
    box-shadow: 0 6px 18px rgba(11,58,164,0.08);
    transform: translateY(-1px);
}
.docs-row.is-new { border-left: 3px solid #0b3aa4; padding-left: 11px; }
.docs-row-ic {
    width: 38px; height: 38px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.docs-row-ic.payslip  { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.docs-row-ic.cud      { background: rgba(255,187,85,0.16); color: #b07023; }
.docs-row-ic.personal { background: rgba(17,186,186,0.12); color: #0c8a8a; }
.docs-row-ic.other    { background: rgba(100,116,139,0.10); color: #475569; }
.docs-row-ic svg { width: 18px; height: 18px; }
.docs-row-info { flex: 1; min-width: 0; }
.docs-row-info .t {
    font-size: 13px; font-weight: 600; color: #1e1e2f;
    margin: 0 0 3px;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical;
}
.docs-row-info .s {
    font-size: 11px; color: #94a3b8;
    display: flex; align-items: center; gap: 6px;
    flex-wrap: wrap;
}
.docs-row-info .s .new-pill {
    background: rgba(11,58,164,0.10);
    color: #0b3aa4;
    padding: 1px 7px; border-radius: 999px;
    font-size: 9px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.docs-row-dl {
    width: 36px; height: 36px;
    border-radius: 9px;
    background: rgba(11,58,164,0.08);
    color: #0b3aa4;
    border: 1px solid rgba(11,58,164,0.16);
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all .12s ease;
    flex-shrink: 0;
}
.docs-row-dl:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.docs-row-dl svg { width: 16px; height: 16px; }

@media (max-width: 640px) {
    .docs-filters { padding: 8px; flex-direction: column; align-items: stretch; }
    .docs-tabs {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr);
        gap: 4px;
        width: 100%;
        padding: 4px;
        border-radius: 10px;
    }
    .docs-tab { text-align: center; padding: 9px 8px; border-radius: 8px; }
    .docs-filters-right { width: 100%; }
    .docs-year-form { flex: 1; }
    .docs-year-form select { width: 100%; }
    .docs-grid-new { grid-template-columns: 1fr; }
}
</style>

<?php if (empty($documents)): ?>
    <div class="empty-box">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
        </svg>
        <h3>Nessun documento trovato</h3>
        <?php if ($filterYear || $filterType): ?>
            <p>Prova a rimuovere i filtri per vedere tutti i documenti</p>
        <?php else: ?>
            <p>I tuoi documenti appariranno qui quando verranno caricati</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($groupedDocs as $group): ?>
        <div class="docs-month-h">
            <h2><?= htmlspecialchars(getMonthName($group['month'])) ?> <?= (int)$group['year'] ?></h2>
            <span class="count"><?= count($group['documents']) ?></span>
        </div>
        <div class="docs-grid-new">
            <?php foreach ($group['documents'] as $doc):
                $isDownloaded = !empty($doc['is_downloaded']);
                $tLbl = Document::TYPES[$doc['type']] ?? $doc['type'];
            ?>
                <a href="<?= PUBLIC_URL ?>/api/download.php?id=<?= (int)$doc['id'] ?>" class="docs-row <?= !$isDownloaded ? 'is-new' : '' ?>">
                    <div class="docs-row-ic <?= e($doc['type']) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="docs-row-info">
                        <div class="t"><?= htmlspecialchars($doc['title']) ?></div>
                        <div class="s">
                            <?= htmlspecialchars($tLbl) ?> · <?= formatFileSize($doc['file_size']) ?> · <?= formatDate($doc['created_at']) ?>
                            <?php if (!$isDownloaded): ?><span class="new-pill">Nuovo</span><?php endif; ?>
                        </div>
                    </div>
                    <span class="docs-row-dl" title="Scarica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($personalDocs)): ?>
    <div class="docs-month-h">
        <h2>Documenti personali</h2>
        <span class="count"><?= count($personalDocs) ?></span>
    </div>
    <div class="docs-grid-new">
        <?php foreach ($personalDocs as $doc): ?>
            <a href="?download_personal=<?= (int)$doc['id'] ?>" class="docs-row">
                <div class="docs-row-ic personal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="docs-row-info">
                    <div class="t"><?= htmlspecialchars($doc['name']) ?></div>
                    <div class="s">
                        Personale · <?= formatFileSize($doc['file_size']) ?>
                        <?php if (!empty($doc['expires_on'])): ?>
                            · Scade <?= htmlspecialchars(date('d/m/Y', strtotime($doc['expires_on']))) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="docs-row-dl" title="Scarica">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal Info Documenti -->
<div class="modal-overlay" id="infoModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Informazioni sui Documenti</h3>
            <button class="modal-close" onclick="closeInfoModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="info-item payslip">
                <h4>Buste Paga</h4>
                <p>Le buste paga vengono caricate mensilmente dal commercialista. Si consiglia di conservarle per almeno 5 anni per eventuali verifiche fiscali o previdenziali.</p>
            </div>
            <div class="info-item cud">
                <h4>CUD / Certificazione Unica</h4>
                <p>La Certificazione Unica (CU) viene rilasciata annualmente ed e necessaria per la dichiarazione dei redditi. Contiene i dati relativi ai redditi percepiti e alle ritenute effettuate.</p>
            </div>
            <div class="info-item other">
                <h4>Altri Documenti</h4>
                <p>In questa categoria trovi contratti di lavoro, comunicazioni personali, certificati e altra documentazione varia relativa al tuo rapporto di lavoro.</p>
            </div>
        </div>
    </div>
</div>

<script>
function openInfoModal() {
    document.getElementById('infoModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeInfoModal() {
    document.getElementById('infoModal').classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('infoModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeInfoModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInfoModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
