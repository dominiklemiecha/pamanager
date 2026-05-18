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

// Stats per banner
$__dpTotal = count($departments);
$__dpActive = 0; $__dpEmployees = 0;
foreach ($departments as $d) {
    if (!empty($d['is_active'])) $__dpActive++;
    $__dpEmployees += (int) ($d['employee_count'] ?? 0);
}

$pageTitle = $editDepartment ? 'Modifica Reparto' : ($showForm ? 'Nuovo Reparto' : 'Gestione Reparti');
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
/* ===== Reparti — design system ConnecteedHR ===== */
.dp-hero {
    margin-bottom: 18px;
}
.dp-hero-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px;
    background: #0b3aa4;
    border: 1px solid #0b3aa4;
    border-radius: 10px;
    color: white; font-weight: 600; font-size: 13px;
    text-decoration: none;
    backdrop-filter: blur(8px);
    transition: all .12s ease;
}
.dp-hero-btn:hover {
    background: #082b7b;
    border-color: #082b7b;
    color: white; text-decoration: none;
}

.dp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.dp-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    display: flex; flex-direction: column;
    gap: 14px;
    transition: all .12s ease;
    position: relative;
}
.dp-card:hover {
    border-color: rgba(11,58,164,0.30);
    box-shadow: 0 8px 24px rgba(11,58,164,0.08);
    transform: translateY(-2px);
}
.dp-card.is-inactive { opacity: 0.65; }
.dp-card.is-inactive .dp-code { background: #f1f5f9; color: #64748b; }

.dp-head {
    display: flex; align-items: flex-start; gap: 14px;
}
.dp-quick { display: flex; gap: 4px; flex-shrink: 0; }
.dp-quick form { margin: 0; display: inline-flex; }
.dp-code {
    width: 52px; height: 52px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0b3aa4 0%, #0b3aa4 100%);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Space Grotesk', monospace;
    font-size: 14px; font-weight: 700;
    letter-spacing: 0.04em;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(11,58,164,0.25);
}
.dp-head-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.dp-name {
    font-family: 'Host Grotesk','Inter',sans-serif;
    font-size: 16px; font-weight: 700;
    color: var(--ink); margin: 0;
    letter-spacing: -0.01em;
    overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap;
}
.dp-status {
    display: inline-flex; align-items: center; gap: 5px;
    width: fit-content;
    padding: 2px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.dp-status::before {
    content: ""; width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}
.dp-status.active { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.dp-status.inactive { background: rgba(100,116,139,0.10); color: #475569; }

.dp-desc {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
    margin: 0;
    min-height: 38px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dp-desc.empty { font-style: italic; color: #94a3b8; }

.dp-stats {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    padding: 12px;
    background: linear-gradient(180deg, #f8fafe 0%, #f1f5fd 100%);
    border-radius: 10px;
    border: 1px solid rgba(11,58,164,0.10);
}
.dp-stat { display: flex; align-items: center; gap: 10px; }
.dp-stat-ic {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: rgba(11,58,164,0.10);
    color: #0b3aa4;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.dp-stat-ic svg { width: 16px; height: 16px; }
.dp-stat-info { display: flex; flex-direction: column; line-height: 1.1; }
.dp-stat-v {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 18px; font-weight: 700;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}
.dp-stat-l {
    font-size: 10px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.05em;
    font-weight: 600;
    margin-top: 2px;
}

.dp-actions {
    display: flex; gap: 6px;
    border-top: 1px solid var(--border);
    padding-top: 14px;
    margin-top: auto;
}
.dp-btn {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 8px;
    font-family: inherit; font-size: 12px; font-weight: 600;
    border: 1px solid transparent;
    text-decoration: none; cursor: pointer;
    transition: all .12s ease;
}
.dp-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.dp-btn-primary:hover { background: #0b3aa4; color: white; text-decoration: none; }
.dp-btn-ghost { background: white; color: #475569; border-color: var(--border); }
.dp-btn-ghost:hover { border-color: #0b3aa4; color: #0b3aa4; text-decoration: none; }
.dp-btn svg { width: 14px; height: 14px; }

.dp-iconbtn {
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: white;
    color: #475569; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all .12s ease;
    flex-shrink: 0;
    text-decoration: none;
    padding: 0;
}
.dp-iconbtn:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
.dp-iconbtn.primary { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.dp-iconbtn.primary:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.dp-iconbtn.danger { background: rgba(247,92,108,0.08); color: #f75c6c; border-color: rgba(247,92,108,0.20); }
.dp-iconbtn.danger:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.dp-iconbtn svg { width: 14px; height: 14px; }

.dp-empty {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 48px 24px;
    text-align: center;
}
.dp-empty svg { width: 48px; height: 48px; color: #cbd5e0; margin-bottom: 12px; }
.dp-empty p { margin: 0 0 16px; color: var(--muted); font-size: 14px; }

/* Form */
.dp-form {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px;
    max-width: 720px;
}
.dp-form-grid {
    display: grid; grid-template-columns: 1fr 200px; gap: 16px;
    margin-bottom: 16px;
}
.dp-fg { display: flex; flex-direction: column; gap: 6px; }
.dp-fg.full { grid-column: 1 / -1; }
.dp-fg label {
    font-size: 11px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.dp-fg label .req { color: #f75c6c; }
.dp-fg input, .dp-fg textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit; font-size: 14px;
    background: white;
    transition: all .12s ease;
}
.dp-fg input:focus, .dp-fg textarea:focus {
    outline: none; border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}
.dp-fg textarea { resize: vertical; min-height: 80px; }
.dp-fg small { font-size: 11px; color: var(--muted); }
.dp-form-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    border-top: 1px solid var(--border);
    padding-top: 16px; margin-top: 4px;
}

@media (max-width: 540px) {
    .dp-form-grid { grid-template-columns: 1fr; }
    .dp-stats { grid-template-columns: 1fr; }
}
</style>

<!-- Banner -->
<?php if (!$showForm): ?>
<div class="welcome-card dp-hero">
    <div>
        <h2>Reparti</h2>
        <p>Gestisci la struttura organizzativa della tua azienda.
        <?php if ($__dpTotal > 0): ?>
            <strong><?= $__dpActive ?> attiv<?= $__dpActive === 1 ? 'o' : 'i' ?></strong> su <?= $__dpTotal ?> · <?= $__dpEmployees ?> dipendenti assegnati.
        <?php else: ?>
            <strong>Nessun reparto creato.</strong>
        <?php endif; ?>
        </p>
    </div>
    <a href="?action=new" class="dp-hero-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        Nuovo Reparto
    </a>
</div>
<?php endif; ?>

<div class="admin-page">
    <?php if ($showForm): ?>
        <div class="page-header" style="margin-bottom: 1.25rem;">
            <a href="departments.php" class="btn btn-secondary">← Torna alla lista</a>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <!-- Form creazione / modifica -->
        <form method="POST" class="dp-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $editDepartment ? 'update' : 'create' ?>">
            <?php if ($editDepartment): ?>
                <input type="hidden" name="department_id" value="<?= $editDepartment['id'] ?>">
            <?php endif; ?>

            <div class="dp-form-grid">
                <div class="dp-fg">
                    <label for="name">Nome reparto <span class="req">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($editDepartment['name'] ?? '') ?>"
                           placeholder="es. Ufficio Tecnico">
                </div>
                <div class="dp-fg">
                    <label for="code">Codice <span class="req">*</span></label>
                    <input type="text" id="code" name="code" required maxlength="20"
                           value="<?= e($editDepartment['code'] ?? '') ?>"
                           placeholder="es. TEC" style="text-transform:uppercase;">
                    <small>3–5 caratteri identificativi.</small>
                </div>
                <div class="dp-fg full">
                    <label for="description">Descrizione</label>
                    <textarea id="description" name="description" rows="3"
                              placeholder="Breve descrizione del reparto…"><?= e($editDepartment['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="dp-form-actions">
                <a href="departments.php" class="dp-btn dp-btn-ghost">Annulla</a>
                <button type="submit" class="dp-btn dp-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?= $editDepartment ? 'Aggiorna reparto' : 'Crea reparto' ?>
                </button>
            </div>
        </form>

    <?php elseif (empty($departments)): ?>
        <div class="dp-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
            <p>Non hai ancora creato nessun reparto.</p>
            <a href="?action=new" class="dp-btn dp-btn-primary" style="flex:0 0 auto;">
                <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Crea il primo reparto
            </a>
        </div>
    <?php else: ?>
        <div class="dp-grid">
            <?php foreach ($departments as $dept): ?>
                <article class="dp-card <?= $dept['is_active'] ? '' : 'is-inactive' ?>">
                    <div class="dp-head">
                        <div class="dp-head-info">
                            <h3 class="dp-name"><?= e($dept['name']) ?></h3>
                            <span class="dp-status <?= $dept['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $dept['is_active'] ? 'Attivo' : 'Inattivo' ?>
                            </span>
                        </div>
                        <div class="dp-quick">
                            <a href="department-detail.php?id=<?= $dept['id'] ?>" class="dp-iconbtn primary" title="Gestisci">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                            <a href="departments.php?edit=<?= $dept['id'] ?>" class="dp-iconbtn" title="Modifica">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </a>
                            <?php if ($dept['employee_count'] == 0 && $dept['admin_count'] == 0): ?>
                                <form method="POST" onsubmit="return confirm('Eliminare definitivamente questo reparto?')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                    <button type="submit" class="dp-iconbtn danger" title="Elimina">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($dept['description'])): ?>
                        <p class="dp-desc"><?= e($dept['description']) ?></p>
                    <?php endif; ?>

                    <div class="dp-stats">
                        <div class="dp-stat">
                            <div class="dp-stat-ic">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <div class="dp-stat-info">
                                <span class="dp-stat-v"><?= (int)$dept['employee_count'] ?></span>
                                <span class="dp-stat-l">Dipendenti</span>
                            </div>
                        </div>
                        <div class="dp-stat">
                            <div class="dp-stat-ic">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div class="dp-stat-info">
                                <span class="dp-stat-v"><?= (int)$dept['admin_count'] ?></span>
                                <span class="dp-stat-l">Admin</span>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
