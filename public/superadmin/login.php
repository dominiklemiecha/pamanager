<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if (!SuperAdmin::configured()) {
    http_response_code(503);
    if (isset($_GET['check'])) {
        $keys = ['SUPERADMIN_USER', 'SUPERADMIN_PASS_HASH', 'SUPERADMIN_TOTP_SECRET'];
        header('Content-Type: text/plain');
        foreach ($keys as $k) {
            $g = getenv($k);
            $e = $_ENV[$k] ?? null;
            $s = $_SERVER[$k] ?? null;
            echo "$k: getenv=" . ($g === false ? 'NO' : 'YES(' . strlen((string)$g) . ')')
               . " \$_ENV=" . ($e === null ? 'NO' : 'YES(' . strlen((string)$e) . ')')
               . " \$_SERVER=" . ($s === null ? 'NO' : 'YES(' . strlen((string)$s) . ')')
               . "\n";
        }
        exit;
    }
    die('Superadmin non configurato. Visita ?check=1 per diagnostica, oppure esegui tools/superadmin-init.php e imposta le variabili ENV.');
}

if (SuperAdmin::isAuthed()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (SuperAdmin::checkPassword($u, $p)) {
        SuperAdmin::markPasswordOk();
        header('Location: totp.php'); exit;
    }
    // Rate-limit basico: sleep 1s ad ogni fallimento
    usleep(800000);
    $error = 'Credenziali non valide';
}

$pageTitle = 'Superadmin Login';
?>
<!DOCTYPE html>
<html lang="it"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $pageTitle ?></title>
<?= CSRF::metaTag() ?>
<style>
body{font-family:'Inter',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#1e293b;border:1px solid #334155;padding:32px;border-radius:12px;width:340px;}
h1{margin:0 0 24px;font-size:18px;color:#fff;}
label{display:block;font-size:12px;margin:14px 0 6px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;}
input{width:100%;padding:10px 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:14px;box-sizing:border-box;}
input:focus{outline:none;border-color:#0b3aa4;}
button{margin-top:20px;width:100%;padding:12px;background:#0b3aa4;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;}
button:hover{background:#082b7b;}
.err{margin-top:14px;padding:10px 12px;background:#7f1d1d;border-radius:8px;color:#fecaca;font-size:13px;}
</style></head><body>
<form method="POST" class="box" autocomplete="off">
    <?= CSRF::field() ?>
    <h1>Superadmin</h1>
    <label>Username</label>
    <input name="username" autofocus required>
    <label>Password</label>
    <input type="password" name="password" required>
    <button>Accedi</button>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
</form></body></html>
