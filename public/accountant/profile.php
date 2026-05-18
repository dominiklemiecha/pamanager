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
    }
}

if (($_GET['message'] ?? '') === 'updated')  $message = 'Profilo aggiornato.';
if (($_GET['message'] ?? '') === 'password') $message = 'Password cambiata.';

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
