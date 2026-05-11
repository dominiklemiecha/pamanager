<?php
/**
 * Gestione Richieste Reset Password - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$message = '';
$error = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    switch ($action) {
        case 'approve':
            $result = Auth::approveResetRequest($requestId, $user['id']);
            if ($result['success']) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                         . '://' . $_SERVER['HTTP_HOST'];
                $resetLink = $baseUrl . PUBLIC_URL . '/auth/reset-password.php?token=' . $result['token'];
                $message = 'Richiesta approvata! Invia questo link all\'utente:<br><br>
                    <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:1rem;margin:0.5rem 0;">
                        <input type="text" value="' . htmlspecialchars($resetLink) . '"
                               id="resetLink" readonly
                               style="width:100%;padding:0.5rem;border:1px solid #cbd5e0;border-radius:4px;font-size:0.85rem;">
                        <button onclick="copyResetLink()" style="margin-top:0.5rem;padding:0.4rem 1rem;background:#38a169;color:white;border:none;border-radius:4px;cursor:pointer;">
                            Copia Link
                        </button>
                        <span id="copyMsg" style="margin-left:0.5rem;color:#276749;display:none;">Copiato!</span>
                    </div>
                    <small style="color:#718096;">Il link scade tra 24 ore.</small>';
            } else {
                $error = $result['error'];
            }
            break;

        case 'reject':
            $notes = trim($_POST['notes'] ?? '');
            $result = Auth::rejectResetRequest($requestId, $user['id'], $notes);
            if ($result['success']) {
                $message = 'Richiesta rifiutata';
            } else {
                $error = $result['error'];
            }
            break;

        case 'manual_reset':
            $userType = $_POST['user_type'] ?? '';
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userType && $userId) {
                $result = Auth::adminResetPassword($userType, $userId, $user['id']);
                if ($result['success']) {
                    $message = 'Password resettata. Nuova password temporanea: <code>' . htmlspecialchars($result['password']) . '</code>';
                } else {
                    $error = $result['error'];
                }
            }
            break;
    }
}

// Filtro stato
$filterStatus = $_GET['status'] ?? null;

// Carica richieste
$requests = Auth::getResetRequests($filterStatus);
$pendingCount = Auth::countPendingResetRequests();

// Carica utenti per reset manuale (scope: azienda corrente + admin globali)
$__prCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$users = Database::fetchAll(
    "SELECT id, username, name, email, role FROM users
     WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL)
     ORDER BY name",
    [$__prCid]
);

// Carica dipendenti per reset manuale (scope azienda corrente)
$employees = Database::fetchAll(
    "SELECT id, username, fiscal_code, CONCAT(last_name, ' ', first_name) as name, email
     FROM employees WHERE is_active = 1 AND company_id = ? ORDER BY last_name, first_name",
    [$__prCid]
);

$pageTitle = 'Gestione Reset Password';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.reset-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

/* Stats Cards */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon svg {
    width: 24px;
    height: 24px;
}

