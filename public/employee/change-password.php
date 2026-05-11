<?php
/**
 * Cambio password dipendente (sia forzato che volontario)
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();

try {
    $row = Database::fetchOne("SELECT must_change_password FROM employees WHERE id = ?", [$employee['id']]);
    $mustChange = !empty($row['must_change_password']);
} catch (Exception $e) {
    $mustChange = false;
}

$error = '';
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        $error = 'La nuova password e la conferma non coincidono';
    } else {
        $result = Auth::changeEmployeePassword($employee['id'], $current, $new);
        if (!empty($result['success'])) {
            try {
                Database::update('employees', [
                    'must_change_password' => 0,
                    'password_changed_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$employee['id']]);
            } catch (Exception $e) {
                Database::update('employees', [
                    'password_changed_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$employee['id']]);
            }
            $success = true;
            $mustChange = false;
        } else {
            if (!empty($result['errors'])) {
                $errors = $result['errors'];
            } else {
                $error = $result['error'] ?? 'Errore durante il cambio password';
            }
        }
    }
}

$pageTitle = $mustChange ? 'Imposta nuova password' : 'Cambia Password';

// Per cambio password forzato mostriamo una pagina standalone (senza sidebar) per impedire navigazione
if ($mustChange && !$success):
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - PAManager</title>
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?= time() ?>">
    <?= CSRF::metaTag() ?>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>PAManager</h1>
                <p>Primo accesso: imposta una nuova password</p>
            </div>

            <div class="alert alert-warning">
                Per motivi di sicurezza devi impostare una nuova password personale prima di poter accedere all'area dipendente.
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-error">
                    <ul style="margin:0;padding-left:1.2rem;">
                        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <?= CSRF::field() ?>
                <div class="form-group">
                    <label for="current_password">Password attuale</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="new_password">Nuova password</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                    <small class="text-muted">Minimo <?= PASSWORD_MIN_LENGTH ?> caratteri, con maiuscole, minuscole, numeri e caratteri speciali.</small>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Conferma nuova password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Imposta Password</button>
            </form>

            <div class="login-footer">
                <a href="<?= PUBLIC_URL ?>/auth/logout.php" class="back-link">Esci</a>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    return;
endif;

include dirname(__DIR__) . '/includes/header-employee.php';
?>
<div class="container">
    <div class="page-header">
        <h2>Cambia Password</h2>
        <p class="text-muted">Aggiorna la tua password di accesso. Per sicurezza ti consigliamo di cambiarla periodicamente.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">Password aggiornata con successo.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul style="margin:0;padding-left:1.2rem;">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width:520px;">
        <div class="card-body">
            <form method="POST">
                <?= CSRF::field() ?>
                <div class="form-group">
                    <label for="current_password">Password attuale</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="new_password">Nuova password</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                    <small class="text-muted">Minimo <?= PASSWORD_MIN_LENGTH ?> caratteri, con maiuscole, minuscole, numeri e caratteri speciali.</small>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Conferma nuova password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Aggiorna Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
