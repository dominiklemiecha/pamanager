<?php
/**
 * Login Dipendenti
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

// Redirect se già loggato
if (Auth::isEmployeeLoggedIn()) {
    header('Location: ' . PUBLIC_URL . '/employee/');
    exit;
}

if (Auth::isUserLoggedIn()) {
    $user = Auth::getUser();
    $redirect = $user['role'] === 'admin' ? PUBLIC_URL . '/admin/' : PUBLIC_URL . '/accountant/';
    header('Location: ' . $redirect);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    // Rate limiting
    $rateLimitKey = 'login_employee_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!checkRateLimit($rateLimitKey, 5, 300)) {
        $error = 'Troppi tentativi. Riprova tra qualche minuto.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Inserisci username e password';
        } else {
            $result = Auth::loginEmployee($username, $password);

            if ($result['success']) {
                $mustChange = false;
                try {
                    $row = Database::fetchOne("SELECT must_change_password FROM employees WHERE id = ?", [$result['employee']['id']]);
                    $mustChange = !empty($row['must_change_password']);
                } catch (Exception $e) {
                    // Migrazione non eseguita
                }
                header('Location: ' . PUBLIC_URL . ($mustChange ? '/employee/change-password.php' : '/employee/'));
                exit;
            }

            $error = $result['error'];
        }
    }
}

$pageTitle = 'Accesso Dipendenti - PAManager';
$csrfField = CSRF::field();
$csrfMeta = CSRF::metaTag();
$usernameValue = htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <?php echo $csrfMeta; ?>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>PAManager</h1>
                <p>Accesso Dipendenti</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <?php echo $csrfField; ?>

                <div class="form-group">
                    <label for="username">Username o Codice Fiscale</label>
                    <input type="text" id="username" name="username" required
                           autocomplete="username" autofocus
                           placeholder="es. mario.rossi o RSSMRA80A01H501U"
                           value="<?php echo $usernameValue; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Accedi</button>

                <div style="text-align: center; margin-top: 1rem;">
                    <a href="<?= PUBLIC_URL ?>/auth/forgot-password.php?type=employee" style="font-size: 0.85rem; color: #4a5568;">
                        Hai dimenticato la password?
                    </a>
                </div>
            </form>

            <div class="login-footer">
                <a href="<?= PUBLIC_URL ?>/" class="back-link">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                    </svg>
                    Torna alla home
                </a>
            </div>
        </div>

    </div>
</body>
</html>
