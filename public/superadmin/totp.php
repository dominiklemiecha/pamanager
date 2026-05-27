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
<style>
body{font-family:'Inter',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#1e293b;border:1px solid #334155;padding:32px;border-radius:12px;width:340px;text-align:center;}
h1{margin:0 0 8px;font-size:18px;color:#fff;}
p{margin:0 0 20px;font-size:13px;color:#94a3b8;}
input{width:100%;padding:14px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:22px;text-align:center;letter-spacing:.6em;font-family:'Space Grotesk',monospace;box-sizing:border-box;}
input:focus{outline:none;border-color:#0b3aa4;}
button{margin-top:18px;width:100%;padding:12px;background:#0b3aa4;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;}
button:hover{background:#082b7b;}
.err{margin-top:14px;padding:10px;background:#7f1d1d;border-radius:8px;color:#fecaca;font-size:13px;}
a{color:#94a3b8;font-size:12px;text-decoration:none;}
</style></head><body>
<form method="POST" class="box" autocomplete="off">
    <?= CSRF::field() ?>
    <h1>Verifica 2FA</h1>
    <p>Inserisci il codice a 6 cifre del tuo authenticator.</p>
    <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required>
    <button>Verifica</button>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <p style="margin-top:18px;"><a href="logout.php">Annulla</a></p>
</form></body></html>
