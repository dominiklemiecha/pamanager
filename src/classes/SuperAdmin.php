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

    /** Path persistente (volume Dokploy) per credenziali quando l'env del container non e' affidabile. */
    private const PERSIST_FILE = '/var/www/html/storage/superadmin.env';

    private static ?array $persistCache = null;

    private static function loadPersistFile(): array
    {
        if (self::$persistCache !== null) return self::$persistCache;
        $out = [];
        if (is_readable(self::PERSIST_FILE)) {
            $lines = @file(self::PERSIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '' || $ln[0] === '#') continue;
                $pos = strpos($ln, '=');
                if ($pos === false) continue;
                $k = trim(substr($ln, 0, $pos));
                $v = trim(substr($ln, $pos + 1));
                $v = trim($v, "\"'");
                if ($k !== '') $out[$k] = $v;
            }
        }
        return self::$persistCache = $out;
    }

    private static function env(string $key): string
    {
        // 1) File persistente (sopravvive a redeploy Dokploy)
        $persist = self::loadPersistFile();
        if (!empty($persist[$key])) return (string) $persist[$key];

        // 2) Env del container / Apache
        $candidates = [
            getenv($key),
            $_ENV[$key] ?? null,
            $_SERVER[$key] ?? null,
        ];
        foreach ($candidates as $v) {
            if ($v !== false && $v !== null && $v !== '') return (string) $v;
        }
        return '';
    }

    public static function configured(): bool
    {
        return self::env('SUPERADMIN_USER') !== ''
            && self::env('SUPERADMIN_PASS_HASH') !== ''
            && self::env('SUPERADMIN_TOTP_SECRET') !== '';
    }

    public static function checkPassword(string $username, string $password): bool
    {
        $expectedUser = self::env('SUPERADMIN_USER');
        $hash         = self::env('SUPERADMIN_PASS_HASH');
        if ($expectedUser === '' || $hash === '') return false;
        if (!hash_equals($expectedUser, $username)) return false;
        return password_verify($password, $hash);
    }

    public static function checkTotp(string $code): bool
    {
        $secret = self::env('SUPERADMIN_TOTP_SECRET');
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

    /**
     * Company da cui inviare le email di sistema del superadmin.
     * Il pannello superadmin non ha tenant corrente: usiamo la prima azienda
     * con SMTP abilitato (di norma la principale, es. Connecteed).
     */
    private static function systemMailCompanyId(): ?int
    {
        $row = Database::fetchOne(
            "SELECT company_id FROM app_settings
             WHERE setting_key = 'smtp_enabled' AND setting_value = '1'
             ORDER BY company_id LIMIT 1"
        );
        return $row ? (int)$row['company_id'] : null;
    }

    private static function sendWelcomeEmail(string $email, string $name, string $companyName, string $token): bool
    {
        if (!class_exists('Mailer') || !class_exists('Settings')) return false;
        // Forza lo SMTP della company di sistema (il superadmin non ha tenant corrente)
        $mailCid = self::systemMailCompanyId();
        if ($mailCid === null) return false;
        Settings::forceCompany($mailCid);
        try {
            return self::doSendWelcome($email, $name, $companyName, $token);
        } finally {
            Settings::forceCompany(null);
        }
    }

    private static function doSendWelcome(string $email, string $name, string $companyName, string $token): bool
    {
        if (!Mailer::isConfigured()) return false;
        // Preferisci APP_URL (ha lo schema https). PUBLIC_URL e' solo path -> nei mail
        // client diventa http:// e viene bloccato dal redirect HTTPS.
        $base = defined('APP_URL') && !empty(APP_URL) ? rtrim(APP_URL, '/') : (defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : '');
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

    /** Tutte le aziende del sistema (per assegnazione). */
    public static function allCompanies(): array
    {
        return Database::fetchAll("SELECT id, name, is_active FROM companies ORDER BY name");
    }

    /**
     * Imposta esattamente le aziende possedute da un admin (scrive user_companies).
     * Cosi' un admin con company_id NULL smette di essere "globale" e vede solo queste.
     */
    public static function setAdminCompanies(int $userId, array $companyIds): array
    {
        $u = Database::fetchOne("SELECT id FROM users WHERE id = ? AND role = 'admin'", [$userId]);
        if (!$u) return ['success' => false, 'error' => 'Admin non trovato'];
        $ids = array_values(array_unique(array_filter(array_map('intval', $companyIds), fn($v) => $v > 0)));
        try {
            Database::delete('user_companies', 'user_id = ?', [$userId]);
            foreach ($ids as $cid) {
                try { Database::insert('user_companies', ['user_id' => $userId, 'company_id' => $cid]); }
                catch (Throwable $e) { error_log('[setAdminCompanies] ' . $e->getMessage()); }
            }
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_admin_companies_set', 'user', $userId, null, ['company_ids' => $ids]); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Lista admin tenant (1 admin = 1 riga) con elenco aziende gestite. */
    public static function listAdmins(): array
    {
        $admins = Database::fetchAll(
            "SELECT id, username, name, email, company_id, is_active, last_login, created_at
             FROM users WHERE role = 'admin' ORDER BY created_at DESC"
        );
        foreach ($admins as &$a) {
            $a['companies'] = self::companiesForAdmin((int)$a['id'], $a['company_id']);
            $a['employees_count'] = 0;
            $ids = array_column($a['companies'], 'id');
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $row = Database::fetchOne(
                    "SELECT COUNT(*) AS n FROM employees WHERE is_active = 1 AND company_id IN ($ph)",
                    array_map('intval', $ids)
                );
                $a['employees_count'] = (int)($row['n'] ?? 0);
            }
        }
        unset($a);
        return $admins;
    }

    /**
     * Aziende gestite da un admin:
     *  - admin globale (company_id NULL): tutte le aziende attive (modello legacy single-tenant)
     *  - admin tenant: la sua company_id + eventuali link via user_companies
     */
    public static function companiesForAdmin(int $userId, ?int $primaryCompanyId): array
    {
        $linked = Database::fetchAll("SELECT company_id FROM user_companies WHERE user_id = ?", [$userId]);
        // Admin globale "vero" (NULL + zero link): tutte le aziende
        if ($primaryCompanyId === null && empty($linked)) {
            return Database::fetchAll("SELECT id, name, is_active FROM companies ORDER BY name");
        }
        $ids = [];
        if ($primaryCompanyId !== null) $ids[] = (int)$primaryCompanyId;
        foreach ($linked as $l) {
            $cid = (int)$l['company_id'];
            if ($cid > 0 && !in_array($cid, $ids, true)) $ids[] = $cid;
        }
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT id, name, is_active FROM companies WHERE id IN ($ph) ORDER BY name",
            array_map('intval', $ids)
        );
    }

    /** Disattiva admin (e tutte le sue aziende). */
    public static function deactivateAdmin(int $userId): array
    {
        try {
            $u = Database::fetchOne("SELECT id, company_id FROM users WHERE id = ? AND role = 'admin'", [$userId]);
            if (!$u) return ['success' => false, 'error' => 'Admin non trovato'];
            Database::update('users', ['is_active' => 0], 'id = ?', [$userId]);
            $companies = self::companiesForAdmin($userId, $u['company_id']);
            foreach ($companies as $c) {
                Database::update('companies', ['is_active' => 0], 'id = ?', [(int)$c['id']]);
            }
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_admin_deactivated', 'user', $userId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function activateAdmin(int $userId): array
    {
        try {
            $u = Database::fetchOne("SELECT id, company_id FROM users WHERE id = ? AND role = 'admin'", [$userId]);
            if (!$u) return ['success' => false, 'error' => 'Admin non trovato'];
            Database::update('users', ['is_active' => 1], 'id = ?', [$userId]);
            $companies = self::companiesForAdmin($userId, $u['company_id']);
            foreach ($companies as $c) {
                Database::update('companies', ['is_active' => 1], 'id = ?', [(int)$c['id']]);
            }
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_admin_activated', 'user', $userId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Elimina admin + tutte le sue aziende e dati. Irreversibile. */
    public static function deleteAdmin(int $userId): array
    {
        try {
            $u = Database::fetchOne("SELECT id, company_id FROM users WHERE id = ? AND role = 'admin'", [$userId]);
            if (!$u) return ['success' => false, 'error' => 'Admin non trovato'];
            $companies = self::companiesForAdmin($userId, $u['company_id']);
            foreach ($companies as $c) {
                self::deleteTenant((int)$c['id']);
            }
            // Cancella eventuale admin globale rimasto (se company_id NULL non e' stato toccato)
            Database::execute("DELETE FROM user_companies WHERE user_id = ?", [$userId]);
            Database::execute("DELETE FROM users WHERE id = ?", [$userId]);
            if (class_exists('AuditLog')) {
                try { AuditLog::log('superadmin_admin_deleted', 'user', $userId); } catch (Throwable $e) {}
            }
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
