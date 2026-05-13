<?php
/**
 * Partial condiviso per le pagine "gestione utenti per ruolo" (accountant, consulente_lavoro).
 *
 * Variabili richieste in input (dal file chiamante):
 *   $ROLE         string  — slug del ruolo (es. 'accountant')
 *   $LABEL        string  — etichetta singolare (es. 'Commercialista')
 *   $LABEL_PLURAL string  — etichetta plurale (es. 'Commercialisti')
 *   $SELF         string  — filename della pagina chiamante (es. 'accountant.php')
 *   $LIST_FN      callable — funzione che ritorna la lista utenti del ruolo
 *   $ICON_PATH    string  — path SVG dell'icona
 */

$user   = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id     = isset($_POST['id']) && $_POST['id'] !== ''
    ? (int) $_POST['id']
    : (isset($_GET['id']) ? (int) $_GET['id'] : null);
$message = '';
$error   = '';
$generatedPassword = null;

// ---- Azioni POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $postAction = $_POST['action'] ?? '';

    switch ($postAction) {
        case 'create':
            $autogen = !empty($_POST['autogen_password']);
            $password = $autogen ? Auth::generateSecurePassword() : ($_POST['password'] ?? '');

            $result = User::create([
                'username' => $_POST['username'] ?? '',
                'password' => $password,
                'name'     => $_POST['name'] ?? '',
                'email'    => $_POST['email'] ?? '',
                'role'     => $ROLE,
            ]);

            if ($result['success']) {
                if ($autogen) {
                    $generatedPassword = $password;
                    $action  = 'list';
                    $message = "{$LABEL} creato. Password generata: <code>" . e($password) . "</code> &mdash; <strong>copiala adesso, non sara' piu' visualizzata.</strong>";
                } else {
                    header("Location: {$SELF}?message=created");
                    exit;
                }
            } else {
                $error  = $result['error'];
                $action = 'new';
            }
            break;

        case 'update':
            if ($id) {
                $result = User::update($id, [
                    'username'  => $_POST['username'] ?? '',
                    'name'      => $_POST['name'] ?? '',
                    'email'     => $_POST['email'] ?? '',
                    'is_active' => isset($_POST['is_active']),
                ]);
                if ($result['success']) {
                    header("Location: {$SELF}?message=updated");
                    exit;
                }
                $error  = $result['error'];
                $action = 'edit';
            }
            break;

        case 'delete':
            if ($id) {
                $result = User::delete($id);
                if ($result['success']) {
                    header("Location: {$SELF}?message=deleted");
                    exit;
                }
                $error = $result['error'];
            }
            break;

        case 'reset_password':
            if ($id) {
                $result = User::resetPassword($id);
                if ($result['success']) {
                    $generatedPassword = $result['password'];
                    $message = "Nuova password generata: <code>" . e($result['password']) . "</code> &mdash; <strong>comunicala al {$LABEL}, non sara' piu' visualizzata.</strong>";
                } else {
                    $error = $result['error'];
                }
            }
            break;

        case 'toggle_active':
            if ($id) {
                $u = User::getById($id);
                if ($u) {
                    $r = $u['is_active'] ? User::deactivate($id) : User::activate($id);
                    if ($r['success']) {
                        header("Location: {$SELF}?message=updated");
                        exit;
                    }
                    $error = $r['error'];
                }
            }
            break;
    }
}

