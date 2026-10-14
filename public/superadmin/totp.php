<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if (!SuperAdmin::configured()) { http_response_code(503); die('Superadmin non configurato.'); }
if (SuperAdmin::isAuthed())    { header('Location: index.php'); exit; }
if (!SuperAdmin::isPasswordOk()) { header('Location: login.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $code = preg_replace('/\s+/', '', $_POST['code'] ?? '');
    if (SuperAdmin::checkTotp($code)) {
        SuperAdmin::markFullyAuthed();
        header('Location: index.php'); exit;
    }
    usleep(800000);
    $error = 'Codice non valido o scaduto';
}
?>
<!DOCTYPE html>
<html lang="it"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Superadmin — verifica 2FA</title>
<?= CSRF::metaTag() ?>
<?php include __DIR__ . '/_ui.php'; ?></head><body class="auth">
<form method="POST" class="box" autocomplete="off">
    <?= CSRF::field() ?>
    <h1>Verifica 2FA</h1>
    <label>Codice</label>
    <input class="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required>
    <button>Verifica</button>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <p style="margin:16px 0 0;text-align:center;"><a class="plain tiny" href="logout.php">Annulla</a></p>
</form></body></html>
