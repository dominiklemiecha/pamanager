<?php
/**
 * Classe Department - Gestione reparti
 * PAManager - Comune
 */

class Department
{
    /**
     * Ottiene un reparto per ID
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) AS employee_count,
                    (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'admin_reparto') AS admin_count
             FROM departments d
             WHERE d.id = ?",
            [$id]
        );
    }

    /**
     * Ottiene un reparto per codice
     */
    public static function getByCode(string $code): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM departments WHERE code = ?",
            [strtoupper($code)]
        );
    }

    /**
     * Lista tutti i reparti
     */
    public static function getAll(bool $activeOnly = true): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_active = TRUE) AS employee_count,
                       (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'admin_reparto' AND u.is_active = TRUE) AS admin_count
                FROM departments d
                WHERE d.company_id = ?";
        $params = [$cid];

        if ($activeOnly) {
            $sql .= " AND d.is_active = TRUE";
        }

        $sql .= " ORDER BY d.name ASC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Lista reparti per select dropdown
     */
    public static function getForSelect(bool $activeOnly = true): array
    {
        $sql = "SELECT id, name, code FROM departments";
        if ($activeOnly) {
            $sql .= " WHERE is_active = TRUE";
        }
        $sql .= " ORDER BY name ASC";
        return Database::fetchAll($sql);
    }

    /**
     * Conta reparti
     */
    public static function count(bool $activeOnly = true): int
    {
        $where = $activeOnly ? 'is_active = TRUE' : '1=1';
        return Database::count('departments', $where);
    }

    /**
     * Crea un nuovo reparto
     */
    public static function create(array $data): array
    {
        if (empty($data['name']) || strlen(trim($data['name'])) < 2) {
            return ['success' => false, 'error' => 'Nome reparto obbligatorio (min. 2 caratteri)'];
        }

        if (empty($data['code']) || strlen(trim($data['code'])) < 2) {
            return ['success' => false, 'error' => 'Codice reparto obbligatorio (min. 2 caratteri)'];
        }

        $code = strtoupper(trim($data['code']));
        if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
            return ['success' => false, 'error' => 'Codice può contenere solo lettere maiuscole, numeri e underscore'];
        }

        // Verifica nome unico
        if (Database::exists('departments', 'name = ?', [trim($data['name'])])) {
            return ['success' => false, 'error' => 'Nome reparto già esistente'];
        }

        // Verifica codice unico
        if (Database::exists('departments', 'code = ?', [$code])) {
            return ['success' => false, 'error' => 'Codice reparto già esistente'];
        }

        try {
            $id = Database::insert('departments', [
                'company_id' => class_exists('Tenant') ? Tenant::currentCompanyId() : 1,
                'name' => trim($data['name']),
                'code' => $code,
                'description' => trim($data['description'] ?? '') ?: null,
                'is_active' => true
            ]);

            self::logAction('department_created', $id, null, $data);

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante la creazione del reparto'];
        }
    }

    /**
     * Aggiorna un reparto
     */
    public static function update(int $id, array $data): array
    {
        $department = self::getById($id);
        if (!$department) {
            return ['success' => false, 'error' => 'Reparto non trovato'];
        }

        $updateData = [];

        if (isset($data['name']) && !empty($data['name'])) {
            if (Database::exists('departments', 'name = ? AND id != ?', [trim($data['name']), $id])) {
                return ['success' => false, 'error' => 'Nome reparto già esistente'];
            }
            $updateData['name'] = trim($data['name']);
        }

        if (isset($data['code']) && !empty($data['code'])) {
            $code = strtoupper(trim($data['code']));
            if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
                return ['success' => false, 'error' => 'Codice può contenere solo lettere maiuscole, numeri e underscore'];
            }
            if (Database::exists('departments', 'code = ? AND id != ?', [$code, $id])) {
                return ['success' => false, 'error' => 'Codice reparto già esistente'];
            }
            $updateData['code'] = $code;
        }

        if (array_key_exists('description', $data)) {
            $updateData['description'] = trim($data['description'] ?? '') ?: null;
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (bool) $data['is_active'];
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('departments', $updateData, 'id = ?', [$id]);
            self::logAction('department_updated', $id, $department, $updateData);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Elimina un reparto
     */
    public static function delete(int $id): array
    {
        $department = self::getById($id);
        if (!$department) {
            return ['success' => false, 'error' => 'Reparto non trovato'];
        }

        // Verifica se ci sono dipendenti assegnati
        if ($department['employee_count'] > 0) {
            return ['success' => false, 'error' => 'Impossibile eliminare: ci sono dipendenti assegnati a questo reparto'];
        }

        // Verifica se ci sono admin assegnati
        if ($department['admin_count'] > 0) {
            return ['success' => false, 'error' => 'Impossibile eliminare: ci sono admin assegnati a questo reparto'];
        }

        try {
            Database::delete('departments', 'id = ?', [$id]);
            self::logAction('department_deleted', $id, $department, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    /**
     * Disattiva un reparto
     */
    public static function deactivate(int $id): array
    {
        return self::update($id, ['is_active' => false]);
    }

    /**
     * Riattiva un reparto
     */
    public static function activate(int $id): array
    {
        return self::update($id, ['is_active' => true]);
    }

    /**
     * Ottiene i dipendenti di un reparto
     */
    public static function getEmployees(int $departmentId, bool $activeOnly = true): array
    {
        $sql = "SELECT e.* FROM employees e WHERE e.department_id = ?";
        $params = [$departmentId];

        if ($activeOnly) {
            $sql .= " AND e.is_active = TRUE";
        }

        $sql .= " ORDER BY e.last_name, e.first_name";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Ottiene gli admin di un reparto
     */
    public static function getAdmins(int $departmentId, bool $activeOnly = true): array
    {
        $sql = "SELECT u.* FROM users u WHERE u.department_id = ? AND u.role = 'admin_reparto'";
        $params = [$departmentId];

        if ($activeOnly) {
            $sql .= " AND u.is_active = TRUE";
        }

        $sql .= " ORDER BY u.name";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Assegna un admin a un reparto
     */
    public static function assignAdmin(int $userId, int $departmentId): array
    {
        $user = User::getById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'Utente non trovato'];
        }

        $department = self::getById($departmentId);
        if (!$department) {
            return ['success' => false, 'error' => 'Reparto non trovato'];
        }

        try {
            Database::update('users', [
                'role' => 'admin_reparto',
                'department_id' => $departmentId
            ], 'id = ?', [$userId]);

            self::logAction('admin_assigned', $departmentId, null, [
                'user_id' => $userId,
                'user_name' => $user['name']
            ]);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'assegnazione'];
        }
    }

    /**
     * Rimuove un admin da un reparto (torna accountant)
     */
    public static function removeAdmin(int $userId): array
    {
        $user = User::getById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'Utente non trovato'];
        }

        try {
            Database::update('users', [
                'role' => 'accountant',
                'department_id' => null
            ], 'id = ?', [$userId]);

            self::logAction('admin_removed', $user['department_id'] ?? 0, null, [
                'user_id' => $userId,
                'user_name' => $user['name']
            ]);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante la rimozione'];
        }
    }

    /**
     * Assegna un dipendente a un reparto
     */
    public static function assignEmployee(int $employeeId, ?int $departmentId): array
    {
        $employee = Employee::getById($employeeId);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        if ($departmentId !== null) {
            $department = self::getById($departmentId);
            if (!$department) {
                return ['success' => false, 'error' => 'Reparto non trovato'];
            }
        }

        try {
            Database::update('employees', [
                'department_id' => $departmentId
            ], 'id = ?', [$employeeId]);

            self::logAction('employee_assigned', $departmentId ?? 0, null, [
                'employee_id' => $employeeId,
                'employee_name' => $employee['first_name'] . ' ' . $employee['last_name']
            ]);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'assegnazione'];
        }
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
                    'entity_type' => 'department',
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
