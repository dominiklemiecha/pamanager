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

    /**
     * Company corrente per il viewer.
     * SICUREZZA: ritorna solo aziende REALMENTE accessibili al viewer. Se non ne ha
     * nessuna ritorna 0 (=> nessun dato). NIENTE fallback a company 1 e NIENTE uso
     * di session non validate: erano vettori di leak cross-tenant.
     */
    public static function currentCompanyId(): int
    {
        // 1) Dipendente loggato: tied alla sua azienda
        $emp = Auth::getEmployee();
        if ($emp && !empty($emp['company_id'])) return (int)$emp['company_id'];

        // 2) User loggato (admin / accountant / consulente / admin_reparto)
        $user = Auth::getUser();
        if ($user) {
            $accessible = self::accessibleCompanyIds($user);
            if (empty($accessible)) return 0; // nessuna azienda assegnata => nessun dato

            // La company in sessione vale SOLO se e' tra le accessibili
            if (!empty($_SESSION[self::SESSION_KEY])) {
                $sessionId = (int)$_SESSION[self::SESSION_KEY];
                if (in_array($sessionId, $accessible, true)) return $sessionId;
            }
            $first = (int)$accessible[0];
            $_SESSION[self::SESSION_KEY] = $first;
            return $first;
        }

        // 3) Nessuno loggato: nessun dato
        return 0;
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
        // Admin globale vero: sempre switch (vede tutte)
        if (self::isTrueGlobalAdmin($user)) return true;
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

        $ids = self::accessibleCompanyIds($user);
        // Caso speciale: admin globale "vero" (NULL + nessun user_companies) -> tutte
        if (self::isTrueGlobalAdmin($user)) {
            return Database::fetchAll("SELECT * FROM companies WHERE is_active = 1 ORDER BY name");
        }
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT * FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name",
            array_map('intval', $ids)
        );
    }

    /**
     * True solo se admin con company_id esplicitamente NULL E senza alcun
     * collegamento in user_companies (vero superadmin/maintainer legacy).
     * Un admin NULL CON user_companies e' invece scopato a quelle aziende.
     */
    private static function isTrueGlobalAdmin(array $user): bool
    {
        if (($user['role'] ?? '') !== 'admin') return false;
        if (!array_key_exists('company_id', $user) || $user['company_id'] !== null) return false;
        $n = Database::fetchOne("SELECT COUNT(*) AS n FROM user_companies WHERE user_id = ?", [(int)$user['id']]);
        return (int)($n['n'] ?? 0) === 0;
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
        // Campo company_id assente in sessione: NON assumere globale (session corrotta) -> blocca
        if (($user['role'] ?? '') === 'admin' && !array_key_exists('company_id', $user)) {
            return [];
        }
        // Admin globale "vero" (NULL + zero user_companies): tutte le aziende attive
        if (self::isTrueGlobalAdmin($user)) {
            return array_map('intval', array_column(
                Database::fetchAll("SELECT id FROM companies WHERE is_active = 1 ORDER BY id"),
                'id'
            ));
        }
        // Admin tenant (anche company_id NULL ma CON user_companies) + staff esterno:
        // company_id primaria + tutte le righe user_companies
        if (in_array($user['role'] ?? '', ['admin', 'accountant', 'consulente_lavoro'], true)) {
            $ids = [];
            if (!empty($user['company_id'])) $ids[] = (int)$user['company_id'];
            $rows = Database::fetchAll(
                "SELECT c.id FROM companies c
                 JOIN user_companies uc ON uc.company_id = c.id
                 WHERE uc.user_id = ? AND c.is_active = 1",
                [(int)$user['id']]
            );
            foreach ($rows as $r) {
                $cid = (int)$r['id'];
                if ($cid > 0 && !in_array($cid, $ids, true)) $ids[] = $cid;
            }
            return $ids;
        }
        if (!empty($user['company_id'])) return [(int)$user['company_id']];
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

    /**
     * Permette a uno user (accountant/consulente_lavoro) di rimuovere SE STESSO
     * da un'azienda — utile a fine mandato se l'admin non lo ha tolto.
     * Vincolo: non puoi rimuoverti dall'ultima azienda accessibile (eviti lock-out).
     */
    public static function leaveCompany(int $userId, int $companyId): array
    {
        $u = Database::fetchOne("SELECT id, role FROM users WHERE id = ?", [$userId]);
        if (!$u) return ['success' => false, 'error' => 'Utente non trovato'];
        if (!in_array($u['role'], ['accountant', 'consulente_lavoro'], true)) {
            return ['success' => false, 'error' => 'Operazione non consentita per questo ruolo'];
        }
        $ids = self::getUserCompanyIds($userId);
        if (!in_array($companyId, $ids, true)) {
            return ['success' => false, 'error' => 'Non sei collegato a questa azienda'];
        }
        if (count($ids) <= 1) {
            return ['success' => false, 'error' => 'Non puoi rimuoverti dall\'ultima azienda: contatta l\'admin per la disattivazione totale'];
        }
        Database::delete('user_companies', 'user_id = ? AND company_id = ?', [$userId, $companyId]);
        if (class_exists('AuditLog')) {
            try { AuditLog::log('user_left_company', 'user', $userId, null, ['company_id' => $companyId]); } catch (Throwable $e) {}
        }
        // Se l'azienda lasciata era quella corrente in sessione, sblocca
        if (!empty($_SESSION[self::SESSION_KEY]) && (int)$_SESSION[self::SESSION_KEY] === $companyId) {
            unset($_SESSION[self::SESSION_KEY]);
        }
        return ['success' => true];
    }

    /**
     * Conta "attivita' da vedere" per OGNI azienda accessibile.
     * Ritorna mappa [company_id => count].
     * Definizione attivita':
     *  - leave_requests con status='pending'
     *  - chat_messages non letti destinati al viewer corrente (user)
     */
    public static function activityByCompany(): array
    {
        $u = Auth::getUser();
        if (!$u) return [];
        $accessible = self::accessibleCompanyIdsForCurrentUser();
        if (empty($accessible)) return [];
        $result = array_fill_keys(array_map('intval', $accessible), 0);
        $ph = implode(',', array_fill(0, count($accessible), '?'));
        $cids = array_map('intval', $accessible);
        try {
            $rows = Database::fetchAll(
                "SELECT company_id, COUNT(*) AS n FROM leave_requests
                 WHERE company_id IN ($ph) AND status = 'pending'
                 GROUP BY company_id",
                $cids
            );
            foreach ($rows as $r) $result[(int)$r['company_id']] = ($result[(int)$r['company_id']] ?? 0) + (int)$r['n'];
        } catch (Throwable $e) {}
        try {
            $uid = (int)$u['id'];
            $rows = Database::fetchAll(
                "SELECT cc.company_id AS company_id, COUNT(*) AS n
                 FROM chat_messages cm
                 JOIN chat_conversations cc ON cm.conversation_id = cc.id
                 WHERE cc.company_id IN ($ph)
                   AND ((cc.participant1_type = 'user' AND cc.participant1_id = ?)
                        OR (cc.participant2_type = 'user' AND cc.participant2_id = ?))
                   AND NOT (cm.sender_type = 'user' AND cm.sender_id = ?)
                   AND cm.is_read = FALSE
                 GROUP BY cc.company_id",
                array_merge($cids, [$uid, $uid, $uid])
            );
            foreach ($rows as $r) $result[(int)$r['company_id']] = ($result[(int)$r['company_id']] ?? 0) + (int)$r['n'];
        } catch (Throwable $e) {}
        return $result;
    }

    /**
     * Somma attivita' nelle aziende ACCESSIBILI diverse da quella corrente.
     * Indicatore per il chip tenant-switcher: se > 0 lampeggia.
     */
    public static function otherCompaniesActivity(): int
    {
        $map = self::activityByCompany();
        $current = self::currentCompanyId();
        $tot = 0;
        foreach ($map as $cid => $n) {
            if ((int)$cid !== (int)$current) $tot += (int)$n;
        }
        return $tot;
    }
}