.stat-icon.pending { background: #fef3c7; color: #d97706; }
.stat-icon.completed { background: #d1fae5; color: #059669; }
.stat-icon.rejected { background: #fee2e2; color: #dc2626; }

.stat-info h3 {
    font-size: 1.5rem;
    margin: 0;
    color: #1a202c;
}

.stat-info p {
    font-size: 0.8rem;
    color: #718096;
    margin: 0;
}

/* Manual Reset Section */
.manual-reset-section {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.manual-reset-section h2 {
    font-size: 1rem;
    margin: 0 0 1rem;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.manual-reset-section h2 svg {
    width: 20px;
    height: 20px;
    color: #718096;
}

.manual-reset-form {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
}

.manual-reset-form .form-group {
    flex: 1;
    min-width: 200px;
}

.manual-reset-form label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.35rem;
}

.manual-reset-form select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.85rem;
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
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.requests-header h2 {
    font-size: 1rem;
    margin: 0;
    color: #2d3748;
}

.filter-tabs {
    display: flex;
    gap: 0.5rem;
}

.filter-tab {
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    text-decoration: none;
    color: #718096;
    background: #f7fafc;
    transition: all 0.2s;
}

.filter-tab:hover {
    background: #edf2f7;
}

.filter-tab.active {
    background: #3182ce;
    color: white;
}

.filter-tab .count {
    background: rgba(0,0,0,0.1);
    padding: 0.1rem 0.4rem;
    border-radius: 8px;
    margin-left: 0.25rem;
}

.filter-tab.active .count {
    background: rgba(255,255,255,0.2);
}

/* Table */
.requests-table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table th,
.requests-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid #f7fafc;
}

.requests-table th {
    background: #f7fafc;
    font-size: 0.7rem;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
}

.requests-table td {
    font-size: 0.85rem;
    color: #4a5568;
}

.requests-table tr:hover {
    background: #f7fafc;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.6rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
}

.status-badge.pending { background: #fef3c7; color: #d97706; }
.status-badge.sent { background: #dbeafe; color: #2563eb; }
.status-badge.completed { background: #d1fae5; color: #059669; }
.status-badge.rejected { background: #fee2e2; color: #dc2626; }
.status-badge.expired { background: #e2e8f0; color: #64748b; }

/* User Type Badge */
.user-type-badge {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
}

.user-type-badge.admin { background: #e9d5ff; color: #7c3aed; }
.user-type-badge.accountant { background: #dbeafe; color: #2563eb; }
.user-type-badge.employee { background: #d1fae5; color: #059669; }

/* Actions */
.action-btns {
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.35rem 0.6rem;
    font-size: 0.7rem;
    border-radius: 4px;
}

.btn-approve {
    background: #38a169;
    color: white;
    border: none;
    cursor: pointer;
}

.btn-approve:hover {
    background: #2f855a;
}

.btn-reject {
    background: #e53e3e;
    color: white;
    border: none;
    cursor: pointer;
}

.btn-reject:hover {
    background: #c53030;
}

/* Empty State */
.empty-state {
    padding: 3rem;
    text-align: center;
    color: #718096;
}

.empty-state svg {
    width: 48px;
    height: 48px;
    color: #cbd5e0;
    margin-bottom: 1rem;
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
    max-width: 400px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h3 {
    margin: 0;
    font-size: 1rem;
}

.modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.25rem;
    color: #718096;
}

.modal-body {
    padding: 1.25rem;
}

.modal-body .form-group {
    margin-bottom: 1rem;
}

.modal-body label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    margin-bottom: 0.35rem;
}

.modal-body textarea {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    resize: vertical;
    min-height: 80px;
}

.modal-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

/* Token display */
.token-display {
    background: #f0fff4;
    border: 1px solid #9ae6b4;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1rem;
    word-break: break-all;
}

.token-display code {
    background: #276749;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
}

.copy-btn {
    margin-left: 0.5rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.7rem;
    background: #3182ce;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

@media (max-width: 768px) {
    .requests-table {
        display: block;
        overflow-x: auto;
    }

    .manual-reset-form {
        flex-direction: column;
    }

    .manual-reset-form .form-group {
        width: 100%;
    }
}
</style>

<div class="reset-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon pending">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                </svg>
            </div>
            <div class="stat-info">
                <h3><?= $pendingCount ?></h3>
                <p>In Attesa</p>
            </div>
        </div>
    </div>

    <!-- Manual Reset -->
    <div class="manual-reset-section">
        <h2>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
            </svg>
            Reset Manuale Password
        </h2>
        <form method="POST" class="manual-reset-form" onsubmit="return confirm('Sei sicuro di voler resettare la password di questo utente?')">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="manual_reset">

            <div class="form-group">
                <label>Seleziona Tipo Utente</label>
                <select name="user_type" id="manualUserType" required>
                    <option value="">-- Seleziona tipo --</option>
                    <option value="admin">Amministratore</option>
                    <option value="accountant">Commercialista</option>
                    <option value="employee">Dipendente</option>
                </select>
            </div>

            <div class="form-group">
                <label>Utente</label>
                <select name="user_id" id="manualUserId" required>
                    <option value="">-- Prima seleziona il tipo --</option>
                </select>
            </div>

            <button type="submit" class="btn btn-warning">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                    <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
                </svg>
                Reset Password
            </button>
        </form>
    </div>

    <!-- Requests List -->
    <div class="requests-section">
        <div class="requests-header">
            <h2>Richieste Reset Password</h2>
            <div class="filter-tabs">
                <a href="password-resets.php" class="filter-tab <?= !$filterStatus ? 'active' : '' ?>">Tutte</a>
                <a href="password-resets.php?status=pending" class="filter-tab <?= $filterStatus === 'pending' ? 'active' : '' ?>">
                    In Attesa
                    <?php if ($pendingCount > 0): ?>
                        <span class="count"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="password-resets.php?status=completed" class="filter-tab <?= $filterStatus === 'completed' ? 'active' : '' ?>">Completate</a>
                <a href="password-resets.php?status=rejected" class="filter-tab <?= $filterStatus === 'rejected' ? 'active' : '' ?>">Rifiutate</a>
            </div>
        </div>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                <p>Nessuna richiesta trovata</p>
            </div>
        <?php else: ?>
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>Utente</th>
                        <th>Tipo</th>
                        <th>Data Richiesta</th>
                        <th>IP</th>
                        <th>Stato</th>
                        <th>Gestito da</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($req['user_name'] ?? 'N/D') ?></strong><br>
                                <small style="color: #718096;"><?= htmlspecialchars($req['user_identifier'] ?? '') ?></small>
                            </td>
                            <td>
                                <span class="user-type-badge <?= $req['user_type'] ?>">
                                    <?= $req['user_type'] ?>
                                </span>
                            </td>
                            <td><?= formatDateTime($req['created_at']) ?></td>
                            <td><code style="font-size: 0.75rem;"><?= htmlspecialchars($req['requested_ip']) ?></code></td>
                            <td>
                                <span class="status-badge <?= $req['status'] ?>">
                                    <?php
                                    $statusLabels = [
                                        'pending' => 'In Attesa',
                                        'sent' => 'Inviato',
                                        'completed' => 'Completato',
                                        'rejected' => 'Rifiutato',
                                        'expired' => 'Scaduto'
                                    ];
                                    echo $statusLabels[$req['status']] ?? $req['status'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['resolved_by_name']): ?>
                                    <?= htmlspecialchars($req['resolved_by_name']) ?><br>
                                    <small style="color: #718096;"><?= formatDateTime($req['resolved_at']) ?></small>
                                <?php else: ?>
                                    <span style="color: #a0aec0;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <div class="action-btns">
                                        <form method="POST" style="display: inline;">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                            <button type="submit" class="btn-sm btn-approve" onclick="return confirm('Approva questa richiesta?')">
                                                Approva
                                            </button>
                                        </form>
                                        <button type="button" class="btn-sm btn-reject" onclick="openRejectModal(<?= $req['id'] ?>)">
                                            Rifiuta
                                        </button>
                                    </div>
                                <?php elseif ($req['status'] === 'sent' && $req['token_id']): ?>
                                    <small style="color: #718096;">Token inviato</small>
                                <?php else: ?>
                                    <span style="color: #a0aec0;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Rifiuta Richiesta</h3>
            <button class="modal-close" onclick="closeRejectModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectRequestId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Motivo del rifiuto (opzionale)</label>
                    <textarea name="notes" placeholder="Inserisci una nota..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Annulla</button>
                <button type="submit" class="btn btn-danger">Rifiuta</button>
            </div>
        </form>
    </div>
</div>

<script>
// Users and employees data for manual reset
const users = <?= json_encode($users) ?>;
const employees = <?= json_encode($employees) ?>;

document.getElementById('manualUserType').addEventListener('change', function() {
    const type = this.value;
    const userSelect = document.getElementById('manualUserId');
    userSelect.innerHTML = '<option value="">-- Seleziona utente --</option>';

    if (type === 'employee') {
        employees.forEach(e => {
            const option = document.createElement('option');
            option.value = e.id;
            option.textContent = e.name + ' (' + (e.username || e.fiscal_code) + ')';
            userSelect.appendChild(option);
        });
    } else if (type) {
        const filteredUsers = users.filter(u => u.role === type);
        filteredUsers.forEach(u => {
            const option = document.createElement('option');
            option.value = u.id;
            option.textContent = u.name + ' (' + u.username + ')';
            userSelect.appendChild(option);
        });
    }
});

// Reject Modal
function openRejectModal(requestId) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}

document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

// Copy reset link
function copyResetLink() {
    const input = document.getElementById('resetLink');
    if (input) {
        input.select();
        document.execCommand('copy');
        const msg = document.getElementById('copyMsg');
        if (msg) {
            msg.style.display = 'inline';
            setTimeout(() => msg.style.display = 'none', 2000);
        }
    }
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
