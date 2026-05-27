<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if (!SuperAdmin::configured()) { http_response_code(503); die('Superadmin non configurato.'); }
SuperAdmin::requireAuth();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $r = SuperAdmin::createTenant(
            $_POST['company_name'] ?? '',
            $_POST['admin_name'] ?? '',
            $_POST['admin_email'] ?? '',
            $_POST['admin_username'] ?? ''
        );
        if ($r['success']) {
            $emailNote = !empty($r['email_sent']) ? ' Email di benvenuto inviata.' : ' <em>Email NON inviata: comunicare manualmente il link primo accesso.</em>';
            $message = "Tenant creato.{$emailNote}";
        } else {
            $error = $r['error'];
        }
    } elseif ($action === 'deactivate') {
        $r = SuperAdmin::deactivateAdmin((int)($_POST['user_id'] ?? 0));
        $r['success'] ? $message = 'Tenant disattivato' : $error = $r['error'];
    } elseif ($action === 'activate') {
        $r = SuperAdmin::activateAdmin((int)($_POST['user_id'] ?? 0));
        $r['success'] ? $message = 'Tenant riattivato' : $error = $r['error'];
    } elseif ($action === 'delete') {
        if (($_POST['confirm'] ?? '') !== 'ELIMINA') {
            $error = 'Conferma mancante o errata';
        } else {
            $r = SuperAdmin::deleteAdmin((int)($_POST['user_id'] ?? 0));
            $r['success'] ? $message = 'Tenant eliminato definitivamente' : $error = $r['error'];
        }
    } elseif ($action === 'resend_welcome') {
        $r = SuperAdmin::resendWelcome((int)($_POST['user_id'] ?? 0));
        $r['success'] ? $message = 'Email primo accesso reinviata' : $error = $r['error'];
    }
}

$tenants = SuperAdmin::listAdmins();
?>
<!DOCTYPE html>
<html lang="it"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Superadmin — Tenant</title>
<?= CSRF::metaTag() ?>
<style>
*{box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;}
.wrap{max-width:1200px;margin:0 auto;}
header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
h1{margin:0;font-size:20px;color:#fff;}
.logout{color:#94a3b8;text-decoration:none;font-size:13px;}
.logout:hover{color:#fff;}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px;}
.alert.ok{background:#064e3b;border:1px solid #047857;color:#a7f3d0;}
.alert.err{background:#7f1d1d;border:1px solid #b91c1c;color:#fecaca;}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px;margin-bottom:20px;}
.card h2{margin:0 0 14px;font-size:15px;color:#fff;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
label{display:block;font-size:11px;color:#94a3b8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;}
input{width:100%;padding:10px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:14px;}
input:focus{outline:none;border-color:#0b3aa4;}
button.primary{background:#0b3aa4;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;}
button.primary:hover{background:#082b7b;}
button.icon{background:transparent;border:1px solid #334155;color:#cbd5e1;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:12px;}
button.icon:hover{background:#334155;color:#fff;}
button.icon.danger{border-color:#7f1d1d;color:#fca5a5;}
button.icon.danger:hover{background:#7f1d1d;color:#fff;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:10px;background:#0f172a;color:#94a3b8;font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.04em;border-bottom:1px solid #334155;}
td{padding:12px 10px;border-bottom:1px solid #334155;color:#e2e8f0;}
tr:hover td{background:#0f172a;}
.status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600;}
.status.on{background:#064e3b;color:#a7f3d0;}
.status.off{background:#7f1d1d;color:#fecaca;}
.tiny{font-size:11px;color:#94a3b8;}
form.inline{display:inline-block;margin:0;}
</style></head><body>
<div class="wrap">
<header>
    <h1>PAManager — Superadmin</h1>
    <a href="logout.php" class="logout">Logout</a>
</header>

<?php if ($message): ?><div class="alert ok"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Crea nuovo tenant</h2>
    <form method="POST">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid">
            <div>
                <label>Nome azienda</label>
                <input name="company_name" required maxlength="100" placeholder="Acme S.r.l.">
            </div>
            <div>
                <label>Nome admin</label>
                <input name="admin_name" required maxlength="100" placeholder="Mario Rossi">
            </div>
            <div>
                <label>Email admin</label>
                <input type="email" name="admin_email" required maxlength="100" placeholder="mario@acme.it">
            </div>
            <div>
                <label>Username admin</label>
                <input name="admin_username" required pattern="[a-zA-Z0-9_.]{3,50}" maxlength="50" placeholder="mario.rossi">
            </div>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end;">
            <button class="primary" type="submit">Crea tenant + invia email</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Tenant esistenti (<?= count($tenants) ?>)</h2>
    <?php if (empty($tenants)): ?>
        <p class="tiny">Nessun tenant ancora creato.</p>
    <?php else: ?>
    <table>
        <thead><tr>
            <th>Admin tenant</th><th>Email</th><th>Aziende</th><th>Dipendenti</th><th>Ultimo login</th><th>Stato</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tenants as $t):
            $companies = $t['companies'] ?? [];
            $companyNames = array_map(fn($c) => $c['name'], $companies);
        ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($t['name'] ?? '—') ?></strong>
                    <div class="tiny">@<?= htmlspecialchars($t['username'] ?? '') ?> &middot; <?= empty($t['company_id']) ? 'admin globale' : 'admin tenant #' . (int)$t['company_id'] ?></div>
                </td>
                <td><?= htmlspecialchars($t['email'] ?? '—') ?></td>
                <td>
                    <strong><?= count($companies) ?></strong>
                    <?php if (!empty($companyNames)): ?>
                        <div class="tiny"><?= htmlspecialchars(implode(', ', $companyNames)) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= (int)($t['employees_count'] ?? 0) ?></td>
                <td><?= !empty($t['last_login']) ? htmlspecialchars($t['last_login']) : '<span class="tiny">Mai</span>' ?></td>
                <td>
                    <span class="status <?= $t['is_active'] ? 'on' : 'off' ?>">
                        <?= $t['is_active'] ? 'Attivo' : 'Disattivato' ?>
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <?php if (empty($t['last_login'])): ?>
                        <form class="inline" method="POST" onsubmit="return confirm('Reinviare email primo accesso?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="resend_welcome">
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <button class="icon" title="Reinvia email primo accesso">↻ email</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($t['is_active']): ?>
                        <form class="inline" method="POST" onsubmit="return confirm('Disattivare il tenant? L\'admin e tutte le sue aziende verranno disattivati.')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <button class="icon" title="Disattiva">Disattiva</button>
                        </form>
                    <?php else: ?>
                        <form class="inline" method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <button class="icon" title="Riattiva">Riattiva</button>
                        </form>
                    <?php endif; ?>

                    <form class="inline" method="POST" onsubmit="var c=prompt('Per eliminare DEFINITIVAMENTE l\'admin e TUTTE le sue <?= count($companies) ?> aziende, scrivi: ELIMINA'); if(c===null) return false; this.confirm.value=c; return true;">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="confirm" value="">
                        <button class="icon danger" title="Elimina definitivamente">Elimina</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</div></body></html>
