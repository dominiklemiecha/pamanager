<?php
/**
 * Richieste Ferie/Permessi - Dipendente
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$employeeId = $employee['id'];
$message = '';
$error = '';

// Download certificato malattia (GET, prima di output)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_cert'])) {
    $result = LeaveRequest::downloadCertificate((int) $_GET['download_cert']);
    if (!$result['success']) {
        http_response_code(403);
        exit(htmlspecialchars($result['error']));
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $result['filename']);
    if (function_exists('setDownloadHeaders')) {
        setDownloadHeaders($safeName, $result['mime'], filesize($result['file_path']));
    } else {
        header('Content-Type: ' . $result['mime']);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($result['file_path']));
    }
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $leaveType = $_POST['leave_type'] ?? '';
            $data = [
                'employee_id' => $employeeId,
                'leave_type' => $leaveType,
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_full_day' => isset($_POST['is_full_day']),
                'start_time' => $_POST['start_time'] ?? '',
                'end_time' => $_POST['end_time'] ?? '',
                'reason' => $_POST['reason'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'protocol_number' => $leaveType === 'malattia' ? ($_POST['protocol_number'] ?? '') : null,
            ];

            $result = LeaveRequest::create($data);

            if ($result['success']) {
                // Upload allegato generico (non malattia)
                if ($leaveType !== 'malattia' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    LeaveRequest::uploadAttachment($_FILES['attachment'], $result['id']);
                }
                // Upload certificato malattia (opzionale al primo submit)
                if ($leaveType === 'malattia' && isset($_FILES['certificate']) && $_FILES['certificate']['error'] !== UPLOAD_ERR_NO_FILE) {
                    LeaveRequest::saveSickDocs($result['id'], $employeeId, null, $_FILES['certificate']);
                }
                header('Location: leave-requests.php?message=created');
                exit;
            }
            $error = $result['error'];
            break;

        case 'add_sick_docs':
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $protocol  = $_POST['protocol_number'] ?? null;
            $certFile  = $_FILES['certificate'] ?? null;
            $r = LeaveRequest::saveSickDocs($requestId, $employeeId, $protocol, $certFile);
            if ($r['success']) {
                header('Location: leave-requests.php?message=docs_saved');
                exit;
            }
            $error = $r['error'];
            break;

        case 'cancel':
            $requestId = (int) ($_POST['request_id'] ?? 0);
            if ($requestId) {
                $result = LeaveRequest::cancel($requestId, $employeeId);
                if ($result['success']) {
                    header('Location: leave-requests.php?message=cancelled');
                    exit;
                }
                $error = $result['error'];
            }
            break;
    }
}

// Messaggi
if (isset($_GET['message'])) {
    $messages = [
        'created'    => 'Richiesta inviata con successo',
        'cancelled'  => 'Richiesta annullata',
        'docs_saved' => 'Documenti malattia aggiornati'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Filtri
$filterYear = !empty($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$filterStatus = !empty($_GET['status']) ? $_GET['status'] : null;

// Carica richieste
$requests = LeaveRequest::getByEmployee($employeeId, $filterStatus, $filterYear);

$pageTitle = 'Ferie e Permessi';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<style>
.leave-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Form Section */
.form-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.form-header {
    background: linear-gradient(135deg, #0b3aa4 0%, #082b7b 100%);
    padding: 1rem 1.25rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-header h2 {
    margin: 0;
    font-size: 1rem;
    color: white;
}

.form-header svg {
    width: 20px;
    height: 20px;
}

.form-body {
    padding: 1.25rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.form-grid .form-group {
    margin: 0;
}

.form-grid .form-group.full {
    grid-column: 1 / -1;
}

.form-grid label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
}

.form-grid input,
.form-grid select,
.form-grid textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.85rem;
}

.form-grid input:focus,
.form-grid select:focus,
.form-grid textarea:focus {
    outline: none;
    border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}

.form-actions {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #edf2f7;
}

.time-group {
    display: none;
}

.time-group.show {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Checkbox style */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.85rem;
    color: #4a5568;
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}

/* Filters */
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
    min-width: 120px;
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
}

.filter-buttons .btn {
    padding: 0.45rem 0.85rem;
    font-size: 0.8rem;
}

/* Requests List */
.requests-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.requests-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.requests-header h2 {
    margin: 0;
    font-size: 1rem;
    color: #2d3748;
}

