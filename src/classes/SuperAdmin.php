<?php
/**
 * SuperAdmin — autenticazione e helper per la pagina di gestione tenant.
 *
 * Credenziali in ENV (non in DB):
 *   SUPERADMIN_USER          username in chiaro
 *   SUPERADMIN_PASS_HASH     bcrypt della password
 *   SUPERADMIN_TOTP_SECRET   secret Base32 per TOTP (RFC 6238)
 *
 * Genera le credenziali con: php tools/superadmin-init.php
 */
class SuperAdmin
{
    private const SESSION_KEY    = 'superadmin_authed';
    private const SESSION_STAGE  = 'superadmin_stage'; // 'pwd_ok' (manca TOTP) | 'authed'
    private const SESSION_TS     = 'superadmin_login_ts';
    private const MAX_SESSION_S  = 3600; // 1h idle, poi richiede re-login

    public static function configured(): bool
    {
        return getenv('SUPERADMIN_USER') !== false
            && getenv('SUPERADMIN_PASS_HASH') !== false
            && getenv('SUPERADMIN_TOTP_SECRET') !== false;
    }

    public static function checkPassword(string $username, string $password): bool
    {
        $expectedUser = (string) getenv('SUPERADMIN_USER');
        $hash         = (string) getenv('SUPERADMIN_PASS_HASH');
        if ($expectedUser === '' || $hash === '') return false;
        if (!hash_equals($expectedUser, $username)) return false;
        return password_verify($password, $hash);
    }

    public static function checkTotp(string $code): bool
    {
        $secret = (string) getenv('SUPERADMIN_TOTP_SECRET');
        if ($secret === '') return false;
        return MFA::verifyCode($secret, $code);
    }

    public static function markPasswordOk(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION[self::SESSION_STAGE] = 'pwd_ok';
        $_SESSION[self::SESSION_TS] = time();
    }

