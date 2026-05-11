<?php
/**
 * API Router
 * PAManager - Comune
 *
 * Entry point per tutte le API REST
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

// CORS headers - permetti same-origin e domini configurati
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';

// Permetti sempre richieste dallo stesso host
if (!empty($origin)) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost === $host || $originHost === 'localhost' || $originHost === '127.0.0.1') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
} else {
    // Richieste same-origin (no header Origin)
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');

// Preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Content-Type JSON
header('Content-Type: application/json; charset=utf-8');

// Disabilita cache per API
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/**
 * Classe per gestire JWT (semplificata)
 */
class JWT
{
    private static string $secret;
    private static int $expiry = 3600; // 1 ora
    private static bool $initialized = false;

    public static function init(): void
    {
        // Verifica che JWT_SECRET sia configurato
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;

        if (empty($secret)) {
            // In produzione, blocca se non configurato
            $isProduction = !empty($_SERVER['HTTP_HOST']) &&
                           strpos($_SERVER['HTTP_HOST'], 'localhost') === false &&
                           strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false;

            if ($isProduction) {
                error_log('SECURITY ERROR: JWT_SECRET not configured in production!');
                http_response_code(500);
                echo json_encode(['error' => 'Server configuration error']);
                exit;
            }

            // Solo in sviluppo locale, usa default (con warning)
            $secret = 'dev-only-secret-' . md5(__DIR__);
            error_log('WARNING: Using default JWT secret - configure JWT_SECRET for production');
        }

        self::$secret = $secret;
        self::$expiry = (int) ($_ENV['JWT_EXPIRY'] ?? getenv('JWT_EXPIRY') ?: 3600);
        self::$initialized = true;
    }

    /**
     * Genera un token JWT
     */
    public static function generate(array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['iat'] = time();
        $payload['exp'] = time() + self::$expiry;
        $payload = json_encode($payload);

        $base64Header = self::base64UrlEncode($header);
        $base64Payload = self::base64UrlEncode($payload);

        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", self::$secret, true);
        $base64Signature = self::base64UrlEncode($signature);

        return "$base64Header.$base64Payload.$base64Signature";
    }

    /**
     * Verifica e decodifica un token JWT
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        // Verifica firma
        $signature = self::base64UrlDecode($base64Signature);
        $expectedSignature = hash_hmac('sha256', "$base64Header.$base64Payload", self::$secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            return null;
        }

        // Decodifica payload
        $payload = json_decode(self::base64UrlDecode($base64Payload), true);

        if (!$payload) {
            return null;
        }

        // Verifica scadenza
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Estrae token dall'header Authorization
     */
    public static function getTokenFromHeader(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

JWT::init();

/**
 * Risposta JSON standard
 */
function apiResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Errore JSON
 */
function apiError(string $message, int $status = 400): void
{
    apiResponse(['error' => true, 'message' => $message], $status);
}

/**
 * Richiede autenticazione JWT
 */
function requireAuth(): array
{
    $token = JWT::getTokenFromHeader();

    if (!$token) {
        apiError('Token mancante', 401);
    }

    $payload = JWT::verify($token);

    if (!$payload) {
        apiError('Token non valido o scaduto', 401);
    }

    return $payload;
}

// Se questo file è incluso da un altro, non eseguire il routing
if (basename($_SERVER['SCRIPT_FILENAME']) !== 'index.php') {
    return;
}

// Routing base
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Estrai endpoint dalla URI
$basePath = PUBLIC_URL . '/api';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace($basePath, '', $path);
$path = trim($path, '/');

// Route semplice
$segments = $path ? explode('/', $path) : [];
$endpoint = $segments[0] ?? 'status';

// Info API
if ($endpoint === '' || $endpoint === 'status') {
    apiResponse([
        'name' => 'PAManager API',
        'version' => '1.0.0',
        'status' => 'online',
        'endpoints' => [
            'POST /api/auth.php' => 'Autenticazione',
            'GET /api/documents.php' => 'Lista documenti',
            'GET /api/communications.php' => 'Lista comunicazioni'
        ]
    ]);
}

// 404 per endpoint non trovati
apiError('Endpoint non trovato', 404);