.requests-count {
    font-size: 0.75rem;
    color: #718096;
    background: #edf2f7;
    padding: 0.25rem 0.6rem;
    border-radius: 10px;
}

.requests-list {
    padding: 0;
}

.request-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #f7fafc;
}

.request-item:last-child {
    border-bottom: none;
}

.request-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.request-icon svg {
    width: 24px;
    height: 24px;
    color: white;
}

.request-icon.ferie { background: linear-gradient(135deg, #48bb78 0%, #0b3aa4 100%); }
.request-icon.permesso { background: linear-gradient(135deg, #4299e1 0%, #0b3aa4 100%); }
.request-icon.malattia { background: linear-gradient(135deg, #fc8181 0%, #f56565 100%); }
.request-icon.permesso_104 { background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%); }
.request-icon.congedo_parentale { background: linear-gradient(135deg, #ed64a6 0%, #d53f8c 100%); }
.request-icon.congedo_separazione { background: linear-gradient(135deg, #a0aec0 0%, #4a5568 100%); }
.request-icon.congedo_mestruale { background: linear-gradient(135deg, #fbb6ce 0%, #d53f8c 100%); }
.request-icon.altro { background: linear-gradient(135deg, #a0aec0 0%, #718096 100%); }

.request-info {
    flex: 1;
    min-width: 0;
}

.request-type {
    font-weight: 600;
    color: #2d3748;
    font-size: 0.95rem;
}

.request-dates {
    font-size: 0.8rem;
    color: #718096;
    margin-top: 0.15rem;
}

.request-reason {
    font-size: 0.75rem;
    color: #a0aec0;
    margin-top: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.request-status {
    padding: 0.35rem 0.75rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
    min-width: 80px;
}

.request-status.pending { background: #fef3cd; color: #856404; }
.request-status.approved { background: #d4edda; color: #155724; }
.request-status.rejected { background: #f8d7da; color: #721c24; }
.request-status.cancelled { background: #e2e8f0; color: #4a5568; }

.request-actions {
    display: flex;
    gap: 0.5rem;
}

.request-actions .btn-sm {
    padding: 0.35rem 0.6rem;
    font-size: 0.75rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #a0aec0;
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .time-group.show {
        grid-template-columns: 1fr;
    }

    .request-item {
        flex-wrap: wrap;
    }

    .request-info {
        flex: 1 1 calc(100% - 60px);
    }

    .request-status {
        order: 3;
        margin-top: 0.5rem;
    }

    .request-actions {
        order: 4;
        width: 100%;
        margin-top: 0.5rem;
    }

    .request-actions .btn-sm {
        flex: 1;
    }
}
</style>

<?php
$__lrTotal = is_array($requests) ? count($requests) : 0;
$__lrPending = 0; $__lrApproved = 0;
foreach ($requests as $__r) {
    if ($__r['status'] === 'pending') $__lrPending++;
    elseif ($__r['status'] === 'approved') $__lrApproved++;
}
?>
<div class="emp-banner">
    <div>
        <h2>Ferie e permessi</h2>
        <p>
            Gestisci le tue richieste di ferie, permessi e malattia.
            <?php if ($__lrPending > 0): ?>
                <strong style="color:#d97706;"><?= $__lrPending ?> in attesa</strong> di approvazione.
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

<div class="leave-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php
    // Banner alert: malattie del dipendente >24h senza protocollo o certificato
    try {
        $__sickLate = LeaveRequest::sickPendingDocs(24, $employeeId);
    } catch (Throwable $e) { $__sickLate = []; }
    if (!empty($__sickLate)):
    ?>
    <div class="lr-top-alert">
        <div class="lr-top-alert-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="lr-top-alert-body">
            <strong>Hai <?= count($__sickLate) ?> richiest<?= count($__sickLate) === 1 ? 'a' : 'e' ?> di malattia senza documenti</strong>
            <div>Carica numero protocollo INPS e certificato medico dalla lista qui sotto.</div>
        </div>
    </div>
    <style>
    .lr-top-alert {
        display: flex; align-items: center; gap: 12px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 14px;
    }
    .lr-top-alert-ic {
        width: 36px; height: 36px; border-radius: 9px;
        background: rgba(220,38,38,0.10); color: #b91c1c;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .lr-top-alert-body strong { color: #991b1b; font-size: 13.5px; }
    .lr-top-alert-body div { color: #7f1d1d; font-size: 12px; margin-top: 2px; }
    </style>
    <?php endif; ?>

    <!-- Form Nuova Richiesta -->
    <?php
    $__presetType = $_GET['type'] ?? '';
    // Quick type chips (limitati ai più comuni)
    $__quickTypes = [
        'ferie'        => ['label' => 'Ferie',     'days_default' => true],
        'permesso'     => ['label' => 'Permesso',  'days_default' => false],
        'malattia'     => ['label' => 'Malattia',  'days_default' => true],
        'permesso_104' => ['label' => 'L.104',     'days_default' => false],
        'altro'        => ['label' => 'Altro',     'days_default' => true],
    ];
    ?>
    <div class="lr-form-card">
        <div class="lr-form-h">
            <h2>Nuova richiesta</h2>
            <p>Seleziona il tipo, scegli il periodo e invia.</p>
        </div>
        <form method="POST" enctype="multipart/form-data" id="lrForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" id="leave_type" name="leave_type" value="<?= e($__presetType) ?>" required>

            <!-- Type chips -->
            <div class="lr-fg">
                <label class="lr-lbl">Tipo di richiesta <span class="req">*</span></label>
                <div class="lr-type-chips">
                    <?php foreach ($__quickTypes as $key => $cfg): ?>
                        <button type="button" class="lr-chip <?= $__presetType === $key ? 'active' : '' ?>"
                                data-type="<?= $key ?>" data-full="<?= $cfg['days_default'] ? '1' : '0' ?>">
                            <?= e($cfg['label']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick date presets -->
            <div class="lr-fg">
                <label class="lr-lbl">Periodo <span class="req">*</span></label>
                <div class="lr-date-presets">
                    <button type="button" class="lr-preset" data-preset="today">Oggi</button>
                    <button type="button" class="lr-preset" data-preset="tomorrow">Domani</button>
                    <button type="button" class="lr-preset" data-preset="rest-of-week">Resto settimana</button>
                    <button type="button" class="lr-preset" data-preset="next-week">Settimana prossima</button>
                </div>
                <div class="lr-grid-2">
                    <div>
                        <label for="start_date" class="lr-sub-lbl">Data inizio</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div>
                        <label for="end_date" class="lr-sub-lbl">Data fine</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                </div>
            </div>

            <!-- Durata -->
            <div class="lr-fg">
                <label class="lr-lbl">Durata</label>
                <div class="lr-duration-toggle">
                    <label class="lr-dur-opt">
                        <input type="radio" name="is_full_day" value="1" checked>
                        <span>Giornata intera</span>
                    </label>
                    <label class="lr-dur-opt">
                        <input type="radio" name="is_full_day" value="0">
                        <span>Solo alcune ore</span>
                    </label>
                </div>
                <div class="lr-grid-2 lr-time-fields" id="lrTimeFields" hidden>
                    <div>
                        <label for="start_time" class="lr-sub-lbl">Dalle ore</label>
                        <input type="time" id="start_time" name="start_time" value="09:00">
                    </div>
                    <div>
                        <label for="end_time" class="lr-sub-lbl">Alle ore</label>
                        <input type="time" id="end_time" name="end_time" value="13:00">
                    </div>
                </div>
            </div>

            <!-- Motivazione -->
            <div class="lr-fg">
                <label for="reason" class="lr-lbl">Motivazione <span class="req">*</span></label>
                <textarea id="reason" name="reason" rows="2" required
                          placeholder="Es: Vacanza con la famiglia, visita medica…"></textarea>
            </div>

            <!-- Sezione documenti malattia (visibile solo se leave_type=malattia) -->
            <div class="lr-sick-docs" id="lrSickDocs" hidden>
                <div class="lr-sick-docs-h">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M9 12h6M12 9v6"/><circle cx="12" cy="12" r="10"/></svg>
                    <div>
                        <strong>Documenti malattia</strong>
                        <div class="lr-sick-docs-sub">Inserisci il numero di protocollo INPS e carica il certificato. Se non li hai ancora, puoi aggiungerli dopo dalla lista delle tue richieste.</div>
                    </div>
                </div>
                <div class="lr-grid-2">
                    <div>
                        <label for="protocol_number" class="lr-sub-lbl">Numero di protocollo</label>
                        <input type="text" id="protocol_number" name="protocol_number" placeholder="Es. 1234567890" maxlength="100">
                    </div>
                    <div>
                        <label for="certificate" class="lr-sub-lbl">Certificato medico</label>
                        <input type="file" id="certificate" name="certificate" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
            </div>

            <details class="lr-more">
                <summary>Aggiungi note o allegato</summary>
                <div class="lr-more-body">
                    <div class="lr-fg">
                        <label for="notes" class="lr-lbl">Note aggiuntive</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Dettagli per HR..."></textarea>
                    </div>
                    <div class="lr-fg" id="lrAttachWrap">
                        <label for="attachment" class="lr-lbl">Allegato (opzionale)</label>
                        <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small>PDF, JPG, PNG, DOC · max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB</small>
                    </div>
                </div>
            </details>

            <div class="lr-form-actions">
                <button type="submit" class="btn btn-primary lr-submit-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Invia richiesta
                </button>
            </div>
        </form>
    </div>

    <style>
    .lr-form-card {
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 14px;
        padding: 22px 24px;
        margin-bottom: 16px;
    }
    .lr-form-h { margin-bottom: 18px; }
    .lr-form-h h2 {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 18px; font-weight: 700;
        color: #0b3aa4; margin: 0 0 4px;
        letter-spacing: -0.02em;
    }
    .lr-form-h p { margin: 0; color: #6e7191; font-size: 13px; }
    .lr-fg { margin-bottom: 18px; }
    .lr-fg:last-of-type { margin-bottom: 0; }
    .lr-lbl {
        display: block;
        font-size: 11px; font-weight: 600; color: #475569;
        text-transform: uppercase; letter-spacing: 0.04em;
        margin-bottom: 8px;
    }
    .lr-lbl .req { color: #f75c6c; }
    .lr-sub-lbl {
        display: block;
        font-size: 10px; font-weight: 600; color: #94a3b8;
        text-transform: uppercase; letter-spacing: 0.04em;
        margin-bottom: 4px;
    }
    .lr-grid-2 {
        display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    }
    .lr-fg input[type=date], .lr-fg input[type=time], .lr-fg textarea, .lr-fg input[type=file] {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e6e8f0; border-radius: 8px;
        font-family: inherit; font-size: 14px;
        background: white;
        transition: all .12s ease;
    }
    .lr-fg input:focus, .lr-fg textarea:focus {
        outline: none; border-color: #0b3aa4;
        box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
    }
    .lr-fg small { font-size: 11px; color: #94a3b8; margin-top: 4px; display: block; }

    /* Type chips */
    .lr-type-chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .lr-chip {
        padding: 8px 16px;
        border: 1px solid #e6e8f0;
        border-radius: 999px;
        background: white;
        font-family: inherit; font-size: 13px; font-weight: 600;
        color: #6e7191;
        cursor: pointer;
        transition: all .12s ease;
    }
    .lr-chip:hover { border-color: #0b3aa4; color: #0b3aa4; }
    .lr-chip.active {
        background: #0b3aa4; color: white; border-color: #0b3aa4;
    }

    /* Date presets */
    .lr-date-presets {
        display: flex; gap: 6px; flex-wrap: wrap;
        margin-bottom: 10px;
    }
    .lr-preset {
        padding: 6px 12px;
        border: 1px solid #e6e8f0;
        border-radius: 8px;
        background: #fafbfd;
        font-family: inherit; font-size: 12px; font-weight: 600;
        color: #475569;
        cursor: pointer; transition: all .12s ease;
    }
    .lr-preset:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
    .lr-preset.active { background: rgba(11,58,164,0.10); border-color: #0b3aa4; color: #0b3aa4; }

    /* Duration toggle */
    .lr-duration-toggle { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .lr-dur-opt {
        flex: 1; min-width: 140px;
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 14px;
        border: 1px solid #e6e8f0; border-radius: 10px;
        background: #fafbfd;
        cursor: pointer; transition: all .12s ease;
        font-size: 13px;
    }
    .lr-dur-opt input { accent-color: #0b3aa4; }
    .lr-dur-opt:has(input:checked) {
        background: rgba(11,58,164,0.06);
        border-color: #0b3aa4;
        color: #0b3aa4; font-weight: 600;
    }
    .lr-time-fields[hidden] { display: none !important; }
    .lr-time-fields { display: grid; }

    /* More details */
    .lr-more {
        margin: 10px 0 6px;
        border-top: 1px solid #f1f5f9;
        padding-top: 14px;
    }
    .lr-more summary {
        cursor: pointer;
        font-size: 13px; font-weight: 600;
        color: #0b3aa4;
        padding: 4px 0;
        list-style: none;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .lr-more summary::-webkit-details-marker { display: none; }
    .lr-more summary::before {
        content: "+"; font-size: 18px; line-height: 1;
        width: 18px; height: 18px; border-radius: 50%;
        background: rgba(11,58,164,0.10); color: #0b3aa4;
        display: inline-flex; align-items: center; justify-content: center;
        transition: transform .12s ease;
    }
    .lr-more[open] summary::before { content: "−"; }
    .lr-more-body { padding-top: 12px; }

    /* Sick docs section */
    .lr-sick-docs {
        margin-bottom: 18px;
        padding: 14px 16px;
        background: linear-gradient(180deg, #fff5f5, #ffffff);
        border: 1px solid #fecaca;
        border-radius: 12px;
    }
    .lr-sick-docs[hidden] { display: none !important; }
    .lr-sick-docs-h {
        display: flex; gap: 10px; align-items: flex-start;
        margin-bottom: 12px;
        color: #991b1b;
    }
    .lr-sick-docs-h svg { color: #dc2626; margin-top: 2px; flex-shrink: 0; }
    .lr-sick-docs-h strong { font-size: 13px; }
    .lr-sick-docs-sub { font-size: 12px; color: #7f1d1d; margin-top: 2px; line-height: 1.4; }
    .lr-sick-docs input[type=text],
    .lr-sick-docs input[type=file] {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid #fecaca; border-radius: 8px;
        font-family: inherit; font-size: 13px;
        background: white;
    }
    .lr-sick-docs input[type=text]:focus {
        outline: none; border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220,38,38,0.10);
    }

    .lr-form-actions {
        display: flex; justify-content: flex-end;
        border-top: 1px solid #f1f5f9;
        padding-top: 16px; margin-top: 16px;
    }
    .lr-submit-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 11px 22px;
        background: #0b3aa4; color: white;
        border: none; border-radius: 10px;
        font-size: 14px; font-weight: 600;
        cursor: pointer; transition: all .12s ease;
    }
    .lr-submit-btn:hover { background: #082b7b; }

    @media (max-width: 640px) {
        .lr-form-card { padding: 18px; }
        .lr-grid-2 { grid-template-columns: 1fr; }
        .lr-duration-toggle { flex-direction: column; }
        .lr-dur-opt { width: 100%; }
    }
    </style>

    <script>
    (function() {
        const chips = document.querySelectorAll('.lr-chip');
        const hiddenType = document.getElementById('leave_type');
        const presets = document.querySelectorAll('.lr-preset');
        const startD = document.getElementById('start_date');
        const endD   = document.getElementById('end_date');
        const radios = document.querySelectorAll('input[name="is_full_day"]');
        const tFields = document.getElementById('lrTimeFields');

        const sickBox = document.getElementById('lrSickDocs');
        const attachWrap = document.getElementById('lrAttachWrap');
        const toggleSick = (type) => {
            const isSick = type === 'malattia';
            if (sickBox) sickBox.hidden = !isSick;
            // Quando malattia, l'allegato generico viene nascosto (sostituito dal certificato)
            if (attachWrap) attachWrap.style.display = isSick ? 'none' : '';
        };

        // Chip type select
        chips.forEach(c => c.addEventListener('click', () => {
            chips.forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            hiddenType.value = c.dataset.type;
            toggleSick(c.dataset.type);
            // Permessi/L.104 default to "alcune ore"
            if (c.dataset.full === '0') {
                document.querySelector('input[name="is_full_day"][value="0"]').checked = true;
                tFields.hidden = false;
            } else {
                document.querySelector('input[name="is_full_day"][value="1"]').checked = true;
                tFields.hidden = true;
            }
        }));
        // Init malattia view se preset
        toggleSick(hiddenType.value);

        // Duration toggle
        radios.forEach(r => r.addEventListener('change', () => {
            tFields.hidden = (document.querySelector('input[name="is_full_day"]:checked').value === '1');
        }));

        // Date presets — usa data LOCALE (evita shift UTC che torna giorno precedente)
        const fmt = d => {
            const y  = d.getFullYear();
            const m  = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${dd}`;
        };
        presets.forEach(p => p.addEventListener('click', () => {
            presets.forEach(x => x.classList.remove('active'));
            p.classList.add('active');
            const today = new Date(); today.setHours(0,0,0,0);
            let s = new Date(today), e = new Date(today);
            switch (p.dataset.preset) {
                case 'today': break;
                case 'tomorrow':
                    s.setDate(s.getDate()+1); e.setDate(e.getDate()+1); break;
                case 'rest-of-week': {
                    // start oggi, end venerdì
                    const dow = today.getDay() || 7; // 1=lun..7=dom
                    const daysToFri = Math.max(0, 5 - dow);
                    e.setDate(e.getDate() + daysToFri);
                    break;
                }
                case 'next-week': {
                    const dow = today.getDay() || 7;
                    s.setDate(s.getDate() + (8 - dow));
                    e = new Date(s); e.setDate(e.getDate() + 4);
                    break;
                }
            }
            startD.value = fmt(s);
            endD.value   = fmt(e);
        }));

        // Auto-sync end_date when start_date changes (if end before start)
        startD.addEventListener('change', () => {
            if (!endD.value || endD.value < startD.value) endD.value = startD.value;
        });

        // Init preset if type already selected
        const activeChip = document.querySelector('.lr-chip.active');
        if (activeChip && activeChip.dataset.full === '0') {
            document.querySelector('input[name="is_full_day"][value="0"]').checked = true;
            tFields.hidden = false;
        }

    })();

    // Toggle form inline upload documenti malattia (delegato a document perche'
    // le righe vengono renderizzate piu' giu' nel DOM)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.lr-sick-toggle');
        if (!btn) return;
        const form = document.getElementById(btn.dataset.target);
        if (form) form.hidden = !form.hidden;
    });
    </script>

    <!-- Sezione Le mie richieste -->
    <div class="lr-list-card">
        <div class="lr-list-h">
            <h2>Le mie richieste</h2>
            <span class="lr-count"><?= count($requests) ?></span>
        </div>

        <!-- Tab filtri stato + anno -->
        <div class="lr-filters">
            <?php
            $__statusTabs = [
                ''         => 'Tutte',
                'pending'  => 'In attesa',
                'approved' => 'Approvate',
                'rejected' => 'Rifiutate',
            ];
            ?>
            <div class="lr-tabs">
                <?php foreach ($__statusTabs as $key => $label):
                    $url = '?year=' . $filterYear . ($key !== '' ? '&status=' . $key : '');
                ?>
                    <a href="<?= e($url) ?>" class="lr-tab <?= ($filterStatus ?? '') === $key ? 'active' : '' ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="lr-year-select">
                <input type="hidden" name="status" value="<?= e($filterStatus ?? '') ?>">
                <select name="year" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>

        <?php if (empty($requests)): ?>
            <div class="lr-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="42" height="42"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <p>Nessuna richiesta trovata.</p>
            </div>
        <?php else: ?>
            <div class="lr-list">
                <?php foreach ($requests as $req):
                    $statusCfg = [
                        'pending'  => ['#fff3df', '#b07023', 'In attesa'],
                        'approved' => ['#d6e2f4', '#0b3aa4', 'Approvata'],
                        'rejected' => ['#fde2e5', '#cc2d39', 'Rifiutata'],
                        'cancelled'=> ['#f1f5f9', '#64748b', 'Annullata'],
                    ];
                    $sc = $statusCfg[$req['status']] ?? ['#f1f5f9','#64748b',$req['status']];
                    $datesStr = formatDate($req['start_date']);
                    if ($req['start_date'] !== $req['end_date']) $datesStr .= ' → ' . formatDate($req['end_date']);
                    if (!$req['is_full_day'] && !empty($req['start_time'])) {
                        $datesStr .= ' · ' . substr($req['start_time'],0,5) . '–' . substr($req['end_time'],0,5);
                    }
                ?>
                    <?php
                    $__isSick = $req['leave_type'] === 'malattia';
                    $__hasProto = !empty($req['protocol_number']);
                    $__hasCert  = !empty($req['certificate_path']);
                    $__waived   = !empty($req['certificate_waived']);
                    $__needsDocs = $__isSick && (!$__hasProto || (!$__hasCert && !$__waived)) && in_array($req['status'], ['pending','approved'], true);
                    $__hoursOld = !empty($req['created_at']) ? (int) ((time() - strtotime($req['created_at'])) / 3600) : 0;
                    ?>
                    <div class="lr-row">
                        <div class="lr-row-main">
                            <div class="lr-row-head">
                                <span class="lr-row-type"><?= e(LeaveRequest::LEAVE_TYPES[$req['leave_type']] ?? $req['leave_type']) ?></span>
                                <span class="lr-row-status" style="background: <?= $sc[0] ?>; color: <?= $sc[1] ?>;"><?= $sc[2] ?></span>
                                <?php if ($__isSick && $__hasProto): ?>
                                    <span class="lr-row-meta">Prot. <?= e($req['protocol_number']) ?></span>
                                <?php endif; ?>
                                <?php if ($__isSick && $__hasCert): ?>
                                    <a class="lr-row-meta lr-row-link" href="?download_cert=<?= (int)$req['id'] ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        Certificato
                                    </a>
                                <?php endif; ?>
                                <?php if ($__isSick && $__waived): ?>
                                    <span class="lr-row-meta" style="background:#e0e7ff; color:#1e3a8a;" title="Admin ha segnato il certificato come non richiesto">Cert. non richiesto</span>
                                <?php endif; ?>
                            </div>
                            <div class="lr-row-dates"><?= e($datesStr) ?></div>
                            <?php if (!empty($req['reason'])): ?>
                                <div class="lr-row-reason"><?= e($req['reason']) ?></div>
                            <?php endif; ?>
                            <?php if ($req['status'] === 'rejected' && !empty($req['rejection_reason'])): ?>
                                <div class="lr-row-reject">
                                    <strong>Motivo:</strong> <?= e($req['rejection_reason']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($__needsDocs): ?>
                                <div class="lr-sick-missing <?= $__hoursOld >= 24 ? 'is-late' : '' ?>">
                                    <div class="lr-sick-missing-head">
                                        <strong>
                                            <?php if ($__hoursOld >= 24): ?>
                                                Documenti malattia mancanti da <?= (int) floor($__hoursOld / 24) ?> giorn<?= floor($__hoursOld / 24) == 1 ? 'o' : 'i' ?>
                                            <?php else: ?>
                                                Carica numero protocollo e certificato
                                            <?php endif; ?>
                                        </strong>
                                        <button type="button" class="lr-sick-toggle" data-target="sickForm_<?= (int)$req['id'] ?>">Aggiungi documenti</button>
                                    </div>
                                    <form method="POST" enctype="multipart/form-data" class="lr-sick-form" id="sickForm_<?= (int)$req['id'] ?>" hidden>
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="add_sick_docs">
                                        <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                        <div class="lr-grid-2">
                                            <?php if (!$__hasProto): ?>
                                                <div>
                                                    <label class="lr-sub-lbl">Numero protocollo</label>
                                                    <input type="text" name="protocol_number" maxlength="100" placeholder="Es. 1234567890">
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$__hasCert): ?>
                                                <div>
                                                    <label class="lr-sub-lbl">Certificato medico</label>
                                                    <input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="lr-sick-form-actions">
                                            <button type="submit" class="lr-sick-save-btn">Salva documenti</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($req['status'] === 'pending'): ?>
                            <form method="POST" class="lr-row-act" onsubmit="return confirm('Annullare questa richiesta?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button type="submit" class="lr-cancel-btn" title="Annulla richiesta">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    Annulla
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== Lista richieste ===== */
.lr-list-card {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    padding: 22px 24px;
}
.lr-list-h {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px;
}
.lr-list-h h2 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 16px; font-weight: 700;
    margin: 0; color: #0b3aa4;
    letter-spacing: -0.01em;
}
.lr-count {
    font-size: 11px; font-weight: 700;
    color: #6e7191;
    background: #f1f5f9;
    padding: 3px 10px; border-radius: 999px;
}
.lr-filters {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.lr-tabs {
    display: inline-flex; gap: 2px;
    background: #f1f5f9;
    border-radius: 999px;
    padding: 4px;
    flex-wrap: wrap;
}
.lr-tab {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    color: #6e7191; text-decoration: none;
    transition: all .12s ease;
    white-space: nowrap;
}
.lr-tab:hover { color: #0b3aa4; }
.lr-tab.active {
    background: white;
    color: #0b3aa4;
    box-shadow: 0 1px 3px rgba(15,23,42,0.08);
}
.lr-year-select select {
    padding: 7px 12px;
    border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 12px; font-weight: 600;
    color: #475569; background: white;
    cursor: pointer;
}
.lr-empty {
    text-align: center; padding: 36px 18px;
    color: #94a3b8;
}
.lr-empty svg { color: #cbd5e0; margin-bottom: 10px; }
.lr-empty p { margin: 0; font-size: 13px; }
.lr-list { display: flex; flex-direction: column; gap: 8px; }
.lr-row {
    display: flex; align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    background: #fafbfd;
    border: 1px solid #e6e8f0;
    border-radius: 10px;
    transition: all .12s ease;
}
.lr-row:hover { border-color: #0b3aa4; }
.lr-row-main { flex: 1; min-width: 0; }
.lr-row-head {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}
.lr-row-type {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 14px; font-weight: 700;
    color: #1e1e2f;
}
.lr-row-status {
    padding: 2px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.lr-row-dates {
    font-size: 12px; color: #6e7191;
    font-variant-numeric: tabular-nums;
}
.lr-row-reason {
    font-size: 12px; color: #475569;
    margin-top: 4px; line-height: 1.4;
}
.lr-row-reject {
    margin-top: 6px;
    padding: 6px 10px;
    background: #fde2e5;
    border-radius: 6px;
    font-size: 11px; color: #cc2d39;
}
.lr-cancel-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 10px;
    background: white;
    border: 1px solid rgba(247,92,108,0.30);
    border-radius: 7px;
    color: #cc2d39; cursor: pointer;
    font-family: inherit; font-size: 11px; font-weight: 600;
    transition: all .12s ease;
}
.lr-cancel-btn:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.lr-row-act form { margin: 0; }

.lr-row-meta {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10.5px; font-weight: 600;
    color: #475569; background: #f1f5f9;
    padding: 2px 8px; border-radius: 999px;
}
.lr-row-link { text-decoration: none; }
.lr-row-link:hover { background: #e0e7ff; color: #0b3aa4; text-decoration: none; }

.lr-sick-missing {
    margin-top: 10px;
    padding: 10px 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
}
.lr-sick-missing.is-late {
    background: #fef2f2;
    border-color: #fecaca;
}
.lr-sick-missing-head {
    display: flex; justify-content: space-between; align-items: center;
    gap: 10px; flex-wrap: wrap;
}
.lr-sick-missing strong {
    font-size: 12.5px; color: #92400e;
}
.lr-sick-missing.is-late strong { color: #991b1b; }
.lr-sick-toggle {
    background: #b45309; color: white;
    border: none; padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px; font-weight: 600;
    cursor: pointer;
}
.lr-sick-missing.is-late .lr-sick-toggle { background: #b91c1c; }
.lr-sick-toggle:hover { filter: brightness(1.1); }
.lr-sick-form { margin-top: 10px; }
.lr-sick-form[hidden] { display: none !important; }
.lr-sick-form input[type=text],
.lr-sick-form input[type=file] {
    width: 100%; padding: 8px 10px;
    border: 1px solid #e6e8f0; border-radius: 7px;
    font-family: inherit; font-size: 12.5px;
    background: white;
}
.lr-sick-form-actions { margin-top: 10px; text-align: right; }
.lr-sick-save-btn {
    background: #0b3aa4; color: white;
    border: none; padding: 7px 14px;
    border-radius: 7px;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
}
.lr-sick-save-btn:hover { background: #082b7b; }

@media (max-width: 640px) {
    .lr-list-card { padding: 16px; }
    .lr-filters { flex-direction: column; align-items: stretch; }
    .lr-tabs {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr);
        gap: 4px;
        width: 100%;
        padding: 4px;
        border-radius: 12px;
    }
    .lr-tab {
        text-align: center;
        padding: 9px 8px;
        font-size: 12px;
        border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .lr-year-select select { width: 100%; }
    .lr-row { flex-direction: column; }
    .lr-row-act { width: 100%; }
    .lr-cancel-btn { width: 100%; justify-content: center; }
}
</style>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
