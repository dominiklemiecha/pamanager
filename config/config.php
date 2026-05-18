<?php
/**
 * Configurazione Generale
 * PAManager - Comune
 */

// Avvia output buffering per prevenire output accidentale prima di session_start
if (PHP_SAPI !== 'cli' && !defined('OUTPUT_BUFFERING_STARTED')) {
    define('OUTPUT_BUFFERING_STARTED', true);
    ob_start();
}

// Carica variabili da .env se esiste
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $envContent = @file_get_contents($envFile);
    if ($envContent !== false) {
        // Rimuovi BOM se presente
        $envContent = preg_replace('/^\xEF\xBB\xBF/', '', $envContent);
        $lines = explode("\n", $envContent);
        foreach ($lines as $line) {
            $line = trim($line);
            // Salta righe vuote e commenti
            if (empty($line) || strpos($line, '#') === 0) continue;
            // Parsing KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Rimuovi virgolette se presenti
                $value = trim($value, '"\'');
                if (!empty($key) && !isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }
}

define('APP_NAME', 'PAManager');
define('APP_VERSION', '1.0.0');

// Rileva BASE_URL automaticamente
// In produzione: /pamanager  |  In locale: /app-gestionali/gestionalepa
$detectedBase = '';
if (PHP_SAPI !== 'cli') {
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    // Trova la posizione di /public, /admin, /auth, /employee, /accountant, /api
    $markers = ['/public/admin', '/public/auth', '/public/employee', '/public/accountant', '/public/api', '/public'];
    foreach ($markers as $marker) {
        $pos = strpos($scriptPath, $marker);
        if ($pos !== false) {
            $detectedBase = substr($scriptPath, 0, $pos);
            break;
        }
    }
    // Se siamo direttamente in public (es. /pamanager/public)
    if (empty($detectedBase) && preg_match('#^(.+)/public$#', $scriptPath, $m)) {
        $detectedBase = $m[1];
    }
}
define('BASE_URL', $_ENV['BASE_URL'] ?? $detectedBase);

// Quando Apache ha DocumentRoot direttamente su public/ (caso Docker / hosting
// che punta al /public), gli URL non contengono "/public" e PUBLIC_URL = BASE_URL.
// Altrimenti (hosting che serve la root e ti fa entrare via /public) si aggiunge.
$publicIsRoot = (PHP_SAPI !== 'cli')
    && isset($_SERVER['SCRIPT_NAME'])
    && strpos($_SERVER['SCRIPT_NAME'], '/public') === false;
define('PUBLIC_URL', $publicIsRoot ? BASE_URL : (BASE_URL . '/public'));
define('APP_URL', $_ENV['APP_URL'] ?? 'https://localhost' . PUBLIC_URL);

// Percorsi
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('SRC_PATH', ROOT_PATH . '/src');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOGS_PATH', ROOT_PATH . '/logs');
define('VENDOR_PATH', ROOT_PATH . '/vendor');

// Documenti
define('DOCUMENTS_PATH', STORAGE_PATH . '/documents');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_MIME_TYPES', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
]);
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);

// Sessioni - timeout per ruolo (in secondi)
define('SESSION_NAME', 'pamanager_session');
define('SESSION_TIMEOUT_ADMIN', 600);      // 10 minuti
define('SESSION_TIMEOUT_ACCOUNTANT', 600); // 10 minuti
define('SESSION_TIMEOUT_EMPLOYEE', 900);   // 15 minuti
define('SESSION_LIFETIME', 1800);          // Default 30 minuti (fallback)

// Sicurezza Password
define('PASSWORD_COST', 12);
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// Blocco Account
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minuti
define('CSRF_TOKEN_LIFETIME', 3600); // 1 ora

// Rate Limiting
define('RATE_LIMIT_LOGIN', 5);           // tentativi
define('RATE_LIMIT_LOGIN_WINDOW', 300);  // 5 minuti
define('RATE_LIMIT_API', 100);           // richieste
define('RATE_LIMIT_API_WINDOW', 60);     // 1 minuto
define('RATE_LIMIT_DOWNLOAD', 50);       // download
define('RATE_LIMIT_DOWNLOAD_WINDOW', 3600); // 1 ora

// MFA
define('MFA_ENABLED', true);
define('MFA_ISSUER', 'PAManager');
define('MFA_REQUIRED_ROLES', ['admin', 'accountant']); // Ruoli che richiedono MFA

// Debug (disabilitare in produzione)
define('DEBUG_MODE', $_ENV['DEBUG_MODE'] ?? false);

// Timezone
date_default_timezone_set('Europe/Rome');

// Error reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOGS_PATH . '/error.log');
}

// Autoload classi
spl_autoload_register(function ($class) {
    $file = SRC_PATH . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Carica helpers
require_once SRC_PATH . '/helpers/security.php';
require_once SRC_PATH . '/helpers/validation.php';
require_once SRC_PATH . '/helpers/avatar.php';
require_once SRC_PATH . '/helpers/html_sanitize.php';

// Carica autoloader Composer/Vendor se esiste
$vendorAutoload = VENDOR_PATH . '/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
