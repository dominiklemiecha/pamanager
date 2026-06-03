<?php
/**
 * Caricamento Documenti - Consulente del lavoro
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$user = Auth::getUser();
$message = '';
$error = '';

/**
 * Caricamento massivo buste paga.
 * - bulk_analyze: riceve N file PDF, per ciascuno estrae CF + mensilita',
 *   prova il match con i dipendenti dell'azienda corrente, e ritorna JSON
 *   con le righe candidate (file salvati in storage/payslip_staging/{sess}/).
 * - bulk_commit: riceve gli ID staging + dipendente/mese/anno scelti
 *   (eventualmente corretti a mano) e crea i Document via Document::upload.
 */
function payslipStagingDir(): string {
    $dir = ROOT_PATH . '/storage/payslip_staging/' . substr(session_id() ?: 'anon', 0, 32);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'bulk_analyze' || ($_POST['action'] ?? '') === 'bulk_commit')) {
    CSRF::verifyOrDie();
    header('Content-Type: application/json');

    if (($_POST['action'] ?? '') === 'bulk_analyze') {
        $rows = [];
        $files = $_FILES['files'] ?? null;
        if (!$files || !is_array($files['name'])) {
            echo json_encode(['success' => false, 'error' => 'Nessun file ricevuto']);
            exit;
        }
        // Carica dipendenti dell'azienda corrente (per match CF)
        $emps = Employee::getAll(true);
        $byCf = [];
        foreach ($emps as $e) {
            if (!empty($e['fiscal_code'])) $byCf[strtoupper(trim($e['fiscal_code']))] = $e;
        }
        $staging = payslipStagingDir();

        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $origName = (string) $files['name'][$i];
            if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'pdf') {
                $rows[] = ['filename' => $origName, 'status' => 'error', 'error' => 'Non e un PDF'];
                continue;
            }
            $stageId = bin2hex(random_bytes(8));
            $stagePath = $staging . '/' . $stageId . '.pdf';
            if (!@move_uploaded_file($files['tmp_name'][$i], $stagePath)) {
                $rows[] = ['filename' => $origName, 'status' => 'error', 'error' => 'Salvataggio fallito'];
                continue;
            }
            try {
                $parsed = PayslipParser::parse($stagePath);
            } catch (Throwable $e) {
                $rows[] = ['filename' => $origName, 'staging_id' => $stageId, 'status' => 'error', 'error' => 'Parser: ' . $e->getMessage()];
                continue;
            }
            $cf = $parsed['cf'];
            $emp = $cf && isset($byCf[strtoupper($cf)]) ? $byCf[strtoupper($cf)] : null;
            $rows[] = [
                'filename'      => $origName,
                'staging_id'    => $stageId,
                'cf'            => $cf,
                'employee_id'   => $emp ? (int)$emp['id'] : null,
                'employee_name' => $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : null,
                'month'         => $parsed['period']['month'] ?? null,
                'year'          => $parsed['period']['year'] ?? null,
                'status'        => $emp && $parsed['period'] ? 'ready' : ($cf ? 'partial' : 'unmatched'),
            ];
        }
        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }

    if (($_POST['action'] ?? '') === 'bulk_commit') {
        $rowsRaw = $_POST['rows'] ?? '[]';
        $rowsIn = is_string($rowsRaw) ? json_decode($rowsRaw, true) : (is_array($rowsRaw) ? $rowsRaw : []);
        if (!is_array($rowsIn)) $rowsIn = [];
        $staging = payslipStagingDir();
        $created = 0; $errors = [];
        foreach ($rowsIn as $r) {
            $stageId = preg_replace('/[^a-f0-9]/', '', (string)($r['staging_id'] ?? ''));
            $empId   = (int)($r['employee_id'] ?? 0);
            $month   = (int)($r['month'] ?? 0);
            $year    = (int)($r['year'] ?? 0);
            $name    = (string)($r['filename'] ?? 'busta.pdf');
            $stagePath = $staging . '/' . $stageId . '.pdf';
            if ($stageId === '' || !is_file($stagePath) || $empId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
                $errors[] = ['filename' => $name, 'error' => 'Dati riga incompleti'];
                continue;
            }
            // Inietta nel formato atteso da Document::upload ($_FILES-like)
            $fakeFile = [
                'name'     => $name,
                'type'     => 'application/pdf',
                'tmp_name' => $stagePath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => @filesize($stagePath) ?: 0,
            ];
            // Document::upload usa move_uploaded_file; lo bypasso copiando via apposita variante.
            $res = Document::uploadFromPath($stagePath, [
                'employee_id' => $empId,
                'type'        => 'payslip',
                'title'       => "Busta paga " . sprintf('%02d/%d', $month, $year),
                'description' => '',
                'month'       => $month,
                'year'        => $year,
                'original_name' => $name,
            ]);
            if (!empty($res['success'])) {
                @unlink($stagePath);
                $created++;
            } else {
                $errors[] = ['filename' => $name, 'error' => $res['error'] ?? 'Errore upload'];
            }
        }
        echo json_encode(['success' => true, 'created' => $created, 'errors' => $errors]);
        exit;
    }
}

