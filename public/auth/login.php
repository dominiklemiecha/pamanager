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
    <meta name="theme-color" content="#1a365d">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PAManager">
    <link rel="apple-touch-icon" href="<?= PUBLIC_URL ?>/assets/images/icon.php?size=180&v=4">
    <link rel="manifest" href="<?= PUBLIC_URL ?>/manifest.json.php">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <?php echo $csrfMeta; ?>
    <style>
    .pwa-install {
        margin-top: 1.25rem;
    }
    .pwa-install-btn {
        width: 100%;
        padding: 0.85rem 1.25rem;
        border: 2px dashed rgba(255,255,255,0.5);
        border-radius: 12px;
        background: rgba(255,255,255,0.1);
        color: white;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        transition: all 0.25s;
        backdrop-filter: blur(10px);
    }
    .pwa-install-btn:hover {
        background: rgba(255,255,255,0.2);
        border-color: rgba(255,255,255,0.85);
        transform: translateY(-2px);
    }
    .pwa-install-btn svg { width: 18px; height: 18px; }

    .ios-install-guide {
        background: rgba(255,255,255,0.12);
        border-radius: 14px;
        padding: 1.1rem 1.1rem 1rem;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.18);
    }
    .ios-install-title {
        display: flex; align-items: center; justify-content: center;
        gap: 0.5rem; color: white; font-weight: 600;
        font-size: 0.95rem; margin-bottom: 0.85rem;
    }
    .ios-install-title svg { width: 18px; height: 18px; }
    .ios-install-steps { display: flex; flex-direction: column; gap: 0.5rem; }
    .ios-step {
        display: flex; align-items: center; gap: 0.7rem;
        background: rgba(255,255,255,0.1);
        padding: 0.55rem 0.8rem; border-radius: 10px;
        color: white; font-size: 0.8rem; line-height: 1.4;
    }
    .ios-step-num {
        width: 22px; height: 22px; background: #3182ce;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 0.72rem; flex-shrink: 0;
    }
    .ios-step strong { color: #90cdf4; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
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

        </div>

        <div class="login-info">
            <p>Accesso unico per dipendenti, amministratori, commercialisti e consulenti del lavoro.</p>
            <p>Se hai dimenticato le credenziali, usa "Hai dimenticato la password?" oppure contatta l'amministratore.</p>
        </div>

        <!-- PWA install (Android/Desktop) -->
        <div id="pwaInstallContainer" class="pwa-install" style="display:none;">
            <button id="pwaInstallBtn" class="pwa-install-btn" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Installa l'app sul dispositivo
            </button>
        </div>

        <!-- PWA install iOS guide -->
        <div id="iosInstallHint" class="pwa-install" style="display:none;">
            <div class="ios-install-guide">
                <div class="ios-install-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/></svg>
                    Installa su iPhone/iPad
                </div>
                <div class="ios-install-steps">
                    <div class="ios-step"><span class="ios-step-num">1</span><span>Tocca <strong>Condividi</strong> in basso</span></div>
                    <div class="ios-step"><span class="ios-step-num">2</span><span>Scorri e tocca <strong>Aggiungi a Home</strong></span></div>
                    <div class="ios-step"><span class="ios-step-num">3</span><span>Tocca <strong>Aggiungi</strong> in alto a destra</span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true
            || document.referrer.includes('android-app://');

        let deferredPrompt = null;
        const pwaContainer = document.getElementById('pwaInstallContainer');
        const pwaBtn = document.getElementById('pwaInstallBtn');
        const iosHint = document.getElementById('iosInstallHint');

        if (isStandalone) return; // gia installato

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            if (pwaContainer) pwaContainer.style.display = 'block';
        });

        if (pwaBtn) {
            pwaBtn.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                try {
                    await deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    if (outcome === 'accepted' && pwaContainer) pwaContainer.style.display = 'none';
                } catch (err) { console.error(err); }
                deferredPrompt = null;
            });
        }

        window.addEventListener('appinstalled', () => {
            if (pwaContainer) pwaContainer.style.display = 'none';
            deferredPrompt = null;
        });

        if (isIOS && iosHint) {
            iosHint.style.display = 'block';
        }

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?= PUBLIC_URL ?>/sw.js', { scope: '<?= PUBLIC_URL ?>/' }).catch(() => {});
        }
    })();
    </script>
</body>
</html>
