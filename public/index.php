<?php
/**
 * Entry Point Pubblico
 * PAManager - Comune
 */

require_once dirname(__DIR__) . '/config/config.php';

Auth::init();
setSecurityHeaders();

// Redirect se già loggato
if (Auth::isUserLoggedIn()) {
    $user = Auth::getUser();
    $redirect = $user['role'] === 'admin' ? PUBLIC_URL . '/admin/' : PUBLIC_URL . '/accountant/';
    header('Location: ' . $redirect);
    exit;
}

if (Auth::isEmployeeLoggedIn()) {
    header('Location: ' . PUBLIC_URL . '/employee/');
    exit;
}

$pageTitle = 'PAManager';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestionale per dipendenti comunali">
    <meta name="theme-color" content="#1a365d">
    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PAManager">
    <link rel="apple-touch-icon" href="<?= PUBLIC_URL ?>/assets/images/icon.php?size=180&v=4">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="manifest" href="<?= PUBLIC_URL ?>/manifest.json.php">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/theme.css?v=<?= time() ?>">
    <?= CSRF::metaTag() ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a365d 0%, #2c5282 50%, #3182ce 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            padding: 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        /* Header */
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            backdrop-filter: blur(10px);
        }

        .login-logo svg {
            width: 45px;
            height: 45px;
            color: white;
        }

        .login-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #ffffff !important;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Card */
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            overflow: hidden;
        }

        /* Tabs */
        .login-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
        }

        .login-tab {
            flex: 1;
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            background: #f7fafc;
            font-size: 0.95rem;
            font-weight: 600;
            color: #718096;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .login-tab:first-child {
            border-right: 1px solid #e2e8f0;
        }

        .login-tab svg {
            width: 20px;
            height: 20px;
        }

        .login-tab.active {
            background: white;
            color: #3182ce;
        }

        .login-tab.active.employee {
            color: #3182ce;
            border-bottom: 3px solid #3182ce;
        }

        .login-tab.active.operator {
            color: #38a169;
            border-bottom: 3px solid #38a169;
        }

        .login-tab:hover:not(.active) {
            background: #edf2f7;
        }

        /* Content */
        .login-content {
            padding: 2rem;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .panel-description {
            text-align: center;
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .login-btn {
            width: 100%;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: all 0.3s;
            text-decoration: none;
        }

        .login-btn svg {
            width: 22px;
            height: 22px;
        }

        .login-btn.employee-btn {
            background: linear-gradient(135deg, #3182ce 0%, #2b6cb0 100%);
            color: white;
        }

        .login-btn.employee-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(49, 130, 206, 0.4);
        }

        .login-btn.operator-btn {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: white;
        }

        .login-btn.operator-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(56, 161, 105, 0.4);
        }

        /* Features */
        .features-list {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            color: #4a5568;
            font-size: 0.85rem;
        }

        .feature-item svg {
            width: 18px;
            height: 18px;
            color: #38a169;
            flex-shrink: 0;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
        }

        .login-footer a {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        /* Install Button */
        .install-btn {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px dashed rgba(255,255,255,0.5);
            border-radius: 12px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .install-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.8);
            transform: translateY(-2px);
        }

        .install-btn svg {
            width: 22px;
            height: 22px;
        }

        /* iOS Install Guide */
        .ios-install-guide {
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .ios-install-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
        }

        .ios-install-steps {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .ios-step {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(255,255,255,0.1);
            padding: 0.85rem 1rem;
            border-radius: 12px;
            color: white;
            font-size: 0.9rem;
        }

        .ios-step-number {
            width: 28px;
            height: 28px;
            background: #3182ce;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .ios-step-icon {
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ios-step-icon svg {
            width: 20px;
            height: 20px;
        }

        .ios-step-text {
            flex: 1;
            line-height: 1.4;
        }

        .ios-step-text strong {
            color: #90cdf4;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .ios-step-icon.animate {
            animation: bounce 1s ease-in-out infinite;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-title {
                font-size: 2rem;
            }

            .login-tab {
                padding: 1rem;
                font-size: 0.85rem;
            }

            .login-content {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 18.5l-7-3.5V9l7 3.5 7-3.5v8l-7 3.5z"/>
                </svg>
            </div>
            <h1 class="login-title">PAManager</h1>
        </div>

        <div class="login-card">
            <div class="login-tabs">
                <button class="login-tab employee active" onclick="switchTab('employee')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    Dipendente
                </button>
                <button class="login-tab operator" onclick="switchTab('operator')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                    </svg>
                    Amministrazione
                </button>
            </div>

            <div class="login-content">
                <!-- Dipendente Panel -->
                <div class="tab-panel active" id="panel-employee">
                    <p class="panel-description">
                        Accedi per consultare le tue buste paga, certificazioni CUD e comunicazioni.
                    </p>
                    <a href="<?= PUBLIC_URL ?>/auth/login-employee.php" class="login-btn employee-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/>
                        </svg>
                        Accedi
                    </a>
                    <div class="features-list">
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Buste paga mensili
                        </div>
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Certificazioni CUD
                        </div>
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Comunicazioni aziendali
                        </div>
                    </div>
                </div>

                <!-- Operatore Panel -->
                <div class="tab-panel" id="panel-operator">
                    <p class="panel-description">
                        Area riservata per la gestione documenti, dipendenti e comunicazioni.
                    </p>
                    <a href="<?= PUBLIC_URL ?>/auth/login.php" class="login-btn operator-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        Accedi
                    </a>
                    <div class="features-list">
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Gestione dipendenti
                        </div>
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Caricamento documenti
                        </div>
                        <div class="feature-item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                            Invio comunicazioni
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pulsante Installa App (Android/Desktop) -->
        <div id="installContainer" style="display: none; margin-top: 1.5rem;">
            <button id="installApp" class="install-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
                </svg>
                Installa App
            </button>
        </div>

        <!-- Istruzioni iOS - Guida Visuale -->
        <div id="iosInstallHint" style="display: none; margin-top: 1.5rem;">
            <div class="ios-install-guide">
                <div class="ios-install-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z"/>
                    </svg>
                    Installa su iPhone/iPad
                </div>
                <div class="ios-install-steps">
                    <div class="ios-step">
                        <span class="ios-step-number">1</span>
                        <div class="ios-step-icon animate">
                            <!-- Safari Share Icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M16 5l-1.42 1.42-1.59-1.59V16h-1.98V4.83L9.42 6.42 8 5l4-4 4 4zm4 5v11c0 1.1-.9 2-2 2H6c-1.11 0-2-.9-2-2V10c0-1.11.89-2 2-2h3v2H6v11h12V10h-3V8h3c1.1 0 2 .89 2 2z"/>
                            </svg>
                        </div>
                        <span class="ios-step-text">Tocca l'icona <strong>Condividi</strong> in basso</span>
                    </div>
                    <div class="ios-step">
                        <span class="ios-step-number">2</span>
                        <div class="ios-step-icon">
                            <!-- Plus Square Icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/>
                            </svg>
                        </div>
                        <span class="ios-step-text">Scorri e tocca <strong>Aggiungi a Home</strong></span>
                    </div>
                    <div class="ios-step">
                        <span class="ios-step-number">3</span>
                        <div class="ios-step-icon">
                            <!-- Checkmark Icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                            </svg>
                        </div>
                        <span class="ios-step-text">Tocca <strong>Aggiungi</strong> in alto a destra</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="login-footer">
            <p>&copy; <?= date('Y') ?> PAManager</p>
        </div>
    </div>

    <script>
        function switchTab(type) {
            // Update tabs
            document.querySelectorAll('.login-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('.login-tab.' + type).classList.add('active');

            // Update panels
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            document.getElementById('panel-' + type).classList.add('active');
        }

        // Rileva piattaforma
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isAndroid = /Android/.test(navigator.userAgent);
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                            window.navigator.standalone === true ||
                            document.referrer.includes('android-app://');

        // PWA Install - Android/Desktop (beforeinstallprompt API)
        let deferredPrompt = null;
        const installContainer = document.getElementById('installContainer');
        const installBtn = document.getElementById('installApp');

        window.addEventListener('beforeinstallprompt', (e) => {
            // Previeni il prompt automatico del browser
            e.preventDefault();
            // Salva l'evento per usarlo dopo
            deferredPrompt = e;
            // Mostra il pulsante di installazione
            if (installContainer) {
                installContainer.style.display = 'block';
            }
            console.log('[PWA] beforeinstallprompt ricevuto - app installabile');
        });

        if (installBtn) {
            installBtn.addEventListener('click', async () => {
                if (!deferredPrompt) {
                    console.log('[PWA] Nessun prompt disponibile');
                    return;
                }

                // Mostra loading
                installBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="animation: spin 1s linear infinite;"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg> Installazione...';

                try {
                    // Mostra il prompt di installazione nativo
                    await deferredPrompt.prompt();

                    // Attendi la scelta dell'utente
                    const { outcome } = await deferredPrompt.userChoice;
                    console.log('[PWA] Scelta utente:', outcome);

                    if (outcome === 'accepted') {
                        console.log('[PWA] App installata!');
                        installContainer.style.display = 'none';
                    } else {
                        // Ripristina pulsante
                        installBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg> Installa App';
                    }
                } catch (err) {
                    console.error('[PWA] Errore installazione:', err);
                    installBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg> Installa App';
                }

                // Reset prompt (usabile solo una volta)
                deferredPrompt = null;
            });
        }

        // Nascondi se app gia installata
        window.addEventListener('appinstalled', () => {
            console.log('[PWA] App installata con successo!');
            if (installContainer) {
                installContainer.style.display = 'none';
            }
            deferredPrompt = null;
        });

        // Se gia in modalita standalone, nascondi tutto
        if (isStandalone) {
            console.log('[PWA] App gia installata (standalone mode)');
            if (installContainer) installContainer.style.display = 'none';
            document.getElementById('iosInstallHint').style.display = 'none';
        }

        // Registra Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?= PUBLIC_URL ?>/sw.js', { scope: '<?= PUBLIC_URL ?>/' })
                .then(reg => {
                    console.log('[SW] Registrato con scope:', reg.scope);
                })
                .catch(err => console.error('[SW] Errore registrazione:', err));
        }

        // Mostra guida iOS se non installata
        if (isIOS && !isStandalone) {
            document.getElementById('iosInstallHint').style.display = 'block';
        }

        // CSS per animazione spin
        const style = document.createElement('style');
        style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>
</html>
