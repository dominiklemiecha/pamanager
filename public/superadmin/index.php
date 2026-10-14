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
            if (!empty($r['email_sent'])) {
                $message = "Tenant creato. Email di benvenuto inviata.";
            } else {
                $err = htmlspecialchars($r['email_error'] ?? 'SMTP non configurato');
                $message = "Tenant creato. <em>Email NON inviata: {$err}</em> — comunica manualmente il link primo accesso.";
            }
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
    } elseif ($action === 'set_companies') {
        $r = SuperAdmin::setAdminCompanies(
            (int)($_POST['user_id'] ?? 0),
            $_POST['company_ids'] ?? []
        );
        $r['success'] ? $message = 'Aziende assegnate aggiornate' : $error = $r['error'];
    }
}

$tenants = SuperAdmin::listAdmins();
$allCompanies = SuperAdmin::allCompanies();
?>
<!DOCTYPE html>
<html lang="it"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Superadmin — Tenant</title>
<?= CSRF::metaTag() ?>
<?php include __DIR__ . '/_ui.php'; ?></head><body>
<div class="wrap">
<header>
    <h1>Superadmin</h1>
    <nav class="nav">
        <a href="index.php" class="on">Tenant</a>
        <a href="clic.php" class="">Utilizzo HR</a>
        <a href="logout.php">Esci</a>
    </nav>
</header>

<?php if ($message): ?><div class="alert ok"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Nuovo tenant</h2>
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
            <button class="primary" type="submit">Crea</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Tenant · <?= count($tenants) ?></h2>
    <?php if (empty($tenants)): ?>
        <p class="empty">—</p>
    <?php else: ?>
    <div class="scroll"><table>
        <thead><tr>
            <th>Admin</th><th>Email</th><th>Aziende</th><th>Dipendenti</th><th>Ultimo login</th><th>Stato</th><th></th>
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
                    <details style="margin-top:6px;">
                        <summary class="tiny">Aziende</summary>
                        <form method="POST" style="margin-top:8px;">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="set_companies">
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <?php
                                $ownedIds = array_map(fn($c) => (int)$c['id'], $companies);
                                foreach ($allCompanies as $co):
                            ?>
                                <label style="display:block;font-size:12px;margin:3px 0;text-transform:none;letter-spacing:0;">
                                    <input type="checkbox" name="company_ids[]" value="<?= (int)$co['id'] ?>"
                                        <?= in_array((int)$co['id'], $ownedIds, true) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($co['name']) ?><?= $co['is_active'] ? '' : ' (disatt.)' ?>
                                </label>
                            <?php endforeach; ?>
                            <button class="icon" type="submit" style="margin-top:6px;">Salva aziende</button>
                        </form>
                    </details>
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
                            <button class="icon" title="Reinvia email primo accesso">Reinvia email</button>
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
    </table></div>
    <?php endif; ?>
</div>

</div></body></html>
