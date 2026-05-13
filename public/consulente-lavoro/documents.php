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
    background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
    padding: 1rem 1.25rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.upload-header h2 {
    margin: 0;
    font-size: 1rem;
    color: white;
}

.upload-header svg {
    width: 20px;
    height: 20px;
}

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
    border-color: #38a169;
    box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
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
    border-color: #38a169;
    box-shadow: 0 0 0 3px rgba(56, 161, 105, 0.1);
}
.autocomplete-input.has-value {
    background: #f0fff4;
    border-color: #38a169;
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
    background: #f0fff4;
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

.doc-icon.payslip { background: #c6f6d5; color: #276749; }
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
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/>
            </svg>
            <h2>Carica Nuovo Documento</h2>
        </div>
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
                <button type="submit" class="btn btn-success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/>
                    </svg>
                    Carica Documento
                </button>
            </div>
        </form>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" class="filters-row">
            <div class="filter-item">
                <label>Dipendente</label>
                <select name="employee_id">
                    <option value="">Tutti</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $filterEmployee == $emp['id'] ? 'selected' : '' ?>>
                            <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>Tipo Documento</label>
                <select name="type">
                    <option value="">Tutti</option>
                    <?php foreach (Document::TYPES as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterType === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>Periodo</label>
                <input type="month" name="filter_period"
                       value="<?= $filterPeriod ? e($filterPeriod) : '' ?>"
                       min="<?= date('Y') - 5 ?>-01"
                       max="<?= date('Y') ?>-12"
                       placeholder="Seleziona periodo">
            </div>

            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary">Filtra</button>
                <?php if ($filterEmployee || $filterPeriod || $filterType): ?>
                    <a href="documents.php" class="btn btn-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Documents List -->
    <div class="docs-list-section">
        <div class="docs-list-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/>
                </svg>
                Documenti Caricati
            </h2>
            <span class="docs-count"><?= count($documents) ?> documenti</span>
        </div>

        <?php if (empty($documents)): ?>
            <div class="docs-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/>
                </svg>
                <p>Nessun documento trovato</p>
            </div>
        <?php else: ?>
            <div class="docs-grid">
                <?php foreach ($documents as $doc): ?>
                    <div class="doc-card">
                        <div class="doc-icon <?= e($doc['type']) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/>
                            </svg>
                        </div>
                        <div class="doc-main">
                            <div class="doc-title"><?= e($doc['title']) ?></div>
                            <div class="doc-meta">
                                <span><?= getMonthName($doc['month']) ?> <?= $doc['year'] ?></span>
                                <span><?= e(Document::TYPES[$doc['type']] ?? $doc['type']) ?></span>
                            </div>
                        </div>
                        <div class="doc-employee">
                            <?= e($doc['last_name'] . ' ' . $doc['first_name']) ?>
                            <small><?= e($doc['fiscal_code']) ?></small>
                        </div>
                        <div class="doc-size"><?= formatFileSize($doc['file_size']) ?></div>
                        <div class="doc-date"><?= formatDate($doc['created_at']) ?></div>
                        <div class="doc-actions">
                            <a href="<?= PUBLIC_URL ?>/api/download.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-info" title="Scarica">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14">
                                    <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                                </svg>
                            </a>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

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
                input.style.borderColor = '#e53e3e';
                setTimeout(() => input.style.borderColor = '', 2000);
            }
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
