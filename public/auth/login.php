<?php
/**
 * Login unificato per tutti i ruoli (admin, accountant, admin_reparto,
 * consulente_lavoro, employee).
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

function login_redirect_for(string $type, array $entity): string
{
    if ($type === 'employee') return PUBLIC_URL . '/employee/';
    return match($entity['role'] ?? '') {
        'admin'             => PUBLIC_URL . '/admin/',
        'admin_reparto'     => PUBLIC_URL . '/admin-reparto/',
        'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
        'accountant'        => PUBLIC_URL . '/accountant/',
        default             => PUBLIC_URL . '/',
    };
}

// Redirect se gia loggato
if (Auth::isUserLoggedIn()) {
    header('Location: ' . login_redirect_for('user', Auth::getUser()));
    exit;
}
if (Auth::isEmployeeLoggedIn()) {
    header('Location: ' . login_redirect_for('employee', Auth::getEmployee()));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $rateLimitKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!checkRateLimit($rateLimitKey, 5, 300)) {
        $error = 'Troppi tentativi. Riprova tra qualche minuto.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Inserisci username e password';
        } else {
            // Determina se l'username esiste in users o employees (priorita users)
            $userRow = Database::fetchOne("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
            $empRow  = !$userRow ? Database::fetchOne(
                "SELECT id FROM employees WHERE username = ? OR fiscal_code = ? LIMIT 1",
                [$username, strtoupper($username)]
            ) : null;

            if ($userRow) {
                $result = Auth::loginUser($username, $password);
                if (!empty($result['success'])) {
                    if (!empty($result['requires_mfa'])) {
                        header('Location: ' . PUBLIC_URL . '/auth/mfa-verify.php');
                        exit;
                    }
                    header('Location: ' . login_redirect_for('user', $result['user']));
                    exit;
                }
                $error = $result['error'] ?? 'Credenziali non valide';
            } elseif ($empRow) {
                $result = Auth::loginEmployee($username, $password);
                if (!empty($result['success'])) {
                    if (!empty($result['requires_mfa'])) {
                        header('Location: ' . PUBLIC_URL . '/auth/mfa-verify.php');
                        exit;
                    }
                    header('Location: ' . login_redirect_for('employee', $result['employee'] ?? []));
                    exit;
                }
                $error = $result['error'] ?? 'Credenziali non valide';
            } else {
                // Nessun account con questo username — risposta generica per non rivelare esistenza
                $error = 'Credenziali non valide';
            }
        }
    }
}

$pageTitle = 'Accesso - PAManager';
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
                <p>Accedi al portale aziendale</p>
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
            <p>Accesso unico per dipendenti, amministratori, commercialisti e consulenti del lavoro.</p>
            <p>Se hai dimenticato le credenziali, usa "Hai dimenticato la password?" oppure contatta l'amministratore.</p>
        </div>
    </div>
</body>
</html>
