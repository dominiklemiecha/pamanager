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

/**
 * Invia email di benvenuto con credenziali al nuovo utente.
 */
function urm_send_credentials_email(string $email, string $name, string $username, string $password, string $roleLabel): array
{
    if (empty($email) || !class_exists('Mailer') || !Mailer::isConfigured()) {
        return ['sent' => false, 'error' => 'SMTP non configurato o email mancante'];
    }
    $loginUrl = function_exists('buildPublicUrl')
        ? buildPublicUrl('/auth/login.php')
        : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login.php' : '/auth/login.php');
    $nameSafe = htmlspecialchars($name);
    $usernameSafe = htmlspecialchars($username);
    $passwordSafe = htmlspecialchars($password);
    $roleSafe = htmlspecialchars($roleLabel);

    $html = "<p>Ciao {$nameSafe},</p>"
          . "<p>E' stato creato un account come <strong>{$roleSafe}</strong> nel portale PAManager. Queste sono le tue credenziali di accesso:</p>"
          . "<ul>"
          . "<li><strong>Username:</strong> {$usernameSafe}</li>"
          . "<li><strong>Password temporanea:</strong> <code>{$passwordSafe}</code></li>"
          . "</ul>"
          . "<p><a href=\"{$loginUrl}\" style=\"display:inline-block;padding:10px 20px;background:#3182ce;color:white;border-radius:6px;text-decoration:none;font-weight:600;\">Accedi al portale</a></p>"
          . "<p style=\"font-size:13px;color:#718096;\">Per motivi di sicurezza, ti consigliamo di cambiare la password al primo accesso. Non condividere queste credenziali con altre persone.</p>";

    $text = "Ciao {$name},\n\n"
          . "E' stato creato un account come {$roleLabel} nel portale PAManager.\n\n"
          . "Username: {$username}\n"
          . "Password temporanea: {$password}\n\n"
          . "Login: {$loginUrl}\n\n"
          . "Ti consigliamo di cambiare la password al primo accesso.";

    $ok = Mailer::send($email, $name, "Le tue credenziali di accesso PAManager", $html, $text);
    return $ok
        ? ['sent' => true, 'error' => null]
        : ['sent' => false, 'error' => Mailer::getLastError() ?: 'Invio email fallito'];
}

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
                $companyIds = $_POST['company_ids'] ?? [];
                if (is_array($companyIds) && !empty($companyIds)) {
                    Tenant::setUserCompanies((int)$result['id'], $companyIds);
                }
                $emailRes = urm_send_credentials_email(
                    $_POST['email'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['username'] ?? '',
                    $password,
                    $LABEL
                );
                $action = 'list';
                if ($autogen) {
                    $generatedPassword = $password;
                    $emailNote = $emailRes['sent']
                        ? " Le credenziali sono state inviate via email a <strong>" . e($_POST['email'] ?? '') . "</strong>."
                        : ($emailRes['error'] ? " <em>(Email NON inviata: " . e($emailRes['error']) . ". Comunicale manualmente.)</em>" : '');
                    $message = "{$LABEL} creato. Password generata: <code>" . e($password) . "</code> &mdash; <strong>copiala adesso, non sara' piu' visualizzata.</strong>{$emailNote}";
                } else {
                    $emailNote = $emailRes['sent']
                        ? ' Credenziali inviate via email.'
                        : ($emailRes['error'] ? " (Email NON inviata: " . e($emailRes['error']) . ")" : '');
                    header("Location: {$SELF}?message=created" . ($emailRes['sent'] ? '&email=ok' : '&email=fail'));
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
                    $companyIds = $_POST['company_ids'] ?? [];
                    Tenant::setUserCompanies($id, is_array($companyIds) ? $companyIds : []);
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
                    $u = User::getById($id);
                    $emailRes = $u ? urm_send_credentials_email(
                        $u['email'] ?? '',
                        $u['name'] ?? '',
                        $u['username'] ?? '',
                        $result['password'],
                        $LABEL
                    ) : ['sent' => false, 'error' => 'Utente non trovato'];
                    $emailNote = $emailRes['sent']
                        ? " La nuova password e' stata inviata via email a <strong>" . e($u['email'] ?? '') . "</strong>."
                        : ($emailRes['error'] ? " <em>(Email NON inviata: " . e($emailRes['error']) . ". Comunicale manualmente.)</em>" : '');
                    $message = "Nuova password generata: <code>" . e($result['password']) . "</code> &mdash; <strong>copiala adesso.</strong>{$emailNote}";
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
    $emailStatus = $_GET['email'] ?? '';
    $emailSuffix = $_GET['message'] === 'created'
        ? ($emailStatus === 'ok'
            ? ' &mdash; credenziali inviate via email.'
            : ($emailStatus === 'fail' ? ' &mdash; email <strong>NON inviata</strong>, comunica le credenziali manualmente.' : ''))
        : '';
    $messages = [
        'created' => "{$LABEL} creato con successo{$emailSuffix}",
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
/* ===== User Role Manager — design system ConnecteedHR ===== */
.urm-hero { margin-bottom: 18px; }
.urm-hero-btn {
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
.urm-hero-btn:hover { background: #082b7b; border-color: #082b7b; color: white; text-decoration: none; }

.urm-pw-banner {
    background: linear-gradient(135deg, rgba(255,187,85,0.10), rgba(255,187,85,0.04));
    border: 1px solid rgba(255,187,85,0.30);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.urm-pw-banner svg { width: 22px; height: 22px; color: #e09938; flex-shrink: 0; }
.urm-pw-banner .body { flex: 1; font-size: 13px; color: #92400e; line-height: 1.5; }
.urm-pw-banner code { background: white; padding: 3px 8px; border-radius: 6px; font-family: 'Space Grotesk',monospace; font-size: 13px; color: #0f172a; border: 1px solid rgba(255,187,85,0.30); }
.urm-pw-banner button.copy {
    background: #e09938; color: white; border: none; padding: 7px 14px;
    border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; flex-shrink: 0;
    transition: all .12s ease;
}
.urm-pw-banner button.copy:hover { background: #b45309; }

.urm-empty {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 48px 24px;
    text-align: center;
}
.urm-empty svg { width: 48px; height: 48px; color: #cbd5e0; margin-bottom: 12px; }
.urm-empty h3 { color: #475569; margin: 0 0 6px; font-size: 15px; font-weight: 700; }
.urm-empty p { color: #94a3b8; margin: 0 0 16px; font-size: 13px; }

.urm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(330px, 100%), 1fr));
    gap: 16px;
}
.urm-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px;
    display: flex; flex-direction: column;
    gap: 12px;
    transition: all .12s ease;
}
.urm-card:hover {
    transform: translateY(-2px);
    border-color: rgba(11,58,164,0.30);
    box-shadow: 0 8px 24px rgba(11,58,164,0.08);
}
.urm-card.inactive { opacity: 0.65; }

.urm-card-top {
    display: flex; align-items: flex-start; gap: 12px;
}
.urm-avatar {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4 0%, #0b3aa4 100%);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 15px;
    flex-shrink: 0;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(11,58,164,0.25);
}
.urm-card.inactive .urm-avatar { background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%); box-shadow: none; }
.urm-card-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.urm-card-info .name {
    font-family: 'Host Grotesk','Inter',sans-serif;
    font-size: 15px; font-weight: 700; color: #0f172a;
    margin: 0; line-height: 1.25; letter-spacing: -0.01em;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.urm-card-info .username { font-size: 12px; color: #94a3b8; font-family: 'Space Grotesk', monospace; }
.urm-card-status {
    display: inline-flex; align-items: center; gap: 5px;
    width: fit-content;
    padding: 2px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.urm-card-status::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.urm-card-status.on  { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.urm-card-status.off { background: rgba(100,116,139,0.10); color: #475569; }

.urm-quick { display: flex; gap: 4px; flex-shrink: 0; }
.urm-quick form { margin: 0; display: inline-flex; }
.urm-ibtn {
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #475569; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all .12s ease;
    text-decoration: none; padding: 0;
}
.urm-ibtn:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
.urm-ibtn.primary { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.urm-ibtn.primary:hover { background: #0b3aa4; color: white; }
.urm-ibtn.warn { background: rgba(255,187,85,0.08); color: #e09938; border-color: rgba(255,187,85,0.20); }
.urm-ibtn.warn:hover { background: #e09938; color: white; border-color: #e09938; }
.urm-ibtn.danger { background: rgba(247,92,108,0.08); color: #f75c6c; border-color: rgba(247,92,108,0.20); }
.urm-ibtn.danger:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.urm-ibtn svg { width: 14px; height: 14px; }

.urm-card-body {
    padding: 12px;
    background: linear-gradient(180deg, #f8fafe 0%, #f1f5fd 100%);
    border: 1px solid rgba(11,58,164,0.10);
    border-radius: 10px;
    display: flex; flex-direction: column; gap: 8px;
}
.urm-card-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.urm-card-row .ic {
    width: 28px; height: 28px; border-radius: 7px;
    background: rgba(11,58,164,0.10); color: #0b3aa4;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.urm-card-row .ic svg { width: 14px; height: 14px; }
.urm-card-row .data { flex: 1; min-width: 0; line-height: 1.2; }
.urm-card-row .lbl { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
.urm-card-row .val { font-size: 13px; color: #0f172a; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.urm-card-row .val a { color: #0b3aa4; text-decoration: none; }
.urm-card-row .val a:hover { text-decoration: underline; }
.urm-card-row .val.muted { color: #94a3b8; font-style: italic; }

/* Form */
.urm-form {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    max-width: 760px;
}
.urm-form-grid {
    display: grid; grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}
.urm-form-grid .full { grid-column: 1 / -1; }
.urm-field { display: flex; flex-direction: column; gap: 6px; }
.urm-field label {
    font-size: 11px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.urm-field label .req { color: #f75c6c; }
.urm-field input[type=text],
.urm-field input[type=email],
.urm-field input[type=password] {
    width: 100%; padding: 10px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    font-family: inherit; font-size: 14px;
    background: white;
    transition: all .12s ease;
}
.urm-field input:focus { outline: none; border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.10); }
.urm-field small { color: #94a3b8; font-size: 11px; }

.urm-toggle {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fafbfc;
    cursor: pointer; font-size: 13px; color: #0f172a;
    transition: all .12s ease;
    width: fit-content;
}
.urm-toggle:hover { border-color: rgba(11,58,164,0.30); }
.urm-toggle input { accent-color: #0b3aa4; width: 16px; height: 16px; }

.urm-companies {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr));
    gap: 8px; margin-top: 8px;
}
.urm-company-chip {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    background: white; cursor: pointer;
    font-size: 13px; color: #475569;
    transition: all .12s ease;
}
.urm-company-chip:hover { border-color: rgba(11,58,164,0.30); }
.urm-company-chip input { accent-color: #0b3aa4; }
.urm-company-chip:has(input:checked) { background: rgba(11,58,164,0.06); border-color: #0b3aa4; color: #0b3aa4; font-weight: 600; }

.urm-form-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    border-top: 1px solid #e2e8f0;
    padding-top: 18px;
}
.urm-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    border: 1px solid transparent; cursor: pointer;
    text-decoration: none; transition: all .12s ease;
}
.urm-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.urm-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.urm-btn-primary:hover { background: #0b3aa4; border-color: #0b3aa4; color: white; text-decoration: none; }
.urm-btn-ghost { background: white; color: #475569; border-color: #e2e8f0; }
.urm-btn-ghost:hover { border-color: #475569; color: #0f172a; text-decoration: none; }

@media (max-width: 640px) {
    .urm-form-grid { grid-template-columns: 1fr; }
}
</style>

<?php if ($action === 'list'): ?>
<div class="welcome-card urm-hero">
    <div>
        <h2><?= e($LABEL_PLURAL) ?></h2>
        <p>Gestisci gli accessi al portale per i <?= e(strtolower($LABEL_PLURAL)) ?>.
        <?php if (count($users) > 0): ?>
            <strong><?= count($users) ?> utent<?= count($users) === 1 ? 'e' : 'i' ?> registrat<?= count($users) === 1 ? 'o' : 'i' ?>.</strong>
        <?php else: ?>
            <strong>Nessun utente registrato.</strong>
        <?php endif; ?>
        </p>
    </div>
    <a href="?action=new" class="urm-hero-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        Nuovo <?= e($LABEL) ?>
    </a>
</div>
<?php endif; ?>

<div class="admin-page">
    <?php if ($action !== 'list'): ?>
        <div class="page-header" style="margin-bottom: 1.25rem;">
            <a href="<?= e($SELF) ?>" class="btn btn-secondary">← Torna alla lista</a>
        </div>
    <?php endif; ?>

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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <h3>Nessun <?= e(strtolower($LABEL)) ?> registrato</h3>
                <p>Crea il primo per dare accesso al portale.</p>
                <a href="?action=new" class="urm-btn urm-btn-primary">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    Nuovo <?= e($LABEL) ?>
                </a>
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
                                <span class="urm-card-status <?= $u['is_active'] ? 'on' : 'off' ?>">
                                    <?= $u['is_active'] ? 'Attivo' : 'Disattivato' ?>
                                </span>
                            </div>
                            <div class="urm-quick">
                                <a href="?action=edit&id=<?= $u['id'] ?>" class="urm-ibtn primary" title="Modifica">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </a>
                                <form method="POST" onsubmit="return confirm('Resettare la password? Verrà generata una nuova password sicura.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="urm-ibtn warn" title="Reset password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Eliminare definitivamente <?= e($u['username']) ?>? Operazione irreversibile.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="urm-ibtn danger" title="Elimina">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="urm-card-body">
                            <?php if (!empty($u['email'])): ?>
                                <div class="urm-card-row">
                                    <div class="ic">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    </div>
                                    <div class="data">
                                        <div class="lbl">Email</div>
                                        <div class="val"><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="urm-card-row">
                                <div class="ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div class="data">
                                    <div class="lbl">Ultimo accesso</div>
                                    <?php if (!empty($u['last_login'])): ?>
                                        <div class="val"><?= formatDateTime($u['last_login']) ?></div>
                                    <?php else: ?>
                                        <div class="val muted">Mai</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <form method="POST" class="urm-form" autocomplete="off">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $action === 'new' ? 'create' : 'update' ?>">

            <div class="urm-form-grid">
                <div class="urm-field">
                    <label for="username">Username <span class="req">*</span></label>
                    <input type="text" id="username" name="username" required
                           minlength="3" maxlength="50" pattern="[a-zA-Z0-9_\.]+"
                           value="<?= e($current['username'] ?? $_POST['username'] ?? '') ?>">
                    <small>Solo lettere, numeri, underscore e punti</small>
                </div>

                <div class="urm-field">
                    <label for="name">Nome completo <span class="req">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($current['name'] ?? $_POST['name'] ?? '') ?>">
                </div>

                <div class="urm-field full">
                    <label for="email">Email<?= $action === 'new' ? ' *' : '' ?></label>
                    <input type="email" id="email" name="email" maxlength="100" <?= $action === 'new' ? 'required' : '' ?>
                           value="<?= e($current['email'] ?? $_POST['email'] ?? '') ?>">
                    <small>Le credenziali di accesso verranno inviate a questa email</small>
                </div>

                <?php if ($action === 'new'): ?>
                    <div class="urm-field full">
                        <label class="urm-toggle">
                            <input type="checkbox" name="autogen_password" value="1" checked id="autogenChk" onchange="document.getElementById('pwBlock').style.display=this.checked?'none':'flex'">
                            <span>Genera password sicura automaticamente (consigliato)</span>
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
                            <span>Account attivo (l'utente può accedere al portale)</span>
                        </label>
                    </div>
                <?php endif; ?>

                <?php
                $allCompanies = Database::fetchAll("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name");
                $assignedCompanyIds = $current ? Tenant::getUserCompanyIds((int)$current['id']) : [];
                ?>
                <?php if (!empty($allCompanies)): ?>
                    <div class="urm-field full">
                        <label>Aziende assegnate</label>
                        <small>L'utente potrà vedere i dipendenti SOLO delle aziende selezionate. Se non selezioni nulla vedrà tutte le aziende.</small>
                        <div class="urm-companies">
                            <?php foreach ($allCompanies as $co): ?>
                                <label class="urm-company-chip">
                                    <input type="checkbox" name="company_ids[]" value="<?= (int)$co['id'] ?>"
                                        <?= in_array((int)$co['id'], $assignedCompanyIds, true) ? 'checked' : '' ?>>
                                    <span><?= e($co['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="urm-form-actions">
                <a href="<?= e($SELF) ?>" class="urm-btn urm-btn-ghost">Annulla</a>
                <button type="submit" class="urm-btn urm-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?= $action === 'new' ? "Crea {$LABEL}" : 'Salva modifiche' ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
