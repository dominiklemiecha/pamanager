<?php
/**
 * Wrike - Integrazione per-utente con l'API Wrike v4 (consulente del lavoro).
 *
 * Il consulente incolla il suo Permanent Access Token nel profilo; quando arriva
 * una richiesta di assunzione (o un messaggio chat, se abilitato) viene creata
 * un'attivita' nella cartella Wrike scelta. Tutte le chiamate sono best-effort:
 * non devono MAI bloccare il flusso applicativo.
 *
 * API: https://developers.wrike.com (v4). Auth: Bearer token.
 * Datacenter multipli: il host giusto viene rilevato alla connessione e salvato.
 */
class Wrike
{
    public const PROVIDER = 'wrike';

    /** Datacenter Wrike noti (rilevati in ordine alla verifica del token). */
    private const HOSTS = [
        'https://www.wrike.com',
        'https://app-eu.wrike.com',
        'https://app-us2.wrike.com',
    ];

    // ===================== Storage =====================

    /** Riga integrazione per utente (token decifrato in chiave 'token', config decodificata). */
    public static function getForUser(int $userId): ?array
    {
        try {
            $row = Database::fetchOne(
                "SELECT * FROM user_integrations WHERE user_id = ? AND provider = ?",
                [$userId, self::PROVIDER]
            );
        } catch (Throwable $e) {
            return null; // migration 046 non ancora eseguita
        }
        if (!$row) return null;
        $row['token']  = $row['access_token'] ? self::decryptToken($row['access_token']) : null;
        $row['config'] = $row['config'] ? (json_decode($row['config'], true) ?: []) : [];
        return $row;
    }

    public static function isConnected(int $userId): bool
    {
        $i = self::getForUser($userId);
        return $i !== null && !empty($i['token']) && !empty($i['is_active']);
    }

