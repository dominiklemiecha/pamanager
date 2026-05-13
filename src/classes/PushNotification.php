<?php
/**
 * Classe PushNotification - Gestione notifiche push web
 * PAManager - Comune
 *
 * Implementazione completa Web Push Protocol con VAPID
 * Usa solo funzioni native PHP (OpenSSL)
 */

class PushNotification
{
    private static ?array $vapidKeys = null;

    /**
     * Verifica se le funzioni necessarie sono disponibili
     */
    public static function isAvailable(): bool
    {
        return function_exists('openssl_pkey_new') &&
               function_exists('openssl_pkey_get_details') &&
               function_exists('openssl_encrypt');
    }

    /**
     * Ottiene o genera le chiavi VAPID
     */
    public static function getVapidKeys(): array
    {
        if (self::$vapidKeys !== null) {
            return self::$vapidKeys;
        }

        $keyFile = STORAGE_PATH . '/vapid_keys.json';

        if (file_exists($keyFile)) {
            $keys = json_decode(file_get_contents($keyFile), true);
            if ($keys && isset($keys['public']) && isset($keys['private'])) {
                self::$vapidKeys = $keys;
                return $keys;
            }
        }

        // Genera nuove chiavi
        $keys = self::generateVapidKeys();

        // Salva su file
        if (!is_dir(dirname($keyFile))) {
            mkdir(dirname($keyFile), 0755, true);
        }
        file_put_contents($keyFile, json_encode($keys));

        self::$vapidKeys = $keys;
        return $keys;
    }

