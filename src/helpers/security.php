<?php
/**
 * Helper Sicurezza
 * PAManager - Comune
 */

/**
 * Imposta gli header di sicurezza HTTP
 * Configurazione completa per protezione enterprise
 */
function setSecurityHeaders(?string $nonce = null): void
{
    // Se headers già inviati, non fare nulla
    if (headers_sent()) {
        return;
    }

    // Previene MIME sniffing
    header('X-Content-Type-Options: nosniff');

    // Previene clickjacking
    header('X-Frame-Options: DENY');

    // Abilita protezione XSS del browser (legacy, ma ancora utile)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy - non inviare referrer a siti esterni
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Cross-Origin Policies
    // NOTA: COEP require-corp può bloccare risorse esterne (es. Google Fonts, immagini)
    // Usare 'credentialless' o rimuovere se causa problemi
    header('Cross-Origin-Opener-Policy: same-origin');
    // header('Cross-Origin-Embedder-Policy: require-corp'); // Disabilitato per compatibilità
    header('Cross-Origin-Resource-Policy: same-site');

    // Content Security Policy con nonce per script/style inline
    $nonce = $nonce ?? generateNonce();
    $csp = buildContentSecurityPolicy($nonce);
    header('Content-Security-Policy: ' . $csp);

    // HSTS (solo con HTTPS) - 1 anno con preload
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Permissions Policy - disabilita funzionalità non necessarie
    $permissions = [
        'accelerometer=()',
        'ambient-light-sensor=()',
        'autoplay=()',
        'battery=()',
        'camera=()',
        'cross-origin-isolated=()',
        'display-capture=()',
        'document-domain=()',
        'encrypted-media=()',
        'execution-while-not-rendered=()',
        'execution-while-out-of-viewport=()',
        'fullscreen=(self)',
        'geolocation=()',
        'gyroscope=()',
        'keyboard-map=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'navigation-override=()',
        'payment=()',
        'picture-in-picture=()',
        'publickey-credentials-get=()',
        'screen-wake-lock=()',
        'sync-xhr=()',
        'usb=()',
        'web-share=()',
        'xr-spatial-tracking=()'
    ];
    header('Permissions-Policy: ' . implode(', ', $permissions));

    // Cache control per pagine sensibili
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Previene information disclosure
    header_remove('X-Powered-By');
    header_remove('Server');
}

/**
 * Costruisce la Content Security Policy
 *
 * NOTA: 'unsafe-inline' per style-src è necessario per compatibilità
 * con gli stili inline esistenti. Per maggiore sicurezza, migrare
 * tutti gli stili inline a file CSS esterni e usare nonce.
 */
function buildContentSecurityPolicy(string $nonce): string
{
    $csp = [
        // Default: blocca tutto tranne stesso dominio
        "default-src 'self'",

        // Script: stesso dominio + unsafe-inline per compatibilità legacy
        // In futuro migrare a nonce-based
        "script-src 'self' 'unsafe-inline'",

        // Stili: stesso dominio + unsafe-inline per stili inline esistenti
        "style-src 'self' 'unsafe-inline'",

        // Immagini: stesso dominio + data URI per icone SVG/base64
        "img-src 'self' data: https://chart.googleapis.com",

        // Font: stesso dominio + Google Fonts se necessario
        "font-src 'self'",

        // Connessioni (fetch, XHR): solo stesso dominio
        "connect-src 'self'",

        // Media: disabilitato
        "media-src 'none'",

        // Object/embed: disabilitato (previene Flash, PDF embedding)
        "object-src 'none'",

        // Frame: disabilitato
        "frame-src 'none'",

        // Child frame: disabilitato
        "child-src 'none'",

        // Worker: stesso dominio (per Service Worker)
        "worker-src 'self'",

        // Form action: solo stesso dominio
        "form-action 'self'",

        // Frame ancestors: nessuno (previene clickjacking)
        "frame-ancestors 'none'",

        // Base URI: stesso dominio
        "base-uri 'self'",

        // Manifest: stesso dominio (per PWA)
        "manifest-src 'self'",

        // Upgrade insecure requests in produzione
        "upgrade-insecure-requests"
    ];

    return implode('; ', $csp);
}

/**
 * Costruisce CSP strict con nonce (per pagine senza stili inline)
 */
