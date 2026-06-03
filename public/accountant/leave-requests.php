<?php
/**
 * Visualizzazione Ferie/Permessi Approvati - Commercialista
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('accountant');

// Download certificato malattia (sola lettura)
if (isset($_GET['download_cert'])) {
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

// Filtri
$filterYear = !empty($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$filterMonth = !empty($_GET['month']) ? (int) $_GET['month'] : null;
$filterDepartment = !empty($_GET['department_id']) ? (int) $_GET['department_id'] : null;

// Carica richieste approvate
$requests = LeaveRequest::getApproved($filterYear, $filterMonth);

// Filtra per reparto se necessario
if ($filterDepartment) {
    $requests = array_filter($requests, fn($r) => ($r['department_id'] ?? null) == $filterDepartment);
}

// Carica reparti per filtro
$departments = Department::getForSelect();

$pageTitle = 'Ferie e Permessi Approvati';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.leave-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Info Box */
.info-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.info-box svg {
    width: 32px;
    height: 32px;
    opacity: 0.8;
}

.info-box-text h3 {
    margin: 0 0 0.25rem;
    font-size: 1rem;
}

.info-box-text p {
    margin: 0;
    font-size: 0.85rem;
    opacity: 0.9;
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
}

.filter-buttons {
    display: flex;
    gap: 0.5rem;
}

.filter-buttons .btn {
    padding: 0.45rem 0.85rem;
    font-size: 0.8rem;
}

/* Summary Stats */
.summary-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.summary-stat {
    background: white;
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    text-align: center;
}

.summary-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
}

.summary-stat-label {
    font-size: 0.7rem;
    color: #a0aec0;
    text-transform: uppercase;
}

/* Requests Table */
.requests-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
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

.requests-table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table th {
    background: #f7fafc;
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    border-bottom: 1px solid #edf2f7;
}

.requests-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid #f7fafc;
    font-size: 0.85rem;
    color: #4a5568;
}

.requests-table tr:last-child td {
    border-bottom: none;
}

.requests-table tr:hover {
    background: #f7fafc;
}

.employee-cell {
    display: flex;
    flex-direction: column;
}

.employee-cell-name {
    font-weight: 500;
    color: #2d3748;
}

.employee-cell-fc {
    font-size: 0.7rem;
    color: #a0aec0;
    font-family: monospace;
}

.type-badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.7rem;
    font-weight: 600;
}

