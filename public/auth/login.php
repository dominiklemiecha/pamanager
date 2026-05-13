<?php
/**
 * Login Admin/Commercialista
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

// Redirect se gia loggato
if (Auth::isUserLoggedIn()) {
    $user = Auth::getUser();
    $redirect = match($user['role']) {
        'admin' => PUBLIC_URL . '/admin/',
        'admin_reparto' => PUBLIC_URL . '/admin-reparto/',
        'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
        default => PUBLIC_URL . '/accountant/'
    };
    header('Location: ' . $redirect);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $rateLimitKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!checkRateLimit($rateLimitKey, 5, 300)) {
        $error = 'Troppi tentativi. Riprova tra qualche minuto.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Inserisci username e password';
        } else {
            $result = Auth::loginUser($username, $password);

            if ($result['success']) {
                $redirect = match($result['user']['role']) {
                    'admin' => PUBLIC_URL . '/admin/',
                    'admin_reparto' => PUBLIC_URL . '/admin-reparto/',
                    'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
                    default => PUBLIC_URL . '/accountant/'
                };
                header('Location: ' . $redirect);
                exit;
            }

            $error = $result['error'];
        }
    }
}

$pageTitle = 'Accesso Amministrazione - PAManager';
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
                <p>Accesso Amministrazione</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <?php echo $csrfField; ?>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required
                           autocomplete="username" autofocus
                           value="<?php echo $usernameValue; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Accedi</button>

                <div style="text-align: center; margin-top: 1rem;">
                    <a href="<?= PUBLIC_URL ?>/auth/forgot-password.php" style="font-size: 0.85rem; color: #4a5568;">
                        Hai dimenticato la password?
                    </a>
                </div>
            </form>

            <div class="login-footer">
                <a href="<?= PUBLIC_URL ?>/" class="back-link">Torna alla home</a>
            </div>
        </div>

        <div class="login-info">
            <p>Accesso riservato al personale amministrativo.</p>
            <p>Se hai dimenticato le credenziali, contatta l'amministratore di sistema.</p>
        </div>
    </div>
</body>
</html>
