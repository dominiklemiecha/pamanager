<?php
/**
 * Richiesta Reset Password
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

// Redirect se già loggato
if (Auth::isUserLoggedIn()) {
    header('Location: ' . PUBLIC_URL . '/admin/');
    exit;
}

$message = '';
$error = '';
$success = false;
$isEmployee = ($_GET['type'] ?? '') === 'employee';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $rateLimitKey = 'reset_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!checkRateLimit($rateLimitKey, 3, 900)) { // 3 tentativi ogni 15 minuti
        $error = 'Troppi tentativi. Riprova tra qualche minuto.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');

        if (empty($identifier)) {
            $error = 'Inserisci username o email';
        } else {
            $result = Auth::createPasswordResetRequest($identifier);

            if ($result['success']) {
                $success = true;
                $message = 'Se l\'account esiste, l\'amministratore riceverà la tua richiesta di reset password.';
            } else {
                $error = $result['error'] ?? 'Errore durante la richiesta';
            }
        }
    }
}

$pageTitle = 'Recupero Password - PAManager';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?= time() ?>">
    <?= CSRF::metaTag() ?>
    <style>
        .reset-info {
            background: #ebf8ff;
            border: 1px solid #90cdf4;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .reset-info p {
            margin: 0;
            font-size: 0.85rem;
            color: #2b6cb0;
        }
        .success-box {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }
        .success-box svg {
            width: 48px;
            height: 48px;
            color: #38a169;
            margin-bottom: 1rem;
        }
        .success-box h3 {
            color: #276749;
            margin: 0 0 0.5rem;
        }
        .success-box p {
            color: #2f855a;
            margin: 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>PAManager</h1>
                <p>Recupero Password</p>
            </div>

            <?php if ($success): ?>
                <div class="success-box">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <h3>Richiesta Inviata</h3>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
                <div style="margin-top: 1.5rem; text-align: center;">
                    <a href="<?= PUBLIC_URL ?>/auth/login.php" class="btn btn-primary">Torna al Login</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="reset-info">
                    <p>
                        <?php if ($isEmployee): ?>
                            Inserisci il tuo username, email o codice fiscale. Se l'account esiste, l'amministratore riceverà una notifica e potrà inviarti un link per reimpostare la password.
                        <?php else: ?>
                            Inserisci il tuo username o email. Se l'account esiste, l'amministratore riceverà una notifica e potrà inviarti un link per reimpostare la password.
                        <?php endif; ?>
                    </p>
                </div>

                <form method="POST" class="login-form">
                    <?= CSRF::field() ?>
                    <?php if ($isEmployee): ?>
                        <input type="hidden" name="user_type" value="employee">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="identifier"><?= $isEmployee ? 'Username, Email o Codice Fiscale' : 'Username o Email' ?></label>
                        <input type="text" id="identifier" name="identifier" required
                               autocomplete="username" autofocus
                               placeholder="<?= $isEmployee ? 'es. mario.rossi o RSSMRA80A01H501U' : 'Inserisci username o email' ?>"
                               value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Richiedi Reset Password</button>
                </form>
            <?php endif; ?>

            <div class="login-footer">
                <a href="<?= PUBLIC_URL ?>/auth/login.php" class="back-link">Torna al login</a>
            </div>
        </div>
    </div>
</body>
</html>