.type-badge.ferie { background: #d4edda; color: #155724; }
.type-badge.permesso { background: #cce5ff; color: #004085; }
.type-badge.malattia { background: #f8d7da; color: #721c24; }
.type-badge.permesso_104 { background: #e2d5f1; color: #563d7c; }
.type-badge.congedo_parentale { background: #fce4ec; color: #880e4f; }
.type-badge.congedo_separazione { background: #edf2f7; color: #2d3748; }
.type-badge.congedo_mestruale { background: #ffe0e6; color: #b83a4b; }
.type-badge.altro { background: #e2e8f0; color: #4a5568; }

.dept-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    background: #e2e8f0;
    color: #4a5568;
}

.days-cell {
    text-align: center;
    font-weight: 600;
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

/* Export Button */
.export-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.export-btn svg {
    width: 16px;
    height: 16px;
}

@media (max-width: 768px) {
    .filters-row {
        flex-direction: column;
    }

    .filter-item {
        width: 100%;
    }

    .requests-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<div class="leave-page">
    <!-- Info Box -->
    <div class="info-box">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
        </svg>
        <div class="info-box-text">
            <h3>Ferie e Permessi Approvati</h3>
            <p>Questa sezione mostra tutte le richieste approvate per la gestione delle buste paga.</p>
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
                <label>Mese</label>
                <select name="month">
                    <option value="">Tutti</option>
                    <?php foreach (getMonthsArray() as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $filterMonth == $num ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>Reparto</label>
                <select name="department_id">
                    <option value="">Tutti</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $filterDepartment == $dept['id'] ? 'selected' : '' ?>>
                            <?= e($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary">Filtra</button>
                <?php if ($filterMonth || $filterDepartment): ?>
                    <a href="leave-requests.php?year=<?= $filterYear ?>" class="btn btn-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Statistiche Riepilogo -->
    <?php
    $totalDays = 0;
    $byType = [];
    foreach ($requests as $req) {
        $days = LeaveRequest::calculateWorkingDays($req['start_date'], $req['end_date']);
        $totalDays += $days;
        $type = $req['leave_type'];
        if (!isset($byType[$type])) {
            $byType[$type] = 0;
        }
        $byType[$type] += $days;
    }
    ?>
    <div class="summary-stats">
        <div class="summary-stat">
            <div class="summary-stat-value"><?= count($requests) ?></div>
            <div class="summary-stat-label">Richieste</div>
        </div>
        <div class="summary-stat">
            <div class="summary-stat-value"><?= $totalDays ?></div>
            <div class="summary-stat-label">Giorni Totali</div>
        </div>
        <?php foreach ($byType as $type => $days): ?>
            <div class="summary-stat">
                <div class="summary-stat-value"><?= $days ?></div>
                <div class="summary-stat-label"><?= e(LeaveRequest::LEAVE_TYPES[$type] ?? $type) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabella Richieste -->
    <div class="requests-section">
        <div class="requests-header">
            <h2>Lista Richieste Approvate</h2>
            <span class="requests-count"><?= count($requests) ?> richieste</span>
        </div>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <p>Nessuna richiesta approvata per il periodo selezionato</p>
            </div>
        <?php else: ?>
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>Dipendente</th>
                        <th>Reparto</th>
                        <th>Tipo</th>
                        <th>Dal</th>
                        <th>Al</th>
                        <th>Giorni</th>
                        <th>Approvata il</th>
                        <th>Doc. malattia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req):
                        $workingDays = LeaveRequest::calculateWorkingDays($req['start_date'], $req['end_date']);
                    ?>
                        <tr>
                            <td>
                                <div class="employee-cell">
                                    <span class="employee-cell-name">
                                        <?= e($req['last_name'] . ' ' . $req['first_name']) ?>
                                    </span>
                                    <span class="employee-cell-fc"><?= e($req['fiscal_code']) ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($req['department_code']): ?>
                                    <span class="dept-badge"><?= e($req['department_code']) ?></span>
                                <?php else: ?>
                                    <span style="color:#a0aec0;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="type-badge <?= $req['leave_type'] ?>">
                                    <?= e(LeaveRequest::LEAVE_TYPES[$req['leave_type']] ?? $req['leave_type']) ?>
                                </span>
                            </td>
                            <td><?= formatDate($req['start_date']) ?></td>
                            <td><?= formatDate($req['end_date']) ?></td>
                            <td class="days-cell"><?= $workingDays ?></td>
                            <td>
                                <?= $req['approved_at'] ? formatDate($req['approved_at']) : '-' ?>
                            </td>
                            <td>
                                <?php if ($req['leave_type'] !== 'malattia'): ?>
                                    <span style="color:#cbd5e0;">—</span>
                                <?php else: ?>
                                    <div style="display:flex; flex-direction:column; gap:3px; font-size:11px;">
                                        <?php if (!empty($req['protocol_number'])): ?>
                                            <span style="color:#0b3aa4; font-weight:600; font-family:'Space Grotesk',monospace;">PRT <?= e($req['protocol_number']) ?></span>
                                        <?php else: ?>
                                            <span style="color:#dc2626; font-weight:600;">Protocollo mancante</span>
                                        <?php endif; ?>
                                        <?php if (!empty($req['certificate_path'])): ?>
                                            <a href="?download_cert=<?= (int)$req['id'] ?>" style="display:inline-flex; align-items:center; gap:4px; color:#0b3aa4; text-decoration:none; font-weight:600;" title="Scarica certificato">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                Certificato
                                            </a>
                                        <?php elseif (!empty($req['certificate_waived'])): ?>
                                            <span style="color:#15803d; font-weight:600;">Esonero</span>
                                        <?php else: ?>
                                            <span style="color:#dc2626; font-weight:600;">Certificato mancante</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