    /**
     * Genera nuove chiavi VAPID usando OpenSSL
     */
    private static function generateVapidKeys(): array
    {
        if (!self::isAvailable()) {
            throw new Exception('OpenSSL non disponibile');
        }

        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $key = openssl_pkey_new($config);
        if (!$key) {
            throw new Exception('Impossibile generare chiavi: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $d = $details['ec']['d'];

        // Chiave pubblica: 0x04 || x || y (formato uncompressed)
        $publicKey = chr(4) . str_pad($x, 32, chr(0), STR_PAD_LEFT) . str_pad($y, 32, chr(0), STR_PAD_LEFT);

        // Esporta la chiave privata in formato PEM per poterla ricaricare
        $privatePem = '';
        openssl_pkey_export($key, $privatePem);

        return [
            'public' => self::base64UrlEncode($publicKey),
            'private' => self::base64UrlEncode($d), // Mantieni anche il raw per compatibilità
            'private_pem' => base64_encode($privatePem) // PEM completo
        ];
    }

    /**
     * Base64 URL-safe encoding
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decoding
     */
    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    /**
     * Ottiene la chiave pubblica VAPID (per il client)
     */
    public static function getPublicKey(): string
    {
        $keys = self::getVapidKeys();
        return $keys['public'];
    }

    /**
     * Salva una sottoscrizione push
     */
    public static function saveSubscription(string $userType, int $userId, array $subscription): array
    {
        if (empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
            return ['success' => false, 'error' => 'Dati sottoscrizione non validi'];
        }

        try {
            $existing = Database::fetchOne(
                "SELECT id FROM push_subscriptions WHERE endpoint = ?",
                [$subscription['endpoint']]
            );

            // Determina company_id: per employee → dalla riga employees; per altri ruoli → da Tenant
            $companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
            if ($userType === 'employee') {
                $row = Database::fetchOne("SELECT company_id FROM employees WHERE id = ?", [$userId]);
                if ($row && !empty($row['company_id'])) $companyId = (int)$row['company_id'];
            }

            if ($existing) {
                Database::update('push_subscriptions', [
                    'company_id' => $companyId,
                    'user_type' => $userType,
                    'user_id' => $userId,
                    'p256dh' => $subscription['keys']['p256dh'],
                    'auth' => $subscription['keys']['auth'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ], 'id = ?', [$existing['id']]);

                return ['success' => true, 'id' => $existing['id']];
            }

            $id = Database::insert('push_subscriptions', [
                'company_id' => $companyId,
                'user_type' => $userType,
                'user_id' => $userId,
                'endpoint' => $subscription['endpoint'],
                'p256dh' => $subscription['keys']['p256dh'],
                'auth' => $subscription['keys']['auth'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            return ['success' => true, 'id' => $id];
        } catch (Throwable $e) {
            $detail = $e->getMessage();
            error_log('PushNotification::saveSubscription error: ' . $detail);
            // Tentativo di scrivere comunque su un file dedicato (alcuni hosting non rispettano ini_set error_log)
            @file_put_contents(
                ROOT_PATH . '/logs/push_debug.log',
                '[' . date('Y-m-d H:i:s') . '] saveSubscription: ' . get_class($e) . ' - ' . $detail . "\n",
                FILE_APPEND
            );
            // DIAGNOSTICA TEMPORANEA: espone il messaggio reale al client per debug
            return ['success' => false, 'error' => 'Errore salvataggio: ' . $detail];
        }
    }

    /**
     * Rimuove una sottoscrizione
     */
    public static function removeSubscription(string $endpoint): array
    {
        try {
            Database::delete('push_subscriptions', 'endpoint = ?', [$endpoint]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rimuove tutte le sottoscrizioni di un utente
     */
    public static function removeUserSubscriptions(string $userType, int $userId): array
    {
        try {
            Database::delete('push_subscriptions', 'user_type = ? AND user_id = ?', [$userType, $userId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ottiene le sottoscrizioni di un utente
     */
    public static function getUserSubscriptions(string $userType, int $userId): array
    {
        return Database::fetchAll(
            "SELECT * FROM push_subscriptions WHERE user_type = ? AND user_id = ?",
            [$userType, $userId]
        );
    }

    /**
     * Conta sottoscrizioni attive
     */
    public static function countSubscriptions(?string $userType = null): int
    {
        if ($userType) {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM push_subscriptions WHERE user_type = ?",
                [$userType]
            );
        }
        return (int) Database::fetchColumn("SELECT COUNT(*) FROM push_subscriptions");
    }

    /**
     * Invia notifica push a un utente specifico
     */
    public static function sendToUser(string $userType, int $userId, array $payload): array
    {
        // Rispetta il flag notify_push del dipendente (opt-out lato user)
        if ($userType === 'employee') {
            $pref = Database::fetchOne("SELECT notify_push FROM employees WHERE id = ?", [$userId]);
            if ($pref && (int) ($pref['notify_push'] ?? 1) === 0) {
                return ['success' => true, 'sent' => 0, 'message' => 'Push disattivate dal dipendente'];
            }
        }

        $subscriptions = self::getUserSubscriptions($userType, $userId);

        if (empty($subscriptions)) {
            return ['success' => true, 'sent' => 0, 'message' => 'Nessuna sottoscrizione attiva'];
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($subscriptions as $sub) {
            $result = self::sendPushNotification($sub, $payload);

            if ($result['success']) {
                $sent++;
                self::logPush($sub['endpoint'], null, 'sent');
            } else {
                $failed++;
                $errors[] = $result['error'] ?? 'Errore sconosciuto';
                self::logPush($sub['endpoint'], null, 'failed', $result['error'] ?? null);

                // Se endpoint non valido, rimuovilo
                if (isset($result['expired']) && $result['expired']) {
                    self::removeSubscription($sub['endpoint']);
                }
            }
        }

        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Invia notifica a tutti gli utenti di un tipo
     */
    public static function sendToUserType(string $userType, array $payload): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $subscriptions = Database::fetchAll(
            "SELECT * FROM push_subscriptions WHERE user_type = ? AND company_id = ?",
            [$userType, $cid]
        );

        $sent = 0;
        $failed = 0;

        foreach ($subscriptions as $sub) {
            $result = self::sendPushNotification($sub, $payload);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                if (isset($result['expired']) && $result['expired']) {
                    self::removeSubscription($sub['endpoint']);
                }
            }
        }

        return ['success' => true, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Invia notifica a tutti gli admin
     */
    public static function sendToAdmins(array $payload): array
    {
        return self::sendToUserType('admin', $payload);
    }

    /**
     * Invia notifica a tutti gli admin reparto di un dipartimento
     */
    public static function sendToDepartmentAdmins(int $departmentId, array $payload): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $subscriptions = Database::fetchAll(
            "SELECT ps.* FROM push_subscriptions ps
             JOIN users u ON ps.user_type = 'admin_reparto' AND ps.user_id = u.id
             WHERE u.department_id = ? AND ps.company_id = ?",
            [$departmentId, $cid]
        );

        $sent = 0;
        foreach ($subscriptions as $sub) {
            $result = self::sendPushNotification($sub, $payload);
            if ($result['success']) {
                $sent++;
            } elseif (isset($result['expired']) && $result['expired']) {
                self::removeSubscription($sub['endpoint']);
            }
        }

        return ['success' => true, 'sent' => $sent];
    }

    /**
     * Invia la notifica push effettiva usando Web Push Protocol
     */
    private static function sendPushNotification(array $subscription, array $payload): array
    {
        // Usa la libreria minishlink/web-push (battle-tested, spec-compliant)
        // se disponibile (composer install l'ha installata).
        if (class_exists('Minishlink\\WebPush\\WebPush')) {
            return self::sendViaLibrary($subscription, $payload);
        }
        return self::sendViaCustomCrypto($subscription, $payload);
    }

    /**
     * Invio tramite libreria minishlink/web-push (preferito).
     * Implementa Web Push Protocol + VAPID correttamente con AES128GCM.
     */
    private static function sendViaLibrary(array $subscription, array $payload): array
    {
        try {
            $vapidKeys = self::getVapidKeys();

            // Determina subject VAPID (mailto:) — Apple lo richiede valido
            $subEmail = null;
            try {
                $cfg = Settings::getEmailConfig();
                $candidate = $cfg['from_email'] ?? '';
                if ($candidate && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $subEmail = $candidate;
                }
            } catch (Throwable $e) {}
            if (!$subEmail) {
                $host = $_SERVER['HTTP_HOST'] ?? '';
                if ($host && !preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.)/', $host)) {
                    $subEmail = 'noreply@' . preg_replace('/:\d+$/', '', $host);
                }
            }
            $subject = $subEmail ? ('mailto:' . $subEmail) : 'mailto:noreply@example.com';

            $auth = [
                'VAPID' => [
                    'subject'    => $subject,
                    'publicKey'  => $vapidKeys['public'],
                    'privateKey' => $vapidKeys['private'],
                ],
            ];

            $webPush = new \Minishlink\WebPush\WebPush($auth, [
                'TTL'     => 2419200,
                'urgency' => 'high',
            ]);

            $sub = \Minishlink\WebPush\Subscription::create([
                'endpoint'        => $subscription['endpoint'],
                'publicKey'       => $subscription['p256dh'],
                'authToken'       => $subscription['auth'],
                'contentEncoding' => 'aes128gcm',
            ]);

            $report = $webPush->sendOneNotification($sub, json_encode($payload));

            if ($report->isSuccess()) {
                return ['success' => true];
            }

            $statusCode = method_exists($report, 'getResponse') && $report->getResponse()
                ? $report->getResponse()->getStatusCode() : 0;
            $reason = $report->getReason() ?: ('HTTP ' . $statusCode);
            $expired = ($statusCode === 404 || $statusCode === 410);

            error_log('[Push/lib] FAIL: ' . $reason . ' (HTTP ' . $statusCode . ')');
            return ['success' => false, 'error' => $reason, 'expired' => $expired];

        } catch (Throwable $e) {
            error_log('[Push/lib] EXCEPTION: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Implementazione custom (fallback se libreria non disponibile).
     */
    private static function sendViaCustomCrypto(array $subscription, array $payload): array
    {
        try {
            $endpoint = $subscription['endpoint'];
            $userPublicKey = $subscription['p256dh'];
            $userAuthToken = $subscription['auth'];

            $payloadJson = json_encode($payload);
            $encrypted = self::encryptPayload($payloadJson, $userPublicKey, $userAuthToken);
            if (!$encrypted) return ['success' => false, 'error' => 'Errore crittografia payload'];

            $vapidHeaders = self::createVapidHeaders($endpoint);
            return self::sendRequest($endpoint, $encrypted, $vapidHeaders);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cripta il payload usando ECDH + AES-GCM (aes128gcm)
     */
    private static function encryptPayload(string $payload, string $userPublicKey, string $userAuthToken): ?array
    {
        try {
            // Decodifica chiavi client
            $clientPublicKey = self::base64UrlDecode($userPublicKey);
            $clientAuthSecret = self::base64UrlDecode($userAuthToken);

            // Genera chiave locale temporanea per ECDH
            $localKeyPair = self::generateLocalKeyPair();
            if (!$localKeyPair) {
                return null;
            }

            // Calcola shared secret usando ECDH
            $sharedSecret = self::computeSharedSecret($localKeyPair['private_key'], $clientPublicKey);
            if (!$sharedSecret) {
                return null;
            }

            // Derive keys usando HKDF
            // PRK = HKDF-Extract(auth_secret, shared_secret)
            $prk = hash_hmac('sha256', $sharedSecret, $clientAuthSecret, true);

            // Info per IKM
            $keyInfo = "WebPush: info\x00" . $clientPublicKey . $localKeyPair['public_key'];
            $ikm = self::hkdfExpand($prk, $keyInfo, 32);

            // Salt casuale
            $salt = random_bytes(16);

            // PRK per content encryption
            $contentPrk = hash_hmac('sha256', $ikm, $salt, true);

            // Deriva CEK (Content Encryption Key) e nonce
            $cekInfo = "Content-Encoding: aes128gcm\x00";
            $nonceInfo = "Content-Encoding: nonce\x00";

            $cek = self::hkdfExpand($contentPrk, $cekInfo, 16);
            $nonce = self::hkdfExpand($contentPrk, $nonceInfo, 12);

            // Padding del payload (RFC 8188 - aes128gcm)
            // Format: data | delimiter | padding
            // delimiter: 0x02 = more records, 0x01 = final record
            // Per un singolo record: data | 0x01 | zeros (padding opzionale)
            $delimiter = "\x01"; // Final record
            $paddedPayload = $payload . $delimiter;

            // Cripta con AES-128-GCM
            $tag = '';
            $ciphertext = openssl_encrypt(
                $paddedPayload,
                'aes-128-gcm',
                $cek,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                '',
                16
            );

            if ($ciphertext === false) {
                error_log('[Push] openssl_encrypt fallito: ' . openssl_error_string());
                return null;
            }

            // Costruisci body secondo aes128gcm (RFC 8188)
            // Header: salt (16) + rs (4) + idlen (1) + keyid (65)
            $rs = pack('N', 4096); // Record size
            $idlen = chr(65); // Lunghezza chiave pubblica (65 = uncompressed EC point)

            $body = $salt . $rs . $idlen . $localKeyPair['public_key'] . $ciphertext . $tag;

            return [
                'body' => $body,
                'salt' => $salt,
                'localPublicKey' => $localKeyPair['public_key']
            ];

        } catch (Exception $e) {
            error_log('encryptPayload error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Genera coppia di chiavi EC locale per ECDH
     */
    private static function generateLocalKeyPair(): ?array
    {
        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $key = openssl_pkey_new($config);
        if (!$key) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        $x = str_pad($details['ec']['x'], 32, chr(0), STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, chr(0), STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, chr(0), STR_PAD_LEFT);

        return [
            'public_key' => chr(4) . $x . $y,
            'private_key' => $key,
            'd' => $d
        ];
    }

    /**
     * Calcola shared secret usando ECDH
     */
    private static function computeSharedSecret($privateKey, string $peerPublicKey): ?string
    {
        // Estrai coordinate dalla chiave pubblica peer (formato uncompressed: 04 || x || y)
        if (strlen($peerPublicKey) !== 65 || $peerPublicKey[0] !== chr(4)) {
            return null;
        }

        $peerX = substr($peerPublicKey, 1, 32);
        $peerY = substr($peerPublicKey, 33, 32);

        // Costruisci PEM della chiave pubblica peer
        $peerPem = self::createPublicKeyPem($peerX, $peerY);
        $peerKey = openssl_pkey_get_public($peerPem);

        if (!$peerKey) {
            return null;
        }

        // Calcola shared secret (in PHP 8.5 il parametro length è deprecato; restituisce 32 byte di default per P-256)
        $sharedSecret = openssl_pkey_derive($peerKey, $privateKey);

        return $sharedSecret !== false ? $sharedSecret : null;
    }

    /**
     * Crea PEM da coordinate EC
     */
    private static function createPublicKeyPem(string $x, string $y): string
    {
        // ASN.1 DER encoding per chiave EC pubblica P-256
        $der = "\x30\x59" .                    // SEQUENCE (89 bytes)
               "\x30\x13" .                    // SEQUENCE (19 bytes) - AlgorithmIdentifier
               "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . // OID ecPublicKey
               "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" . // OID prime256v1
               "\x03\x42\x00" .                // BIT STRING (66 bytes)
               "\x04" . $x . $y;               // Uncompressed point

        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($der), 64, "\n") .
               "-----END PUBLIC KEY-----\n";
    }

    /**
     * HKDF Expand (RFC 5869)
     */
    private static function hkdfExpand(string $prk, string $info, int $length): string
    {
        $t = '';
        $okm = '';
        $i = 0;

        while (strlen($okm) < $length) {
            $i++;
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }

        return substr($okm, 0, $length);
    }

    /**
     * Crea header VAPID per autenticazione
     */
    private static function createVapidHeaders(string $endpoint): array
    {
        $vapidKeys = self::getVapidKeys();

        // Estrai audience dall'endpoint
        $parsedUrl = parse_url($endpoint);
        $audience = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        // Determina il subject (mailto) — Apple Web Push rifiuta localhost/.local con BadJwtToken.
        // Priorità: smtp_from_email configurata > HTTP_HOST reale > APP_URL > fallback hardcoded.
        $subEmail = null;

        $domainIsBad = static function(string $d): bool {
            $d = preg_replace('/:\d+$/', '', $d);
            return !$d
                || $d === 'localhost'
                || str_ends_with($d, '.local')
                || str_ends_with($d, '.localhost')
                || filter_var($d, FILTER_VALIDATE_IP) !== false;
        };

        try {
            if (class_exists('Settings')) {
                $cfg = Settings::getEmailConfig();
                $candidate = $cfg['from_email'] ?? '';
                if ($candidate && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $candidateDomain = substr(strrchr($candidate, '@') ?: '', 1);
                    if (!$domainIsBad($candidateDomain)) {
                        $subEmail = $candidate;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignora, useremo fallback
        }

        if (!$subEmail) {
            $domain = '';
            if (!empty($_SERVER['HTTP_HOST']) && !$domainIsBad($_SERVER['HTTP_HOST'])) {
                $domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
            } elseif (defined('APP_URL') && APP_URL) {
                $h = parse_url(APP_URL, PHP_URL_HOST) ?: '';
                if (!$domainIsBad($h)) {
                    $domain = $h;
                }
            }

            $subEmail = $domain ? 'noreply@' . $domain : 'noreply@example.com';
        }

        $sub = str_starts_with($subEmail, 'mailto:') ? $subEmail : 'mailto:' . $subEmail;

        // Crea JWT
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256'
        ];

        $payload = [
            'aud' => $audience,
            'exp' => time() + 43200, // 12 ore
            'sub' => $sub
        ];

        error_log('[Push] VAPID JWT - aud: ' . $audience . ', sub: ' . $sub . ', exp: ' . date('Y-m-d H:i:s', $payload['exp']));

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $dataToSign = $headerEncoded . '.' . $payloadEncoded;

        // Firma con chiave privata VAPID
        $signature = self::signWithVapid($dataToSign, $vapidKeys['private']);

        $jwt = $dataToSign . '.' . self::base64UrlEncode($signature);

        return [
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $vapidKeys['public'],
            'Crypto-Key' => 'p256ecdsa=' . $vapidKeys['public']
        ];
    }

    /**
     * Firma dati con chiave privata VAPID (ECDSA P-256)
     */
    private static function signWithVapid(string $data, string $privateKeyBase64): string
    {
        $vapidKeys = self::getVapidKeys();
        $key = null;

        // Metodo 1: Usa PEM se disponibile (più affidabile)
        if (!empty($vapidKeys['private_pem'])) {
            $pem = base64_decode($vapidKeys['private_pem']);
            $key = @openssl_pkey_get_private($pem);
            if ($key) {
                error_log('[Push] Chiave VAPID caricata da PEM');
            }
        }

        // Metodo 2: Ricostruisci da raw bytes
        if (!$key) {
            $privateKeyRaw = self::base64UrlDecode($privateKeyBase64);

            if (strlen($privateKeyRaw) !== 32) {
                throw new Exception('Chiave privata VAPID non valida: lunghezza ' . strlen($privateKeyRaw) . ' invece di 32');
            }

            // Prova diversi formati DER
            $key = self::loadPrivateKeyPKCS8($privateKeyRaw);
            if ($key) {
                error_log('[Push] Chiave VAPID caricata da PKCS8');
            }

            if (!$key) {
                $key = self::loadPrivateKeySEC1($privateKeyRaw);
                if ($key) {
                    error_log('[Push] Chiave VAPID caricata da SEC1');
                }
            }

            if (!$key) {
                $key = self::loadPrivateKeyWithPublic($privateKeyRaw);
                if ($key) {
                    error_log('[Push] Chiave VAPID caricata con metodo alternativo');
                }
            }
        }

        if (!$key) {
            // Ultimo tentativo: rigenera le chiavi
            error_log('[Push] Tutti i metodi falliti, suggerisco rigenerazione chiavi');
            $lastError = openssl_error_string();
            throw new Exception('Errore caricamento chiave privata VAPID: ' . ($lastError ?: 'formato non supportato') . '. Prova a rigenerare le chiavi VAPID dalla pagina di debug.');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new Exception('Errore firma VAPID: ' . openssl_error_string());
        }

        // Converti da DER a formato raw (r || s)
        return self::derToRaw($signature);
    }

    /**
     * Carica chiave privata in formato PKCS#8
     */
    private static function loadPrivateKeyPKCS8(string $privateKeyRaw)
    {
        // PKCS#8 format per EC P-256
        $der = "\x30\x41" .                              // SEQUENCE (65 bytes)
               "\x02\x01\x00" .                          // INTEGER 0 (version)
               "\x30\x13" .                              // SEQUENCE (19 bytes) - AlgorithmIdentifier
               "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" .  // OID ecPublicKey
               "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" . // OID prime256v1
               "\x04\x27" .                              // OCTET STRING (39 bytes)
               "\x30\x25" .                              // SEQUENCE (37 bytes) - ECPrivateKey
               "\x02\x01\x01" .                          // INTEGER 1 (version)
               "\x04\x20" . $privateKeyRaw;              // OCTET STRING (32 bytes)

        $pem = "-----BEGIN PRIVATE KEY-----\n" .
               chunk_split(base64_encode($der), 64, "\n") .
               "-----END PRIVATE KEY-----\n";

        return @openssl_pkey_get_private($pem);
    }

    /**
     * Carica chiave privata in formato SEC1 (EC PRIVATE KEY)
     */
    private static function loadPrivateKeySEC1(string $privateKeyRaw)
    {
        // SEC1 format
        $der = "\x30\x41" .                    // SEQUENCE (65 bytes)
               "\x02\x01\x01" .                // INTEGER 1 (version)
               "\x04\x20" . $privateKeyRaw .   // OCTET STRING (32 bytes) - private key
               "\xa0\x0a" .                    // [0] EXPLICIT (10 bytes)
               "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID prime256v1

        $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
               chunk_split(base64_encode($der), 64, "\n") .
               "-----END EC PRIVATE KEY-----\n";

        return @openssl_pkey_get_private($pem);
    }

    /**
     * Carica chiave privata includendo la chiave pubblica
     */
    private static function loadPrivateKeyWithPublic(string $privateKeyRaw)
    {
        // Ottieni la chiave pubblica dalle chiavi VAPID salvate
        $vapidKeys = self::$vapidKeys;
        if (empty($vapidKeys['public'])) {
            return false;
        }

        $publicKeyRaw = self::base64UrlDecode($vapidKeys['public']);
        if (strlen($publicKeyRaw) !== 65) {
            return false;
        }

        // SEC1 format completo con chiave pubblica
        // ECPrivateKey ::= SEQUENCE {
        //   version INTEGER,
        //   privateKey OCTET STRING,
        //   parameters [0] ECParameters OPTIONAL,
        //   publicKey [1] BIT STRING OPTIONAL
        // }

        // Costruisci il BIT STRING per la chiave pubblica (0x00 prefix per zero unused bits)
        $publicKeyBitString = "\x00" . $publicKeyRaw; // 66 bytes

        $innerDer = "\x02\x01\x01" .                         // INTEGER 1 (version)
                    "\x04\x20" . $privateKeyRaw .            // OCTET STRING (32 bytes) - private key
                    "\xa0\x0a" .                             // [0] EXPLICIT (10 bytes)
                    "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" . // OID prime256v1
                    "\xa1\x44" .                             // [1] EXPLICIT (68 bytes)
                    "\x03\x42" . $publicKeyBitString;        // BIT STRING (66 bytes)

        // Calcola lunghezza totale
        $innerLen = strlen($innerDer);
        if ($innerLen > 127) {
            $der = "\x30\x81" . chr($innerLen) . $innerDer;
        } else {
            $der = "\x30" . chr($innerLen) . $innerDer;
        }

        $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
               chunk_split(base64_encode($der), 64, "\n") .
               "-----END EC PRIVATE KEY-----\n";

        error_log('[Push] Tentativo caricamento chiave con pubblica, PEM length: ' . strlen($pem));

        return @openssl_pkey_get_private($pem);
    }

    /**
     * Converte firma ECDSA da DER a raw (r || s)
     */
    private static function derToRaw(string $der): string
    {
        $pos = 0;
        if ($der[$pos++] !== "\x30") {
            throw new Exception('Invalid DER signature');
        }

        // Lunghezza totale
        $totalLen = ord($der[$pos++]);
        if ($totalLen & 0x80) {
            $pos += ($totalLen & 0x7f);
        }

        // R
        if ($der[$pos++] !== "\x02") {
            throw new Exception('Invalid DER R');
        }
        $rLen = ord($der[$pos++]);
        $r = substr($der, $pos, $rLen);
        $pos += $rLen;

        // S
        if ($der[$pos++] !== "\x02") {
            throw new Exception('Invalid DER S');
        }
        $sLen = ord($der[$pos++]);
        $s = substr($der, $pos, $sLen);

        // Rimuovi padding e normalizza a 32 byte
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) .
               str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Invia richiesta HTTP al push service
     */
    private static function sendRequest(string $endpoint, array $encrypted, array $vapidHeaders): array
    {
        // Rileva se è Apple Push
        $isApple = strpos($endpoint, 'apple') !== false || strpos($endpoint, 'push.apple') !== false;

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 2419200', // 28 giorni (max per Apple)
            'Urgency: high' // Alta priorità
        ];

        // Headers specifici per Apple
        if ($isApple) {
            // Apple richiede Topic per identificare l'app
            $headers[] = 'apns-push-type: alert';
            $headers[] = 'apns-priority: 10'; // 10 = immediate delivery
            $headers[] = 'apns-expiration: ' . (time() + 2419200);
            error_log('[Push] Aggiunti headers Apple-specifici');
        }

        foreach ($vapidHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        // Log dettagliato
        error_log('[Push] Invio a: ' . substr($endpoint, 0, 80) . '...');
        error_log('[Push] Headers: ' . implode(' | ', array_map(fn($h) => substr($h, 0, 50), $headers)));
        error_log('[Push] Body size: ' . strlen($encrypted['body']) . ' bytes');

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
            CURLOPT_VERBOSE => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        // curl_close() è deprecato da PHP 8.0 (no-op): l'handle viene rilasciato dal GC.

        // Log risposta
        error_log('[Push] HTTP Code: ' . $httpCode);
        if ($error) {
            error_log('[Push] CURL Error (' . $errno . '): ' . $error);
            return ['success' => false, 'error' => 'CURL error: ' . $error, 'errno' => $errno];
        }

        // Estrai body dalla risposta (dopo headers)
        $headerSize = strpos($response, "\r\n\r\n");
        $responseBody = $headerSize !== false ? substr($response, $headerSize + 4) : $response;
        if (!empty($responseBody)) {
            error_log('[Push] Response body: ' . substr($responseBody, 0, 200));
        }

        // 201 = created (successo)
        // 410 = gone (sottoscrizione scaduta)
        // 404 = not found (sottoscrizione non valida)
        if ($httpCode === 201) {
            error_log('[Push] Successo!');
            return ['success' => true];
        } elseif ($httpCode === 410 || $httpCode === 404) {
            error_log('[Push] Sottoscrizione scaduta/non valida');
            return ['success' => false, 'error' => 'Subscription expired (HTTP ' . $httpCode . ')', 'expired' => true];
        } else {
            error_log('[Push] Errore HTTP: ' . $httpCode . ' - ' . $responseBody);
            return ['success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . substr($responseBody, 0, 100)];
        }
    }

    /**
     * Invia notifica push basata su una notifica del sistema
     */
    public static function sendFromNotification(array $notification): array
    {
        return self::sendToUser(
            $notification['recipient_type'],
            $notification['recipient_id'],
            [
                'title' => $notification['title'],
                'body' => $notification['message'],
                'url' => $notification['link'] ?? '/',
                'tag' => 'notification-' . ($notification['id'] ?? time())
            ]
        );
    }

    /**
     * Log invio push
     */
    private static function logPush(string $endpoint, ?int $notificationId, string $status, ?string $error = null): void
    {
        try {
            $sub = Database::fetchOne("SELECT id FROM push_subscriptions WHERE endpoint = ?", [$endpoint]);

            Database::insert('push_logs', [
                'subscription_id' => $sub['id'] ?? null,
                'notification_id' => $notificationId,
                'status' => $status,
                'error_message' => $error
            ]);
        } catch (Exception $e) {
            // Ignora errori di logging
        }
    }

    // ==========================================
    // METODI HELPER PER INVIO NOTIFICHE SPECIFICHE
    // ==========================================

    /**
     * Notifica nuovo messaggio chat
     */
    public static function notifyNewChatMessage(string $recipientType, int $recipientId, string $senderName, string $messagePreview): array
    {
        return self::sendToUser($recipientType, $recipientId, [
            'title' => 'Nuovo messaggio da ' . $senderName,
            'body' => mb_substr($messagePreview, 0, 100) . (mb_strlen($messagePreview) > 100 ? '...' : ''),
            'url' => '/chat.php',
            'tag' => 'chat-' . time(),
            'icon' => '/assets/images/icon.php?size=192'
        ]);
    }

    /**
     * Notifica nuova comunicazione
     */
    public static function notifyNewCommunication(string $recipientType, int $recipientId, string $title): array
    {
        $url = $recipientType === 'employee' ? '/employee/communications.php' : '/admin/communications.php';

        return self::sendToUser($recipientType, $recipientId, [
            'title' => 'Nuova comunicazione',
            'body' => $title,
            'url' => $url,
            'tag' => 'communication-' . time(),
            'icon' => '/assets/images/icon.php?size=192'
        ]);
    }

    /**
     * Notifica nuova richiesta ferie (per admin reparto)
     */
    public static function notifyNewLeaveRequest(int $adminId, string $employeeName, string $leaveType, string $role = 'admin_reparto'): array
    {
        $types = [
            'ferie' => 'Ferie',
            'permesso' => 'Permesso',
            'malattia' => 'Malattia',
            'permesso_104' => 'Permesso L.104',
            'congedo_parentale' => 'Congedo Parentale',
            'altro' => 'Altro'
        ];

        $url = $role === 'admin'
            ? '/admin/leave-requests.php'
            : '/admin-reparto/leave-requests.php';

        return self::sendToUser($role, $adminId, [
            'title' => 'Nuova richiesta ' . ($types[$leaveType] ?? $leaveType),
            'body' => $employeeName . ' ha inviato una richiesta',
            'url' => $url,
            'tag' => 'leave-request-' . time(),
            'icon' => '/assets/images/icon.php?size=192'
        ]);
    }

    /**
     * Notifica risposta richiesta ferie (per dipendente)
     */
    public static function notifyLeaveRequestResponse(int $employeeId, string $status, string $leaveType): array
    {
        $statusText = $status === 'approved' ? 'approvata' : 'rifiutata';

        $types = [
            'ferie' => 'Ferie',
            'permesso' => 'Permesso',
            'malattia' => 'Malattia',
            'permesso_104' => 'Permesso L.104',
            'congedo_parentale' => 'Congedo Parentale',
            'altro' => 'Richiesta'
        ];

        return self::sendToUser('employee', $employeeId, [
            'title' => ($types[$leaveType] ?? 'Richiesta') . ' ' . $statusText,
            'body' => 'La tua richiesta di ' . strtolower($types[$leaveType] ?? $leaveType) . ' è stata ' . $statusText,
            'url' => '/employee/leave-requests.php',
            'tag' => 'leave-response-' . time(),
            'icon' => '/assets/images/icon.php?size=192'
        ]);
    }

    /**
     * Notifica nuovo documento caricato (per dipendente)
     */
    public static function notifyNewDocument(int $employeeId, string $documentType, string $period): array
    {
        $types = [
            'busta_paga' => 'Busta paga',
            'cud' => 'CUD',
            'contratto' => 'Contratto',
            'altro' => 'Documento'
        ];

        return self::sendToUser('employee', $employeeId, [
            'title' => 'Nuovo documento disponibile',
            'body' => ($types[$documentType] ?? 'Documento') . ' ' . $period,
            'url' => '/employee/documents.php',
            'tag' => 'document-' . time(),
            'icon' => '/assets/images/icon.php?size=192'
        ]);
    }

    /**
     * Notifica broadcast a tutti i dipendenti
     */
    public static function broadcastToEmployees(string $title, string $body, string $url = '/'): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $subscriptions = Database::fetchAll(
            "SELECT * FROM push_subscriptions WHERE user_type = 'employee' AND company_id = ?",
            [$cid]
        );

        $sent = 0;
        foreach ($subscriptions as $sub) {
            $result = self::sendPushNotification($sub, [
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'tag' => 'broadcast-' . time(),
                'icon' => '/assets/images/icon.php?size=192'
            ]);

            if ($result['success']) {
                $sent++;
            } elseif (isset($result['expired']) && $result['expired']) {
                self::removeSubscription($sub['endpoint']);
            }
        }

        return ['success' => true, 'sent' => $sent];
    }
}
