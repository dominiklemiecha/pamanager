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

/** Blu "Midnight" della sidebar Wrike. */
const WRIKE_NAVY = '#15243d';

/**
 * Logo Wrike ufficiale completo (simbolo + wordmark) reso in bianco,
 * pensato per stare su sfondo navy WRIKE_NAVY.
 */
function wrikeLogoSvg(int $width = 96): string {
    $h = (int) round($width / 5); // aspect ratio originale 100x20
    return '<svg width="' . $width . '" height="' . $h . '" viewBox="0 0 100 20" fill="none" role="img" aria-label="Logo di Wrike" xmlns="http://www.w3.org/2000/svg">'
         . '<path fill="#08cf65" d="M20.78 1.404C21.885.298 22.587 0 24.113 0h6.878c.561 0 .684.509.35.842l-11.49 11.491c-.176.176-.246.21-.352.246-.035.018-.087.018-.122.018s-.088 0-.123-.018c-.106-.035-.176-.07-.351-.246L14.85 8.281c-.175-.176-.21-.246-.245-.351-.018-.035-.018-.088-.018-.123s0-.088.018-.123c.035-.105.07-.175.245-.35l5.93-5.93zM10.745 8.649C9.64 7.544 8.92 7.263 7.395 7.263H.534c-.562 0-.685.509-.351.842l11.49 11.492c.176.175.246.21.352.245a.299.299 0 00.123.018c.035 0 .087 0 .122-.018.105-.035.176-.07.351-.245l4.053-4.07c.175-.176.21-.246.245-.351a.3.3 0 00.018-.123c0-.035 0-.088-.018-.123-.035-.105-.07-.175-.245-.351l-5.93-5.93z"/>'
         . '<path fill="#ffffff" d="M71.064 4.72a1.965 1.965 0 100-3.93 1.965 1.965 0 000 3.93zm1.579 1.578h-3.158v11.035h3.158V6.298zm-9.877 11.035V12.37c0-3 2.649-2.948 4.035-2.72V6.263c-2.21-.193-3.526.421-4.123 1.614h-.07l.017-1.561h-3.07v11.018h3.21zm-22.685 0h2.474l3.79-7.087 3.666 7.087h2.509l5.632-11.035h-3.737l-3.456 7.035-3.281-7.035h-2.684l-3.456 7.07-3.281-7.07H34.52l5.561 11.035zm36.053 0h2l3.298-4.158 2.79 4.158h3.72l-4.387-6.386 3.842-4.649h-3.701l-4.386 5.544h-.07L79.275.79h-3.14v16.544zm18.228-2.368c1.351 0 2.158-.72 2.544-1.298l2.421 1.667c-.982 1.28-2.509 2.28-5.035 2.28-3.386 0-5.912-2.544-5.912-5.754 0-3.228 2.579-5.755 5.912-5.755 3.403 0 5.702 2.562 5.702 5.755v.877h-8.58c.246 1.316 1.37 2.228 2.948 2.228zm2.58-4.421c-.352-1.158-1.37-1.965-2.825-1.965-1.492 0-2.492.807-2.843 1.965h5.667z"/>'
         . '</svg>';
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
<?php $wrikeConnected = $wrike && !empty($wrike['token']); ?>

<h2 style="font-size:1rem; font-weight:700; color:#334155; margin:var(--sp-4) 0 .6rem;">Integrazioni</h2>
<div style="display:flex; flex-wrap:wrap; gap:var(--sp-3);">
    <!-- Tile integrazione: logo + nome, apre il modal -->
    <button type="button" id="wrikeTile" onclick="document.getElementById('wrikeModal').showModal()"
            style="display:flex; align-items:center; gap:16px; width:100%; max-width:440px; text-align:left; cursor:pointer;
                   background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px 18px;
                   transition:border-color .15s, box-shadow .15s; box-shadow:0 1px 2px rgba(16,24,40,.04);">
        <span style="flex:none; height:42px; padding:0 13px; border-radius:10px; background:<?= WRIKE_NAVY ?>; display:flex; align-items:center; justify-content:center;">
            <?= wrikeLogoSvg(70) ?>
        </span>
        <span style="flex:1 1 auto; min-width:0;">
            <span style="display:block; font-weight:700; font-size:.95rem; color:#0f172a; white-space:nowrap;">Integrazione Wrike</span>
            <span style="display:block; font-size:.78rem; color:#64748b;">Crea attività dalle assunzioni e chat</span>
        </span>
        <?php if ($wrikeConnected): ?>
            <span style="flex:none; display:inline-flex; align-items:center; gap:5px; background:#dcfce7; color:#15803d; padding:3px 9px; border-radius:999px; font-size:.7rem; font-weight:700;">
                <span style="width:6px; height:6px; border-radius:50%; background:#16a34a;"></span>Collegato
            </span>
        <?php else: ?>
            <span style="flex:none; background:#f1f5f9; color:#64748b; padding:3px 9px; border-radius:999px; font-size:.7rem; font-weight:700;">Configura</span>
        <?php endif; ?>
    </button>
</div>
<style>
    #wrikeTile:hover { border-color:#08CF65; box-shadow:0 4px 12px rgba(8,207,101,.12); }
    #wrikeModal { border:0; border-radius:16px; padding:0; width:92vw; max-width:560px; box-shadow:0 20px 60px rgba(0,0,0,.25); position:fixed; inset:0; margin:auto; }
    #wrikeModal::backdrop { background:rgba(15,23,42,.5); backdrop-filter:blur(2px); }
    #wrikeModal .wm-head { display:flex; align-items:center; gap:12px; padding:18px 20px; border-bottom:1px solid #eef0f4; }
    #wrikeModal .wm-body { padding:20px; max-height:70vh; overflow-y:auto; }
    #wrikeModal .wm-close { margin-left:auto; background:none; border:0; cursor:pointer; color:#94a3b8; font-size:1.5rem; line-height:1; padding:4px; }
    #wrikeModal .wm-close:hover { color:#475569; }
    #wrikeModal ol { margin:0; padding-left:1.2rem; font-size:.85rem; color:#475569; line-height:1.7; }
    #wrikeModal .form-control { width:100%; }
</style>
<dialog id="wrikeModal">
    <div class="wm-head">
        <span style="flex:none; height:40px; padding:0 14px; border-radius:9px; background:<?= WRIKE_NAVY ?>; display:flex; align-items:center; justify-content:center;"><?= wrikeLogoSvg(78) ?></span>
        <div>
            <div style="font-weight:700; font-size:1.02rem;">Integrazione Wrike</div>
            <div style="font-size:.78rem; color:#94a3b8;">
                <?= $wrikeConnected ? 'Collegato come ' . htmlspecialchars($wrike['config']['account_name'] ?? '—') : 'Non ancora collegato' ?>
            </div>
        </div>
        <button type="button" class="wm-close" onclick="document.getElementById('wrikeModal').close()" aria-label="Chiudi">&times;</button>
    </div>
    <div class="wm-body">
        <?php if (!$wrikeConnected): ?>
            <p style="font-size:.88rem; color:#475569; margin:0 0 1rem;">
                Quando un'azienda ti invia una <strong>richiesta di assunzione</strong> (e, se vuoi, quando ricevi
                <strong>messaggi in chat</strong>) viene creata automaticamente un'attività sulla tua board Wrike,
                che si aggiorna a ogni passaggio fino alla firma del contratto.
            </p>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem;">
                <div style="font-weight:700; font-size:.85rem; margin-bottom:.5rem;">Dove trovo il token? (2 minuti, una volta sola)</div>
                <ol>
                    <li>Accedi a <a href="https://www.wrike.com" target="_blank" rel="noopener">wrike.com</a> (va bene anche l'account <strong>Free</strong>)</li>
                    <li>Clicca sulla <strong>tua foto/iniziali in alto a destra</strong> &rarr; <strong>Apps &amp; Integrations</strong></li>
                    <li>Nel menu a sinistra scegli <strong>API</strong> (sezione "My apps")</li>
                    <li>Crea una nuova app con <strong>+ App</strong> (nome libero, es. "Connecteed HR") oppure apri quella esistente</li>
                    <li>Scorri in fondo fino a <strong>"Permanent access token"</strong> e clicca <strong>Get token</strong> (ti verrà chiesta la password Wrike)</li>
                    <li><strong>Copia il token</strong> mostrato (stringa lunga tipo <code>eyJ0dCI6...</code>) e incollalo qui sotto. <u>Attenzione</u>: Wrike lo mostra una volta sola</li>
                </ol>
                <div style="font-size:.78rem; color:#94a3b8; margin-top:.5rem;">
                    Nota: <strong>NON</strong> servono "Client ID" e "Secret key" (sono per OAuth): serve solo il <strong>Permanent access token</strong>.
                </div>
            </div>
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_connect">
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="wrike_token">Permanent access token</label>
                    <input type="password" id="wrike_token" name="wrike_token" class="form-control" required
                           placeholder="Incolla qui il token copiato da Wrike" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Collega Wrike</button>
            </form>
        <?php else: ?>
            <?php if (!empty($wrike['last_error'])): ?>
                <div class="alert alert-danger" style="margin:0 0 1rem; font-size:.82rem;">
                    Ultimo errore Wrike: <?= htmlspecialchars($wrike['last_error']) ?> — se il token è stato revocato, scollega e ricollega con un token nuovo.
                </div>
            <?php endif; ?>
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_settings">
                <div class="form-group" style="margin-bottom: var(--sp-3);">
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
                <div style="display:flex; flex-direction:column; gap:.6rem; margin-bottom: var(--sp-3); font-size:.88rem;">
                    <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                        <input type="checkbox" name="wrike_on_hire" value="1" <?= !empty($wrike['config']['on_hire']) ? 'checked' : '' ?>>
                        Attività per le <strong>richieste di assunzione</strong>
                    </label>
                    <label style="display:flex; gap:8px; align-items:center; cursor:pointer;">
                        <input type="checkbox" name="wrike_on_chat" value="1" <?= !empty($wrike['config']['on_chat']) ? 'checked' : '' ?>>
                        Attività per i <strong>messaggi chat</strong> (una per conversazione)
                    </label>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Salva impostazioni</button>
            </form>
            <form method="POST" action="profile.php" style="margin-top:.75rem;" onsubmit="return confirm('Scollegare Wrike? Il token salvato verrà eliminato.');">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wrike_disconnect">
                <button type="submit" class="btn" style="width:100%; background:#fee2e2; color:#991b1b; border:0;">Scollega Wrike</button>
            </form>
        <?php endif; ?>
    </div>
</dialog>
<?php if (($wrikeConnected && !empty($wrike['last_error'])) || (isset($_GET['message']) && strpos($_GET['message'], 'wrike') === 0 && $error)): ?>
    <script>document.getElementById('wrikeModal').showModal();</script>
<?php endif; ?>
<?php if ($error && in_array($_POST['action'] ?? '', ['wrike_connect','wrike_settings'], true)): ?>
    <script>document.getElementById('wrikeModal').showModal();</script>
<?php endif; ?>
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
