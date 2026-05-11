<?php
/**
 * Classe MFA - Multi-Factor Authentication (TOTP)
 * PAManager - Comune
 *
 * Implementa autenticazione a due fattori usando TOTP (RFC 6238)
 * Compatibile con Google Authenticator, Microsoft Authenticator, Authy, etc.
 *
 * Caratteristiche:
 * - Generazione secret key sicura
 * - Generazione QR code per setup
 * - Verifica OTP con finestra temporale
 * - Gestione backup codes
 * - Rate limiting su verifica
 */

class MFA
{
    // Configurazione TOTP
    private const SECRET_LENGTH = 16;
    private const CODE_LENGTH = 6;
    private const TIME_STEP = 30; // secondi
    private const TIME_WINDOW = 1; // permetti 1 step prima/dopo
    private const BACKUP_CODES_COUNT = 10;
    private const BACKUP_CODE_LENGTH = 8;

    // Alfabeto Base32 per secret
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Genera un nuovo secret key per TOTP
     *
     * @return string Secret in formato Base32
     */
    public static function generateSecret(): string
    {
        $secret = '';
        $randomBytes = random_bytes(self::SECRET_LENGTH);

        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::BASE32_CHARS[ord($randomBytes[$i]) % 32];
        }

        return $secret;
    }

    /**
     * Genera codici di backup monouso
     *
     * @return array Array di codici di backup
     */
    public static function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
            // Genera codice alfanumerico
            $code = '';
            $chars = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Escludi I, O per evitare confusione
            for ($j = 0; $j < self::BACKUP_CODE_LENGTH; $j++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // Formatta come XXXX-XXXX
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
        }
        return $codes;
    }

    /**
     * Hash dei backup codes per storage sicuro
     *
     * @param array $codes Codici in chiaro
     * @return string JSON di codici hashati
     */
    public static function hashBackupCodes(array $codes): string
    {
        $hashed = [];
        foreach ($codes as $code) {
            // Normalizza (rimuovi trattino, uppercase)
            $normalized = strtoupper(str_replace('-', '', $code));
            $hashed[] = password_hash($normalized, PASSWORD_DEFAULT);
        }
        return json_encode($hashed);
    }

    /**
     * Verifica un backup code
     *
     * @param string $inputCode Codice inserito dall'utente
     * @param string $hashedCodesJson JSON dei codici hashati
     * @return array ['valid' => bool, 'remaining_codes' => string (JSON)]
     */
    public static function verifyBackupCode(string $inputCode, string $hashedCodesJson): array
    {
        $hashedCodes = json_decode($hashedCodesJson, true);
        if (!is_array($hashedCodes)) {
            return ['valid' => false, 'remaining_codes' => $hashedCodesJson];
        }

        // Normalizza input
        $normalized = strtoupper(str_replace(['-', ' '], '', $inputCode));

        foreach ($hashedCodes as $index => $hash) {
            if (password_verify($normalized, $hash)) {
                // Rimuovi codice usato
                unset($hashedCodes[$index]);
                return [
                    'valid' => true,
                    'remaining_codes' => json_encode(array_values($hashedCodes))
                ];
            }
        }

        return ['valid' => false, 'remaining_codes' => $hashedCodesJson];
    }

    /**
     * Genera URL per QR code (provisioning URI)
     *
     * @param string $secret Secret key
     * @param string $accountName Nome account (email o username)
     * @param string $issuer Nome applicazione
     * @return string URI per QR code
     */
    public static function getProvisioningUri(string $secret, string $accountName, ?string $issuer = null): string
    {
        $issuer = $issuer ?? (defined('MFA_ISSUER') ? MFA_ISSUER : 'PAManager');

        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::CODE_LENGTH,
            'period' => self::TIME_STEP
        ];

        $label = urlencode($issuer) . ':' . urlencode($accountName);
        $query = http_build_query($params);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Genera URL per immagine QR code (usando API esterna o libreria)
     *
     * @param string $provisioningUri URI di provisioning
     * @param int $size Dimensione QR in pixel
     * @return string URL immagine QR o data URI
     */
    public static function getQrCodeUrl(string $provisioningUri, int $size = 200): string
    {
        // Opzione 1: Usa libreria QR locale se disponibile
        if (class_exists('Endroid\\QrCode\\QrCode') || class_exists('chillerlan\\QRCode\\QRCode')) {
            return self::generateQrCodeLocal($provisioningUri, $size);
        }

        // Opzione 2: Genera QR code con servizio Google Charts API (deprecato ma funzionante)
        // NOTA: In produzione, usare una libreria locale per privacy
        $encoded = urlencode($provisioningUri);
        return "https://chart.googleapis.com/chart?chs={$size}x{$size}&chld=M|0&cht=qr&chl={$encoded}";
    }

    /**
     * Genera QR code localmente (se libreria disponibile)
     */
    private static function generateQrCodeLocal(string $data, int $size): string
    {
        // Endroid QR Code
        if (class_exists('Endroid\\QrCode\\QrCode')) {
            try {
                $qrCode = new \Endroid\QrCode\QrCode($data);
                $qrCode->setSize($size);
                return $qrCode->writeDataUri();
            } catch (Exception $e) {
                error_log('QR Code generation failed: ' . $e->getMessage());
            }
        }

        // chillerlan QR Code
        if (class_exists('chillerlan\\QRCode\\QRCode')) {
            try {
                $options = new \chillerlan\QRCode\QROptions([
                    'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                    'imageBase64' => true,
                ]);
                $qrcode = new \chillerlan\QRCode\QRCode($options);
                return $qrcode->render($data);
            } catch (Exception $e) {
                error_log('QR Code generation failed: ' . $e->getMessage());
            }
        }

        // Fallback: URL Google Charts
        $encoded = urlencode($data);
        return "https://chart.googleapis.com/chart?chs={$size}x{$size}&chld=M|0&cht=qr&chl={$encoded}";
    }

    /**
     * Genera codice TOTP per un dato timestamp
     *
     * @param string $secret Secret key in Base32
     * @param int|null $timestamp Timestamp (default: ora corrente)
     * @return string Codice OTP
     */
    public static function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = floor($timestamp / self::TIME_STEP);

        // Decodifica secret da Base32
        $key = self::base32Decode($secret);

        // Genera HMAC-SHA1
        $counterBytes = pack('J', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, self::CODE_LENGTH);

        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica codice TOTP
     *
     * @param string $secret Secret key in Base32
     * @param string $inputCode Codice inserito dall'utente
     * @param int|null $timestamp Timestamp (default: ora corrente)
     * @return bool True se valido
     */
    public static function verifyCode(string $secret, string $inputCode, ?int $timestamp = null): bool
    {
        // Normalizza input (rimuovi spazi)
        $inputCode = preg_replace('/\s+/', '', $inputCode);

        // Verifica formato
        if (!preg_match('/^\d{' . self::CODE_LENGTH . '}$/', $inputCode)) {
            return false;
        }

        $timestamp = $timestamp ?? time();

        // Verifica con finestra temporale (per gestire sfasamenti orologio)
        for ($i = -self::TIME_WINDOW; $i <= self::TIME_WINDOW; $i++) {
            $checkTime = $timestamp + ($i * self::TIME_STEP);
            $expectedCode = self::generateCode($secret, $checkTime);

            if (hash_equals($expectedCode, $inputCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abilita MFA per un utente
     *
     * @param string $table 'users' o 'employees'
     * @param int $userId ID utente
     * @param string $secret Secret key
     * @param array $backupCodes Codici di backup in chiaro
     * @return bool Successo
     */
    public static function enable(string $table, int $userId, string $secret, array $backupCodes): bool
    {
        if (!in_array($table, ['users', 'employees'])) {
            return false;
        }

        try {
            Database::update($table, [
                'mfa_secret' => $secret,
                'mfa_enabled' => true,
                'mfa_backup_codes' => self::hashBackupCodes($backupCodes)
            ], 'id = ?', [$userId]);

            // Log evento
            $userType = $table === 'users' ? 'admin' : 'employee';
            AuditLog::log(
                AuditLog::ACTION_MFA_ENABLED,
                $userType,
                $userId,
                $table,
                $userId
            );

            return true;
        } catch (Exception $e) {
            error_log('MFA enable failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disabilita MFA per un utente
     *
     * @param string $table 'users' o 'employees'
     * @param int $userId ID utente
     * @return bool Successo
     */
    public static function disable(string $table, int $userId): bool
    {
        if (!in_array($table, ['users', 'employees'])) {
            return false;
        }

        try {
            Database::update($table, [
                'mfa_secret' => null,
                'mfa_enabled' => false,
                'mfa_backup_codes' => null
            ], 'id = ?', [$userId]);

            // Log evento
            $userType = $table === 'users' ? 'admin' : 'employee';
            AuditLog::log(
                AuditLog::ACTION_MFA_DISABLED,
                $userType,
                $userId,
                $table,
                $userId
            );

            return true;
        } catch (Exception $e) {
            error_log('MFA disable failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica se MFA è richiesto per un ruolo
     *
     * @param string $role Ruolo utente
     * @return bool True se MFA è obbligatorio
     */
    public static function isRequiredForRole(string $role): bool
    {
        if (!defined('MFA_ENABLED') || !MFA_ENABLED) {
            return false;
        }

        $requiredRoles = defined('MFA_REQUIRED_ROLES') ? MFA_REQUIRED_ROLES : ['admin', 'accountant'];
        return in_array($role, $requiredRoles);
    }

    /**
     * Verifica MFA durante login
     *
     * @param string $table 'users' o 'employees'
     * @param int $userId ID utente
     * @param string $code Codice OTP o backup code
     * @return array ['success' => bool, 'error' => string|null, 'used_backup' => bool]
     */
    public static function verifyForLogin(string $table, int $userId, string $code): array
    {
        if (!in_array($table, ['users', 'employees'])) {
            return ['success' => false, 'error' => 'Tabella non valida'];
        }

        // Rate limiting per verifica MFA
        $identifier = $table . '_' . $userId . '_mfa';
        if (!checkRateLimitDb($identifier, 'mfa_verify', 5, 300)) {
            $userType = $table === 'users' ? 'admin' : 'employee';
            AuditLog::log(
                AuditLog::ACTION_RATE_LIMIT_EXCEEDED,
                $userType,
                $userId,
                $table,
                $userId,
                null,
                ['action' => 'mfa_verify'],
                AuditLog::SEVERITY_WARNING
            );
            return [
                'success' => false,
                'error' => 'Troppi tentativi. Riprova tra 5 minuti.',
                'used_backup' => false
            ];
        }

        // Ottieni dati MFA utente
        $user = Database::fetch(
            "SELECT mfa_secret, mfa_backup_codes FROM {$table} WHERE id = ?",
            [$userId]
        );

        if (!$user || empty($user['mfa_secret'])) {
            return ['success' => false, 'error' => 'MFA non configurato', 'used_backup' => false];
        }

        // Prova verifica codice TOTP
        if (self::verifyCode($user['mfa_secret'], $code)) {
            return ['success' => true, 'error' => null, 'used_backup' => false];
        }

        // Prova backup code
        if (!empty($user['mfa_backup_codes'])) {
            $backupResult = self::verifyBackupCode($code, $user['mfa_backup_codes']);

            if ($backupResult['valid']) {
                // Aggiorna backup codes rimanenti
                Database::update($table, [
                    'mfa_backup_codes' => $backupResult['remaining_codes']
                ], 'id = ?', [$userId]);

                return ['success' => true, 'error' => null, 'used_backup' => true];
            }
        }

        // Log tentativo fallito
        $userType = $table === 'users' ? 'admin' : 'employee';
        AuditLog::log(
            AuditLog::ACTION_MFA_FAILED,
            $userType,
            $userId,
            $table,
            $userId,
            null,
            ['reason' => 'invalid_code'],
            AuditLog::SEVERITY_WARNING
        );

        return ['success' => false, 'error' => 'Codice non valido', 'used_backup' => false];
    }

    /**
     * Conta backup codes rimanenti
     *
     * @param string|null $hashedCodesJson JSON dei codici hashati
     * @return int Numero di codici rimanenti
     */
    public static function countRemainingBackupCodes(?string $hashedCodesJson): int
    {
        if (empty($hashedCodesJson)) {
            return 0;
        }

        $codes = json_decode($hashedCodesJson, true);
        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * Rigenera backup codes
     *
     * @param string $table 'users' o 'employees'
     * @param int $userId ID utente
     * @return array|false Nuovi codici in chiaro o false
     */
    public static function regenerateBackupCodes(string $table, int $userId)
    {
        if (!in_array($table, ['users', 'employees'])) {
            return false;
        }

        try {
            $newCodes = self::generateBackupCodes();

            Database::update($table, [
                'mfa_backup_codes' => self::hashBackupCodes($newCodes)
            ], 'id = ?', [$userId]);

            // Log evento
            $userType = $table === 'users' ? 'admin' : 'employee';
            AuditLog::log(
                'mfa_backup_regenerated',
                $userType,
                $userId,
                $table,
                $userId
            );

            return $newCodes;
        } catch (Exception $e) {
            error_log('Backup codes regeneration failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decodifica Base32
     *
     * @param string $data Stringa Base32
     * @return string Dati binari
     */
    private static function base32Decode(string $data): string
    {
        $data = strtoupper($data);
        $data = str_replace('=', '', $data);

        $decoded = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $value = strpos(self::BASE32_CHARS, $char);

            if ($value === false) {
                continue; // Ignora caratteri non validi
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $decoded .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $decoded;
    }

    /**
     * Ottiene secondi rimanenti prima del prossimo codice
     *
     * @return int Secondi rimanenti
     */
    public static function getSecondsRemaining(): int
    {
        return self::TIME_STEP - (time() % self::TIME_STEP);
    }
}
