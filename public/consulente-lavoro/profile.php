<?php
/**
 * Profilo Admin/Consulente/Accountant - modifica nome, email, password
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser(['admin', 'accountant', 'consulente_lavoro']);

$user = Auth::getUser();
$userId = (int) $user['id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            $error = 'Nome obbligatorio';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email non valida';
        } else {
            try {
                Database::update('users',
                    ['name' => $name, 'email' => ($email !== '' ? $email : null)],
                    'id = ?', [$userId]
                );
                Auth::refreshUser();
                header('Location: profile.php?message=updated');
                exit;
            } catch (Throwable $e) {
                $error = 'Errore aggiornamento: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $error = 'Le password non coincidono';
        } else {
            $result = Auth::changeUserPassword($userId, $current, $new);
            if ($result['success']) {
                header('Location: profile.php?message=password');
                exit;
            }
            $error = $result['error'] ?? implode(' ', $result['errors'] ?? ['Errore cambio password']);
        }
    } elseif ($action === 'wrike_connect' && $user['role'] === 'consulente_lavoro') {
        $res = Wrike::connect($userId, $_POST['wrike_token'] ?? '');
        if ($res['success']) {
            header('Location: profile.php?message=wrike_connected');
            exit;
        }
        $error = $res['error'] ?? 'Connessione Wrike fallita';
    } elseif ($action === 'wrike_settings' && $user['role'] === 'consulente_lavoro') {
        $folderId = trim($_POST['wrike_folder'] ?? '');
        $folderName = '';
        if ($folderId !== '') {
            foreach (Wrike::getFolders($userId) as $f) {
                if ($f['id'] === $folderId) { $folderName = $f['title']; break; }
            }
        }
        $res = Wrike::updateSettings($userId, $folderId ?: null, $folderName ?: null,
            !empty($_POST['wrike_on_hire']), !empty($_POST['wrike_on_chat']));
        if ($res['success']) {
            header('Location: profile.php?message=wrike_saved');
            exit;
        }
        $error = $res['error'] ?? 'Errore salvataggio impostazioni Wrike';
    } elseif ($action === 'wrike_disconnect' && $user['role'] === 'consulente_lavoro') {
        Wrike::disconnect($userId);
        header('Location: profile.php?message=wrike_disconnected');
        exit;
    }
}

if (($_GET['message'] ?? '') === 'updated')  $message = 'Profilo aggiornato.';
if (($_GET['message'] ?? '') === 'password') $message = 'Password cambiata.';
if (($_GET['message'] ?? '') === 'wrike_connected')    $message = 'Wrike collegato! Ora scegli la cartella di destinazione e gli eventi.';
if (($_GET['message'] ?? '') === 'wrike_saved')        $message = 'Impostazioni Wrike salvate.';
if (($_GET['message'] ?? '') === 'wrike_disconnected') $message = 'Wrike scollegato.';

// Stato integrazione Wrike (solo consulente)
$wrike = null;
$wrikeFolders = [];
if ($user['role'] === 'consulente_lavoro' && class_exists('Wrike')) {
    $wrike = Wrike::getForUser($userId);
    if ($wrike && !empty($wrike['token'])) {
        $wrikeFolders = Wrike::getFolders($userId);
    }
}

$pageTitle = 'Il mio profilo';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<h1 class="page-title">Il mio profilo</h1>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom: var(--sp-4);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: var(--sp-4);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4);">
    <div class="card">
        <div class="card-h"><h3>Informazioni account</h3></div>
        <div class="card-b">
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update_info">

                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="name">Nome visualizzato</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="nome@azienda.it">
                </div>
                <button type="submit" class="btn btn-primary">Salva modifiche</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-h"><h3>Cambia password</h3></div>
        <div class="card-b">
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="current_password">Password attuale</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="new_password">Nuova password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="confirm_password">Conferma nuova password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">Cambia password</button>
            </form>
        </div>
    </div>
</div>

<?php if ($user['role'] === 'consulente_lavoro'): ?>
<div class="card" style="margin-top: var(--sp-4);">
    <div class="card-h" style="display:flex; align-items:center; gap:10px;">
        <h3 style="margin:0;">Integrazione Wrike</h3>
        <?php if ($wrike && !empty($wrike['token'])): ?>
            <span style="background:#dcfce7; color:#15803d; padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700;">Collegato</span>
        <?php else: ?>
            <span style="background:#f1f5f9; color:#64748b; padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700;">Non collegato</span>
        <?php endif; ?>
    </div>
    <div class="card-b">
        <?php if (!$wrike || empty($wrike['token'])): ?>
            <p style="font-size:.88rem; color:#475569; margin:0 0 1rem;">
                Collega il tuo account Wrike: quando un'azienda ti invia una <strong>richiesta di assunzione</strong>
                (e, se vuoi, quando ricevi <strong>messaggi in chat</strong>) verra' creata automaticamente
                un'attivita' sulla tua board Wrike.
            </p>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem;">
                <div style="font-weight:700; font-size:.85rem; margin-bottom:.5rem;">Dove trovo il token? (2 minuti, una volta sola)</div>
                <ol style="margin:0; padding-left:1.2rem; font-size:.85rem; color:#475569; line-height:1.7;">
                    <li>Accedi a <a href="https://www.wrike.com" target="_blank" rel="noopener">wrike.com</a> (va bene anche l'account <strong>Free</strong>)</li>
                    <li>Clicca sulla <strong>tua foto/iniziali in alto a destra</strong> &rarr; <strong>Apps &amp; Integrations</strong></li>
                    <li>Nel menu a sinistra scegli <strong>API</strong> (sezione "My apps")</li>
                    <li>Crea una nuova app con <strong>+ App</strong> (il nome &egrave; libero, es. "Connecteed HR") oppure apri quella esistente</li>
                    <li>Scorri in fondo alla pagina fino alla sezione <strong>"Permanent access token"</strong> e clicca <strong>Get token</strong> (ti verr&agrave; chiesta la password Wrike)</li>
                    <li><strong>Copia il token</strong> appena mostrato (&egrave; una stringa molto lunga, tipo <code>eyJ0dCI6...</code>) e incollalo qui sotto. <u>Attenzione</u>: Wrike lo mostra una volta sola — se lo perdi, genera un nuovo token</li>
                </ol>
                <div style="font-size:.78rem; color:#94a3b8; margin-top:.5rem;">
                    Nota: <strong>NON</strong> servono "Client ID" e "Secret key" (quelli sono per OAuth): serve solo il <strong>Permanent access token</strong>.
                </div>
            </div>
            <form method="POST" action="profile.php" style="display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_connect">
                <div style="flex:1; min-width:280px;">
                    <label class="form-label" for="wrike_token">Permanent access token</label>
                    <input type="password" id="wrike_token" name="wrike_token" class="form-control" required
                           placeholder="Incolla qui il token copiato da Wrike" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary">Collega Wrike</button>
            </form>
        <?php else: ?>
            <p style="font-size:.88rem; margin:0 0 .35rem;">
                Collegato come <strong><?= htmlspecialchars($wrike['config']['account_name'] ?? '—') ?></strong>
                <span style="color:#94a3b8; font-size:.78rem;">(<?= htmlspecialchars(parse_url($wrike['config']['host'] ?? '', PHP_URL_HOST) ?: 'wrike.com') ?>)</span>
            </p>
            <?php if (!empty($wrike['last_error'])): ?>
                <div class="alert alert-danger" style="margin:.5rem 0; font-size:.82rem;">
                    Ultimo errore Wrike: <?= htmlspecialchars($wrike['last_error']) ?> — se il token &egrave; stato revocato, scollega e ricollega con un token nuovo.
                </div>
            <?php endif; ?>
            <form method="POST" action="profile.php" style="margin-top:.75rem;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_settings">
                <div class="form-group" style="margin-bottom: var(--sp-3); max-width:420px;">
                    <label class="form-label" for="wrike_folder">Cartella/progetto Wrike di destinazione</label>
                    <select id="wrike_folder" name="wrike_folder" class="form-control">
                        <option value="">(Radice account)</option>
                        <?php foreach ($wrikeFolders as $f): ?>
                            <option value="<?= htmlspecialchars($f['id']) ?>" <?= (($wrike['config']['folder_id'] ?? '') === $f['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; flex-direction:column; gap:.5rem; margin-bottom: var(--sp-3); font-size:.88rem;">
                    <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                        <input type="checkbox" name="wrike_on_hire" value="1" <?= !empty($wrike['config']['on_hire']) ? 'checked' : '' ?>>
                        Crea attivit&agrave; per le <strong>richieste di assunzione</strong> (nuove e aggiornate)
                    </label>
                    <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                        <input type="checkbox" name="wrike_on_chat" value="1" <?= !empty($wrike['config']['on_chat']) ? 'checked' : '' ?>>
                        Crea attivit&agrave; per i <strong>messaggi chat</strong> (una per conversazione, finch&eacute; il task resta aperto)
                    </label>
                </div>
                <div style="display:flex; gap:.6rem;">
                    <button type="submit" class="btn btn-primary">Salva impostazioni</button>
                </div>
            </form>
            <form method="POST" action="profile.php" style="margin-top:.75rem;" onsubmit="return confirm('Scollegare Wrike? Il token salvato verra' eliminato.');">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_disconnect">
                <button type="submit" class="btn" style="background:#fee2e2; color:#991b1b; border:0;">Scollega Wrike</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top: var(--sp-4); border-color: var(--danger-200, #fecaca);">
    <div class="card-h"><h3 style="color: var(--danger-700);">Sessione</h3></div>
    <div class="card-b">
        <p style="font-size: var(--text-sm); color: var(--muted); margin: 0 0 var(--sp-3);">
            Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.
        </p>
        <a href="<?= $baseUrl ?>/auth/logout.php" class="btn btn-danger">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
            Esci dall'account
        </a>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