// Gestione upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        // Verifica file
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Seleziona un file da caricare';
        } else {
            // Parse period field (format: YYYY-MM)
            $period = $_POST['period'] ?? '';
            $month = 0;
            $year = 0;
            if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
            }

            $result = Document::upload($_FILES['document'], [
                'employee_id' => $_POST['employee_id'] ?? '',
                'type' => $_POST['type'] ?? '',
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'month' => $month,
                'year' => $year
            ]);

            if ($result['success']) {
                header('Location: documents.php?message=uploaded');
                exit;
            }
            $error = $result['error'];
        }
    } elseif ($action === 'delete') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        if ($docId) {
            $result = Document::delete($docId);
            if ($result['success']) {
                header('Location: documents.php?message=deleted');
                exit;
            }
            $error = $result['error'];
        }
    }
}

// Messaggi
if (isset($_GET['message'])) {
    $messages = [
        'uploaded' => 'Documento caricato con successo',
        'deleted' => 'Documento eliminato con successo'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Carica dipendenti per select
$employees = Employee::getAll(true);

// Filtri per lista documenti (vuoto = tutti)
$filterEmployee = !empty($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;
$filterType = !empty($_GET['type']) ? $_GET['type'] : null;

// Parse filter period (format: YYYY-MM)
$filterPeriod = $_GET['filter_period'] ?? '';
$filterYear = null;
$filterMonth = null;
if (preg_match('/^(\d{4})-(\d{2})$/', $filterPeriod, $matches)) {
    $filterYear = (int) $matches[1];
    $filterMonth = (int) $matches[2];
}

// Carica documenti
$documents = Document::getAll($filterYear, $filterMonth, $filterType);

// Se filtro dipendente
if ($filterEmployee) {
    $documents = array_filter($documents, fn($d) => $d['employee_id'] === $filterEmployee);
}

$pageTitle = 'Carica Documenti - Consulente del lavoro';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
/* Documents Page - Accountant */
.docs-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Upload Section */
.upload-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.upload-header {
    background: linear-gradient(180deg, #fafbff, white);
    padding: 16px 20px;
    color: #1e1e2f;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid #e6e8f0;
}

.upload-header h2 {
    margin: 0;
    font-family: 'Host Grotesk', sans-serif;
    font-size: 15px; font-weight: 700;
    color: #0b3aa4; letter-spacing: -0.01em;
}

.upload-header svg { color: #0b3aa4; }

.upload-form {
    padding: 1.25rem;
}

.upload-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.upload-grid .form-group {
    margin: 0;
}

.upload-grid .form-group.full {
    grid-column: 1 / -1;
}

.upload-grid label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
}

.upload-grid input,
.upload-grid select,
.upload-grid textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.upload-grid input:focus,
.upload-grid select:focus,
.upload-grid textarea:focus {
    outline: none;
    border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.upload-grid small {
    display: block;
    font-size: 0.7rem;
    color: #a0aec0;
    margin-top: 0.25rem;
}

.upload-actions {
    margin-top: 1.25rem;
    padding-top: 1rem;
    border-top: 1px solid #edf2f7;
}

.upload-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.upload-actions .btn svg {
    width: 18px;
    height: 18px;
}

/* Autocomplete */
.autocomplete-wrapper {
    position: relative;
}
.autocomplete-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.85rem;
}
.autocomplete-input:focus {
    outline: none;
    border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}
.autocomplete-input.has-value {
    background: #eef3fb;
    border-color: #0b3aa4;
}
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 6px 6px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.autocomplete-dropdown.show {
    display: block;
}
.autocomplete-item {
    padding: 0.6rem 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.autocomplete-item:last-child {
    border-bottom: none;
}
.autocomplete-item:hover,
.autocomplete-item.active {
    background: #eef3fb;
}
.autocomplete-item .name {
    font-weight: 600;
    color: #1a365d;
    font-size: 0.85rem;
}
.autocomplete-item .fiscal {
    font-size: 0.75rem;
    color: #718096;
    font-family: monospace;
}
.autocomplete-item .highlight {
    background: #fef08a;
    padding: 0 2px;
    border-radius: 2px;
}
.autocomplete-no-results {
    padding: 0.75rem;
    color: #a0aec0;
    text-align: center;
    font-size: 0.85rem;
}

/* Filters Section */
.filters-section {
    background: white;
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
}

.filter-item {
    flex: 1;
    min-width: 140px;
}

.filter-item label {
    display: block;
    font-size: 0.65rem;
    font-weight: 600;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
}

.filter-item select {
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.8rem;
    background: white;
}

.filter-buttons {
    display: flex;
    gap: 0.5rem;
}

.filter-buttons .btn {
    padding: 0.45rem 0.85rem;
    font-size: 0.8rem;
}

/* Documents List */
.docs-list-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.docs-list-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #edf2f7;
    background: #f7fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.docs-list-header h2 {
    margin: 0;
    font-size: 0.95rem;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.docs-list-header h2 svg {
    width: 18px;
    height: 18px;
    color: #718096;
}

.docs-count {
    font-size: 0.75rem;
    color: #718096;
    background: #edf2f7;
    padding: 0.25rem 0.6rem;
    border-radius: 10px;
}

/* Documents Grid */
.docs-grid {
    display: grid;
    gap: 0;
}

.doc-card {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f7fafc;
    transition: background 0.2s;
}

.doc-card:last-child {
    border-bottom: none;
}

.doc-card:hover {
    background: #f7fafc;
}

.doc-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.doc-icon svg {
    width: 20px;
    height: 20px;
}

.doc-icon.payslip { background: #eef3fb; color: #082b7b; }
.doc-icon.cud { background: #fefcbf; color: #975a16; }
.doc-icon.other { background: #e2e8f0; color: #4a5568; }

.doc-main {
    flex: 1;
    min-width: 0;
}

.doc-title {
    font-weight: 500;
    color: #2d3748;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.doc-meta {
    font-size: 0.7rem;
    color: #a0aec0;
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 0.15rem;
}

.doc-employee {
    font-size: 0.8rem;
    color: #4a5568;
    min-width: 140px;
    display: flex;
    flex-direction: column;
}

.doc-employee small {
    font-size: 0.65rem;
    color: #a0aec0;
}

.doc-size {
    font-size: 0.75rem;
    color: #a0aec0;
    min-width: 70px;
    text-align: right;
}

.doc-date {
    font-size: 0.75rem;
    color: #a0aec0;
    min-width: 90px;
    text-align: right;
}

.doc-actions {
    display: flex;
    gap: 0.35rem;
}

.doc-actions .btn-sm {
    padding: 0.35rem 0.6rem;
    font-size: 0.75rem;
}

.docs-empty {
    padding: 3rem;
    text-align: center;
    color: #a0aec0;
}

.docs-empty svg {
    width: 48px;
    height: 48px;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

/* Responsive */
@media (max-width: 768px) {
    .upload-grid {
        grid-template-columns: 1fr 1fr;
    }

    .filters-row {
        flex-direction: column;
    }

    .filter-item {
        width: 100%;
    }

    .filter-buttons {
        width: 100%;
    }

    .filter-buttons .btn {
        flex: 1;
    }

    .doc-card {
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .doc-main {
        flex: 1 1 calc(100% - 56px);
    }

    .doc-employee {
        width: 100%;
        order: 5;
        padding-top: 0.5rem;
        margin-top: 0.5rem;
        border-top: 1px dashed #edf2f7;
    }

    .doc-size,
    .doc-date {
        display: none;
    }

    .doc-actions {
        width: 100%;
        order: 6;
    }

    .doc-actions .btn-sm {
        flex: 1;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .upload-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="cl-banner">
    <div>
        <h2>Buste paga e CU</h2>
        <p>Carica i documenti per i dipendenti. <strong><?= count($documents) ?></strong> caricat<?= count($documents) === 1 ? 'o' : 'i' ?> nel filtro corrente.</p>
    </div>
</div>
<style>
.cl-banner {
    background: white;
    border: 1px solid #e6e8f0;
    border-left: 4px solid #0b3aa4;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.cl-banner h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0 0 4px;
    font-size: 19px; font-weight: 700;
    color: #0b3aa4; letter-spacing: -0.02em;
}
.cl-banner p { margin: 0; font-size: 13px; color: #6e7191; }
</style>

<div class="docs-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Upload Section -->
    <div class="upload-section">
        <div class="upload-header">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <h2>Carica nuovo documento</h2>
        </div>
        <div style="padding:18px 20px; display:flex; gap:12px; flex-wrap:wrap;">
            <button type="button" id="manualOpenBtn" style="flex:1; min-width:220px; display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:14px 18px; background:#fff; color:#0b3aa4; border:1px solid #0b3aa4; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px; transition:all .12s;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Carica manualmente
            </button>
            <button type="button" id="bulkOpenBtn" style="flex:1; min-width:220px; display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:14px 18px; background:#0b3aa4; color:#fff; border:1px solid #0b3aa4; border-radius:10px; font-weight:700; cursor:pointer; font-size:14px; transition:all .12s;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Caricamento massivo buste paga
            </button>
        </div>
    </div>

    <!-- Modale: Carica manualmente -->
    <div id="manualOverlay" style="position:fixed; inset:0; background:rgba(16,24,40,.55); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:14px; width:100%; max-width:680px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 48px rgba(16,24,40,.24); overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e4e7ec;">
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#101828;">Carica documento</h3>
                    <p style="margin:4px 0 0; font-size:12px; color:#667085;">Carica un singolo file per un dipendente specifico.</p>
                </div>
                <button type="button" id="manualCloseBtn" style="border:0; background:#f2f4f7; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:20px; color:#475467;">&times;</button>
            </div>
            <div style="padding:20px; overflow:auto;">
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="upload">

            <div class="upload-grid">
                <div class="form-group">
                    <label for="employee_search">Dipendente *</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="employee_search" class="autocomplete-input"
                               placeholder="Cerca per nome, cognome o codice fiscale..."
                               autocomplete="off" required>
                        <input type="hidden" id="employee_id" name="employee_id" value="<?= e($_POST['employee_id'] ?? '') ?>">
                        <div class="autocomplete-dropdown" id="employee_dropdown"></div>
                    </div>
                    <div id="employees_data" style="display:none;"><?= htmlspecialchars(json_encode(array_map(function($emp) {
                        return [
                            'id' => $emp['id'],
                            'name' => $emp['last_name'] . ' ' . $emp['first_name'],
                            'fiscal_code' => $emp['fiscal_code']
                        ];
                    }, $employees)), ENT_QUOTES) ?></div>
                </div>

                <div class="form-group">
                    <label for="type">Tipo Documento *</label>
                    <select id="type" name="type" required>
                        <option value="">-- Seleziona --</option>
                        <?php foreach (Document::TYPES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($_POST['type'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="period">Periodo *</label>
                    <?php
                        $defaultPeriod = ($_POST['year'] ?? date('Y')) . '-' . str_pad($_POST['month'] ?? date('n'), 2, '0', STR_PAD_LEFT);
                    ?>
                    <input type="month" id="period" name="period" required
                           value="<?= e($defaultPeriod) ?>"
                           min="<?= date('Y') - 5 ?>-01"
                           max="<?= date('Y') ?>-12">
                    <small>Seleziona mese e anno del documento</small>
                </div>

                <div class="form-group">
                    <label for="title">Titolo</label>
                    <input type="text" id="title" name="title" maxlength="255"
                           value="<?= e($_POST['title'] ?? '') ?>"
                           placeholder="Auto-generato se vuoto">
                </div>

                <div class="form-group">
                    <label for="document">File *</label>
                    <input type="file" id="document" name="document" required
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <small>PDF, JPG, PNG, DOC - Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB</small>
                </div>
            </div>

            <div class="upload-actions">
                <button type="submit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/>
                    </svg>
                    Carica Documento
                </button>
            </div>
        </form>
            </div>
        </div>
    </div>
    <!-- /Modale manuale -->

    <!-- Filtri tab type + dipendente/periodo -->
    <div class="cd-filters">
        <div class="cd-tabs">
            <?php
            $__qsBase = function ($override) use ($filterEmployee, $filterPeriod, $filterType) {
                $params = array_filter([
                    'employee_id' => $filterEmployee,
                    'filter_period' => $filterPeriod,
                    'type' => $filterType,
                ]);
                $params = array_merge($params, $override);
                $params = array_filter($params, fn($v) => $v !== null && $v !== '');
                return $params ? '?' . http_build_query($params) : 'documents.php';
            };
            ?>
            <a href="<?= e($__qsBase(['type' => null])) ?>" class="cd-tab <?= !$filterType ? 'active' : '' ?>">Tutti</a>
            <?php foreach (Document::TYPES as $key => $label): ?>
                <a href="<?= e($__qsBase(['type' => $key])) ?>" class="cd-tab <?= $filterType === $key ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="cd-filter-row">
            <?php if ($filterType): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif; ?>
            <select name="employee_id" onchange="this.form.submit()">
                <option value="">Tutti i dipendenti</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $filterEmployee == $emp['id'] ? 'selected' : '' ?>>
                        <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="month" name="filter_period" value="<?= $filterPeriod ? e($filterPeriod) : '' ?>"
                   min="<?= date('Y') - 5 ?>-01" max="<?= date('Y') ?>-12" onchange="this.form.submit()">
            <?php if ($filterEmployee || $filterPeriod || $filterType): ?>
                <a href="documents.php" class="cd-reset">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Lista documenti -->
    <?php if (empty($documents)): ?>
        <div class="cd-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="42" height="42"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>Nessun documento trovato.</p>
        </div>
    <?php else: ?>
        <div class="cd-list">
            <?php foreach ($documents as $doc):
                $tLbl = Document::TYPES[$doc['type']] ?? $doc['type'];
                $initials = strtoupper(substr($doc['first_name'], 0, 1) . substr($doc['last_name'], 0, 1));
            ?>
                <div class="cd-row">
                    <div class="cd-row-ic <?= e($doc['type']) ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="cd-row-main">
                        <div class="cd-row-title">
                            <span class="cd-row-tlb"><?= e($doc['title']) ?></span>
                            <span class="cd-type-pill cd-type-<?= e($doc['type']) ?>"><?= e($tLbl) ?></span>
                        </div>
                        <div class="cd-row-meta">
                            <span class="cd-emp">
                                <span class="cd-emp-av"><?= e($initials) ?></span>
                                <?= e($doc['last_name'] . ' ' . $doc['first_name']) ?>
                            </span>
                            <span class="sep">·</span>
                            <span><?= getMonthName($doc['month']) ?> <?= (int)$doc['year'] ?></span>
                            <span class="sep">·</span>
                            <span><?= formatFileSize($doc['file_size']) ?></span>
                            <span class="sep">·</span>
                            <span>Caricato <?= formatDate($doc['created_at']) ?></span>
                        </div>
                    </div>
                    <div class="cd-row-actions">
                        <a href="<?= PUBLIC_URL ?>/api/download.php?id=<?= $doc['id'] ?>" class="cd-ibtn primary" title="Scarica">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </a>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare definitivamente questo documento?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                            <button type="submit" class="cd-ibtn danger" title="Elimina">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.cd-filters {
    background: white; border: 1px solid #e6e8f0; border-radius: 12px;
    padding: 10px; margin-bottom: 14px;
    display: flex; flex-direction: column; gap: 10px;
}
.cd-tabs {
    display: flex; gap: 2px; background: #f1f5f9; border-radius: 10px;
    padding: 4px; flex-wrap: wrap;
}
.cd-tab {
    padding: 7px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
    color: #6e7191; text-decoration: none;
    white-space: nowrap; transition: all .12s ease;
}
.cd-tab:hover { color: #0b3aa4; }
.cd-tab.active { background: white; color: #0b3aa4; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }
.cd-filter-row {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
}
.cd-filter-row select, .cd-filter-row input[type=month] {
    padding: 8px 12px; border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 13px; background: white; min-width: 180px;
    color: #1e1e2f;
}
.cd-filter-row select:focus, .cd-filter-row input:focus {
    outline: none; border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}
.cd-reset {
    padding: 8px 14px; color: #f75c6c;
    font-size: 12px; font-weight: 600; text-decoration: none;
    border-radius: 8px;
}
.cd-reset:hover { background: rgba(247,92,108,0.08); }

.cd-empty {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    padding: 48px 18px; text-align: center; color: #94a3b8;
}
.cd-empty svg { color: #cbd5e0; margin-bottom: 10px; }
.cd-empty p { margin: 0; font-size: 13px; }

.cd-list { display: flex; flex-direction: column; gap: 8px; }
.cd-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 16px;
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    transition: all .12s ease;
}
.cd-row:hover { border-color: #0b3aa4; box-shadow: 0 4px 12px rgba(11,58,164,0.06); }
.cd-row-ic {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.cd-row-ic.payslip  { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.cd-row-ic.cud      { background: rgba(255,187,85,0.16); color: #b07023; }
.cd-row-ic.other    { background: rgba(100,116,139,0.10); color: #475569; }
.cd-row-ic svg { width: 18px; height: 18px; }
.cd-row-main { flex: 1; min-width: 0; }
.cd-row-title {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 4px;
}
.cd-row-tlb {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 14px; font-weight: 700; color: #1e1e2f;
    letter-spacing: -0.01em;
    overflow: hidden; text-overflow: ellipsis;
    max-width: 100%;
}
.cd-type-pill {
    padding: 2px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.cd-type-payslip { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.cd-type-cud     { background: rgba(255,187,85,0.14); color: #b07023; }
.cd-type-other   { background: #f1f5f9; color: #475569; }
.cd-row-meta {
    font-size: 12px; color: #6e7191;
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.cd-row-meta .sep { color: #cbd5e0; }
.cd-emp {
    display: inline-flex; align-items: center; gap: 6px;
    font-weight: 600; color: #1e1e2f;
}
.cd-emp-av {
    width: 22px; height: 22px; border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4, #082b7b);
    color: white;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700;
}
.cd-row-actions { display: flex; gap: 6px; flex-shrink: 0; }
.cd-row-actions form { margin: 0; }
.cd-ibtn {
    width: 32px; height: 32px; border-radius: 8px;
    border: 1px solid #e6e8f0; background: white;
    color: #475569; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all .12s ease; text-decoration: none;
}
.cd-ibtn:hover { border-color: #0b3aa4; color: #0b3aa4; }
.cd-ibtn svg { width: 14px; height: 14px; }
.cd-ibtn.primary { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.cd-ibtn.primary:hover { background: #0b3aa4; color: white; }
.cd-ibtn.danger { background: rgba(247,92,108,0.08); color: #cc2d39; border-color: rgba(247,92,108,0.20); }
.cd-ibtn.danger:hover { background: #f75c6c; color: white; border-color: #f75c6c; }

@media (max-width: 700px) {
    .cd-filter-row select, .cd-filter-row input[type=month] { width: 100%; min-width: 0; }
    .cd-row { flex-wrap: wrap; }
    .cd-row-main { flex-basis: 100%; }
    .cd-row-actions { width: 100%; justify-content: flex-end; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('employee_search');
    const hidden = document.getElementById('employee_id');
    const dropdown = document.getElementById('employee_dropdown');
    const dataEl = document.getElementById('employees_data');

    if (!input || !dataEl) return;

    const employees = JSON.parse(dataEl.textContent);
    let activeIndex = -1;

    // Se già selezionato, mostra il nome
    if (hidden.value) {
        const selected = employees.find(e => e.id == hidden.value);
        if (selected) {
            input.value = selected.name + ' - ' + selected.fiscal_code;
            input.classList.add('has-value');
        }
    }

    function highlightText(text, search) {
        if (!search) return text;
        const regex = new RegExp('(' + search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(regex, '<span class="highlight">$1</span>');
    }

    function showDropdown(results, search) {
        if (results.length === 0) {
            dropdown.innerHTML = '<div class="autocomplete-no-results">Nessun dipendente trovato</div>';
        } else {
            dropdown.innerHTML = results.map((emp, i) => `
                <div class="autocomplete-item ${i === activeIndex ? 'active' : ''}" data-id="${emp.id}" data-index="${i}">
                    <div class="name">${highlightText(emp.name, search)}</div>
                    <div class="fiscal">${highlightText(emp.fiscal_code, search)}</div>
                </div>
            `).join('');
        }
        dropdown.classList.add('show');
    }

    function hideDropdown() {
        dropdown.classList.remove('show');
        activeIndex = -1;
    }

    function selectEmployee(emp) {
        hidden.value = emp.id;
        input.value = emp.name + ' - ' + emp.fiscal_code;
        input.classList.add('has-value');
        hideDropdown();
    }

    input.addEventListener('input', function() {
        const search = this.value.toLowerCase().trim();
        hidden.value = '';
        input.classList.remove('has-value');
        activeIndex = -1;

        if (search.length < 1) {
            hideDropdown();
            return;
        }

        const results = employees.filter(emp => {
            const searchStr = (emp.name + ' ' + emp.fiscal_code).toLowerCase();
            return searchStr.includes(search);
        }).slice(0, 10);

        showDropdown(results, search);
    });

    input.addEventListener('keydown', function(e) {
        const items = dropdown.querySelectorAll('.autocomplete-item[data-id]');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            items.forEach((item, i) => item.classList.toggle('active', i === activeIndex));
            items[activeIndex]?.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            items.forEach((item, i) => item.classList.toggle('active', i === activeIndex));
            items[activeIndex]?.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && items[activeIndex]) {
                const id = items[activeIndex].dataset.id;
                const emp = employees.find(e => e.id == id);
                if (emp) selectEmployee(emp);
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    dropdown.addEventListener('click', function(e) {
        const item = e.target.closest('.autocomplete-item[data-id]');
        if (item) {
            const emp = employees.find(e => e.id == item.dataset.id);
            if (emp) selectEmployee(emp);
        }
    });

    input.addEventListener('focus', function() {
        if (this.value.length >= 1 && !hidden.value) {
            this.dispatchEvent(new Event('input'));
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-wrapper')) {
            hideDropdown();
        }
    });

    // Validazione form
    const form = input.closest('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hidden.value) {
                e.preventDefault();
                input.focus();
                input.style.borderColor = '#f75c6c';
                setTimeout(() => input.style.borderColor = '', 2000);
            }
        });
    }
});
</script>

<!-- ===== Caricamento massivo buste paga ===== -->
<div id="bulkOverlay" style="position:fixed; inset:0; background:rgba(16,24,40,.55); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:920px; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 20px 48px rgba(16,24,40,.24); overflow:hidden;">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e4e7ec;">
            <div>
                <h3 style="margin:0; font-size:16px; font-weight:700; color:#101828;">Caricamento massivo buste paga</h3>
                <p style="margin:4px 0 0; font-size:12px; color:#667085;">Trascina PDF: il sistema legge codice fiscale e mensilità e li assegna ai dipendenti.</p>
            </div>
            <button type="button" id="bulkCloseBtn" style="border:0; background:#f2f4f7; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:20px; color:#475467;">&times;</button>
        </div>

        <div id="bulkDrop" style="margin:20px; padding:36px; border:2px dashed #d0d5dd; border-radius:12px; background:#fafbfc; text-align:center; cursor:pointer;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#0b3aa4" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:10px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div style="font-size:14px; font-weight:600; color:#101828;">Trascina qui i PDF oppure <span style="color:#0b3aa4;">scegli file</span></div>
            <div style="font-size:12px; color:#667085; margin-top:4px;">Puoi selezionare più PDF contemporaneamente.</div>
            <input type="file" id="bulkInput" accept="application/pdf" multiple hidden>
        </div>

        <div id="bulkProgress" style="padding:0 20px;" hidden>
            <div style="font-size:13px; color:#475467; margin:8px 0 12px;">Analisi in corso...</div>
        </div>

        <div id="bulkTableWrap" style="flex:1; overflow:auto; padding:0 20px;" hidden>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb; text-align:left; color:#475467;">
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec;">File</th>
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec;">CF</th>
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec;">Dipendente</th>
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec;">Mese</th>
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec;">Anno</th>
                        <th style="padding:10px; border-bottom:1px solid #e4e7ec; text-align:center;">Stato</th>
                    </tr>
                </thead>
                <tbody id="bulkRows"></tbody>
            </table>
        </div>

        <div id="bulkActions" style="padding:14px 20px; border-top:1px solid #e4e7ec; display:flex; justify-content:space-between; align-items:center; gap:10px;" hidden>
            <div id="bulkSummary" style="font-size:12px; color:#475467;"></div>
            <div style="display:flex; gap:8px;">
                <button type="button" id="bulkResetBtn" style="padding:9px 16px; border:1px solid #d0d5dd; background:#fff; border-radius:8px; font-weight:600; cursor:pointer; color:#475467;">Annulla</button>
                <button type="button" id="bulkCommitBtn" style="padding:9px 18px; border:0; background:#0b3aa4; color:#fff; border-radius:8px; font-weight:700; cursor:pointer;">Conferma e carica</button>
            </div>
        </div>
    </div>
</div>

<script>
// Modale "Carica manualmente"
(function(){
    const overlay = document.getElementById('manualOverlay');
    const openBtn = document.getElementById('manualOpenBtn');
    const closeBtn = document.getElementById('manualCloseBtn');
    if (!overlay || !openBtn) return;
    const open = () => overlay.style.display='flex';
    const close = () => overlay.style.display='none';
    openBtn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && overlay.style.display==='flex') close(); });
    // Auto-apri se il server ha segnalato un errore di validazione sul form manuale
    <?php if (!empty($error)): ?>open();<?php endif; ?>
})();

(function(){
    const openBtn = document.getElementById('bulkOpenBtn');
    const closeBtn = document.getElementById('bulkCloseBtn');
    const overlay = document.getElementById('bulkOverlay');
    const drop = document.getElementById('bulkDrop');
    const input = document.getElementById('bulkInput');
    const progress = document.getElementById('bulkProgress');
    const tableWrap = document.getElementById('bulkTableWrap');
    const rowsEl = document.getElementById('bulkRows');
    const actions = document.getElementById('bulkActions');
    const summary = document.getElementById('bulkSummary');
    const resetBtn = document.getElementById('bulkResetBtn');
    const commitBtn = document.getElementById('bulkCommitBtn');

    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const empData = JSON.parse(document.getElementById('employees_data')?.textContent || '[]');
    const monthNames = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

    function openModal(){ overlay.style.display='flex'; resetUI(); }
    function closeModal(){ overlay.style.display='none'; }
    function resetUI(){ rowsEl.innerHTML=''; tableWrap.hidden=true; actions.hidden=true; progress.hidden=true; input.value=''; }

    openBtn?.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
    resetBtn.addEventListener('click', closeModal);
    drop.addEventListener('click', () => input.click());
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.style.background='#eef3fb'; });
    drop.addEventListener('dragleave', () => drop.style.background='#fafbfc');
    drop.addEventListener('drop', e => { e.preventDefault(); drop.style.background='#fafbfc'; handleFiles(e.dataTransfer.files); });
    input.addEventListener('change', () => handleFiles(input.files));

    async function handleFiles(files){
        if (!files || !files.length) return;
        progress.hidden = false; tableWrap.hidden = true; actions.hidden = true;
        const fd = new FormData();
        fd.append('action', 'bulk_analyze');
        fd.append('csrf_token', CSRF_TOKEN);
        for (const f of files) fd.append('files[]', f);
        try {
            const r = await fetch('documents.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (!j.success) throw new Error(j.error || 'Errore analisi');
            renderRows(j.rows || []);
        } catch (err) {
            progress.hidden = true;
            alert('Errore: ' + err.message);
        }
    }

    function renderRows(rows){
        progress.hidden = true;
        if (!rows.length) { tableWrap.hidden = true; actions.hidden = true; return; }
        rowsEl.innerHTML = '';
        rows.forEach((r,i) => rowsEl.appendChild(rowEl(r,i)));
        tableWrap.hidden = false; actions.hidden = false;
        updateSummary();
    }

    function rowEl(r, idx){
        const tr = document.createElement('tr');
        tr.dataset.stagingId = r.staging_id || '';
        tr.dataset.filename = r.filename || '';
        tr.style.borderBottom = '1px solid #f2f4f7';

        const tdFile = td(r.filename || '—'); tdFile.style.fontWeight = '600';
        const tdCf = td(r.cf || '—'); tdCf.style.color = r.cf ? '#101828' : '#dc2626';

        // Select dipendente
        const tdEmp = document.createElement('td'); tdEmp.style.padding = '8px';
        const sel = document.createElement('select');
        sel.style.cssText = 'width:100%; padding:6px 8px; border:1px solid #d0d5dd; border-radius:6px; font-size:12px;';
        sel.dataset.role = 'emp';
        const opt0 = new Option('— scegli —', '');
        sel.appendChild(opt0);
        empData.forEach(e => {
            const o = new Option(e.name + (e.fiscal_code ? ' · '+e.fiscal_code : ''), e.id);
            if (r.employee_id && e.id == r.employee_id) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener('change', updateSummary);
        tdEmp.appendChild(sel);

        // Mese
        const tdM = document.createElement('td'); tdM.style.padding = '8px';
        const selM = document.createElement('select');
        selM.style.cssText = 'width:100%; padding:6px 8px; border:1px solid #d0d5dd; border-radius:6px; font-size:12px;';
        selM.dataset.role = 'm';
        for (let m=1; m<=12; m++) {
            const o = new Option(monthNames[m], m);
            if (r.month == m) o.selected = true;
            selM.appendChild(o);
        }
        if (!r.month) selM.value = '';
        selM.addEventListener('change', updateSummary);
        tdM.appendChild(selM);

        // Anno
        const tdY = document.createElement('td'); tdY.style.padding = '8px';
        const inpY = document.createElement('input'); inpY.type = 'number'; inpY.min = '2000'; inpY.max = '2100';
        inpY.style.cssText = 'width:84px; padding:6px 8px; border:1px solid #d0d5dd; border-radius:6px; font-size:12px;';
        inpY.dataset.role = 'y'; inpY.value = r.year || '';
        inpY.addEventListener('input', updateSummary);
        tdY.appendChild(inpY);

        // Stato (badge popolato da updateSummary dopo che il tr e' nel DOM)
        const tdS = document.createElement('td'); tdS.style.padding='8px'; tdS.style.textAlign='center';
        tdS.dataset.role = 'st';

        tr.appendChild(tdFile); tr.appendChild(tdCf); tr.appendChild(tdEmp); tr.appendChild(tdM); tr.appendChild(tdY); tr.appendChild(tdS);
        return tr;
    }

    function td(txt){ const t = document.createElement('td'); t.style.padding='10px 8px'; t.textContent = txt; return t; }

    function setRowStatus(td, r){
        const tr = td.closest('tr');
        if (!tr) return;
        const emp = tr.querySelector('select[data-role=emp]')?.value || '';
        const m = tr.querySelector('select[data-role=m]')?.value || '';
        const y = tr.querySelector('input[data-role=y]')?.value || '';
        let label = '⚠ Da completare', color = '#b45309', bg = '#fef3c7';
        if (emp && m && y) { label = '✓ Pronto'; color='#15803d'; bg='#dcfce7'; }
        td.innerHTML = `<span style="display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; color:${color}; background:${bg};">${label}</span>`;
    }

    function updateSummary(){
        let ready=0, total=0;
        document.querySelectorAll('#bulkRows tr').forEach(tr => {
            total++;
            const emp = tr.querySelector('select[data-role=emp]').value;
            const m = tr.querySelector('select[data-role=m]').value;
            const y = tr.querySelector('input[data-role=y]').value;
            if (emp && m && y) ready++;
            const stTd = tr.querySelector('td[data-role=st]');
            if (stTd) setRowStatus(stTd, {});
        });
        summary.textContent = `${ready} di ${total} pronte al caricamento`;
        commitBtn.disabled = ready === 0;
        commitBtn.style.opacity = ready === 0 ? '0.5' : '1';
    }

    commitBtn.addEventListener('click', async () => {
        const rows = [];
        document.querySelectorAll('#bulkRows tr').forEach(tr => {
            const emp = tr.querySelector('select[data-role=emp]').value;
            const m = tr.querySelector('select[data-role=m]').value;
            const y = tr.querySelector('input[data-role=y]').value;
            if (!emp || !m || !y) return;
            rows.push({ staging_id: tr.dataset.stagingId, filename: tr.dataset.filename, employee_id: parseInt(emp), month: parseInt(m), year: parseInt(y) });
        });
        if (!rows.length) return;
        commitBtn.disabled = true; commitBtn.textContent = 'Caricamento...';
        const fd = new FormData();
        fd.append('action', 'bulk_commit');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('rows', JSON.stringify(rows));
        try {
            const r = await fetch('documents.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            const errLines = (j.errors||[]).map(e => `• ${e.filename}: ${e.error}`).join('\n');
            alert(`Caricate ${j.created} buste paga.` + (errLines ? '\n\nErrori:\n'+errLines : ''));
            window.location.href = 'documents.php?message=uploaded';
        } catch (err) {
            alert('Errore: '+err.message);
            commitBtn.disabled = false; commitBtn.textContent = 'Conferma e carica';
        }
    });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
