<?php
/**
 * Classe Auth - Autenticazione e gestione sessioni
 * PAManager - Comune
 *
 * Sicurezza implementata:
 * - Password policy (12+ caratteri, complessità)
 * - Session timeout per ruolo
 * - Blocco account dopo 5 tentativi
 * - Audit logging completo
 * - Protezione session hijacking
 * - MFA support (opzionale)
 */

class Auth
{
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_EMPLOYEE_KEY = 'auth_employee';
    private const SESSION_ROLE_KEY = 'auth_role';

    /**
     * Inizializza la sessione sicura con timeout per ruolo
     */
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Configurazione sessione sicura
            ini_set('session.cookie_httponly', 1);
            // Cookie secure solo se HTTPS attivo (permette sviluppo locale su HTTP)
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            ini_set('session.cookie_secure', $isHttps ? 1 : 0);
            // Lax (non Strict): iOS PWA riapertura tratta la richiesta come cross-site con Strict e perde il cookie
            ini_set('session.cookie_samesite', 'Lax');
            // Cookie persistente: senza questo iOS lo cancella allo swipe-via della PWA
            ini_set('session.cookie_lifetime', SESSION_LIFETIME);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

            session_name(SESSION_NAME);
            session_start();
        }

        // Verifica timeout sessione basato sul ruolo
        if (isset($_SESSION['last_activity'])) {
            $timeout = self::getSessionTimeout();
            $elapsed = time() - $_SESSION['last_activity'];

            if ($elapsed > $timeout) {
                $userType = $_SESSION[self::SESSION_ROLE_KEY] ?? 'unknown';
                $userId = self::getCurrentUserId();

                AuditLog::log(
                    AuditLog::ACTION_SESSION_EXPIRED,
                    $userType,
                    $userId,
                    null,
                    null,
                    null,
                    ['timeout' => $timeout, 'elapsed' => $elapsed]
                );

                self::logout();
                return;
            }
        }

        // Verifica integrità sessione
        if (self::isLoggedIn() && !self::verifySessionIntegrity()) {
            return; // logout già eseguito in verifySessionIntegrity
        }

        $_SESSION['last_activity'] = time();
    }

    /**
     * Ottiene il timeout della sessione in base al ruolo
     */
    private static function getSessionTimeout(): int
    {
        $role = $_SESSION[self::SESSION_ROLE_KEY] ?? null;

        switch ($role) {
            case 'admin':
                return SESSION_TIMEOUT_ADMIN;
            case 'accountant':
            case 'admin_reparto':
                return SESSION_TIMEOUT_ACCOUNTANT;
            case 'employee':
                return SESSION_TIMEOUT_EMPLOYEE;
            default:
                return SESSION_LIFETIME;
        }
    }

    /**
     * Ottiene l'ID utente corrente
     */
    private static function getCurrentUserId(): int
    {
        if (isset($_SESSION[self::SESSION_USER_KEY])) {
            return $_SESSION[self::SESSION_USER_KEY]['id'];
        }
        if (isset($_SESSION[self::SESSION_EMPLOYEE_KEY])) {
            return $_SESSION[self::SESSION_EMPLOYEE_KEY]['id'];
        }
        return 0;
    }

    /**
     * Valida la complessità della password
     *
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = "La password deve contenere almeno " . PASSWORD_MIN_LENGTH . " caratteri";
        }

        if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera maiuscola";
        }

        if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera minuscola";
        }

        if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = "La password deve contenere almeno un numero";
        }

        if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
            $errors[] = "La password deve contenere almeno un carattere speciale (!@#$%^&*...)";
        }

        // Verifica password comuni
        $commonPasswords = ['password', '123456', 'qwerty', 'admin', 'letmein'];
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = "La password è troppo comune";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Hash password con Argon2id (preferito) o bcrypt
     */
    public static function hashPassword(string $password): string
    {
        // Usa Argon2id se disponibile (PHP 7.3+)
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);
        }

        // Fallback a bcrypt
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    }

    /**
     * Login utente (admin/commercialista)
     */
    public static function loginUser(string $username, string $password): array
    {
        $user = Database::fetchOne(
            "SELECT * FROM users WHERE username = ? AND is_active = TRUE",
            [$username]
        );

        if (!$user) {
            AuditLog::logLoginFailed('unknown', 0, 'user_not_found', ['username' => $username]);
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }

        // Verifica blocco account
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remainingTime = ceil((strtotime($user['locked_until']) - time()) / 60);
            AuditLog::logLoginFailed($user['role'], $user['id'], 'account_locked');
            return ['success' => false, 'error' => "Account bloccato. Riprova tra {$remainingTime} minuti."];
        }

        // Verifica password
        if (!password_verify($password, $user['password_hash'])) {
            self::incrementFailedAttempts('users', $user['id']);
            AuditLog::logLoginFailed($user['role'], $user['id'], 'wrong_password');
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }

        // Verifica se richiede MFA
        if (MFA_ENABLED && in_array($user['role'], MFA_REQUIRED_ROLES)) {
            if (!empty($user['mfa_secret']) && $user['mfa_enabled']) {
                // Salva dati temporanei per step MFA
                $_SESSION['mfa_pending'] = [
                    'user_id' => $user['id'],
                    'user_type' => 'user',
                    'timestamp' => time()
                ];
                return ['success' => true, 'requires_mfa' => true, 'user_id' => $user['id']];
            }
        }

        // Completa il login
        return self::completeUserLogin($user);
    }

    /**
     * Completa il login utente dopo eventuali verifiche MFA
     */
    public static function completeUserLogin(array $user): array
    {
        // Reset tentativi falliti e aggiorna ultimo login
        Database::update('users', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login' => date('Y-m-d H:i:s')
        ], 'id = ?', [$user['id']]);

        // Rigenera ID sessione per prevenire session fixation
        session_regenerate_id(true);

        // Salva dati utente in sessione.
        // company_id e' CRITICO: senza, Tenant::accessibleCompanyIds tratta
        // qualunque admin come "globale" e gli mostra TUTTI i tenant.
        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'name' => $user['name'],
            'email' => $user['email'],
            'company_id' => $user['company_id'] ?? null,
            'department_id' => $user['department_id'] ?? null,
            'mfa_enabled' => $user['mfa_enabled'] ?? false
        ];
        $_SESSION[self::SESSION_ROLE_KEY] = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['login_time'] = time();

        // Rimuovi dati MFA pending
        unset($_SESSION['mfa_pending']);

        AuditLog::logLogin($user['role'], $user['id'], [
            'username' => $user['username'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        return ['success' => true, 'user' => $_SESSION[self::SESSION_USER_KEY]];
    }

    /**
     * Login dipendente con username/codice fiscale e password
     */
    public static function loginEmployee(string $username, string $password): array
    {
        $usernameNormalized = trim($username);
        $fiscalCodeNormalized = strtoupper(trim($username));

        // Username/codice fiscale sono unici per azienda (migration 039). Se lo stesso
        // valore esiste in piu' aziende dello stesso tenant, prendiamo il record con
        // ultimo login piu' recente, poi id maggiore (deterministico).
        $employee = Database::fetchOne(
            "SELECT * FROM employees WHERE (username = ? OR fiscal_code = ?) AND is_active = TRUE
             ORDER BY last_login DESC, id DESC LIMIT 1",
            [$usernameNormalized, $fiscalCodeNormalized]
        );

        if (!$employee) {
            AuditLog::logLoginFailed('employee', 0, 'employee_not_found', ['username' => $username]);
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }

        // Verifica blocco account
        if ($employee['locked_until'] && strtotime($employee['locked_until']) > time()) {
            $remainingTime = ceil((strtotime($employee['locked_until']) - time()) / 60);
            AuditLog::logLoginFailed('employee', $employee['id'], 'account_locked');
            return ['success' => false, 'error' => "Account bloccato. Riprova tra {$remainingTime} minuti."];
        }

        // Verifica password
        if (!password_verify($password, $employee['password_hash'])) {
            self::incrementFailedAttempts('employees', $employee['id']);
            AuditLog::logLoginFailed('employee', $employee['id'], 'wrong_password');
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }

        // Reset tentativi falliti e aggiorna ultimo login
        Database::update('employees', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login' => date('Y-m-d H:i:s')
        ], 'id = ?', [$employee['id']]);

        session_regenerate_id(true);

        $_SESSION[self::SESSION_EMPLOYEE_KEY] = [
            'id' => $employee['id'],
            'username' => $employee['username'],
            'fiscal_code' => $employee['fiscal_code'],
            'first_name' => $employee['first_name'],
            'last_name' => $employee['last_name'],
            'email' => $employee['email'],
            'department_id' => $employee['department_id'] ?? null,
            'company_id' => $employee['company_id'] ?? null,
        ];
        // Reset tenant-switcher dell'eventuale sessione admin precedente:
        // il dipendente è tied alla sua company, non deve mai vedere altre tenant.
        unset($_SESSION['tenant_company_id']);
        $_SESSION[self::SESSION_ROLE_KEY] = 'employee';
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['login_time'] = time();

        AuditLog::logLogin('employee', $employee['id'], [
            'username' => $employee['username'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        return ['success' => true, 'employee' => $_SESSION[self::SESSION_EMPLOYEE_KEY]];
    }

    /**
     * Incrementa tentativi falliti con notifica se raggiunto limite
     */
    private static function incrementFailedAttempts(string $table, int $id): void
    {
        $record = Database::fetchOne("SELECT failed_attempts FROM {$table} WHERE id = ?", [$id]);
        $attempts = ($record['failed_attempts'] ?? 0) + 1;

        $data = ['failed_attempts' => $attempts];

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);

            // Log alert critico
            AuditLog::logSuspiciousActivity(
                $table === 'users' ? 'user' : 'employee',
                $id,
                'Account locked after max failed attempts',
                ['attempts' => $attempts, 'lockout_minutes' => LOCKOUT_TIME / 60]
            );
        }

        Database::update($table, $data, 'id = ?', [$id]);
    }

    /**
     * Verifica integrità sessione (protezione hijacking)
     */
    private static function verifySessionIntegrity(): bool
    {
        $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Verifica cambio User Agent (potenziale hijack)
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $currentAgent) {
            $userType = $_SESSION[self::SESSION_ROLE_KEY] ?? 'unknown';
            $userId = self::getCurrentUserId();

            AuditLog::logSessionHijackAttempt($userType, $userId, [
                'original_ua' => $_SESSION['user_agent'],
                'current_ua' => $currentAgent,
                'original_ip' => $_SESSION['ip_address'] ?? '',
                'current_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            self::logout();
            return false;
        }

        return true;
    }

    /**
     * Logout con logging
     */
    public static function logout(): void
    {
        if (self::isUserLoggedIn()) {
            AuditLog::logLogout($_SESSION[self::SESSION_USER_KEY]['role'], $_SESSION[self::SESSION_USER_KEY]['id']);
        } elseif (self::isEmployeeLoggedIn()) {
            AuditLog::logLogout('employee', $_SESSION[self::SESSION_EMPLOYEE_KEY]['id']);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Verifica se l'utente (admin/commercialista) è loggato
     */
    public static function isUserLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_USER_KEY]);
    }

    /**
     * Verifica se il dipendente è loggato
     */
    public static function isEmployeeLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_EMPLOYEE_KEY]);
    }

    /**
     * Verifica se qualcuno è loggato
     */
    public static function isLoggedIn(): bool
    {
        return self::isUserLoggedIn() || self::isEmployeeLoggedIn();
    }

    /**
     * Ottiene i dati dell'utente loggato
     */
    public static function getUser(): ?array
    {
        $user = $_SESSION[self::SESSION_USER_KEY] ?? null;
        if (!$user || empty($user['id'])) return $user;
        // Hydration retroattiva: sessioni create prima del fix che salva company_id
        // (vedi completeUserLogin). Senza il check su array_key_exists distinguiamo
        // "campo assente" (session vecchia) da "company_id NULL" (admin globale legittimo).
        if (!array_key_exists('company_id', $user)) {
            try {
                $row = Database::fetchOne("SELECT company_id, department_id FROM users WHERE id = ?", [(int)$user['id']]);
                if ($row !== null) {
                    $user['company_id'] = $row['company_id'] !== null ? (int)$row['company_id'] : null;
                    if (empty($user['department_id'])) $user['department_id'] = $row['department_id'] ?? null;
                    $_SESSION[self::SESSION_USER_KEY] = $user;
                    unset($_SESSION['tenant_company_id']);
                }
            } catch (Throwable $e) { /* fallback silenzioso */ }
        }
        return $user;
    }

    /**
     * Ottiene i dati del dipendente loggato
     */
    public static function getEmployee(): ?array
    {
        $emp = $_SESSION[self::SESSION_EMPLOYEE_KEY] ?? null;
        if (!$emp || empty($emp['id'])) return $emp;
        // Hydration retroattiva: sessioni create prima dell'aggiunta di company_id
        if (empty($emp['company_id'])) {
            try {
                $row = Database::fetchOne(
                    "SELECT company_id, department_id FROM employees WHERE id = ?",
                    [(int) $emp['id']]
                );
                if ($row && !empty($row['company_id'])) {
                    $emp['company_id'] = (int) $row['company_id'];
                    if (empty($emp['department_id'])) $emp['department_id'] = $row['department_id'] ?? null;
                    $_SESSION[self::SESSION_EMPLOYEE_KEY] = $emp;
                    // Allinea anche il tenant switcher
                    unset($_SESSION['tenant_company_id']);
                }
            } catch (Throwable $e) { /* ignora */ }
        }
        return $emp;
    }

    /**
     * Ricarica i dati dell'utente staff dal DB nella sessione (dopo update profilo).
     */
    public static function refreshUser(): void
    {
        $current = self::getUser();
        if (!$current || empty($current['id'])) return;
        $fresh = Database::fetchOne("SELECT * FROM users WHERE id = ?", [(int) $current['id']]);
        if ($fresh) {
            $_SESSION[self::SESSION_USER_KEY] = array_merge($current, $fresh);
        }
    }

    /**
     * Ricarica i dati del dipendente dal DB nella sessione (dopo update profilo).
     */
    public static function refreshEmployee(): void
    {
        $current = self::getEmployee();
        if (!$current || empty($current['id'])) return;
        $fresh = Employee::getById((int) $current['id']);
        if ($fresh) {
            // Mantiene eventuali campi non-DB già in sessione
            $_SESSION[self::SESSION_EMPLOYEE_KEY] = array_merge($current, $fresh);
        }
    }

    /**
     * Verifica se l'utente ha un ruolo specifico
     */
    public static function hasRole(string $role): bool
    {
        $user = self::getUser();
        return $user && $user['role'] === $role;
    }

    /**
     * Verifica se è admin
     */
    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    /**
     * Verifica se è commercialista
     */
    public static function isAccountant(): bool
    {
        return self::hasRole('accountant');
    }

    /**
     * Verifica se è admin reparto
     */
    public static function isAdminReparto(): bool
    {
        return self::hasRole('admin_reparto');
    }

    /**
     * Ottiene il department_id dell'utente loggato
     */
    public static function getDepartmentId(): ?int
    {
        $user = self::getUser();
        return $user['department_id'] ?? null;
    }

    /**
     * Richiede autenticazione utente
     * @param string|array|null $role Ruolo o array di ruoli consentiti
     */
    public static function requireUser(string|array|null $role = null): void
    {
        if (!self::isUserLoggedIn()) {
            header('Location: ' . PUBLIC_URL . '/auth/login.php');
            exit;
        }

        if ($role !== null) {
            $allowedRoles = is_array($role) ? $role : [$role];
            $userRole = $_SESSION[self::SESSION_USER_KEY]['role'];

            if (!in_array($userRole, $allowedRoles)) {
                AuditLog::logUnauthorizedAccess($_SERVER['REQUEST_URI'] ?? '', [
                    'required_role' => $role,
                    'actual_role' => $userRole
                ]);
                http_response_code(403);
                echo 'Accesso non autorizzato';
                exit;
            }
        }
    }

    /**
     * Richiede autenticazione admin reparto e verifica appartenenza reparto
     */
    public static function requireAdminReparto(?int $departmentId = null): void
    {
        self::requireUser('admin_reparto');

        if ($departmentId !== null) {
            $userDeptId = self::getDepartmentId();
            if ($userDeptId !== $departmentId) {
                AuditLog::logUnauthorizedAccess($_SERVER['REQUEST_URI'] ?? '', [
                    'required_department' => $departmentId,
                    'actual_department' => $userDeptId
                ]);
                http_response_code(403);
                echo 'Accesso non autorizzato a questo reparto';
                exit;
            }
        }
    }

    /**
     * Richiede autenticazione dipendente
     */
    public static function requireEmployee(): void
    {
        if (!self::isEmployeeLoggedIn()) {
            header('Location: ' . PUBLIC_URL . '/auth/login.php');
            exit;
        }
    }

    /**
     * Cambia password utente con validazione
     */
    public static function changeUserPassword(int $userId, string $currentPassword, string $newPassword): array
    {
        // Valida nuova password
        $validation = self::validatePassword($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $user = Database::fetchOne("SELECT password_hash, role FROM users WHERE id = ?", [$userId]);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Password attuale non corretta'];
        }

        // Verifica che la nuova password sia diversa dalla vecchia
        if (password_verify($newPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'La nuova password deve essere diversa dalla precedente'];
        }

        $newHash = self::hashPassword($newPassword);
        Database::update('users', ['password_hash' => $newHash], 'id = ?', [$userId]);

        AuditLog::logPasswordChange($user['role'], $userId);

        return ['success' => true];
    }

    /**
     * Cambia password dipendente con validazione
     */
    public static function changeEmployeePassword(int $employeeId, string $currentPassword, string $newPassword): array
    {
        // Valida nuova password
        $validation = self::validatePassword($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $employee = Database::fetchOne("SELECT password_hash FROM employees WHERE id = ?", [$employeeId]);

        if (!$employee || !password_verify($currentPassword, $employee['password_hash'])) {
            return ['success' => false, 'error' => 'Password attuale non corretta'];
        }

        if (password_verify($newPassword, $employee['password_hash'])) {
            return ['success' => false, 'error' => 'La nuova password deve essere diversa dalla precedente'];
        }

        $newHash = self::hashPassword($newPassword);
        Database::update('employees', ['password_hash' => $newHash], 'id = ?', [$employeeId]);

        AuditLog::logPasswordChange('employee', $employeeId);

        return ['success' => true];
    }

    /**
     * Resetta password (genera nuova password)
     */
    public static function resetPassword(string $table, int $id): array
    {
        // Genera password sicura temporanea
        $tempPassword = self::generateSecurePassword();
        $hash = self::hashPassword($tempPassword);

        Database::update($table, [
            'password_hash' => $hash,
            'failed_attempts' => 0,
            'locked_until' => null
        ], 'id = ?', [$id]);

        $userType = $table === 'users' ? 'admin' : 'employee';
        AuditLog::log(AuditLog::ACTION_PASSWORD_RESET, $userType, $id);

        return ['success' => true, 'password' => $tempPassword];
    }

    /**
     * Genera password sicura
     */
    public static function generateSecurePassword(int $length = 16): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $allChars = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Ottiene tempo rimanente sessione
     */
    public static function getSessionTimeRemaining(): int
    {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }

        $timeout = self::getSessionTimeout();
        $elapsed = time() - $_SESSION['last_activity'];

        return max(0, $timeout - $elapsed);
    }

    // ===== PASSWORD RESET SYSTEM =====

    /**
     * Crea richiesta di reset password
     */
    public static function createPasswordResetRequest(string $identifier): array
    {
        $userType = null;
        $userId = null;
        $userData = null;

        // Prima cerca negli utenti (admin/commercialista)
        $user = Database::fetchOne(
            "SELECT id, username, email, role, name FROM users WHERE username = ? OR email = ?",
            [$identifier, $identifier]
        );

        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
            $userData = $user;
        } else {
            // Cerca nei dipendenti (per username, email o codice fiscale)
            $employee = Database::fetchOne(
                "SELECT id, username, email, fiscal_code, first_name, last_name
                 FROM employees
                 WHERE username = ? OR email = ? OR fiscal_code = ?",
                [$identifier, $identifier, strtoupper($identifier)]
            );

            if ($employee) {
                $userType = 'employee';
                $userId = $employee['id'];
                $userData = [
                    'id' => $employee['id'],
                    'name' => $employee['first_name'] . ' ' . $employee['last_name'],
                    'email' => $employee['email'],
                    'username' => $employee['username'] ?? $employee['fiscal_code']
                ];
            }
        }

        if (!$userType) {
            // Non rivelare se l'utente esiste o no
            return ['success' => true, 'message' => 'Se l\'utente esiste, riceverai istruzioni via email'];
        }

        // Verifica se esiste già una richiesta pending recente (ultimi 15 minuti)
        $existing = Database::fetchOne(
            "SELECT id FROM password_reset_requests
             WHERE user_type = ? AND user_id = ? AND status = 'pending'
             AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [$userType, $userId]
        );

        if ($existing) {
            return ['success' => true, 'message' => 'Richiesta già inviata. Controlla la tua email.'];
        }

        // Crea token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        try {
            // Inserisci token
            $tokenId = Database::insert('password_reset_tokens', [
                'user_type' => $userType,
                'user_id' => $userId,
                'token' => $token,
                'expires_at' => $expiresAt,
                'ip_address' => getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            // Tentativo di invio email automatico
            $emailSent = false;
            if (!empty($userData['email']) && class_exists('Mailer') && Mailer::isConfigured()) {
                $resetUrl = buildPublicUrl('/auth/reset-password.php?token=' . urlencode($token));
                $name = htmlspecialchars($userData['name'] ?? '');
                $html = "<p>Ciao {$name},</p>" .
                        "<p>Hai richiesto il reset della password per il tuo account PAManager.</p>" .
                        "<p><a href=\"{$resetUrl}\">Clicca qui per impostare una nuova password</a></p>" .
                        "<p>Il link è valido per 1 ora. Se non hai effettuato tu la richiesta puoi ignorare questa email.</p>";
                $text = "Ciao {$name},\n\nReset password PAManager: {$resetUrl}\nLink valido 1 ora.";
                $emailSent = Mailer::send($userData['email'], $userData['name'] ?? '', 'Recupero password PAManager', $html, $text);
            }

            // Se l'email è partita la richiesta è già evasa (status 'sent'), altrimenti
            // resta 'pending' in attesa di approvazione manuale dell'admin (fallback).
            Database::insert('password_reset_requests', [
                'user_type' => $userType,
                'user_id' => $userId,
                'token_id' => $tokenId,
                'requested_ip' => getClientIp(),
                'status' => $emailSent ? 'sent' : 'pending',
                'resolved_at' => $emailSent ? date('Y-m-d H:i:s') : null,
                'notes' => $emailSent ? 'Email inviata automaticamente' : null
            ]);

            // Log
            AuditLog::log(
                $emailSent ? 'password_reset_email_sent' : 'password_reset_requested',
                $userType,
                $userId,
                null,
                null,
                null,
                ['ip' => getClientIp(), 'email_sent' => $emailSent]
            );

            return [
                'success' => true,
                'message' => 'Se l\'utente esiste, riceverà istruzioni via email',
                'token' => $token,
                'user' => $userData,
                'email_sent' => $emailSent
            ];
        } catch (Exception $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante la richiesta. Riprova più tardi.'];
        }
    }

    /**
     * Verifica token di reset
     */
    public static function verifyResetToken(string $token): ?array
    {
        // I ruoli "staff" (admin/accountant/consulente_lavoro/admin_reparto) stanno
        // nella tabella users; solo 'employee' sta in employees.
        $tokenData = Database::fetchOne(
            "SELECT prt.*,
                    CASE WHEN prt.user_type <> 'employee'
                         THEN u.name ELSE e.first_name END as user_name,
                    CASE WHEN prt.user_type <> 'employee'
                         THEN u.email ELSE e.email END as user_email
             FROM password_reset_tokens prt
             LEFT JOIN users u ON prt.user_type <> 'employee' AND prt.user_id = u.id
             LEFT JOIN employees e ON prt.user_type = 'employee' AND prt.user_id = e.id
             WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL",
            [$token]
        );

        return $tokenData;
    }

    /**
     * Completa il reset della password con token
     */
    public static function completePasswordReset(string $token, string $newPassword): array
    {
        $tokenData = self::verifyResetToken($token);

        if (!$tokenData) {
            return ['success' => false, 'error' => 'Link non valido o scaduto'];
        }

        // Valida nuova password
        $validation = self::validatePassword($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $table = ($tokenData['user_type'] === 'employee') ? 'employees' : 'users';
        $hash = self::hashPassword($newPassword);

        try {
            // Aggiorna password
            Database::update($table, [
                'password_hash' => $hash,
                'failed_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$tokenData['user_id']]);

            // Marca token come usato
            Database::update('password_reset_tokens', [
                'used_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$tokenData['id']]);

            // Aggiorna richiesta
            Database::query(
                "UPDATE password_reset_requests SET status = 'completed', resolved_at = NOW()
                 WHERE token_id = ?",
                [$tokenData['id']]
            );

            // Log
            AuditLog::log('password_reset_completed', $tokenData['user_type'], $tokenData['user_id']);

            return ['success' => true, 'message' => 'Password aggiornata con successo'];
        } catch (Exception $e) {
            error_log('Password reset completion failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante il reset. Riprova più tardi.'];
        }
    }

    /**
     * Ottiene tutte le richieste di reset (per admin)
     */
    public static function getResetRequests(?string $status = null, int $limit = 50): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        // Scope: dipendenti dell'azienda corrente, oppure utenti (admin/accountant) della stessa azienda
        $sql = "SELECT prr.*,
                       CASE WHEN prr.user_type IN ('admin', 'accountant')
                            THEN u.name ELSE CONCAT(e.last_name, ' ', e.first_name) END as user_name,
                       CASE WHEN prr.user_type IN ('admin', 'accountant')
                            THEN u.email ELSE e.email END as user_email,
                       CASE WHEN prr.user_type IN ('admin', 'accountant')
                            THEN u.username ELSE e.fiscal_code END as user_identifier,
                       resolver.name as resolved_by_name
                FROM password_reset_requests prr
                LEFT JOIN users u ON prr.user_type IN ('admin', 'accountant') AND prr.user_id = u.id
                LEFT JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
                LEFT JOIN users resolver ON prr.resolved_by = resolver.id
                WHERE (
                    (prr.user_type = 'employee'  AND e.company_id = ?) OR
                    (prr.user_type IN ('accountant','admin') AND (u.company_id = ? OR u.company_id IS NULL))
                )";
        $params = [$cid, $cid];

        if ($status) {
            $sql .= " AND prr.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY prr.created_at DESC LIMIT ?";
        $params[] = $limit;

        try {
            return Database::fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Conta richieste reset pending (scope per azienda corrente)
     */
    public static function countPendingResetRequests(): int
    {
        try {
            $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM password_reset_requests prr
                 LEFT JOIN users u ON prr.user_type IN ('admin','accountant') AND prr.user_id = u.id
                 LEFT JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
                 WHERE prr.status = 'pending'
                   AND ((prr.user_type = 'employee' AND e.company_id = ?)
                     OR (prr.user_type IN ('accountant','admin') AND (u.company_id = ? OR u.company_id IS NULL)))",
                [$cid, $cid]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Conta richieste reset pending per un reparto specifico (solo dipendenti)
     */
    public static function countPendingResetRequestsByDepartment(int $departmentId): int
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM password_reset_requests prr
                 JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
                 WHERE prr.status = 'pending' AND e.department_id = ?",
                [$departmentId]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Ottiene richieste reset per un reparto specifico (solo dipendenti)
     */
    public static function getResetRequestsByDepartment(int $departmentId, ?string $status = null): array
    {
        try {
            $sql = "SELECT prr.*,
                           e.username AS user_username,
                           CONCAT(e.last_name, ' ', e.first_name) AS user_name,
                           e.email AS user_email,
                           resolver.name AS resolved_by_name
                    FROM password_reset_requests prr
                    JOIN employees e ON prr.user_type = 'employee' AND prr.user_id = e.id
                    LEFT JOIN users resolver ON prr.resolved_by = resolver.id
                    WHERE e.department_id = ?";
            $params = [$departmentId];

            if ($status) {
                $sql .= " AND prr.status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY prr.created_at DESC";

            return Database::fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Admin: approva richiesta e genera token
     */
    public static function approveResetRequest(int $requestId, int $adminId): array
    {
        $request = Database::fetchOne(
            "SELECT * FROM password_reset_requests WHERE id = ? AND status = 'pending'",
            [$requestId]
        );

        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        // Genera nuovo token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        try {
            $tokenId = Database::insert('password_reset_tokens', [
                'user_type' => $request['user_type'],
                'user_id' => $request['user_id'],
                'token' => $token,
                'expires_at' => $expiresAt,
                'ip_address' => getClientIp(),
                'user_agent' => 'Admin approved'
            ]);

            Database::update('password_reset_requests', [
                'status' => 'sent',
                'token_id' => $tokenId,
                'resolved_by' => $adminId,
                'resolved_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$requestId]);

            AuditLog::log('password_reset_approved', 'admin', $adminId, 'password_reset_requests', $requestId);

            return ['success' => true, 'token' => $token];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'approvazione'];
        }
    }

    /**
     * Admin: rifiuta richiesta
     */
    public static function rejectResetRequest(int $requestId, int $adminId, string $notes = ''): array
    {
        try {
            Database::update('password_reset_requests', [
                'status' => 'rejected',
                'resolved_by' => $adminId,
                'resolved_at' => date('Y-m-d H:i:s'),
                'notes' => $notes
            ], 'id = ?', [$requestId]);

            AuditLog::log('password_reset_rejected', 'admin', $adminId, 'password_reset_requests', $requestId);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante il rifiuto'];
        }
    }

    /**
     * Admin: reset manuale password
     */
    public static function adminResetPassword(string $userType, int $userId, int $adminId): array
    {
        $table = in_array($userType, ['admin', 'accountant']) ? 'users' : 'employees';

        // Genera password temporanea
        $tempPassword = self::generateSecurePassword();
        $hash = self::hashPassword($tempPassword);

        try {
            Database::update($table, [
                'password_hash' => $hash,
                'failed_attempts' => 0,
                'locked_until' => null,
                'must_change_password' => 1,
                'password_changed_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$userId]);

            AuditLog::log('admin_password_reset', 'admin', $adminId, $table, $userId, null, [
                'target_user_type' => $userType,
                'target_user_id' => $userId
            ]);

            return ['success' => true, 'password' => $tempPassword];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante il reset'];
        }
    }
}
