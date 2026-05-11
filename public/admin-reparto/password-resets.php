<?php
/**
 * Gestione Richieste Reset Password - Admin Reparto
 * Solo dipendenti del proprio reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = $user['department_id'] ?? null;

if (!$departmentId) {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<h2>Nessun reparto assegnato</h2>';
    echo '<p>Contatta l\'amministratore per essere assegnato a un reparto.</p>';
    echo '</div>';
    exit;
}

$department = Department::getById($departmentId);
$message = '';
$error = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    // Verifica che la richiesta sia di un dipendente del proprio reparto
    if ($requestId) {
        $request = Database::fetchOne(
            "SELECT prr.*, e.department_id
             FROM password_reset_requests prr
             JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
             WHERE prr.id = ? AND e.department_id = ?",
            [$requestId, $departmentId]
        );

        if (!$request) {
            $error = 'Richiesta non trovata o non appartenente al tuo reparto';
        } else {
            switch ($action) {
                case 'approve':
                    $result = Auth::approveResetRequest($requestId, $user['id']);
                    if ($result['success']) {
                        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                                 . '://' . $_SERVER['HTTP_HOST'];
                        $resetLink = $baseUrl . PUBLIC_URL . '/auth/reset-password.php?token=' . $result['token'];
                        $message = 'Richiesta approvata! Invia questo link al dipendente:<br><br>
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
            }
        }
    }

    // Reset manuale dipendente del reparto
    if ($action === 'manual_reset') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);

        // Verifica che il dipendente sia del proprio reparto
        $employee = Database::fetchOne(
            "SELECT id FROM employees WHERE id = ? AND department_id = ? AND is_active = 1",
            [$employeeId, $departmentId]
        );

        if ($employee) {
            $result = Auth::adminResetPassword('employee', $employeeId, $user['id']);
            if ($result['success']) {
                $message = 'Password resettata. Nuova password temporanea: <code style="background:#edf2f7;padding:0.25rem 0.5rem;border-radius:4px;">' . htmlspecialchars($result['password']) . '</code>';
            } else {
                $error = $result['error'];
            }
        } else {
            $error = 'Dipendente non trovato nel tuo reparto';
        }
    }
}

// Filtro stato
$filterStatus = $_GET['status'] ?? null;

// Carica richieste solo del proprio reparto
$requests = Auth::getResetRequestsByDepartment($departmentId, $filterStatus);
$pendingCount = Auth::countPendingResetRequestsByDepartment($departmentId);

// Carica dipendenti del reparto per reset manuale
$employees = Department::getEmployees($departmentId, true);

$pageTitle = 'Reset Password - ' . htmlspecialchars($department['name']);
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
?>

<div class="dashboard">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Statistiche -->
    <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">
        <div class="stat-card stat-warning">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= $pendingCount ?></span>
                <span class="stat-label">Richieste in Attesa</span>
            </div>
        </div>

        <div class="stat-card stat-primary">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value"><?= count($employees) ?></span>
                <span class="stat-label">Dipendenti Reparto</span>
            </div>
        </div>
    </div>

    <!-- Reset Manuale -->
    <section class="dashboard-card">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
                </svg>
                Reset Manuale Password
            </h3>
        </div>
        <div class="card-body">
            <form method="POST" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="manual_reset">

                <div class="form-group" style="flex: 1; min-width: 250px; margin: 0;">
                    <label for="employee_id" style="font-size: 0.8rem; color: #718096; display: block; margin-bottom: 0.35rem;">Dipendente</label>
                    <select name="employee_id" id="employee_id" required class="form-control">
                        <option value="">-- Seleziona Dipendente --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?> (<?= e($emp['fiscal_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-warning"
                        onclick="return confirm('Generare una nuova password per questo dipendente?')">
                    Reset Password
                </button>
            </form>
            <p style="margin-top: 0.75rem; font-size: 0.8rem; color: #a0aec0;">
                Genera una password temporanea che il dipendente dovrà cambiare al primo accesso.
            </p>
        </div>
    </section>

    <!-- Richieste -->
    <section class="dashboard-card dashboard-card-full">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                Richieste Reset Password
            </h3>
            <div style="display: flex; gap: 0.5rem;">
                <a href="password-resets.php" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-secondary' ?>">Tutte</a>
                <a href="password-resets.php?status=pending" class="btn btn-sm <?= $filterStatus === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">In Attesa</a>
                <a href="password-resets.php?status=sent" class="btn btn-sm <?= $filterStatus === 'sent' ? 'btn-primary' : 'btn-secondary' ?>">Approvate</a>
                <a href="password-resets.php?status=rejected" class="btn btn-sm <?= $filterStatus === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Rifiutate</a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($requests)): ?>
                <div class="empty-state-small">
                    <p>Nessuna richiesta <?= $filterStatus ? 'con questo stato' : '' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-hover">
                        <thead>
                            <tr>
                                <th>Dipendente</th>
                                <th>Data Richiesta</th>
                                <th>Stato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($req['user_name']) ?></strong><br>
                                        <small class="text-muted"><?= e($req['user_email'] ?? $req['user_username']) ?></small>
                                    </td>
                                    <td><?= formatDateTime($req['created_at']) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = match($req['status']) {
                                            'pending' => 'badge-warning',
                                            'sent' => 'badge-success',
                                            'completed' => 'badge-info',
                                            'rejected' => 'badge-danger',
                                            default => 'badge-gray'
                                        };
                                        $statusText = match($req['status']) {
                                            'pending' => 'In Attesa',
                                            'sent' => 'Approvata',
                                            'completed' => 'Completata',
                                            'rejected' => 'Rifiutata',
                                            default => $req['status']
                                        };
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                        <?php if ($req['resolved_by_name']): ?>
                                            <br><small class="text-muted">da <?= e($req['resolved_by_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($req['status'] === 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Approva</button>
                                            </form>
                                            <form method="POST" style="display: inline; margin-left: 0.25rem;">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Rifiutare questa richiesta?')">Rifiuta</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
function copyResetLink() {
    var input = document.getElementById('resetLink');
    input.select();
    input.setSelectionRange(0, 99999);
    document.execCommand('copy');
    document.getElementById('copyMsg').style.display = 'inline';
    setTimeout(function() {
        document.getElementById('copyMsg').style.display = 'none';
    }, 2000);
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