    public static function markFullyAuthed(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_STAGE] = 'authed';
        $_SESSION[self::SESSION_TS] = time();
    }

    public static function isPasswordOk(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION[self::SESSION_STAGE])) return false;
        if (($_SESSION[self::SESSION_STAGE] ?? '') !== 'pwd_ok') return false;
        return (time() - (int)($_SESSION[self::SESSION_TS] ?? 0)) < self::MAX_SESSION_S;
    }

    public static function isAuthed(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION[self::SESSION_KEY])) return false;
        if (($_SESSION[self::SESSION_STAGE] ?? '') !== 'authed') return false;
        return (time() - (int)($_SESSION[self::SESSION_TS] ?? 0)) < self::MAX_SESSION_S;
    }

    public static function requireAuth(): void
    {
        if (!self::isAuthed()) {
            header('Location: ' . PUBLIC_URL . '/superadmin/login.php');
            exit;
        }
        // Rinnova TS su ogni richiesta autenticata
        $_SESSION[self::SESSION_TS] = time();
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_STAGE], $_SESSION[self::SESSION_TS]);
        session_regenerate_id(true);
    }

    /**
     * Crea un nuovo tenant: azienda + admin user + reset-token + email primo accesso.
     * Ritorna ['success'=>bool, 'company_id'=>int|null, 'user_id'=>int|null, 'error'=>?string].
     */
    public static function createTenant(string $companyName, string $adminName, string $adminEmail, string $adminUsername): array
    {
        $companyName  = trim($companyName);
        $adminName    = trim($adminName);
        $adminEmail   = trim($adminEmail);
        $adminUsername= trim($adminUsername);

        if ($companyName === '') return ['success' => false, 'error' => 'Nome azienda obbligatorio'];
        if ($adminName === '')   return ['success' => false, 'error' => 'Nome admin obbligatorio'];
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Email non valida'];
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $adminUsername)) return ['success' => false, 'error' => 'Username non valido (3-50 char, lettere/numeri/._)'];

        if (Database::exists('users', 'username = ?', [$adminUsername])) {
            return ['success' => false, 'error' => 'Username gia in uso'];
        }
        if (Database::exists('users', 'LOWER(email) = LOWER(?)', [$adminEmail])) {
            return ['success' => false, 'error' => 'Email gia in uso su un altro utente'];
        }

        try {
            $companyId = Database::insert('companies', [
                'name' => $companyName,
                'is_active' => 1,
                'needs_setup' => 0,
            ]);

            // Placeholder password: l'admin la sceglie via link primo accesso
            $randomPw = bin2hex(random_bytes(16));
            $userId = Database::insert('users', [
                'company_id'    => $companyId,
                'username'      => $adminUsername,
                'password_hash' => password_hash($randomPw, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]),
                'role'          => 'admin',
                'name'          => $adminName,
                'email'         => $adminEmail,
                'is_active'     => 1,
            ]);

            // Token primo accesso (48h)
            $token = bin2hex(random_bytes(32));
            Database::insert('password_reset_tokens', [
                'user_type'  => 'admin',
                'user_id'    => $userId,
                'token'      => $token,
                'expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
                'ip_address' => getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);

            // Email primo accesso
            $emailSent = self::sendWelcomeEmail($adminEmail, $adminName, $companyName, $token);

            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_tenant_created', 'company', $companyId, null, null, null, [
                    'admin_user_id' => $userId,
                    'email_sent' => $emailSent,
                ]); } catch (Throwable $e) {}
            }

            return ['success' => true, 'company_id' => $companyId, 'user_id' => $userId, 'email_sent' => $emailSent];
        } catch (Throwable $e) {
            error_log('[SuperAdmin::createTenant] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante la creazione: ' . $e->getMessage()];
        }
    }

    private static function sendWelcomeEmail(string $email, string $name, string $companyName, string $token): bool
    {
        if (!class_exists('Mailer') || !Mailer::isConfigured()) return false;
        $base = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : '';
        $url  = $base . '/auth/reset-password.php?token=' . urlencode($token);
        $companySafe = htmlspecialchars($companyName);
        $nameSafe = htmlspecialchars($name);

        $html = "<p>Ciao {$nameSafe},</p>"
              . "<p>E' stato creato un account amministratore per <strong>{$companySafe}</strong> sul portale PAManager.</p>"
              . "<p>Per attivarlo e impostare la tua password clicca qui sotto (link valido 48 ore):</p>"
              . "<p><a href=\"{$url}\" style=\"display:inline-block;padding:12px 24px;background:#0b3aa4;color:white;border-radius:8px;text-decoration:none;font-weight:600;\">Attiva account</a></p>"
              . "<p style=\"font-size:12px;color:#64748b;\">Se il bottone non funziona copia questo link: {$url}</p>";
        $text = "Ciao {$name},\n\nE' stato creato un account amministratore per {$companyName} sul portale PAManager.\n\n"
              . "Attiva il tuo account impostando la password al seguente link (valido 48h):\n{$url}\n";
        return Mailer::send($email, $name, "Benvenuto su PAManager — attiva il tuo account", $html, $text);
    }

    /**
     * Reinvio link primo accesso (rigenera token + email).
     */
    public static function resendWelcome(int $userId): array
    {
        $u = Database::fetchOne("SELECT id, name, email, company_id FROM users WHERE id = ? AND role = 'admin'", [$userId]);
        if (!$u) return ['success' => false, 'error' => 'Admin non trovato'];
        if (empty($u['email'])) return ['success' => false, 'error' => 'Admin senza email'];
        $c = Database::fetchOne("SELECT name FROM companies WHERE id = ?", [$u['company_id']]);
        $token = bin2hex(random_bytes(32));
        Database::insert('password_reset_tokens', [
            'user_type'  => 'admin',
            'user_id'    => $userId,
            'token'      => $token,
            'expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
        $sent = self::sendWelcomeEmail($u['email'], $u['name'] ?? '', $c['name'] ?? '', $token);
        return ['success' => $sent, 'error' => $sent ? null : 'Invio email fallito'];
    }

    /** Disattiva sia l'azienda che l'admin principale. */
    public static function deactivateTenant(int $companyId): array
    {
        try {
            Database::update('companies', ['is_active' => 0], 'id = ?', [$companyId]);
            Database::execute("UPDATE users SET is_active = 0 WHERE company_id = ? AND role = 'admin'", [$companyId]);
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_tenant_deactivated', 'company', $companyId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Riattiva azienda + admin principale. */
    public static function activateTenant(int $companyId): array
    {
        try {
            Database::update('companies', ['is_active' => 1], 'id = ?', [$companyId]);
            Database::execute("UPDATE users SET is_active = 1 WHERE company_id = ? AND role = 'admin'", [$companyId]);
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_tenant_activated', 'company', $companyId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Elimina completamente: azienda + dipendenti + utenti + dati. Irreversibile. */
    public static function deleteTenant(int $companyId): array
    {
        try {
            // Stessa cascata di public/admin/companies.php (delete action)
            Database::execute("DELETE FROM chat_messages WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM chat_conversations WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM notifications WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM push_subscriptions WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM app_settings WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM communications WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM employees WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM departments WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM user_companies WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM users WHERE company_id = ?", [$companyId]);
            Database::execute("DELETE FROM companies WHERE id = ?", [$companyId]);
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_tenant_deleted', 'company', $companyId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Lista tenant (1 azienda = 1 riga) con info admin principale. */
    public static function listTenants(): array
    {
        return Database::fetchAll(
            "SELECT c.id AS company_id, c.name AS company_name, c.is_active AS company_active,
                    c.created_at,
                    u.id AS admin_id, u.name AS admin_name, u.email AS admin_email,
                    u.username AS admin_username, u.is_active AS admin_active,
                    u.last_login,
                    (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.id AND e.is_active = 1) AS employees_count
             FROM companies c
             LEFT JOIN users u ON u.company_id = c.id AND u.role = 'admin'
             ORDER BY c.created_at DESC"
        );
    }
}