// ---- Messaggi GET ----
if (isset($_GET['message']) && !$message) {
    $messages = [
        'created' => "{$LABEL} creato con successo",
        'updated' => "{$LABEL} aggiornato",
        'deleted' => "{$LABEL} eliminato",
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// ---- Carica dati ----
$current = null;
$users   = [];

if ($action === 'list') {
    $users = call_user_func($LIST_FN);
} elseif (($action === 'edit' || $action === 'view') && $id) {
    $current = User::getById($id);
    if (!$current || $current['role'] !== $ROLE) {
        header("Location: {$SELF}?error=not_found");
        exit;
    }
}

$pageTitle = $action === 'new'  ? "Nuovo {$LABEL}"
           : ($action === 'edit' ? "Modifica {$LABEL}"
           : "Gestione {$LABEL_PLURAL}");

include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.urm-wrap { max-width: 1100px; margin: 0 auto; }
.urm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.urm-header-left { display: flex; align-items: center; gap: 0.75rem; }
.urm-header-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: white;
    flex-shrink: 0;
}
.urm-header-icon svg { width: 22px; height: 22px; }
.urm-header h1 { margin: 0; font-size: 1.15rem; color: #2d3748; }
.urm-header .sub { font-size: 0.8rem; color: #a0aec0; }

.urm-pw-banner {
    background: #fffbeb;
    border: 1px solid #fbd38d;
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.urm-pw-banner svg { width: 22px; height: 22px; color: #d69e2e; flex-shrink: 0; }
.urm-pw-banner .body { flex: 1; font-size: 0.85rem; color: #744210; line-height: 1.5; }
.urm-pw-banner code { background: white; padding: 3px 8px; border-radius: 5px; font-family: 'SF Mono', Monaco, monospace; font-size: 0.85rem; color: #2d3748; border: 1px solid #fbd38d; }
.urm-pw-banner button.copy {
    background: #d69e2e; color: white; border: none; padding: 6px 12px;
    border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; flex-shrink: 0;
}
.urm-pw-banner button.copy:hover { background: #b7791f; }

.urm-empty {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 3rem 1.5rem;
    text-align: center;
}
.urm-empty svg { width: 56px; height: 56px; color: #cbd5e0; margin-bottom: 0.75rem; }
.urm-empty h3 { color: #4a5568; margin: 0 0 0.4rem; font-size: 1rem; }
.urm-empty p { color: #a0aec0; margin: 0 0 1rem; font-size: 0.85rem; }

.urm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));
    gap: 0.75rem;
}
.urm-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
    transition: transform .15s, box-shadow .15s;
    display: flex;
    flex-direction: column;
}
.urm-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
.urm-card.inactive { opacity: 0.7; }
.urm-card-top {
    padding: 1rem 1.1rem;
    display: flex; gap: 0.75rem;
    border-bottom: 1px solid #edf2f7;
}
.urm-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1rem;
    flex-shrink: 0;
    text-transform: uppercase;
}
.urm-card.inactive .urm-avatar { background: linear-gradient(135deg, #a0aec0 0%, #718096 100%); }
.urm-card-info { flex: 1; min-width: 0; }
.urm-card-info .name {
    font-size: 0.95rem; font-weight: 600; color: #2d3748;
    margin: 0; line-height: 1.3;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.urm-card-info .username { font-size: 0.72rem; color: #a0aec0; font-family: 'SF Mono', Monaco, monospace; margin-top: 2px; }
.urm-card-status { font-size: 0.62rem; padding: 3px 9px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; height: fit-content; }
.urm-card-status.on  { background: #c6f6d5; color: #22543d; }
.urm-card-status.off { background: #fed7d7; color: #742a2a; }

.urm-card-body { padding: 0.75rem 1.1rem; flex: 1; }
.urm-card-row { display: flex; gap: 0.5rem; font-size: 0.78rem; margin-bottom: 0.35rem; }
.urm-card-row:last-child { margin-bottom: 0; }
.urm-card-row .lbl { color: #a0aec0; min-width: 80px; flex-shrink: 0; }
.urm-card-row .val { color: #4a5568; word-break: break-word; }
.urm-card-row a { color: #3182ce; text-decoration: none; }

.urm-card-actions {
    display: flex;
    gap: 0.4rem;
    padding: 0.75rem 1.1rem;
    background: #f7fafc;
    border-top: 1px solid #edf2f7;
    flex-wrap: wrap;
}
.urm-card-actions form { margin: 0; }
.urm-btn {
    padding: 6px 11px;
    border-radius: 6px;
    border: none;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
    color: white;
    transition: filter .15s;
}
.urm-btn:hover { filter: brightness(0.93); }
.urm-btn.primary { background: #3182ce; }
.urm-btn.warn    { background: #d69e2e; }
.urm-btn.toggle  { background: #718096; }
.urm-btn.toggle.on { background: #48bb78; }
.urm-btn.danger  { background: #e53e3e; }

/* Form */
.urm-form-card {
    background: white; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    padding: 1.5rem;
    max-width: 720px;
}
.urm-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem 1.25rem;
}
.urm-form-grid .full { grid-column: 1 / -1; }
.urm-field label { display: block; font-size: 0.72rem; color: #4a5568; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.35rem; }
.urm-field input[type=text],
.urm-field input[type=email],
.urm-field input[type=password] {
    width: 100%; padding: 0.55rem 0.75rem;
    border: 1px solid #e2e8f0; border-radius: 7px;
    font-size: 0.9rem;
    transition: border-color .15s, box-shadow .15s;
}
.urm-field input:focus { outline: none; border-color: #3182ce; box-shadow: 0 0 0 3px rgba(49,130,206,0.12); }
.urm-field small { color: #a0aec0; font-size: 0.7rem; display: block; margin-top: 0.3rem; }
.urm-toggle { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0; }
.urm-toggle input { width: 18px; height: 18px; }
.urm-toggle label { margin: 0 !important; font-size: 0.85rem !important; text-transform: none !important; color: #2d3748 !important; font-weight: 500 !important; letter-spacing: 0 !important; }

.urm-form-actions {
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid #edf2f7;
    display: flex; gap: 0.5rem; justify-content: flex-end;
}
.urm-form-actions .btn { padding: 0.55rem 1.2rem; border-radius: 7px; border: none; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.urm-form-actions .btn-primary   { background: #3182ce; color: white; }
.urm-form-actions .btn-primary:hover { background: #2c5282; }
.urm-form-actions .btn-secondary { background: #edf2f7; color: #2d3748; }
.urm-form-actions .btn-secondary:hover { background: #e2e8f0; }

@media (max-width: 640px) {
    .urm-form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="urm-wrap">
    <div class="urm-header">
        <div class="urm-header-left">
            <div class="urm-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="<?= $ICON_PATH ?>"/></svg>
            </div>
            <div>
                <h1>
                    <?php if ($action === 'list'): ?>
                        <?= e($LABEL_PLURAL) ?>
                    <?php elseif ($action === 'new'): ?>
                        Nuovo <?= e($LABEL) ?>
                    <?php else: ?>
                        Modifica <?= e($LABEL) ?>
                    <?php endif; ?>
                </h1>
                <div class="sub">
                    <?php if ($action === 'list'): ?>
                        <?= count($users) ?> utent<?= count($users) === 1 ? 'e' : 'i' ?> registrat<?= count($users) === 1 ? 'o' : 'i' ?>
                    <?php else: ?>
                        <a href="<?= e($SELF) ?>" style="color:#3182ce;text-decoration:none;">← Torna alla lista</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($action === 'list'): ?>
            <a href="?action=new" class="urm-btn primary" style="padding:9px 16px;font-size:.85rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/></svg>
                Nuovo <?= e($LABEL) ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <?php if ($generatedPassword): ?>
            <div class="urm-pw-banner">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                <div class="body">
                    <?= $message ?>
                </div>
                <button type="button" class="copy" id="urmCopyPwBtn" data-pw="<?= e($generatedPassword) ?>">Copia password</button>
            </div>
            <script>
                (function(){
                    var btn = document.getElementById('urmCopyPwBtn');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        var pw = btn.getAttribute('data-pw') || '';
                        navigator.clipboard.writeText(pw).then(function(){
                            btn.textContent = 'Copiata!';
                            setTimeout(function(){ btn.textContent = 'Copia password'; }, 2000);
                        }).catch(function(){
                            btn.textContent = 'Errore copia';
                            setTimeout(function(){ btn.textContent = 'Copia password'; }, 2000);
                        });
                    });
                })();
            </script>
        <?php else: ?>
            <div class="alert alert-success" style="border-radius:10px;"><?= $message ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="border-radius:10px;"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <?php if (empty($users)): ?>
            <div class="urm-empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <h3>Nessun <?= e(strtolower($LABEL)) ?> registrato</h3>
                <p>Crea il primo per dare accesso al portale.</p>
                <a href="?action=new" class="urm-btn primary" style="padding:9px 16px;font-size:.85rem;">+ Nuovo <?= e($LABEL) ?></a>
            </div>
        <?php else: ?>
            <div class="urm-grid">
                <?php foreach ($users as $u):
                    $initials = strtoupper(substr($u['name'] ?? $u['username'], 0, 2));
                ?>
                    <div class="urm-card <?= !$u['is_active'] ? 'inactive' : '' ?>">
                        <div class="urm-card-top">
                            <div class="urm-avatar"><?= e($initials) ?></div>
                            <div class="urm-card-info">
                                <p class="name"><?= e($u['name']) ?></p>
                                <div class="username">@<?= e($u['username']) ?></div>
                            </div>
                            <span class="urm-card-status <?= $u['is_active'] ? 'on' : 'off' ?>">
                                <?= $u['is_active'] ? 'Attivo' : 'Disattivato' ?>
                            </span>
                        </div>
                        <div class="urm-card-body">
                            <?php if (!empty($u['email'])): ?>
                                <div class="urm-card-row">
                                    <span class="lbl">Email</span>
                                    <span class="val"><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></span>
                                </div>
                            <?php endif; ?>
                            <div class="urm-card-row">
                                <span class="lbl">Ultimo accesso</span>
                                <span class="val">
                                    <?= !empty($u['last_login']) ? formatDateTime($u['last_login']) : '<span style="color:#cbd5e0;">Mai</span>' ?>
                                </span>
                            </div>
                            <?php if (!empty($u['created_at'])): ?>
                                <div class="urm-card-row">
                                    <span class="lbl">Creato il</span>
                                    <span class="val"><?= formatDate($u['created_at']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="urm-card-actions">
                            <a href="?action=edit&id=<?= $u['id'] ?>" class="urm-btn primary">Modifica</a>
                            <form method="POST" onsubmit="return confirm('Resettare la password? Verra' generata una nuova password sicura.')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="urm-btn warn">Reset password</button>
                            </form>
                            <form method="POST">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="urm-btn toggle <?= $u['is_active'] ? 'on' : '' ?>">
                                    <?= $u['is_active'] ? 'Disattiva' : 'Attiva' ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Eliminare definitivamente <?= e($u['username']) ?>? Operazione irreversibile.')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="urm-btn danger">Elimina</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <form method="POST" class="urm-form-card" autocomplete="off">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $action === 'new' ? 'create' : 'update' ?>">

            <div class="urm-form-grid">
                <div class="urm-field">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required
                           minlength="3" maxlength="50" pattern="[a-zA-Z0-9_\.]+"
                           value="<?= e($current['username'] ?? $_POST['username'] ?? '') ?>">
                    <small>Solo lettere, numeri, underscore e punti</small>
                </div>

                <div class="urm-field">
                    <label for="name">Nome completo *</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($current['name'] ?? $_POST['name'] ?? '') ?>">
                </div>

                <div class="urm-field full">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="100"
                           value="<?= e($current['email'] ?? $_POST['email'] ?? '') ?>">
                    <small>Usata per reset password e notifiche</small>
                </div>

                <?php if ($action === 'new'): ?>
                    <div class="urm-field full">
                        <label class="urm-toggle">
                            <input type="checkbox" name="autogen_password" value="1" checked id="autogenChk" onchange="document.getElementById('pwBlock').style.display=this.checked?'none':'block'">
                            <span>Genera automaticamente una password sicura (consigliato)</span>
                        </label>
                    </div>

                    <div class="urm-field full" id="pwBlock" style="display:none;">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" minlength="8" maxlength="100" autocomplete="new-password">
                        <small>Minimo 8 caratteri con maiuscole, minuscole, numeri e simboli</small>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'edit'): ?>
                    <div class="urm-field full">
                        <label class="urm-toggle">
                            <input type="checkbox" name="is_active" <?= $current['is_active'] ? 'checked' : '' ?>>
                            <span>Account attivo (l'utente puo' accedere al portale)</span>
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="urm-form-actions">
                <a href="<?= e($SELF) ?>" class="btn btn-secondary">Annulla</a>
                <button type="submit" class="btn btn-primary">
                    <?= $action === 'new' ? "Crea {$LABEL}" : 'Salva modifiche' ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
