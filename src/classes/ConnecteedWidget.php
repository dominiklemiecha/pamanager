<?php
/**
 * Widget assistenza del CRM Connecteed.
 *
 * Il widget (script servito dal CRM) chiede a questo gestionale una
 * dichiarazione firmata di chi e' l'utente loggato: un JWT HS256 valido
 * 5 minuti, firmato con la chiave dell'integrazione. La chiave resta sul
 * server e non arriva mai al browser.
 *
 * ENV:
 *   CONNECTEED_APP_ID       identificativo pubblico (sta anche nel tag script)
 *   CONNECTEED_APP_SECRET   chiave di firma, vista una sola volta nel CRM
 */
class ConnecteedWidget
{
    public const SCRIPT_URL = 'https://crm.connecteed.com/api/v1/widget/v1.js';
    public const ORIGIN     = 'https://crm.connecteed.com';

    private static function env(string $key): string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $v) {
            if ($v !== false && $v !== null && trim((string) $v) !== '') return trim((string) $v);
        }
        return '';
    }

    public static function appId(): string
    {
        return self::env('CONNECTEED_APP_ID');
    }

    public static function configured(): bool
    {
        return self::appId() !== '' && self::env('CONNECTEED_APP_SECRET') !== '';
    }

    /** Tag script da mettere nelle pagine di chi ha fatto l'accesso. */
    public static function scriptTag(): string
    {
        if (!self::configured() || !Auth::isLoggedIn()) return '';
        return '<script src="' . self::SCRIPT_URL . '"'
            . ' data-app-id="' . htmlspecialchars(self::appId(), ENT_QUOTES) . '"'
            . ' data-session-url="' . htmlspecialchars(PUBLIC_URL . '/api/assistenza-sessione.php', ENT_QUOTES) . '"'
            . ' async></script>';
    }

    /** Identita' dell'utente in sessione, o null se nessuno e' loggato. */
    public static function currentIdentity(): ?array
    {
        if (Auth::isUserLoggedIn()) {
            $u = Auth::getUser() ?? [];
            return [
                'sub'  => (string) (($u['email'] ?? '') ?: ($u['username'] ?? '')),
                'name' => (string) (($u['name'] ?? '') ?: ($u['username'] ?? '')),
                'role' => (string) ($u['role'] ?? ''),
            ];
        }
        if (Auth::isEmployeeLoggedIn()) {
            $e = Auth::getEmployee() ?? [];
            $name = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
            return [
                'sub'  => (string) (($e['email'] ?? '') ?: ($e['username'] ?? '')),
                'name' => $name !== '' ? $name : (string) ($e['username'] ?? ''),
                'role' => 'employee',
            ];
        }
        return null;
    }

    /** Firma la dichiarazione di identita' per il widget. */
    public static function signToken(array $identity, array $ctx = []): string
    {
        $now = time();
        $payload = [
            'jti'  => bin2hex(random_bytes(16)),
            'iss'  => self::appId(),
            'aud'  => 'connecteed-widget',
            'iat'  => $now,
            'exp'  => $now + 300,
            'sub'  => $identity['sub'],
            'name' => $identity['name'],
            'role' => $identity['role'],
            'ctx'  => $ctx,
        ];
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig  = $b64(hash_hmac('sha256', $head . '.' . $body, self::env('CONNECTEED_APP_SECRET'), true));
        return $head . '.' . $body . '.' . $sig;
    }
}