    /**
     * Verifica il token contro i datacenter e salva l'integrazione.
     * @return array ['success', 'error', 'account_name', 'host']
     */
    public static function connect(int $userId, string $token): array
    {
        $token = trim($token);
        if ($token === '') return ['success' => false, 'error' => 'Token mancante'];
        if (strlen($token) < 20) return ['success' => false, 'error' => 'Il token sembra troppo corto: copia il "Permanent access token" completo'];

        $found = null;
        $lastErr = 'Token non valido';
        foreach (self::HOSTS as $host) {
            try {
                $res = self::api($token, $host, 'GET', '/contacts', ['me' => 'true']);
                if (!empty($res['data'][0])) {
                    $c = $res['data'][0];
                    $found = [
                        'host' => $host,
                        'account_name' => trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')),
                        'me_id' => $c['id'] ?? null,
                    ];
                    break;
                }
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        if (!$found) {
            return ['success' => false, 'error' => 'Connessione fallita: ' . $lastErr];
        }

        $existing = self::getForUser($userId);
        $config = $existing['config'] ?? [];
        $config['host'] = $found['host'];
        $config['account_name'] = $found['account_name'];
        $config['me_id'] = $found['me_id'];
        // Default: assunzioni ON, chat OFF (la chat puo' essere rumorosa)
        if (!isset($config['on_hire'])) $config['on_hire'] = 1;
        if (!isset($config['on_chat'])) $config['on_chat'] = 0;

        $payload = [
            'access_token' => self::encryptToken($token),
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
            'last_error' => null,
            'last_success_at' => date('Y-m-d H:i:s'),
        ];
        try {
            if ($existing) {
                Database::update('user_integrations', $payload, 'id = ?', [(int)$existing['id']]);
            } else {
                Database::insert('user_integrations', array_merge($payload, [
                    'user_id' => $userId,
                    'provider' => self::PROVIDER,
                ]));
            }
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Errore salvataggio: ' . $e->getMessage()];
        }
        return ['success' => true, 'account_name' => $found['account_name'], 'host' => $found['host']];
    }

    public static function disconnect(int $userId): void
    {
        try {
            Database::delete('user_integrations', 'user_id = ? AND provider = ?', [$userId, self::PROVIDER]);
        } catch (Throwable $e) {}
    }

    /** Aggiorna cartella destinazione + toggle eventi. */
    public static function updateSettings(int $userId, ?string $folderId, ?string $folderName, bool $onHire, bool $onChat): array
    {
        $i = self::getForUser($userId);
        if (!$i) return ['success' => false, 'error' => 'Integrazione non configurata'];
        $config = $i['config'];
        $config['folder_id']   = $folderId ?: null;
        $config['folder_name'] = $folderName ?: null;
        $config['on_hire'] = $onHire ? 1 : 0;
        $config['on_chat'] = $onChat ? 1 : 0;
        try {
            Database::update('user_integrations',
                ['config' => json_encode($config, JSON_UNESCAPED_UNICODE)],
                'id = ?', [(int)$i['id']]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        return ['success' => true];
    }

    // ===================== API Wrike =====================

    /** Lista cartelle (id, title) dove poter creare task. */
    public static function getFolders(int $userId): array
    {
        $i = self::getForUser($userId);
        if (!$i || empty($i['token'])) return [];
        try {
            $res = self::api($i['token'], self::hostOf($i), 'GET', '/folders');
            $out = [];
            foreach ($res['data'] ?? [] as $f) {
                $scope = $f['scope'] ?? '';
                if ($scope === 'WsRoot') {
                    $out[] = ['id' => $f['id'], 'title' => '(Radice account)'];
                } elseif ($scope === 'WsFolder') {
                    $out[] = ['id' => $f['id'], 'title' => $f['title'] ?? $f['id']];
                }
            }
            return $out;
        } catch (Throwable $e) {
            self::markError($userId, $e->getMessage());
            return [];
        }
    }

    /**
     * Crea un task nella cartella configurata (o nella radice).
     * @return ?string id del task creato, null su errore
     */
    public static function createTask(int $userId, string $title, string $descriptionHtml = '', string $importance = 'Normal'): ?string
    {
        $i = self::getForUser($userId);
        if (!$i || empty($i['token']) || empty($i['is_active'])) return null;
        $host = self::hostOf($i);
        try {
            $folderId = $i['config']['folder_id'] ?? null;
            if (!$folderId) {
                // Fallback: radice account
                $res = self::api($i['token'], $host, 'GET', '/folders');
                foreach ($res['data'] ?? [] as $f) {
                    if (($f['scope'] ?? '') === 'WsRoot') { $folderId = $f['id']; break; }
                }
            }
            if (!$folderId) throw new RuntimeException('Nessuna cartella Wrike disponibile');

            $params = [
                'title' => mb_substr($title, 0, 250),
                'description' => $descriptionHtml,
                'importance' => $importance,
            ];
            // Auto-assegnazione: il task compare in "My to-dos"/Assegnati a me.
            // (la Inbox di Wrike NON si puo' alimentare via API: mostra solo azioni di altri utenti)
            $meId = self::meId($i);
            if ($meId) $params['responsibles'] = json_encode([$meId]);

            $res = self::api($i['token'], $host, 'POST', '/folders/' . rawurlencode($folderId) . '/tasks', $params);
            $taskId = $res['data'][0]['id'] ?? null;
            if ($taskId) {
                try {
                    Database::update('user_integrations',
                        ['last_success_at' => date('Y-m-d H:i:s'), 'last_error' => null],
                        'id = ?', [(int)$i['id']]);
                } catch (Throwable $e) {}
            }
            return $taskId;
        } catch (Throwable $e) {
            self::markError($userId, $e->getMessage());
            return null;
        }
    }

    // ===================== Trigger applicativi =====================

    /** Attivita' per nuova richiesta di assunzione (o re-invio dopo modifiche). */
    public static function taskForHireRequest(int $consulenteUserId, int $hireRequestId, array $hr, bool $isResend): void
    {
        try {
            $i = self::getForUser($consulenteUserId);
            if (!$i || empty($i['is_active']) || empty($i['config']['on_hire'])) return;

            $who = trim(($hr['employee_first_name'] ?? '') . ' ' . ($hr['employee_last_name'] ?? ''));
            if ($who === '') $who = 'richiesta #' . $hireRequestId;
            $company = $hr['employer_name'] ?? '';
            $title = ($isResend ? 'Richiesta assunzione AGGIORNATA — ' : 'Nuova richiesta assunzione — ') . $who . ($company ? ' (' . $company . ')' : '');

            $link = function_exists('buildPublicUrl')
                ? buildPublicUrl('/consulente-lavoro/hire-requests.php?id=' . $hireRequestId)
                : '/consulente-lavoro/hire-requests.php?id=' . $hireRequestId;
            $desc = '<b>' . htmlspecialchars($company) . '</b> ha ' . ($isResend ? 'aggiornato la' : 'inviato una') . ' richiesta di simulazione/assunzione per <b>' . htmlspecialchars($who) . '</b>.<br>'
                  . 'Mansioni: ' . htmlspecialchars((string)($hr['role_description'] ?? '—')) . '<br>'
                  . 'Ore settimanali: ' . htmlspecialchars((string)($hr['weekly_hours'] ?? '—'))
                  . ' — Inizio: ' . htmlspecialchars((string)($hr['start_date'] ?? '—')) . '<br><br>'
                  . '<a href="' . htmlspecialchars($link) . '">Apri la richiesta sul gestionale</a>';

            self::createTask($consulenteUserId, $title, $desc, 'High');
        } catch (Throwable $e) {
            error_log('[Wrike::taskForHireRequest] ' . $e->getMessage());
        }
    }

    /**
     * Attivita' per messaggio chat. Dedupe: UNA attivita' per conversazione finche'
     * il task resta aperto su Wrike (se completato/cancellato se ne crea uno nuovo).
     */
    public static function taskForChatMessage(int $consulenteUserId, int $conversationId, string $senderName, string $preview, string $chatUrl): void
    {
        try {
            $i = self::getForUser($consulenteUserId);
            if (!$i || empty($i['is_active']) || empty($i['config']['on_chat'])) return;
            $host = self::hostOf($i);

            // Task gia' creato per questa conversazione e ancora aperto? Allora skip.
            $existingTaskId = $i['config']['chat_tasks'][(string)$conversationId] ?? null;
            if ($existingTaskId) {
                try {
                    $res = self::api($i['token'], $host, 'GET', '/tasks/' . rawurlencode($existingTaskId));
                    $status = $res['data'][0]['status'] ?? null;
                    if (in_array($status, ['Active', 'Deferred'], true)) return; // task ancora aperto
                } catch (Throwable $e) {
                    // task non trovato / eliminato: si ricrea
                }
            }

            $link = function_exists('buildPublicUrl') ? buildPublicUrl($chatUrl) : $chatUrl;
            $title = 'Chat gestionale — nuovo messaggio da ' . $senderName;
            $desc = '<b>' . htmlspecialchars($senderName) . '</b> ti ha scritto sul gestionale:<br>'
                  . '<blockquote>' . htmlspecialchars($preview) . '</blockquote>'
                  . '<a href="' . htmlspecialchars($link) . '">Apri la chat per rispondere</a>';
            $taskId = self::createTask($consulenteUserId, $title, $desc, 'Normal');
            if ($taskId) {
                // Salva il mapping conversazione -> task per il dedupe
                $fresh = self::getForUser($consulenteUserId);
                if ($fresh) {
                    $config = $fresh['config'];
                    $config['chat_tasks'][(string)$conversationId] = $taskId;
                    Database::update('user_integrations',
                        ['config' => json_encode($config, JSON_UNESCAPED_UNICODE)],
                        'id = ?', [(int)$fresh['id']]);
                }
            }
        } catch (Throwable $e) {
            error_log('[Wrike::taskForChatMessage] ' . $e->getMessage());
        }
    }

    // ===================== Internals =====================

    private static function hostOf(array $integration): string
    {
        return $integration['config']['host'] ?? self::HOSTS[0];
    }

    /**
     * Contact ID Wrike dell'utente collegato (per l'auto-assegnazione).
     * Le integrazioni create prima di questo campo lo recuperano e salvano al volo.
     */
    private static function meId(array $integration): ?string
    {
        if (!empty($integration['config']['me_id'])) return $integration['config']['me_id'];
        try {
            $res = self::api($integration['token'], self::hostOf($integration), 'GET', '/contacts', ['me' => 'true']);
            $meId = $res['data'][0]['id'] ?? null;
            if ($meId) {
                $config = $integration['config'];
                $config['me_id'] = $meId;
                Database::update('user_integrations',
                    ['config' => json_encode($config, JSON_UNESCAPED_UNICODE)],
                    'id = ?', [(int)$integration['id']]);
            }
            return $meId;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function markError(int $userId, string $err): void
    {
        error_log('[Wrike] user ' . $userId . ': ' . $err);
        try {
            Database::update('user_integrations',
                ['last_error' => mb_substr($err, 0, 250)],
                'user_id = ? AND provider = ?', [$userId, self::PROVIDER]);
        } catch (Throwable $e) {}
    }

    /** Chiamata HTTP all'API v4. Lancia RuntimeException su errore. */
    private static function api(string $token, string $host, string $method, string $path, array $params = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('estensione cURL non disponibile');
        }
        $url = rtrim($host, '/') . '/api/v4' . $path;
        $ch = curl_init();
        $headers = ['Authorization: Bearer ' . $token];
        if ($method === 'GET') {
            if (!empty($params)) $url .= '?' . http_build_query($params);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException('errore di rete verso Wrike (curl ' . $errno . ')');
        }
        $json = json_decode($body, true);
        if ($http >= 400 || !is_array($json)) {
            $msg = is_array($json) ? ($json['errorDescription'] ?? $json['error'] ?? ('HTTP ' . $http)) : ('HTTP ' . $http);
            throw new RuntimeException($msg);
        }
        return $json;
    }

    // --- Cifratura token (AES-256-GCM, chiave derivata da env o credenziali DB) ---

    private static function cryptoKey(): string
    {
        $secret = getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($secret === '') {
            // Fallback: derivata dalle credenziali DB (stabile per ambiente).
            // Se cambiano, il consulente reincolla il token dal profilo.
            $db = require dirname(__DIR__, 2) . '/config/database.php';
            $secret = ($db['password'] ?? '') . '|' . ($db['database'] ?? '') . '|wrike-v1';
        }
        return hash('sha256', $secret, true);
    }

    private static function encryptToken(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return 'plain:' . base64_encode($plain); // fallback estremo
        return 'gcm:' . base64_encode($iv . $tag . $ct);
    }

    private static function decryptToken(string $stored): ?string
    {
        if (strpos($stored, 'plain:') === 0) {
            return base64_decode(substr($stored, 6)) ?: null;
        }
        if (strpos($stored, 'gcm:') !== 0) return null;
        $raw = base64_decode(substr($stored, 4));
        if ($raw === false || strlen($raw) < 29) return null;
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $out = openssl_decrypt($ct, 'aes-256-gcm', self::cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $out === false ? null : $out;
    }
}
