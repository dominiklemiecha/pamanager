<?php
/**
 * Tenant — gestione multi-tenant per azienda.
 *
 * - Admin (users.company_id IS NULL): puo switchare tra tutte le aziende attive.
 *   La selezione corrente e' salvata in sessione (TENANT_SESSION_KEY).
 * - Altri utenti (accountant/admin_reparto/employee): tied alla loro company_id (no switch).
 * - Dipendenti (tabella employees): legati alla loro company_id (no switch).
 *
 * Le query nei moduli applicativi devono filtrare per `company_id = Tenant::currentCompanyId()`.
 */
class Tenant
{
    private const SESSION_KEY = 'tenant_company_id';

    /** Restituisce la company_id corrente per il viewer. Fallback su 1. */
    public static function currentCompanyId(): int
    {
        // 1) Sessione (switch da admin)
        if (!empty($_SESSION[self::SESSION_KEY])) {
            return (int)$_SESSION[self::SESSION_KEY];
        }
        // 2) Dipendente loggato
        $emp = Auth::getEmployee();
        if ($emp && !empty($emp['company_id'])) return (int)$emp['company_id'];
        // 3) User loggato (non admin)
        $user = Auth::getUser();
        if ($user && !empty($user['company_id'])) {
            $_SESSION[self::SESSION_KEY] = (int)$user['company_id'];
            return (int)$user['company_id'];
        }
        // 4) Default
        return 1;
    }

    public static function currentCompany(): ?array
    {
        try {
            return Database::fetchOne("SELECT * FROM companies WHERE id = ?", [self::currentCompanyId()]);
        } catch (Throwable $e) { return null; }
    }

    /** Solo l'admin (users.company_id IS NULL) puo switchare. */
    public static function canSwitch(): bool
    {
        $user = Auth::getUser();
        if (!$user) return false;
        if (($user['role'] ?? '') !== 'admin') return false;
        return empty($user['company_id']);
    }

    public static function switchCompany(int $companyId): bool
    {
        if (!self::canSwitch()) return false;
        $exists = Database::fetchOne("SELECT id FROM companies WHERE id = ? AND is_active = 1", [$companyId]);
        if (!$exists) return false;
        $_SESSION[self::SESSION_KEY] = $companyId;
        // Invalida cache di Settings (SMTP, branding, ...) per ricaricarla nella nuova azienda
        if (class_exists('Settings')) Settings::flushCache();
        return true;
    }

    /** Lista aziende accessibili al viewer. */
    public static function getAccessibleCompanies(): array
    {
        if (self::canSwitch()) {
            return Database::fetchAll("SELECT * FROM companies WHERE is_active = 1 ORDER BY name");
        }
        $cid = self::currentCompanyId();
        $row = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$cid]);
        return $row ? [$row] : [];
    }

    /**
     * True se l'azienda corrente ha bisogno del wizard di setup nome (placeholder).
     * Visibile solo ad admin.
     */
    public static function needsSetupWizard(): bool
    {
        if (!self::canSwitch()) return false;
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

    /** Helper: clausola SQL "AND company_id = ?" + parametro, per appendere a query esistenti. */
    public static function whereClause(string $alias = ''): array
    {
        $col = $alias ? ($alias . '.company_id') : 'company_id';
        return ["AND $col = ?", [self::currentCompanyId()]];
    }
}
