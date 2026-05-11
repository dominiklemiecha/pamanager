<?php
/**
 * Reset Password - Conferma nuova password
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// Verifica token
$tokenData = Auth::verifyResetToken($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenData) {
    CSRF::verifyOrDie();

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password !== $confirmPassword) {
        $error = 'Le password non coincidono';
    } else {
        $result = Auth::completePasswordReset($token, $password);

        if ($result['success']) {
            $success = true;
        } else {
            $error = $result['error'] ?? implode(', ', $result['errors'] ?? ['Errore sconosciuto']);
        }
    }
}

$pageTitle = 'Reimposta Password - PAManager';
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
        .password-requirements {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
        }
        .password-requirements h4 {
            margin: 0 0 0.5rem;
            font-size: 0.85rem;
            color: #4a5568;
        }
        .password-requirements ul {
            margin: 0;
            padding-left: 1.25rem;
            color: #718096;
        }
        .password-requirements li {
            margin-bottom: 0.25rem;
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
        .error-box {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }
        .error-box svg {
            width: 48px;
            height: 48px;
            color: #e53e3e;
            margin-bottom: 1rem;
        }
        .error-box h3 {
            color: #c53030;
            margin: 0 0 0.5rem;
        }
        .error-box p {
            color: #9b2c2c;
            margin: 0;
            font-size: 0.9rem;
        }
        .show-password {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #718096;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>PAManager</h1>
                <p>Reimposta Password</p>
            </div>

            <?php if ($success):
                // Determina il login corretto in base al tipo utente
                $isEmployee = ($tokenData['user_type'] ?? '') === 'employee';
                $loginUrl = $isEmployee
                    ? PUBLIC_URL . '/auth/login-employee.php'
                    : PUBLIC_URL . '/auth/login.php';
                $loginLabel = $isEmployee ? 'Accedi come Dipendente' : 'Vai al Login';
            ?>
                <div class="success-box">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <h3>Password Aggiornata</h3>
                    <p>La tua password è stata reimpostata con successo. Ora puoi accedere con la nuova password.</p>
                </div>
                <div style="margin-top: 1.5rem; text-align: center;">
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-primary"><?= htmlspecialchars($loginLabel) ?></a>
                </div>
            <?php elseif (!$tokenData): ?>
                <div class="error-box">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <h3>Link Non Valido</h3>
                    <p>Questo link per il reset password non è valido o è scaduto. Richiedi un nuovo link di reset.</p>
                </div>
                <div style="margin-top: 1.5rem; text-align: center;">
                    <a href="<?= PUBLIC_URL ?>/auth/forgot-password.php" class="btn btn-primary">Richiedi Nuovo Reset</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <p style="margin-bottom: 1rem; color: #4a5568; font-size: 0.9rem;">
                    Ciao <strong><?= htmlspecialchars($tokenData['user_name'] ?? 'Utente') ?></strong>, inserisci la tua nuova password.
                </p>

                <div class="password-requirements">
                    <h4>Requisiti password:</h4>
                    <ul>
                        <li>Almeno 12 caratteri</li>
                        <li>Almeno una lettera maiuscola</li>
                        <li>Almeno una lettera minuscola</li>
                        <li>Almeno un numero</li>
                        <li>Almeno un carattere speciale (!@#$%^&*)</li>
                    </ul>
                </div>

                <form method="POST" class="login-form">
                    <?= CSRF::field() ?>

                    <div class="form-group">
                        <label for="password">Nuova Password</label>
                        <input type="password" id="password" name="password" required
                               autocomplete="new-password" minlength="12">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Conferma Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required
                               autocomplete="new-password" minlength="12">
                    </div>

                    <label class="show-password">
                        <input type="checkbox" id="showPassword">
                        Mostra password
                    </label>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: 1rem;">Reimposta Password</button>
                </form>
            <?php endif; ?>

            <div class="login-footer">
                <?php
                $footerLoginUrl = (($tokenData['user_type'] ?? '') === 'employee')
                    ? PUBLIC_URL . '/auth/login-employee.php'
                    : PUBLIC_URL . '/auth/login.php';
                ?>
                <a href="<?= htmlspecialchars($footerLoginUrl) ?>" class="back-link">Torna al login</a>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('showPassword')?.addEventListener('change', function() {
        const type = this.checked ? 'text' : 'password';
        document.getElementById('password').type = type;
        document.getElementById('confirm_password').type = type;
    });
    </script>
</body>
</html>
