<?php
/**
 * Tenant — gestione multi-tenant per azienda.
 *
 * Modello accessi:
 * - Admin globale (users.company_id IS NULL): puo switchare tra TUTTE le aziende attive.
 * - Staff esterni (accountant, consulente_lavoro): possono essere assegnati a piu aziende
 *   tramite la tabella user_companies. Se la tabella ha righe per quell'utente,
 *   vede solo quelle. Altrimenti fallback su users.company_id (1 sola azienda).
 * - Altri user (admin_reparto): tied a users.company_id (no switch).
 * - Dipendenti: tied a employees.company_id (no switch).
 *
 * La selezione corrente e' salvata in sessione (TENANT_SESSION_KEY) e validata
 * contro le aziende accessibili al viewer.
 */
class Tenant
{
    private const SESSION_KEY = 'tenant_company_id';
    private const SWITCH_ROLES = ['admin', 'accountant', 'consulente_lavoro'];

    /** Restituisce la company_id corrente per il viewer. Fallback su prima accessibile o 1. */
    public static function currentCompanyId(): int
    {
        // 1) Dipendente loggato: tied
        $emp = Auth::getEmployee();
        if ($emp && !empty($emp['company_id'])) return (int)$emp['company_id'];

        $user = Auth::getUser();

        // 2) User loggato
        if ($user) {
            $accessible = self::accessibleCompanyIds($user);

            // Se in sessione c'e' una company VALIDA fra quelle accessibili, usala
            if (!empty($_SESSION[self::SESSION_KEY])) {
                $sessionId = (int)$_SESSION[self::SESSION_KEY];
                if (in_array($sessionId, $accessible, true)) return $sessionId;
            }

            // Default: prima accessibile, o users.company_id, o 1
            if (!empty($accessible)) {
                $first = $accessible[0];
                $_SESSION[self::SESSION_KEY] = $first;
                return $first;
            }
            if (!empty($user['company_id'])) return (int)$user['company_id'];
        }

        // 3) Sessione persistente (caso edge: post-logout/login altro user)
        if (!empty($_SESSION[self::SESSION_KEY])) return (int)$_SESSION[self::SESSION_KEY];

        return 1;
    }

    public static function currentCompany(): ?array
    {
        try {
            return Database::fetchOne("SELECT * FROM companies WHERE id = ?", [self::currentCompanyId()]);
        } catch (Throwable $e) { return null; }
    }

    /** L'utente puo cambiare azienda corrente se ha accesso a >1. */
    public static function canSwitch(): bool
    {
        $user = Auth::getUser();
        if (!$user) return false;
        if (!in_array($user['role'] ?? '', self::SWITCH_ROLES, true)) return false;
        $accessible = self::accessibleCompanyIds($user);
        if ($user['role'] === 'admin' && empty($user['company_id'])) {
            // Admin globale: sempre switch (su tutte le aziende attive)
            return true;
        }
        return count($accessible) > 1;
    }

    public static function switchCompany(int $companyId): bool
    {
        $user = Auth::getUser();
        if (!$user) return false;
        if (!in_array($user['role'] ?? '', self::SWITCH_ROLES, true)) return false;

        $accessible = self::accessibleCompanyIds($user);
        $isGlobalAdmin = $user['role'] === 'admin' && empty($user['company_id']);

        if (!$isGlobalAdmin && !in_array($companyId, $accessible, true)) {
            return false;
        }

        $exists = Database::fetchOne("SELECT id FROM companies WHERE id = ? AND is_active = 1", [$companyId]);
        if (!$exists) return false;

        $_SESSION[self::SESSION_KEY] = $companyId;
        if (class_exists('Settings')) Settings::flushCache();
        return true;
    }

    /** Lista aziende accessibili al viewer (array di record). */
    public static function getAccessibleCompanies(): array
    {
        $user = Auth::getUser();
        if (!$user) return [];

        // Admin globale: tutte
        if ($user['role'] === 'admin' && empty($user['company_id'])) {
            return Database::fetchAll("SELECT * FROM companies WHERE is_active = 1 ORDER BY name");
        }

        // Staff esterno multi-azienda: user_companies
        if (in_array($user['role'], ['accountant', 'consulente_lavoro'], true)) {
            $rows = Database::fetchAll(
                "SELECT c.* FROM companies c
                 JOIN user_companies uc ON uc.company_id = c.id
                 WHERE uc.user_id = ? AND c.is_active = 1
                 ORDER BY c.name",
                [$user['id']]
            );
            if (!empty($rows)) return $rows;
        }

        // Fallback: singola company_id del user
        if (!empty($user['company_id'])) {
            $row = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$user['company_id']]);
            return $row ? [$row] : [];
        }

        return [];
    }

    /** ID delle aziende accessibili al viewer corrente (admin / staff). */
    public static function accessibleCompanyIdsForCurrentUser(): array
    {
        $user = Auth::getUser();
        if (!$user) return [];
        return self::accessibleCompanyIds($user);
    }

    /** ID delle aziende accessibili come array di interi. */
    private static function accessibleCompanyIds(array $user): array
    {
        // Admin globale: tutte attive
        if ($user['role'] === 'admin' && empty($user['company_id'])) {
            return array_map('intval', array_column(
                Database::fetchAll("SELECT id FROM companies WHERE is_active = 1 ORDER BY id"),
                'id'
            ));
        }
        // Staff esterno: user_companies
        if (in_array($user['role'], ['accountant', 'consulente_lavoro'], true)) {
            $rows = Database::fetchAll(
                "SELECT c.id FROM companies c
                 JOIN user_companies uc ON uc.company_id = c.id
                 WHERE uc.user_id = ? AND c.is_active = 1
                 ORDER BY c.id",
                [$user['id']]
            );
            if (!empty($rows)) {
                return array_map('intval', array_column($rows, 'id'));
            }
        }
        // Fallback singolo
        if (!empty($user['company_id'])) {
            return [(int)$user['company_id']];
        }
        return [];
    }

    /**
     * True se l'azienda corrente ha bisogno del wizard di setup nome (placeholder).
     */
    public static function needsSetupWizard(): bool
    {
        $user = Auth::getUser();
        if (!$user || $user['role'] !== 'admin' || !empty($user['company_id'])) return false;
        $c = self::currentCompany();
        return $c && !empty($c['needs_setup']);
    }

    public static function completeSetup(int $companyId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') return false;
        Database::update('companies', ['name' => $name, 'needs_setup' => 0], 'id = ?', [$companyId]);
        return true;
    }

    public static function whereClause(string $alias = ''): array
    {
        $col = $alias ? ($alias . '.company_id') : 'company_id';
        return ["AND $col = ?", [self::currentCompanyId()]];
    }

    // ---- Helper per assegnazione user→companies (usato dall'admin) ----

    public static function getUserCompanyIds(int $userId): array
    {
        $rows = Database::fetchAll("SELECT company_id FROM user_companies WHERE user_id = ?", [$userId]);
        return array_map('intval', array_column($rows, 'company_id'));
    }

    public static function setUserCompanies(int $userId, array $companyIds): void
    {
        Database::delete('user_companies', 'user_id = ?', [$userId]);
        foreach (array_unique(array_map('intval', $companyIds)) as $cid) {
            if ($cid > 0) {
                try {
                    Database::insert('user_companies', ['user_id' => $userId, 'company_id' => $cid]);
                } catch (Throwable $e) {
                    error_log('[Tenant] setUserCompanies failed: ' . $e->getMessage());
                }
            }
        }
    }
}
