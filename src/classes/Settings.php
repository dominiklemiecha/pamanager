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

    private static function cid(): int
    {
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

    public static function getSmtpConfig(): array
    {
        return [
            'enabled'    => (bool) (self::get('smtp_enabled') === '1'),
            'host'       => self::get('smtp_host', ''),
            'port'       => (int) self::get('smtp_port', '587'),
            'encryption' => self::get('smtp_encryption', 'tls'),
            'username'   => self::get('smtp_username', ''),
            'password'   => self::get('smtp_password', ''),
            'from_email' => self::get('smtp_from_email', ''),
            'from_name'  => self::get('smtp_from_name', 'PAManager'),
        ];
    }

    /** Resetta la cache (es. dopo switch azienda) */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
