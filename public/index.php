<?php
/**
 * Entry Point Pubblico - PAManager.
 * Landing semplice con CTA al login unificato + opzioni installazione PWA.
 */

require_once dirname(__DIR__) . '/config/config.php';

Auth::init();
setSecurityHeaders();

// Redirect se gia loggato
if (Auth::isUserLoggedIn()) {
    $u = Auth::getUser();
    $redirect = match($u['role'] ?? '') {
        'admin'             => PUBLIC_URL . '/admin/',
        'admin_reparto'     => PUBLIC_URL . '/admin-reparto/',
        'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
        'accountant'        => PUBLIC_URL . '/accountant/',
        default             => PUBLIC_URL . '/auth/login.php',
    };
    header('Location: ' . $redirect);
    exit;
}
if (Auth::isEmployeeLoggedIn()) {
    header('Location: ' . PUBLIC_URL . '/employee/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Portale gestionale aziendale">
    <meta name="theme-color" content="#1a365d">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PAManager">
    <link rel="apple-touch-icon" href="<?= PUBLIC_URL ?>/assets/images/icon.php?size=180&v=4">
    <title>PAManager</title>
    <link rel="manifest" href="<?= PUBLIC_URL ?>/manifest.json.php">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?= time() ?>">
    <?= CSRF::metaTag() ?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #1a365d 0%, #2c5282 50%, #3182ce 100%);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    padding: 1rem;
}
.landing { width: 100%; max-width: 420px; }
.brand { text-align: center; margin-bottom: 2rem; }
.brand-logo {
    width: 80px; height: 80px;
    background: rgba(255,255,255,0.15);
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
    backdrop-filter: blur(10px);
}
.brand-logo svg { width: 45px; height: 45px; color: white; }
.brand h1 {
    font-size: 2.5rem; font-weight: 700; color: white; margin: 0;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.brand p { color: rgba(255,255,255,0.85); margin-top: 0.5rem; font-size: 0.95rem; }

.cta-card {
    background: white; border-radius: 20px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    padding: 2rem;
}
.cta-card p.intro { text-align: center; color: #4a5568; font-size: 0.9rem; line-height: 1.55; margin-bottom: 1.5rem; }
.cta-btn {
    width: 100%; padding: 1rem 1.5rem; border: none; border-radius: 12px;
    font-size: 1rem; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 0.75rem;
    transition: all 0.25s;
    text-decoration: none;
    background: linear-gradient(135deg, #3182ce 0%, #2b6cb0 100%);
    color: white;
}
.cta-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(49,130,206,0.45); }
.cta-btn svg { width: 22px; height: 22px; }

.cta-forgot { text-align: center; margin-top: 1.25rem; }
.cta-forgot a { color: #4a5568; font-size: 0.85rem; text-decoration: none; }
.cta-forgot a:hover { color: #2d3748; text-decoration: underline; }

.audience-note {
    margin-top: 1.5rem; padding-top: 1.25rem;
    border-top: 1px solid #e2e8f0;
    font-size: 0.78rem; color: #718096; text-align: center; line-height: 1.6;
}
.audience-note strong { color: #2d3748; }

.install-btn {
    width: 100%; padding: 0.9rem 1.5rem;
    border: 2px dashed rgba(255,255,255,0.5);
    border-radius: 12px;
    background: rgba(255,255,255,0.1);
    color: white; font-size: 0.95rem; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 0.6rem;
    transition: all 0.25s; backdrop-filter: blur(10px);
    margin-top: 1.25rem;
}
.install-btn:hover { background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.8); transform: translateY(-2px); }
.install-btn svg { width: 20px; height: 20px; }

.ios-install-guide {
    background: rgba(255,255,255,0.15);
    border-radius: 16px;
    padding: 1.25rem;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
    margin-top: 1.25rem;
}
.ios-title { display: flex; align-items: center; justify-content: center; gap: 0.5rem; color: white; font-weight: 600; font-size: 1rem; margin-bottom: 1rem; }
.ios-steps { display: flex; flex-direction: column; gap: 0.6rem; }
.ios-step { display: flex; align-items: center; gap: 0.75rem; background: rgba(255,255,255,0.1); padding: 0.65rem 0.85rem; border-radius: 10px; color: white; font-size: 0.82rem; }
.ios-step-num { width: 24px; height: 24px; background: #3182ce; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0; }
.ios-step strong { color: #90cdf4; }

.footer { text-align: center; margin-top: 1.5rem; color: rgba(255,255,255,0.7); font-size: 0.78rem; }

@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div class="landing">
    <div class="brand">
        <div class="brand-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 18.5l-7-3.5V9l7 3.5 7-3.5v8l-7 3.5z"/></svg>
        </div>
        <h1>PAManager</h1>
        <p>Il portale aziendale per dipendenti e staff</p>
    </div>

    <div class="cta-card">
        <p class="intro">Accedi con username e password per entrare nella tua area personale.</p>
        <a href="<?= PUBLIC_URL ?>/auth/login.php" class="cta-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/></svg>
            Accedi al portale
        </a>
        <div class="cta-forgot">
            <a href="<?= PUBLIC_URL ?>/auth/forgot-password.php">Hai dimenticato la password?</a>
        </div>
        <div class="audience-note">
            Punto di accesso unico per <strong>dipendenti</strong>, <strong>amministratori</strong>,
            <strong>commercialisti</strong> e <strong>consulenti del lavoro</strong>.
        </div>
    </div>

    <div id="installContainer" style="display: none;">
        <button id="installApp" class="install-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
            Installa l'app sul dispositivo
        </button>
    </div>

    <div id="iosInstallHint" style="display: none;">
        <div class="ios-install-guide">
            <div class="ios-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/></svg>
                Installa su iPhone/iPad
            </div>
            <div class="ios-steps">
                <div class="ios-step"><span class="ios-step-num">1</span><span>Tocca l'icona <strong>Condividi</strong> in basso</span></div>
                <div class="ios-step"><span class="ios-step-num">2</span><span>Scorri e tocca <strong>Aggiungi a Home</strong></span></div>
                <div class="ios-step"><span class="ios-step-num">3</span><span>Tocca <strong>Aggiungi</strong> in alto a destra</span></div>
            </div>
        </div>
    </div>

    <div class="footer">© <?= date('Y') ?> PAManager</div>
</div>

<script>
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                    window.navigator.standalone === true ||
                    document.referrer.includes('android-app://');

let deferredPrompt = null;
const installContainer = document.getElementById('installContainer');
const installBtn = document.getElementById('installApp');

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (installContainer) installContainer.style.display = 'block';
});

if (installBtn) {
    installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        try {
            await deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted' && installContainer) installContainer.style.display = 'none';
        } catch (err) { console.error(err); }
        deferredPrompt = null;
    });
}

window.addEventListener('appinstalled', () => {
    if (installContainer) installContainer.style.display = 'none';
    deferredPrompt = null;
});

if (isStandalone) {
    if (installContainer) installContainer.style.display = 'none';
    const ios = document.getElementById('iosInstallHint');
    if (ios) ios.style.display = 'none';
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= PUBLIC_URL ?>/sw.js', { scope: '<?= PUBLIC_URL ?>/' });
}

if (isIOS && !isStandalone) {
    document.getElementById('iosInstallHint').style.display = 'block';
}
</script>
</body>
</html>