function buildStrictContentSecurityPolicy(string $nonce): string
{
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
        "style-src 'self' 'nonce-{$nonce}'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "media-src 'none'",
        "object-src 'none'",
        "frame-src 'none'",
        "child-src 'none'",
        "worker-src 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "manifest-src 'self'",
        "upgrade-insecure-requests"
    ];

    return implode('; ', $csp);
}

/**
 * Imposta headers specifici per download file
 */
function setDownloadHeaders(string $filename, string $mimeType, int $fileSize): void
{
    // Headers sicurezza base
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // Content-Disposition per forzare download
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');

    // Tipo MIME
    header('Content-Type: ' . $mimeType);

    // Dimensione file
    header('Content-Length: ' . $fileSize);

    // No caching per documenti sensibili
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Previene esecuzione nel browser
    header('Content-Security-Policy: default-src \'none\'');
}

/**
 * Verifica se la connessione è HTTPS
 */
function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Forza redirect a HTTPS (in produzione)
 */
function forceHttps(): void
{
    if (!isHttps() && !DEBUG_MODE) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $url, true, 301);
        exit;
    }
}

/**
 * Escape HTML per output sicuro
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape per attributi HTML
 */
function eAttr(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape per JavaScript (JSON)
 */
function eJs(mixed $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Sanitizza una stringa rimuovendo tag HTML
 */
function sanitize(string $string): string
{
    return strip_tags(trim($string));
}

/**
 * Genera un token casuale sicuro
 */
function generateToken(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Confronto sicuro di stringhe (timing-safe)
 */
function secureCompare(string $known, string $user): bool
{
    return hash_equals($known, $user);
}

/**
 * Costruisce un URL assoluto basato sull'host corrente della richiesta.
 * Usa HTTPS/HTTP automatico, gestisce proxy, e usa PUBLIC_URL come prefisso.
 */
function buildPublicUrl(string $path = ''): string
{
    $proto = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    } elseif (($_SERVER['SERVER_PORT'] ?? 80) == 443) {
        $proto = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $base = defined('PUBLIC_URL') ? PUBLIC_URL : '';

    return $proto . '://' . $host . $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/**
 * Ottiene l'IP reale del client
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Verifica se la richiesta è AJAX
 */
function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Risposta JSON con header appropriati
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Redirect sicuro (solo URL interni)
 */
function redirect(string $url, int $statusCode = 302): void
{
    // Verifica che l'URL sia interno
    $parsed = parse_url($url);

    if (isset($parsed['host'])) {
        $allowedHosts = [$_SERVER['HTTP_HOST'] ?? 'localhost'];
        if (!in_array($parsed['host'], $allowedHosts)) {
            $url = '/'; // Fallback a home se URL esterno
        }
    }

    header('Location: ' . $url, true, $statusCode);
    exit;
}

/**
 * Verifica rate limiting basato su sessione (per richieste veloci)
 */
function checkRateLimitSession(string $key, int $maxAttempts = 10, int $windowSeconds = 60): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $rateLimitKey = 'rate_limit_' . md5($key);
    $now = time();

    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = [
            'attempts' => 1,
            'window_start' => $now
        ];
        return true;
    }

    $data = $_SESSION[$rateLimitKey];

    // Reset finestra se scaduta
    if ($now - $data['window_start'] > $windowSeconds) {
        $_SESSION[$rateLimitKey] = [
            'attempts' => 1,
            'window_start' => $now
        ];
        return true;
    }

    // Incrementa tentativi
    $_SESSION[$rateLimitKey]['attempts']++;

    return $_SESSION[$rateLimitKey]['attempts'] <= $maxAttempts;
}

/**
 * Verifica rate limiting basato su database (persistente, cross-session)
 * Più robusto per protezione brute-force
 */
function checkRateLimitDb(string $identifier, string $action, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    try {
        $now = date('Y-m-d H:i:s');

        // Pulisci vecchi record
        Database::execute(
            "DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$windowSeconds]
        );

        // Cerca record esistente
        $record = Database::fetch(
            "SELECT * FROM rate_limits WHERE identifier = ? AND action = ?",
            [$identifier, $action]
        );

        if (!$record) {
            // Primo tentativo
            Database::insert('rate_limits', [
                'identifier' => $identifier,
                'action' => $action,
                'attempts' => 1,
                'window_start' => $now
            ]);
            return true;
        }

        // Verifica se finestra scaduta
        $windowStart = strtotime($record['window_start']);
        if (time() - $windowStart > $windowSeconds) {
            // Reset finestra
            Database::execute(
                "UPDATE rate_limits SET attempts = 1, window_start = ? WHERE id = ?",
                [$now, $record['id']]
            );
            return true;
        }

        // Incrementa tentativi
        $newAttempts = $record['attempts'] + 1;
        Database::execute(
            "UPDATE rate_limits SET attempts = ? WHERE id = ?",
            [$newAttempts, $record['id']]
        );

        return $newAttempts <= $maxAttempts;

    } catch (Exception $e) {
        // In caso di errore DB, fallback a session rate limit
        error_log('Rate limit DB error: ' . $e->getMessage());
        return checkRateLimitSession($identifier . '_' . $action, $maxAttempts, $windowSeconds);
    }
}

/**
 * Rate limiting per login (combina IP e username)
 */
function checkLoginRateLimit(string $username): array
{
    $ip = getClientIp();
    $maxAttempts = defined('RATE_LIMIT_LOGIN') ? RATE_LIMIT_LOGIN : 5;
    $windowSeconds = defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 300;

    // Controlla sia per IP che per username
    $ipAllowed = checkRateLimitDb($ip, 'login', $maxAttempts, $windowSeconds);
    $userAllowed = checkRateLimitDb($username, 'login', $maxAttempts, $windowSeconds);

    if (!$ipAllowed || !$userAllowed) {
        $retryAfter = $windowSeconds;
        return [
            'allowed' => false,
            'message' => "Troppi tentativi di accesso. Riprova tra " . ceil($retryAfter / 60) . " minuti.",
            'retry_after' => $retryAfter
        ];
    }

    return ['allowed' => true];
}

/**
 * Rate limiting per download documenti
 */
function checkDownloadRateLimit(int $userId, string $userType): array
{
    $identifier = $userType . '_' . $userId;
    $maxDownloads = defined('RATE_LIMIT_DOWNLOAD') ? RATE_LIMIT_DOWNLOAD : 50;
    $windowSeconds = defined('RATE_LIMIT_DOWNLOAD_WINDOW') ? RATE_LIMIT_DOWNLOAD_WINDOW : 3600;

    if (!checkRateLimitDb($identifier, 'download', $maxDownloads, $windowSeconds)) {
        return [
            'allowed' => false,
            'message' => "Limite download raggiunto. Riprova tra " . ceil($windowSeconds / 60) . " minuti."
        ];
    }

    return ['allowed' => true];
}

/**
 * Rate limiting per API
 */
function checkApiRateLimit(): array
{
    $ip = getClientIp();
    $maxRequests = defined('RATE_LIMIT_API') ? RATE_LIMIT_API : 100;
    $windowSeconds = defined('RATE_LIMIT_API_WINDOW') ? RATE_LIMIT_API_WINDOW : 60;

    if (!checkRateLimitSession($ip . '_api', $maxRequests, $windowSeconds)) {
        return [
            'allowed' => false,
            'message' => 'Rate limit exceeded. Please slow down.',
            'retry_after' => $windowSeconds
        ];
    }

    return ['allowed' => true];
}

/**
 * Alias per retrocompatibilità
 */
function checkRateLimit(string $key, int $maxAttempts = 10, int $windowSeconds = 60): bool
{
    return checkRateLimitSession($key, $maxAttempts, $windowSeconds);
}

/**
 * Log di sicurezza
 */
function securityLog(string $event, array $context = []): void
{
    $logFile = LOGS_PATH . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $message = sprintf(
        "[%s] [%s] %s | IP: %s | UA: %s | Context: %s",
        $timestamp,
        strtoupper($event),
        $context['message'] ?? '',
        $ip,
        $userAgent,
        json_encode($context)
    );

    if (!is_dir(LOGS_PATH)) {
        mkdir(LOGS_PATH, 0755, true);
    }

    file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Genera nonce per CSP
 */
function generateNonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Verifica se un percorso file è sicuro (previene path traversal)
 */
function isPathSafe(string $basePath, string $userPath): bool
{
    $realBase = realpath($basePath);
    $realPath = realpath($basePath . '/' . $userPath);

    if ($realBase === false || $realPath === false) {
        return false;
    }

    return strpos($realPath, $realBase) === 0;
}

/**
 * Maschera dati sensibili per logging
 */
function maskSensitiveData(array $data, array $sensitiveKeys = ['password', 'token', 'secret']): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = maskSensitiveData($value, $sensitiveKeys);
        } elseif (in_array(strtolower($key), $sensitiveKeys)) {
            $data[$key] = '***MASKED***';
        }
    }
    return $data;
}
