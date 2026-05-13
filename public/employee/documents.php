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
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
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
    color: #3182ce;
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
    color: #3182ce;
    font-weight: 600;
}

.doc-card .download-status.downloaded {
    color: #38a169;
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
    background: #ebf8ff;
    color: #2b6cb0;
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

.doc-card-icon.payslip { background: #c6f6d5; color: #276749; }
.doc-card-icon.cud { background: #fefcbf; color: #975a16; }
.doc-card-icon.other { background: #e2e8f0; color: #4a5568; }
.doc-card-icon.personal { background: #bee3f8; color: #2c5282; }

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

.type-badge.payslip { background: #c6f6d5; color: #276749; }
.type-badge.cud { background: #fefcbf; color: #975a16; }
.type-badge.other { background: #e2e8f0; color: #4a5568; }
.type-badge.personal { background: #bee3f8; color: #2c5282; }

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
    background: #3182ce;
    color: white;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.75rem;
    font-weight: 500;
    transition: background 0.2s;
}

.doc-card-bottom .dl-btn:hover {
    background: #2c5282;
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

.info-item.payslip { border-color: #38a169; }
.info-item.cud { border-color: #d69e2e; }
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

<!-- Filtri -->
<form method="GET" class="filters-card">
    <label>Filtra per:</label>
    <select name="year">
        <option value="">Tutti gli anni</option>
        <?php foreach ($availableYears as $y): ?>
            <option value="<?php echo $y['year']; ?>" <?php echo $filterYear == $y['year'] ? 'selected' : ''; ?>>
                <?php echo $y['year']; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="type">
        <option value="">Tutti i tipi</option>
        <?php foreach (Document::TYPES as $key => $label): ?>
            <option value="<?php echo $key; ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($label); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Filtra</button>

    <?php if ($filterYear || $filterType): ?>
        <div class="filter-tags">
            <?php if ($filterYear): ?>
                <span class="filter-tag">Anno: <?php echo $filterYear; ?></span>
            <?php endif; ?>
            <?php if ($filterType): ?>
                <span class="filter-tag">Tipo: <?php echo Document::TYPES[$filterType] ?? $filterType; ?></span>
            <?php endif; ?>
            <a href="documents.php" class="btn btn-link" style="padding:0;">Reset</a>
        </div>
    <?php endif; ?>

    <button type="button" class="info-btn" onclick="openInfoModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
        </svg>
        Info documenti
    </button>
</form>

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
        <div class="year-section">
            <div class="year-header">
                <h2><?php echo getMonthName($group['month']); ?> <?php echo $group['year']; ?></h2>
                <span class="count"><?php echo count($group['documents']); ?> doc</span>
            </div>
            <div class="docs-grid">
                <?php foreach ($group['documents'] as $doc):
                        $isDownloaded = !empty($doc['is_downloaded']);
                    ?>
                    <div class="doc-card <?php echo $isDownloaded ? 'downloaded' : 'unread'; ?>">
                        <div class="doc-card-top">
                            <div class="doc-card-icon <?php echo $doc['type']; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                                </svg>
                            </div>
                            <div class="doc-card-info">
                                <span class="type-badge <?php echo $doc['type']; ?>">
                                    <?php echo Document::TYPES[$doc['type']] ?? $doc['type']; ?>
                                </span>
                                <h3><?php echo htmlspecialchars($doc['title']); ?></h3>
                                <span class="period"><?php echo getMonthName($doc['month']); ?> <?php echo $doc['year']; ?></span>
                                <?php if ($isDownloaded): ?>
                                    <div class="download-status downloaded">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                        </svg>
                                        Scaricato <?php echo !empty($doc['last_download_at']) ? formatDate($doc['last_download_at']) : ''; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="download-status new">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                        </svg>
                                        Da scaricare
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="doc-card-bottom">
                            <span class="meta"><?php echo formatFileSize($doc['file_size']); ?> - <?php echo formatDate($doc['created_at']); ?></span>
                            <a href="<?= PUBLIC_URL ?>/api/download.php?id=<?php echo $doc['id']; ?>" class="dl-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                                </svg>
                                Scarica
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($personalDocs)): ?>
    <div class="year-section">
        <div class="year-header">
            <h2>Documenti personali</h2>
            <span class="count"><?php echo count($personalDocs); ?> doc</span>
        </div>
        <div class="docs-grid">
            <?php foreach ($personalDocs as $doc): ?>
                <div class="doc-card">
                    <div class="doc-card-top">
                        <div class="doc-card-icon personal">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                            </svg>
                        </div>
                        <div class="doc-card-info">
                            <span class="type-badge personal">Personale</span>
                            <h3><?php echo htmlspecialchars($doc['name']); ?></h3>
                            <?php if (!empty($doc['expires_on'])): ?>
                                <span class="period">Scade il <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['expires_on']))); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="doc-card-bottom">
                        <span class="meta"><?php echo formatFileSize($doc['file_size']); ?> · <?php echo formatDate($doc['created_at']); ?></span>
                        <a href="?download_personal=<?php echo (int) $doc['id']; ?>" class="dl-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                            </svg>
                            Scarica
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
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
