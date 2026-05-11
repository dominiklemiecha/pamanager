<?php
/**
 * FieldVisibility — gestisce la visibilita dei campi anagrafici dipendente
 * per ruolo. Configurazione in Settings KV 'employee_field_visibility' (JSON).
 *
 * Regola speciale: il dipendente vede SEMPRE i propri dati, indipendentemente
 * dalla visibility configurata. La visibility controlla cosa vedono gli ALTRI ruoli.
 */
class FieldVisibility
{
    private const SETTINGS_KEY = 'employee_field_visibility';

    /** Campi gestiti dalla visibility. */
    public const FIELDS = [
        'username'       => 'Username',
        'fiscal_code'    => 'Codice fiscale',
        'first_name'     => 'Nome',
        'last_name'      => 'Cognome',
        'email'          => 'Email',
        'phone'          => 'Telefono',
        'address'        => 'Indirizzo di residenza',
        'birth_date'     => 'Data di nascita',
        'department_id'  => 'Reparto',
        'position'       => 'Posizione',
        'hire_date'      => 'Data assunzione',
        'job_level'      => 'Livello',
        'ral_amount'     => 'RAL annua',
        'monthly_salary' => 'Retribuzione mensile',
        'iban'           => 'IBAN',
        'medical_certs'  => 'Certificati medici',
    ];

    /**
     * Ruoli selezionabili nella UI "Visibile a".
     * NOTA: 'employee' (altri dipendenti) NON e' selezionabile — hardcoded:
     *   - Solo birth_date e' visibile agli altri dipendenti (banner compleanno)
     *   - Tutti gli altri campi sono privati tra dipendenti.
     */
    public const ROLES = [
        'admin'         => 'Amministratore',
        'accountant'    => 'Commercialista',
        'admin_reparto' => 'Admin reparto',
    ];

    /** Default: admin sempre incluso (implicito). Commercialista + admin reparto opt-in. */
    public const DEFAULTS = [
        'username'       => ['admin', 'accountant', 'admin_reparto'],
        'fiscal_code'    => ['admin', 'accountant'],
        'first_name'     => ['admin', 'accountant', 'admin_reparto'],
        'last_name'      => ['admin', 'accountant', 'admin_reparto'],
        'email'          => ['admin', 'accountant', 'admin_reparto'],
        'phone'          => ['admin', 'accountant', 'admin_reparto'],
        'address'        => ['admin', 'accountant'],
        'birth_date'     => ['admin', 'accountant', 'admin_reparto'],
        'department_id'  => ['admin', 'accountant', 'admin_reparto'],
        'position'       => ['admin', 'accountant', 'admin_reparto'],
        'hire_date'      => ['admin', 'accountant', 'admin_reparto'],
        'job_level'      => ['admin', 'accountant'],
        'ral_amount'     => ['admin', 'accountant'],
        'monthly_salary' => ['admin', 'accountant'],
        'iban'           => ['admin', 'accountant'],
        'medical_certs'  => ['admin'],
    ];

    /**
     * Campi hardcoded sempre visibili agli altri dipendenti (no config).
     * Necessari per funzionalita base (chat/colleghi/banner compleanno).
     */
    public const HARDCODED_EMPLOYEE_VISIBLE = ['first_name', 'last_name', 'position', 'department_id', 'birth_date'];

    /**
     * Restituisce la mappa completa field => [ruoli abilitati].
     */
    public static function getConfig(): array
    {
        $raw = Settings::get(self::SETTINGS_KEY, null);
        $loaded = $raw ? (json_decode($raw, true) ?: []) : [];
        $out = [];
        foreach (self::FIELDS as $field => $_label) {
            $out[$field] = isset($loaded[$field]) && is_array($loaded[$field])
                ? array_values(array_intersect(array_keys(self::ROLES), $loaded[$field]))
                : self::DEFAULTS[$field];
        }
        return $out;
    }

    public static function saveConfig(array $config, ?int $userId = null): void
    {
        $sanitized = [];
        foreach (self::FIELDS as $field => $_label) {
            $roles = $config[$field] ?? [];
            $sanitized[$field] = array_values(array_intersect(array_keys(self::ROLES), is_array($roles) ? $roles : []));
        }
        Settings::set(self::SETTINGS_KEY, json_encode($sanitized), $userId);
    }

    /**
     * True se il viewer (con il suo ruolo) puo vedere $field per $targetEmployeeId.
     *   - admin: sempre tutto
     *   - employee: vede sempre i propri dati
     *   - admin_reparto: vede tutto del proprio reparto SE visibility lo include
     *   - accountant: vede solo i campi marcati 'accountant'
     *
     * $viewerType: 'admin' | 'accountant' | 'admin_reparto' | 'employee'
     * $viewerId:   id user (per admin/accountant/admin_reparto) o id employee (per employee)
     */
    public static function canView(string $field, string $viewerType, ?int $viewerId, int $targetEmployeeId, ?int $viewerDeptId = null, ?int $targetDeptId = null): bool
    {
        if ($viewerType === 'admin') return true;

        if ($viewerType === 'employee') {
            // Vede sempre i propri dati
            if ($viewerId === $targetEmployeeId) return true;
            // Per altri dipendenti: solo campi hardcoded (es. data di nascita per il banner)
            return in_array($field, self::HARDCODED_EMPLOYEE_VISIBLE, true);
        }

        if ($viewerType === 'admin_reparto') {
            // Solo se il dipendente e' nel suo reparto
            if ($viewerDeptId === null || $targetDeptId === null || $viewerDeptId !== $targetDeptId) return false;
            $cfg = self::getConfig();
            return in_array('admin_reparto', $cfg[$field] ?? [], true);
        }

        if ($viewerType === 'accountant') {
            $cfg = self::getConfig();
            return in_array('accountant', $cfg[$field] ?? [], true);
        }

        return false;
    }

    /**
     * Restituisce array di nomi-campo visibili al viewer dato.
     */
    public static function getVisibleFields(string $viewerType, ?int $viewerId, int $targetEmployeeId, ?int $viewerDeptId = null, ?int $targetDeptId = null): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $f) {
            if (self::canView($f, $viewerType, $viewerId, $targetEmployeeId, $viewerDeptId, $targetDeptId)) {
                $out[] = $f;
            }
        }
        return $out;
    }
}
