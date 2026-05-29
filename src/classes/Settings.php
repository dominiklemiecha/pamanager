<?php
/**
 * Gestione impostazioni applicazione (chiave/valore) — scoped per company.
 *
 * Ogni impostazione e' isolata per azienda: SMTP, branding, configurazioni
 * variano per ciascun tenant. La cache e' invalidata se cambia la company corrente.
 */
class Settings
{
    /** @var array<int, array<string, ?string>> [companyId => [key => value]] */
    private static array $cache = [];

    /** Override esplicito della company (es. superadmin senza sessione tenant). */
    private static ?int $forceCompanyId = null;

    /** Forza la company per le letture successive (null = torna al comportamento normale). */
    public static function forceCompany(?int $companyId): void
    {
        self::$forceCompanyId = $companyId;
    }

    private static function cid(): int
    {
        if (self::$forceCompanyId !== null) return self::$forceCompanyId;
        return class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
    }

    private static function load(int $cid): void
    {
        if (isset(self::$cache[$cid])) return;
        self::$cache[$cid] = [];
        try {
            $rows = Database::fetchAll(
                "SELECT setting_key, setting_value FROM app_settings WHERE company_id = ?",
                [$cid]
            );
            foreach ($rows as $row) {
                self::$cache[$cid][$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // Tabella mancante o migration non eseguita
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $cid = self::cid();
        self::load($cid);
        return self::$cache[$cid][$key] ?? $default;
    }

    public static function getAll(): array
    {
        $cid = self::cid();
        self::load($cid);
        return self::$cache[$cid];
    }

    public static function set(string $key, ?string $value, ?int $userId = null): void
    {
        $cid = self::cid();
        self::load($cid);
        $existing = Database::fetchOne(
            "SELECT setting_key FROM app_settings WHERE setting_key = ? AND company_id = ?",
            [$key, $cid]
        );
        if ($existing) {
            Database::update('app_settings', [
                'setting_value' => $value,
                'updated_by'    => $userId,
            ], 'setting_key = ? AND company_id = ?', [$key, $cid]);
        } else {
            Database::insert('app_settings', [
                'setting_key'   => $key,
                'company_id'    => $cid,
                'setting_value' => $value,
                'updated_by'    => $userId,
            ]);
        }
        self::$cache[$cid][$key] = $value;
    }

    public static function setMany(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value, $userId);
        }
    }

    /** Legge una variabile d'ambiente da tutte le sorgenti possibili (Docker/Dokploy/.env). */
    private static function env(string $key, string $default = ''): string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $v) {
            if ($v !== false && $v !== null && $v !== '') return (string) $v;
        }
        return $default;
    }

    /**
     * Configurazione SMTP GLOBALE (SaaS): letta SOLO dalle variabili d'ambiente.
     * Nessuna impostazione per-azienda. Variabili:
     *   SMTP_ENABLED (1/0), SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION (none|tls|ssl),
     *   SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL, SMTP_FROM_NAME
     */
    public static function getSmtpConfig(): array
    {
        return [
            'enabled'    => self::env('SMTP_ENABLED', '0') === '1',
            'host'       => self::env('SMTP_HOST'),
            'port'       => (int) self::env('SMTP_PORT', '587'),
            'encryption' => self::env('SMTP_ENCRYPTION', 'tls'),
            'username'   => self::env('SMTP_USERNAME'),
            'password'   => self::env('SMTP_PASSWORD'),
            'from_email' => self::env('SMTP_FROM_EMAIL'),
            'from_name'  => self::env('SMTP_FROM_NAME', 'ConnecteedHR'),
        ];
    }

    /** Resetta la cache (es. dopo switch azienda) */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
