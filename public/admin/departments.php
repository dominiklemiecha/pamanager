<?php
/**
 * Gestione Reparti - Admin
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $result = Department::create([
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'description' => $_POST['description'] ?? ''
            ]);
            if ($result['success']) { header('Location: departments.php?message=created'); exit; }
            $error = $result['error'];
            break;
        case 'update':
            $id = (int) ($_POST['department_id'] ?? 0);
            if ($id) {
                $result = Department::update($id, [
                    'name' => $_POST['name'] ?? '',
                    'code' => $_POST['code'] ?? '',
                    'description' => $_POST['description'] ?? ''
                ]);
                if ($result['success']) { header('Location: departments.php?message=updated'); exit; }
                $error = $result['error'];
            }
            break;
        case 'delete':
            $id = (int) ($_POST['department_id'] ?? 0);
            if ($id) {
                $result = Department::delete($id);
                if ($result['success']) { header('Location: departments.php?message=deleted'); exit; }
                $error = $result['error'];
            }
            break;
        case 'toggle':
            $id = (int) ($_POST['department_id'] ?? 0);
            $department = Department::getById($id);
            if ($department) {
                $result = $department['is_active']
                    ? Department::deactivate($id)
                    : Department::activate($id);
                if ($result['success']) { header('Location: departments.php?message=toggled'); exit; }
                $error = $result['error'];
            }
            break;
    }
}

if (isset($_GET['message'])) {
    $messages = [
        'created' => 'Reparto creato con successo',
        'updated' => 'Reparto aggiornato con successo',
        'deleted' => 'Reparto eliminato con successo',
        'toggled' => 'Stato reparto aggiornato'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

$departments = Department::getAll(false);
$editDepartment = null;
$showForm = false;
if (!empty($_GET['edit'])) {
    $editDepartment = Department::getById((int) $_GET['edit']);
    $showForm = true;
}
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showForm = true;
}

$pageTitle = $editDepartment ? 'Modifica Reparto' : ($showForm ? 'Nuovo Reparto' : 'Gestione Reparti');
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="page-top">
    <?php if ($showForm): ?>
        <a href="departments.php" class="btn btn-secondary">← Torna alla lista</a>
    <?php else: ?>
        <a href="?action=new" class="btn btn-primary">+ Nuovo Reparto</a>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($showForm): ?>
    <!-- Form creazione / modifica -->
    <div class="form-card" style="max-width:720px;">
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $editDepartment ? 'update' : 'create' ?>">
            <?php if ($editDepartment): ?>
                <input type="hidden" name="department_id" value="<?= $editDepartment['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Nome reparto *</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($editDepartment['name'] ?? '') ?>"
                           placeholder="es. Ufficio Tecnico">
                </div>
                <div class="form-group">
                    <label for="code">Codice *</label>
                    <input type="text" id="code" name="code" required maxlength="20"
                           value="<?= e($editDepartment['code'] ?? '') ?>"
                           placeholder="es. TEC" style="text-transform:uppercase;">
                    <small>Codice breve identificativo (3-5 caratteri).</small>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="description">Descrizione</label>
                    <textarea id="description" name="description" rows="3"
                              placeholder="Breve descrizione del reparto..."><?= e($editDepartment['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editDepartment ? 'Aggiorna reparto' : 'Crea reparto' ?>
                </button>
                <a href="departments.php" class="btn btn-secondary">Annulla</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- Lista reparti -->
    <?php if (empty($departments)): ?>
        <div class="card">
            <div class="card-body empty-state" style="padding:3rem 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="48" height="48" style="opacity:0.25;margin-bottom:0.75rem;">
                    <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10z"/>
                </svg>
                <p style="margin:0 0 1rem;color:var(--muted);">Non hai ancora creato nessun reparto.</p>
                <a href="?action=new" class="btn btn-primary">+ Crea il primo reparto</a>
            </div>
        </div>
    <?php else: ?>
        <div class="dept-grid">
            <?php foreach ($departments as $dept): ?>
                <article class="card dept-card <?= $dept['is_active'] ? '' : 'is-inactive' ?>">
                    <div class="dept-head">
                        <div class="dept-head-main">
                            <span class="dept-code-pill"><?= e($dept['code']) ?></span>
                            <h3 class="dept-name"><?= e($dept['name']) ?></h3>
                        </div>
                        <span class="badge <?= $dept['is_active'] ? 'badge-success' : 'badge-light' ?>">
                            <?= $dept['is_active'] ? 'Attivo' : 'Inattivo' ?>
                        </span>
                    </div>

                    <p class="dept-desc">
                        <?= $dept['description'] ? e($dept['description']) : '<span style="color:var(--muted-2);">Nessuna descrizione</span>' ?>
                    </p>

                    <div class="dept-stats">
                        <div class="dept-stat">
                            <span class="dept-stat-value"><?= (int)$dept['admin_count'] ?></span>
                            <span class="dept-stat-label">Admin</span>
                        </div>
                        <div class="dept-stat">
                            <span class="dept-stat-value"><?= (int)$dept['employee_count'] ?></span>
                            <span class="dept-stat-label">Dipendenti</span>
                        </div>
                    </div>

                    <div class="dept-actions">
                        <a href="department-detail.php?id=<?= $dept['id'] ?>" class="btn btn-sm btn-primary">Gestisci</a>
                        <a href="departments.php?edit=<?= $dept['id'] ?>" class="btn btn-sm btn-secondary">Modifica</a>
                        <form method="POST" style="margin:0;display:inline;">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline">
                                <?= $dept['is_active'] ? 'Disattiva' : 'Attiva' ?>
                            </button>
                        </form>
                        <?php if ($dept['employee_count'] == 0 && $dept['admin_count'] == 0): ?>
                            <form method="POST" style="margin:0;display:inline;" onsubmit="return confirm('Eliminare questo reparto?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger btn-soft">Elimina</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<style>
/* Stili specifici della pagina reparti, allineati al design system */
.dept-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
.dept-card {
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    transition: border-color var(--t-base), box-shadow var(--t-base);
}
.dept-card.is-inactive { opacity: 0.65; }
.dept-card:hover { border-color: var(--accent); }
.dept-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}
.dept-head-main { display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; flex: 1; }
.dept-code-pill {
    display: inline-flex;
    align-items: center;
    background: var(--accent-soft);
    color: var(--accent-dark);
    border: 1px solid var(--accent-border);
    border-radius: var(--r-sm);
    padding: 1px 7px;
    font-family: var(--font-mono);
    font-size: 0.7rem;
    font-weight: 600;
    width: fit-content;
    letter-spacing: 0.04em;
}
.dept-name {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--ink);
    margin: 0;
    letter-spacing: -0.02em;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dept-desc {
    color: var(--muted);
    font-size: 0.85rem;
    margin: 0;
    min-height: 36px;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dept-stats {
    display: flex;
    gap: 1.25rem;
    padding: 0.75rem 0;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.dept-stat { display: flex; flex-direction: column; gap: 0; }
.dept-stat-value {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1;
    letter-spacing: -0.02em;
}
.dept-stat-label {
    font-size: 0.7rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 500;
    margin-top: 3px;
}
.dept-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }
</style>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
