<?php
/**
 * Classe CSRF - Protezione Cross-Site Request Forgery
 * PAManager - Comune
 */

class CSRF
{
    private const TOKEN_LENGTH = 32;
    private const TOKEN_NAME = 'csrf_token';

    /**
     * Genera un nuovo token CSRF
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[self::TOKEN_NAME] = $token;
        $_SESSION[self::TOKEN_NAME . '_time'] = time();

        return $token;
    }

    /**
     * Ottiene il token corrente o ne genera uno nuovo
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Rigenera se scaduto
        if (self::isTokenExpired()) {
            return self::generateToken();
        }

        return $_SESSION[self::TOKEN_NAME] ?? self::generateToken();
    }

    /**
     * Verifica se il token è scaduto
     */
    private static function isTokenExpired(): bool
    {
        if (!isset($_SESSION[self::TOKEN_NAME . '_time'])) {
            return true;
        }

        return (time() - $_SESSION[self::TOKEN_NAME . '_time']) > CSRF_TOKEN_LIFETIME;
    }

    /**
     * Valida un token CSRF
     */
    public static function validateToken(?string $token): bool
    {
        if ($token === null || empty($token)) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        if (self::isTokenExpired()) {
            self::clearToken();
            return false;
        }

        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }

    /**
     * Verifica il token dalla richiesta POST
     */
    public static function verify(): bool
    {
        $token = $_POST[self::TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return self::validateToken($token);
    }

    /**
     * Verifica e termina se non valido
     */
    public static function verifyOrDie(): void
    {
        if (!self::verify()) {
            http_response_code(403);
            if (self::isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Token CSRF non valido']);
            } else {
                echo 'Richiesta non valida. Token CSRF mancante o scaduto.';
            }
            exit;
        }
    }

    /**
     * Rimuove il token dalla sessione
     */
    public static function clearToken(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION[self::TOKEN_NAME]);
        unset($_SESSION[self::TOKEN_NAME . '_time']);
    }

    /**
     * Genera il campo hidden per il form
     */
    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="' . self::TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Genera il meta tag per AJAX
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * Verifica se la richiesta è AJAX
     */
    private static function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
