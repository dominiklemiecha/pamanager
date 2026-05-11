<?php
/**
 * Dettaglio Reparto - Admin
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$deptId = (int) ($_GET['id'] ?? 0);
if (!$deptId) {
    header('Location: departments.php');
    exit;
}

$department = Department::getById($deptId);
if (!$department) {
    header('Location: departments.php?error=not_found');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_admin':
            $result = User::create([
                'username' => $_POST['admin_username'] ?? '',
                'password' => $_POST['admin_password'] ?? '',
                'name' => $_POST['admin_name'] ?? '',
                'email' => $_POST['admin_email'] ?? '',
                'role' => 'admin_reparto',
                'department_id' => $deptId
            ]);
            if ($result['success']) { header('Location: department-detail.php?id=' . $deptId . '&message=admin_created'); exit; }
            $error = $result['error'];
            break;
        case 'assign_employee':
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            if ($employeeId) {
                $result = Department::assignEmployee($employeeId, $deptId);
                if ($result['success']) { header('Location: department-detail.php?id=' . $deptId . '&message=employee_assigned'); exit; }
                $error = $result['error'];
            }
            break;
        case 'remove_employee':
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            if ($employeeId) {
                $result = Department::assignEmployee($employeeId, null);
                if ($result['success']) { header('Location: department-detail.php?id=' . $deptId . '&message=employee_removed'); exit; }
                $error = $result['error'];
            }
            break;
        case 'remove_admin':
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId) {
                $result = Department::removeAdmin($userId);
                if ($result['success']) { header('Location: department-detail.php?id=' . $deptId . '&message=admin_removed'); exit; }
                $error = $result['error'];
            }
            break;
    }
}

if (isset($_GET['message'])) {
    $messages = [
        'admin_created' => 'Admin reparto creato con successo',
        'employee_assigned' => 'Dipendente assegnato al reparto',
        'employee_removed' => 'Dipendente rimosso dal reparto',
        'admin_removed' => 'Admin rimosso'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

$deptEmployees = Department::getEmployees($deptId, false);
$deptAdmins    = Department::getAdmins($deptId);
$allEmployees  = Employee::getAll(true);
$unassignedEmployees = array_filter($allEmployees, function($emp) use ($deptId) {
    return empty($emp['department_id']) || $emp['department_id'] != $deptId;
});

$pageTitle = 'Reparto: ' . $department['name'];
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="page-top">
    <a href="departments.php" class="btn btn-secondary">← Torna ai Reparti</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<!-- Header reparto -->
<div class="card dept-banner">
    <div class="dept-banner-main">
        <span class="dept-code-pill"><?= e($department['code']) ?></span>
        <div>
            <h2 class="dept-banner-name"><?= e($department['name']) ?></h2>
            <?php if (!empty($department['description'])): ?>
                <p class="dept-banner-desc"><?= e($department['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="dept-banner-stats">
        <div class="banner-stat">
            <span class="banner-stat-value"><?= count($deptAdmins) ?></span>
            <span class="banner-stat-label">Admin</span>
        </div>
        <div class="banner-stat">
            <span class="banner-stat-value"><?= count($deptEmployees) ?></span>
            <span class="banner-stat-label">Dipendenti</span>
        </div>
        <div class="banner-stat">
            <span class="badge <?= $department['is_active'] ? 'badge-success' : 'badge-light' ?>">
                <?= $department['is_active'] ? 'Attivo' : 'Inattivo' ?>
            </span>
        </div>
    </div>
</div>

<!-- Sezione Admin -->
<div class="dept-section-grid">
    <section class="card">
        <div class="card-header">
            <h3>Crea Admin per il reparto</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_admin">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="admin_username">Username *</label>
                        <input type="text" id="admin_username" name="admin_username" required
                               minlength="3" maxlength="50" pattern="[a-zA-Z0-9_\.]+"
                               placeholder="es. mario.rossi">
                    </div>
                    <div class="form-group">
                        <label for="admin_password">Password *</label>
                        <input type="password" id="admin_password" name="admin_password" required
                               minlength="8" autocomplete="new-password" placeholder="Min. 8 caratteri">
                    </div>
                    <div class="form-group">
                        <label for="admin_name">Nome completo *</label>
                        <input type="text" id="admin_name" name="admin_name" required maxlength="100"
                               placeholder="es. Mario Rossi">
                    </div>
                    <div class="form-group">
                        <label for="admin_email">Email</label>
                        <input type="email" id="admin_email" name="admin_email" maxlength="100"
                               placeholder="es. mario.rossi@azienda.it">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Crea admin reparto</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h3>Admin del reparto</h3>
            <span class="badge badge-light"><?= count($deptAdmins) ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($deptAdmins)): ?>
                <div class="empty-state">
                    <p style="margin:0;">Nessun admin assegnato a questo reparto.</p>
                </div>
            <?php else: ?>
                <ul class="person-list">
                    <?php foreach ($deptAdmins as $admin): ?>
                        <li class="person-item">
                            <div class="person-info">
                                <div class="person-avatar"><?= strtoupper(mb_substr($admin['name'], 0, 2)) ?></div>
                                <div class="person-details">
                                    <div class="name"><?= e($admin['name']) ?></div>
                                    <div class="meta">@<?= e($admin['username']) ?> · <?= e($admin['email'] ?? '—') ?></div>
                                </div>
                            </div>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Eliminare questo admin?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="remove_admin">
                                <input type="hidden" name="user_id" value="<?= $admin['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger btn-soft">Rimuovi</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Sezione Dipendenti -->
<section class="card" style="margin-top:1rem;">
    <div class="card-header">
        <h3>Assegna dipendente</h3>
    </div>
    <div class="card-body">
        <?php if (empty($unassignedEmployees)): ?>
            <div class="empty-state">
                <p style="margin:0;">Tutti i dipendenti sono già assegnati a questo reparto o non ci sono dipendenti disponibili.</p>
            </div>
        <?php else: ?>
            <form method="POST" class="assign-row">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="assign_employee">
                <div class="form-group" style="flex:1;min-width:240px;margin:0;">
                    <label for="employee_id">Seleziona dipendente</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">— Seleziona —</option>
                        <?php foreach ($unassignedEmployees as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                                <?php if (!empty($emp['department_name'])): ?>
                                    (attualmente: <?= e($emp['department_name']) ?>)
                                <?php else: ?>
                                    (non assegnato)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Assegna al reparto</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card" style="margin-top:1rem;">
    <div class="card-header">
        <h3>Dipendenti del reparto</h3>
        <span class="badge badge-light"><?= count($deptEmployees) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($deptEmployees)): ?>
            <div class="empty-state">
                <p style="margin:0;">Nessun dipendente assegnato a questo reparto.</p>
            </div>
        <?php else: ?>
            <ul class="person-list">
                <?php foreach ($deptEmployees as $emp): ?>
                    <li class="person-item">
                        <div class="person-info">
                            <?= employeeAvatarHtml($emp, 'person-avatar') ?>
                            <div class="person-details">
                                <div class="name"><?= e($emp['last_name'] . ' ' . $emp['first_name']) ?></div>
                                <div class="meta"><?= e($emp['fiscal_code']) ?> · <?= e($emp['email'] ?? '—') ?></div>
                            </div>
                        </div>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Rimuovere questo dipendente dal reparto?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="remove_employee">
                            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Rimuovi</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<style>
.dept-banner {
    padding: 1.25rem 1.4rem;
    margin-bottom: 1rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
}
.dept-banner-main {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-width: 0;
    flex: 1;
}
.dept-banner-name { font-size: 1.3rem; font-weight: 700; margin: 0; letter-spacing: -0.025em; color: var(--ink); }
.dept-banner-desc { color: var(--muted); margin: 2px 0 0; font-size: 0.88rem; }
.dept-code-pill {
    background: var(--accent-soft);
    color: var(--accent-dark);
    border: 1px solid var(--accent-border);
    border-radius: var(--r-md);
    padding: 0.5rem 0.85rem;
    font-family: var(--font-mono);
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    flex-shrink: 0;
}
.dept-banner-stats { display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
.banner-stat { display: flex; flex-direction: column; align-items: flex-start; }
.banner-stat-value { font-size: 1.4rem; font-weight: 700; color: var(--ink); line-height: 1; letter-spacing: -0.02em; }
.banner-stat-label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500; margin-top: 3px; }

.dept-section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 900px) { .dept-section-grid { grid-template-columns: 1fr; } }

.assign-row {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.person-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.person-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.7rem 0.85rem;
    background: var(--subtle);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    transition: border-color var(--t-fast);
}
.person-item:hover { border-color: var(--border-strong); }
.person-info { display: flex; align-items: center; gap: 0.75rem; min-width: 0; flex: 1; }
.person-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--accent-soft);
    color: var(--accent-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.78rem;
    flex-shrink: 0;
    border: 1px solid var(--accent-border);
}
.person-details { min-width: 0; }
.person-details .name { font-weight: 600; color: var(--ink); font-size: 0.9rem; line-height: 1.2; }
.person-details .meta { font-size: 0.78rem; color: var(--muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
