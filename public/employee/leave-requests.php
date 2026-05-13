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

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $data = [
                'employee_id' => $employeeId,
                'leave_type' => $_POST['leave_type'] ?? '',
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_full_day' => isset($_POST['is_full_day']),
                'start_time' => $_POST['start_time'] ?? '',
                'end_time' => $_POST['end_time'] ?? '',
                'reason' => $_POST['reason'] ?? '',
                'notes' => $_POST['notes'] ?? ''
            ];

            $result = LeaveRequest::create($data);

            if ($result['success']) {
                // Upload allegato se presente
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    LeaveRequest::uploadAttachment($_FILES['attachment'], $result['id']);
                }
                header('Location: leave-requests.php?message=created');
                exit;
            }
            $error = $result['error'];
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
        'created' => 'Richiesta inviata con successo',
        'cancelled' => 'Richiesta annullata'
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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

.request-icon.ferie { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
.request-icon.permesso { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
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

<div class="leave-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Form Nuova Richiesta -->
    <div class="form-section">
        <div class="form-header">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
            </svg>
            <h2>Nuova Richiesta</h2>
        </div>
        <div class="form-body">
            <form method="POST" enctype="multipart/form-data">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="leave_type">Tipo Assenza *</label>
                        <select id="leave_type" name="leave_type" required>
                            <option value="">-- Seleziona --</option>
                            <?php foreach (LeaveRequest::LEAVE_TYPES as $key => $label): ?>
                                <option value="<?= $key ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="start_date">Data Inizio *</label>
                        <input type="date" id="start_date" name="start_date" required
                               min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date">Data Fine *</label>
                        <input type="date" id="end_date" name="end_date" required
                               min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="is_full_day" name="is_full_day" checked>
                            Giornata intera
                        </label>
                    </div>

                    <div class="form-group time-group" id="time_group">
                        <div>
                            <label for="start_time">Ora Inizio</label>
                            <input type="time" id="start_time" name="start_time">
                        </div>
                        <div>
                            <label for="end_time">Ora Fine</label>
                            <input type="time" id="end_time" name="end_time">
                        </div>
                    </div>

                    <div class="form-group full">
                        <label for="reason">Motivazione *</label>
                        <textarea id="reason" name="reason" rows="2" required
                                  placeholder="Inserisci la motivazione della richiesta..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="notes">Note Aggiuntive</label>
                        <textarea id="notes" name="notes" rows="2"
                                  placeholder="Eventuali note..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="attachment">Allegato</label>
                        <input type="file" id="attachment" name="attachment"
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small style="display:block; font-size:0.7rem; color:#a0aec0; margin-top:0.25rem;">
                            PDF, JPG, PNG, DOC - Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB
                        </small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Invia Richiesta</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtri -->
    <div class="filters-section">
        <form method="GET" class="filters-row">
            <div class="filter-item">
                <label>Anno</label>
                <select name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>Stato</label>
                <select name="status">
                    <option value="">Tutti</option>
                    <?php foreach (LeaveRequest::STATUSES as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary">Filtra</button>
            </div>
        </form>
    </div>

    <!-- Lista Richieste -->
    <div class="requests-section">
        <div class="requests-header">
            <h2>Le Mie Richieste</h2>
            <span class="requests-count"><?= count($requests) ?> richieste</span>
        </div>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <p>Nessuna richiesta trovata</p>
            </div>
        <?php else: ?>
            <div class="requests-list">
                <?php foreach ($requests as $req): ?>
                    <div class="request-item">
                        <div class="request-icon <?= $req['leave_type'] ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                            </svg>
                        </div>
                        <div class="request-info">
                            <div class="request-type">
                                <?= e(LeaveRequest::LEAVE_TYPES[$req['leave_type']] ?? $req['leave_type']) ?>
                            </div>
                            <div class="request-dates">
                                <?= formatDate($req['start_date']) ?>
                                <?php if ($req['start_date'] !== $req['end_date']): ?>
                                    - <?= formatDate($req['end_date']) ?>
                                <?php endif; ?>
                                <?php if (!$req['is_full_day'] && $req['start_time']): ?>
                                    (<?= substr($req['start_time'], 0, 5) ?> - <?= substr($req['end_time'], 0, 5) ?>)
                                <?php endif; ?>
                            </div>
                            <div class="request-reason"><?= e($req['reason']) ?></div>
                            <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
                                <div class="request-reason" style="color: #c53030;">
                                    Motivo rifiuto: <?= e($req['rejection_reason']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="request-status <?= $req['status'] ?>">
                            <?= e(LeaveRequest::STATUSES[$req['status']] ?? $req['status']) ?>
                        </span>
                        <?php if ($req['status'] === 'pending'): ?>
                            <div class="request-actions">
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Annullare questa richiesta?')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Annulla</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const isFullDay = document.getElementById('is_full_day');
    const timeGroup = document.getElementById('time_group');
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');

    // Toggle orari per permessi orari
    function toggleTimeGroup() {
        if (isFullDay.checked) {
            timeGroup.classList.remove('show');
        } else {
            timeGroup.classList.add('show');
        }
    }

    isFullDay.addEventListener('change', toggleTimeGroup);
    toggleTimeGroup();

    // Sincronizza data fine con data inizio
    startDate.addEventListener('change', function() {
        if (!endDate.value || endDate.value < this.value) {
            endDate.value = this.value;
        }
        endDate.min = this.value;
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
