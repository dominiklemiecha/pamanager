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
    // Rispetta ?return=/path-locale se valido (solo path interni, no URL esterne)
    $ret = $_GET['return'] ?? $_POST['return'] ?? '';
    if (is_string($ret) && $ret !== '' && preg_match('#^/[a-zA-Z0-9/_\-.?=&%]*$#', $ret)) {
        return PUBLIC_URL . $ret;
    }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Host+Grotesk:wght@500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <?php echo $csrfMeta; ?>
    <style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        margin: 0;
        background: #f8f9fc;
        color: #1e1e2f;
        min-height: 100vh;
    }

    .login-wrap {
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        min-height: 100vh;
    }

    /* ============ LATO SINISTRO — hero brand ============ */
    .login-hero {
        position: relative;
        background:
            radial-gradient(ellipse 60% 80% at 100% 100%, rgba(255,255,255,0.10) 0%, transparent 70%),
            radial-gradient(ellipse 50% 60% at 0% 0%, rgba(255,255,255,0.06) 0%, transparent 70%),
            linear-gradient(135deg, #0b3aa4 0%, #082b7b 100%);
        color: white;
        padding: 56px 64px;
        display: flex; flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
    }
    .login-hero::before {
        content: ""; position: absolute; inset: 0;
        background-image:
            radial-gradient(1.4px 1.4px at 8% 16%, rgba(255,255,255,0.6), transparent 70%),
            radial-gradient(1px 1px at 22% 30%, rgba(255,255,255,0.45), transparent 70%),
            radial-gradient(1.2px 1.2px at 38% 12%, rgba(255,255,255,0.5), transparent 70%),
            radial-gradient(1px 1px at 52% 42%, rgba(255,255,255,0.4), transparent 70%),
            radial-gradient(1.5px 1.5px at 68% 22%, rgba(255,255,255,0.55), transparent 70%),
            radial-gradient(1px 1px at 80% 60%, rgba(255,255,255,0.4), transparent 70%),
            radial-gradient(1.2px 1.2px at 16% 72%, rgba(255,255,255,0.5), transparent 70%),
            radial-gradient(1px 1px at 44% 84%, rgba(255,255,255,0.4), transparent 70%),
            radial-gradient(1.4px 1.4px at 72% 92%, rgba(255,255,255,0.55), transparent 70%);
        opacity: 0.7;
        pointer-events: none;
    }
    .login-hero > * { position: relative; z-index: 1; }
    .login-brand {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 28px; font-weight: 700;
        letter-spacing: -0.025em;
        color: white;
    }
    .login-hero-body { max-width: 480px; }
    .login-hero h2 {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 44px; font-weight: 700;
        letter-spacing: -0.03em;
        line-height: 1.1; margin: 0 0 16px;
    }
    .login-hero p {
        font-size: 15px; opacity: 0.78;
        line-height: 1.6; margin: 0 0 32px;
        max-width: 460px;
    }
    .login-features { list-style: none; padding: 0; margin: 0; display: grid; gap: 14px; }
    .login-features li {
        display: flex; align-items: center; gap: 12px;
        font-size: 14px;
    }
    .login-feat-ic {
        width: 32px; height: 32px;
        border-radius: 10px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.18);
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .login-feat-ic svg { width: 16px; height: 16px; }
    .login-hero-foot {
        font-size: 12px; opacity: 0.55;
    }

    /* ============ LATO DESTRO — form ============ */
    .login-form-side {
        display: flex; flex-direction: column;
        justify-content: center; align-items: center;
        padding: 40px 24px;
        background: #f8f9fc;
    }
    .login-card {
        width: 100%; max-width: 420px;
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 20px;
        padding: 36px 36px 28px;
        box-shadow: 0 1px 3px rgba(15,23,42,0.05), 0 12px 32px rgba(15,23,42,0.06);
    }
    .login-card-h { margin-bottom: 24px; }
    .login-card-h h1 {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 26px; font-weight: 700;
        letter-spacing: -0.025em;
        color: #0b3aa4;
        margin: 0 0 6px;
    }
    .login-card-h p { margin: 0; color: #6e7191; font-size: 14px; }

    .login-err {
        display: flex; align-items: center; gap: 10px;
        background: rgba(247,92,108,0.08);
        border: 1px solid rgba(247,92,108,0.20);
        color: #cc2d39;
        padding: 10px 14px;
        border-radius: 10px;
        font-size: 13px;
        margin-bottom: 18px;
    }
    .login-err svg { width: 16px; height: 16px; flex-shrink: 0; }

    .login-fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .login-fg label {
        font-size: 11px; font-weight: 600; color: #475569;
        text-transform: uppercase; letter-spacing: 0.04em;
    }
    .login-input-wrap { position: relative; }
    .login-input-wrap > .ic {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: #94a3b8; pointer-events: none;
    }
    .login-input-wrap > .ic svg { width: 16px; height: 16px; }
    .login-fg input {
        width: 100%;
        padding: 12px 14px 12px 42px;
        border: 1px solid #e6e8f0;
        border-radius: 10px;
        font-family: inherit; font-size: 14px;
        background: #fafbfd;
        color: #1e1e2f;
        transition: all .12s ease;
    }
    .login-fg input:focus {
        outline: none;
        border-color: #0b3aa4;
        background: white;
        box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
    }
    .toggle-pw {
        position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
        background: transparent; border: none; cursor: pointer;
        color: #94a3b8; padding: 6px; border-radius: 6px;
        display: inline-flex; align-items: center;
    }
    .toggle-pw:hover { color: #0b3aa4; background: rgba(11,58,164,0.05); }
    .toggle-pw svg { width: 18px; height: 18px; }

    .login-options {
        display: flex; justify-content: space-between; align-items: center;
        margin: 4px 0 20px;
        font-size: 13px;
    }
    .login-options a {
        color: #0b3aa4; text-decoration: none; font-weight: 600;
    }
    .login-options a:hover { text-decoration: underline; }

    .login-btn {
        width: 100%;
        padding: 13px 18px;
        background: #0b3aa4;
        color: white;
        border: none;
        border-radius: 10px;
        font-family: inherit; font-size: 14px; font-weight: 600;
        cursor: pointer;
        transition: all .12s ease;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    }
    .login-btn:hover { background: #082b7b; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(11,58,164,0.30); }
    .login-btn:active { transform: translateY(0); }
    .login-btn svg { width: 16px; height: 16px; }

    .login-foot {
        text-align: center; margin-top: 20px;
        font-size: 12px; color: #94a3b8;
    }

    /* PWA install */
    .pwa-install { margin-top: 16px; }
    .pwa-install-btn {
        width: 100%;
        padding: 11px 14px;
        border: 1px dashed #c0d2eb;
        border-radius: 10px;
        background: #fafbfd;
        color: #0b3aa4;
        font-size: 13px; font-weight: 600;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: all .15s ease;
    }
    .pwa-install-btn:hover {
        background: rgba(11,58,164,0.04);
        border-color: #0b3aa4;
        border-style: solid;
    }
    .pwa-install-btn svg { width: 16px; height: 16px; }
    .ios-install-guide {
        background: #fafbfd;
        border: 1px solid #e6e8f0;
        border-radius: 12px;
        padding: 14px;
    }
    .ios-install-title {
        display: flex; align-items: center; justify-content: center;
        gap: 6px; color: #1e1e2f; font-weight: 600;
        font-size: 13px; margin-bottom: 10px;
    }
    .ios-install-title svg { width: 16px; height: 16px; color: #0b3aa4; }
    .ios-install-steps { display: flex; flex-direction: column; gap: 6px; }
    .ios-step {
        display: flex; align-items: center; gap: 8px;
        background: white;
        border: 1px solid #e6e8f0;
        padding: 8px 10px; border-radius: 8px;
        color: #475569; font-size: 12px; line-height: 1.4;
    }
    .ios-step-num {
        width: 20px; height: 20px; background: #0b3aa4; color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 11px; flex-shrink: 0;
    }
    .ios-step strong { color: #1e1e2f; }

    /* Mobile / Tablet */
    @media (max-width: 960px) {
        .login-wrap { grid-template-columns: 1fr; }
        .login-hero { display: none; }
    }
    @media (max-width: 480px) {
        .login-card { padding: 28px 22px 22px; border-radius: 16px; }
        .login-form-side { padding: 24px 14px; }
    }
    </style>
</head>
<body>
    <div class="login-wrap">
        <!-- HERO -->
        <aside class="login-hero">
            <div class="login-brand">ConnecteedHR</div>
            <div class="login-hero-body">
                <h2>Il tuo team, organizzato.</h2>
                <p>Ferie, presenze, comunicazioni, documenti e chat. Tutto in un solo posto, accessibile da ogni dispositivo.</p>
                <ul class="login-features">
                    <li>
                        <span class="login-feat-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                        Gestisci ferie e permessi in pochi click
                    </li>
                    <li>
                        <span class="login-feat-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        Comunica con il team in tempo reale
                    </li>
                    <li>
                        <span class="login-feat-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </span>
                        Documenti e buste paga sempre disponibili
                    </li>
                </ul>
            </div>
            <div class="login-hero-foot">© <?= date('Y') ?> ConnecteedHR · Tutti i diritti riservati</div>
        </aside>

        <!-- FORM -->
        <main class="login-form-side">
            <div style="width: 100%; max-width: 420px;">
                <div class="login-card">
                    <div class="login-card-h">
                        <h1>Bentornato 👋</h1>
                        <p>Accedi al tuo account ConnecteedHR.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="login-err">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <?php echo $csrfField; ?>
                        <?php if (!empty($_GET['return']) && is_string($_GET['return']) && preg_match('#^/[a-zA-Z0-9/_\-.?=&%]*$#', $_GET['return'])): ?>
                            <input type="hidden" name="return" value="<?= htmlspecialchars($_GET['return']) ?>">
                        <?php endif; ?>

                        <div class="login-fg">
                            <label for="username">Username o codice fiscale</label>
                            <div class="login-input-wrap">
                                <span class="ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" id="username" name="username" required
                                       autocomplete="username" autofocus
                                       placeholder="Inserisci il tuo username"
                                       value="<?php echo $usernameValue; ?>">
                            </div>
                        </div>

                        <div class="login-fg">
                            <label for="password">Password</label>
                            <div class="login-input-wrap">
                                <span class="ic">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input type="password" id="password" name="password" required
                                       autocomplete="current-password"
                                       placeholder="••••••••">
                                <button type="button" class="toggle-pw" id="togglePw" aria-label="Mostra password">
                                    <svg id="eyeOn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg id="eyeOff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="login-options">
                            <span></span>
                            <a href="<?= PUBLIC_URL ?>/auth/forgot-password.php">Password dimenticata?</a>
                        </div>

                        <button type="submit" class="login-btn">
                            Accedi
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                    </form>

                </div>

                <!-- PWA install -->
                <div id="pwaInstallContainer" class="pwa-install" style="display:none;">
                    <button id="pwaInstallBtn" class="pwa-install-btn" type="button">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                        Installa l'app sul dispositivo
                    </button>
                </div>
                <div id="iosInstallHint" class="pwa-install" style="display:none;">
                    <div class="ios-install-guide">
                        <div class="ios-install-title">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/></svg>
                            Installa su iPhone/iPad
                        </div>
                        <div class="ios-install-steps">
                            <div class="ios-step"><span class="ios-step-num">1</span><span>Tocca <strong>Condividi</strong> in basso</span></div>
                            <div class="ios-step"><span class="ios-step-num">2</span><span>Tocca <strong>Aggiungi a Home</strong></span></div>
                            <div class="ios-step"><span class="ios-step-num">3</span><span>Tocca <strong>Aggiungi</strong> in alto a destra</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function() {
        var btn = document.getElementById('togglePw');
        var inp = document.getElementById('password');
        var eyeOn = document.getElementById('eyeOn');
        var eyeOff = document.getElementById('eyeOff');
        if (btn && inp) {
            btn.addEventListener('click', function() {
                var is = inp.type === 'password';
                inp.type = is ? 'text' : 'password';
                eyeOn.style.display  = is ? 'none' : '';
                eyeOff.style.display = is ? '' : 'none';
                btn.setAttribute('aria-label', is ? 'Nascondi password' : 'Mostra password');
            });
        }
    })();
    </script>

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
