<?php
/**
 * Classe User - Gestione utenti (admin e commercialista)
 * PAManager - Comune
 */

class User
{
    /**
     * Ottiene un utente per ID
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT u.id, u.username, u.role, u.name, u.email, u.is_active, u.created_at, u.last_login,
                    u.department_id, d.name AS department_name, d.code AS department_code
             FROM users u
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE u.id = ?",
            [$id]
        );
    }

    /**
     * Ottiene un utente per username
     */
    public static function getByUsername(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT u.id, u.username, u.role, u.name, u.email, u.is_active, u.created_at, u.last_login,
                    u.department_id, d.name AS department_name, d.code AS department_code
             FROM users u
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE u.username = ?",
            [$username]
        );
    }

    /**
     * Lista tutti gli utenti
     */
    public static function getAll(?string $role = null): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        // Scoping multi-tenant: utenti dell'azienda corrente.
        // Gli admin globali (company_id = NULL) sono inclusi solo se viene richiesto il ruolo 'admin'.
        $sql = "SELECT u.id, u.username, u.role, u.name, u.email, u.is_active, u.created_at, u.last_login,
                       u.department_id, d.name AS department_name, d.code AS department_code
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE (u.company_id = ?";
        $params = [$cid];

        if ($role === 'admin') {
            $sql .= " OR u.company_id IS NULL"; // admin globali visibili
        }
        $sql .= ")";

        if ($role !== null) {
            $sql .= " AND u.role = ?";
            $params[] = $role;
        }

        $sql .= " ORDER BY u.name ASC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Lista tutti gli admin reparto
     */
    public static function getAdminReparto(): array
    {
        return self::getAll('admin_reparto');
    }

    /**
     * Lista tutti i commercialisti
     */
    public static function getAccountants(): array
    {
        return self::getAll('accountant');
    }

    /**
     * Lista tutti i consulenti del lavoro
     */
    public static function getConsulentiLavoro(): array
    {
        return self::getAll('consulente_lavoro');
    }

    /**
     * Crea un nuovo utente
     */
    public static function create(array $data): array
    {
        // Validazione
        if (empty($data['username']) || strlen($data['username']) < 3) {
            return ['success' => false, 'error' => 'Username deve avere almeno 3 caratteri'];
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            return ['success' => false, 'error' => 'Password deve avere almeno 8 caratteri'];
        }

        if (empty($data['name'])) {
            return ['success' => false, 'error' => 'Nome obbligatorio'];
        }

        if (!in_array($data['role'], ['admin', 'accountant', 'admin_reparto', 'consulente_lavoro'])) {
            return ['success' => false, 'error' => 'Ruolo non valido'];
        }

        // Verifica username unico
        if (Database::exists('users', 'username = ?', [$data['username']])) {
            return ['success' => false, 'error' => 'Username già esistente'];
        }

        // Verifica complessità password
        if (!self::validatePasswordStrength($data['password'])) {
            return ['success' => false, 'error' => 'Password troppo debole. Deve contenere almeno una maiuscola, una minuscola, un numero e un carattere speciale.'];
        }

        // Per admin_reparto, department_id è obbligatorio
        if ($data['role'] === 'admin_reparto' && empty($data['department_id'])) {
            return ['success' => false, 'error' => 'Per admin reparto è obbligatorio specificare un reparto'];
        }

        $nullIfEmpty = static fn($v) => ($v === '' || $v === null) ? null : $v;

        try {
            // Admin globali (con accesso a tutte le aziende) hanno company_id NULL.
            // Tutti gli altri ruoli sono legati all'azienda corrente.
            $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
            $companyIdForUser = ($data['role'] === 'admin' && !empty($data['is_global'])) ? null : $cid;
            $id = Database::insert('users', [
                'company_id' => $companyIdForUser,
                'username' => $data['username'],
                'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]),
                'role' => $data['role'],
                'name' => $data['name'],
                'email' => $nullIfEmpty($data['email'] ?? null),
                'department_id' => $nullIfEmpty($data['department_id'] ?? null),
                'is_active' => 1
            ]);

            self::logAction('user_created', $id, null, $data);

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante la creazione dell\'utente: ' . $e->getMessage()];
        }
    }

    /**
     * Aggiorna un utente
     */
    public static function update(int $id, array $data): array
    {
        $user = self::getById($id);
        if (!$user) {
            return ['success' => false, 'error' => 'Utente non trovato'];
        }

        $updateData = [];

        if (isset($data['name']) && !empty($data['name'])) {
            $updateData['name'] = $data['name'];
        }

        if (isset($data['email'])) {
            $updateData['email'] = ($data['email'] === '') ? null : $data['email'];
        }

        if (isset($data['is_active']) && $data['is_active'] !== '') {
            $updateData['is_active'] = (int) (bool) $data['is_active'];
        }

        // Username modificabile solo se diverso
        if (isset($data['username']) && $data['username'] !== $user['username']) {
            if (Database::exists('users', 'username = ? AND id != ?', [$data['username'], $id])) {
                return ['success' => false, 'error' => 'Username già esistente'];
            }
            $updateData['username'] = $data['username'];
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('users', $updateData, 'id = ?', [$id]);
            self::logAction('user_updated', $id, $user, $updateData);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Elimina un utente
     */
    public static function delete(int $id): array
    {
        $user = self::getById($id);
        if (!$user) {
            return ['success' => false, 'error' => 'Utente non trovato'];
        }

        // Non permettere eliminazione dell'ultimo admin
        if ($user['role'] === 'admin') {
            $adminCount = Database::count('users', 'role = ? AND is_active = TRUE', ['admin']);
            if ($adminCount <= 1) {
                return ['success' => false, 'error' => 'Impossibile eliminare l\'ultimo amministratore'];
            }
        }

        try {
            Database::delete('users', 'id = ?', [$id]);
            self::logAction('user_deleted', $id, $user, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione: ' . $e->getMessage()];
        }
    }

    /**
     * Disattiva un utente (soft delete)
     */
    public static function deactivate(int $id): array
    {
        return self::update($id, ['is_active' => false]);
    }

    /**
     * Riattiva un utente
     */
    public static function activate(int $id): array
    {
        return self::update($id, ['is_active' => true]);
    }

    /**
     * Reset password (genera nuova password)
     */
    public static function resetPassword(int $id): array
    {
        $user = self::getById($id);
        if (!$user) {
            return ['success' => false, 'error' => 'Utente non trovato'];
        }

        $newPassword = self::generatePassword();
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);

        try {
            Database::update('users', [
                'password_hash' => $hash,
                'failed_attempts' => 0,
                'locked_until' => null
            ], 'id = ?', [$id]);

            self::logAction('password_reset', $id, null, null);

            return ['success' => true, 'password' => $newPassword];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante il reset password'];
        }
    }

    /**
     * Valida la forza della password
     */
    private static function validatePasswordStrength(string $password): bool
    {
        // Almeno 8 caratteri, 1 maiuscola, 1 minuscola, 1 numero, 1 speciale
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password) === 1;
    }

    /**
     * Genera una password sicura
     */
    private static function generatePassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=';

        $password = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $upper . $lower . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Log azioni
     */
    private static function logAction(string $action, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::getUser();
        if ($user) {
            try {
                Database::insert('audit_log', [
                    'user_type' => $user['role'],
                    'user_id' => $user['id'],
                    'action' => $action,
                    'entity_type' => 'user',
                    'entity_id' => $entityId,
                    'old_values' => $oldValues ? json_encode($oldValues) : null,
                    'new_values' => $newValues ? json_encode($newValues) : null,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('Audit log failed: ' . $e->getMessage());
            }
        }
    }
}
