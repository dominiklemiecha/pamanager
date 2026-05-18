<?php
/**
 * Configurazione SMTP - Admin
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$message = '';
$error = '';
$testResult = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $existing = Settings::getSmtpConfig();
        $passwordInput = $_POST['smtp_password'] ?? '';
        // Mantieni password corrente se il campo è vuoto
        $passwordToSave = ($passwordInput === '') ? $existing['password'] : $passwordInput;

        $data = [
            'smtp_enabled'    => isset($_POST['smtp_enabled']) ? '1' : '0',
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_encryption' => in_array($_POST['smtp_encryption'] ?? '', ['none', 'tls', 'ssl'], true) ? $_POST['smtp_encryption'] : 'tls',
            'smtp_username'   => trim($_POST['smtp_username'] ?? ''),
            'smtp_password'   => $passwordToSave,
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'PAManager'),
        ];

        if ($data['smtp_enabled'] === '1') {
            if (empty($data['smtp_host']) || empty($data['smtp_from_email'])) {
                $error = 'Per abilitare SMTP host e email mittente sono obbligatori.';
            } elseif (!filter_var($data['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Email mittente non valida.';
            }
        }

        if (!$error) {
            Settings::setMany($data, $user['id']);
            AuditLog::log('smtp_settings_updated', 'admin', $user['id']);
            $message = 'Impostazioni SMTP salvate.';
        }
    } elseif ($action === 'test') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Inserisci un indirizzo email valido per il test.';
        } else {
            $ok = Mailer::send(
                $testEmail,
                $user['name'],
                'Test SMTP - PAManager',
                '<p>Email di test inviata da PAManager.</p><p>Se la stai leggendo la configurazione SMTP funziona correttamente.</p>',
                "Email di test inviata da PAManager.\nSe la stai leggendo la configurazione SMTP funziona correttamente."
            );
            if ($ok) {
                $testResult = 'Email di test inviata con successo a ' . htmlspecialchars($testEmail);
            } else {
                $error = 'Invio fallito: ' . htmlspecialchars(Mailer::getLastError() ?? 'errore sconosciuto');
            }
        }
    }
}

$smtp = Settings::getSmtpConfig();
$pageTitle = 'Configurazione · Email / SMTP';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($testResult): ?>
        <div class="alert alert-success"><?= htmlspecialchars($testResult) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="cfg-card">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="save">

        <h3>Server SMTP</h3>
        <p class="desc">Configura un server SMTP per inviare notifiche email (reset password, comunicazioni, allegati).</p>

        <label class="cfg-toggle" style="margin-bottom: 18px;">
            <input type="checkbox" name="smtp_enabled" value="1" <?= $smtp['enabled'] ? 'checked' : '' ?>>
            <span>Abilita invio email tramite SMTP</span>
        </label>

        <div class="cfg-grid cols-3">
            <div class="cfg-fg">
                <label for="smtp_host">Host SMTP</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="es. smtp.gmail.com">
            </div>
            <div class="cfg-fg">
                <label for="smtp_port">Porta</label>
                <input type="number" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars((string)$smtp['port']) ?>" min="1" max="65535">
            </div>
            <div class="cfg-fg">
                <label for="smtp_encryption">Cifratura</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <option value="tls" <?= $smtp['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl" <?= $smtp['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                    <option value="none" <?= $smtp['encryption'] === 'none' ? 'selected' : '' ?>>Nessuna</option>
                </select>
            </div>
        </div>

        <div class="cfg-grid cols-2" style="margin-top: 14px;">
            <div class="cfg-fg">
                <label for="smtp_username">Username</label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?= htmlspecialchars($smtp['username']) ?>" autocomplete="off">
            </div>
            <div class="cfg-fg">
                <label for="smtp_password">Password</label>
                <input type="password" id="smtp_password" name="smtp_password" placeholder="<?= !empty($smtp['password']) ? '•••••• (lascia vuoto per non modificare)' : '' ?>" autocomplete="new-password">
            </div>
        </div>

        <div class="cfg-grid cols-2" style="margin-top: 14px;">
            <div class="cfg-fg">
                <label for="smtp_from_email">Email mittente</label>
                <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= htmlspecialchars($smtp['from_email']) ?>" placeholder="noreply@comune.it">
            </div>
            <div class="cfg-fg">
                <label for="smtp_from_name">Nome mittente</label>
                <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= htmlspecialchars($smtp['from_name']) ?>">
            </div>
        </div>

        <div class="cfg-actions">
            <button type="submit" class="cfg-btn cfg-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Salva impostazioni
            </button>
        </div>
    </form>

    <div class="cfg-card">
        <h3>Test invio email</h3>
        <p class="desc">Salva prima le impostazioni, poi invia un'email di prova.</p>
        <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="test">
            <div class="cfg-fg" style="flex:1; min-width:240px; margin:0;">
                <label for="test_email">Indirizzo email destinatario</label>
                <input type="email" id="test_email" name="test_email" required value="<?= htmlspecialchars($user['email'] ?? '') ?>">
            </div>
            <button type="submit" class="cfg-btn cfg-btn-ghost">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Invia Email di Test
            </button>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
