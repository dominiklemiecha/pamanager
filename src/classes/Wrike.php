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

    /**
     * Attivita' per richiesta di assunzione. Un SOLO task per richiesta:
     * il primo invio lo crea (mapping in config.hire_tasks), il re-invio dopo
     * modifiche aggiunge un commento sul task esistente (nuovo task solo se
     * quello vecchio e' stato eliminato su Wrike).
     */
    public static function taskForHireRequest(int $consulenteUserId, int $hireRequestId, array $hr, bool $isResend): void
    {
        try {
            $i = self::getForUser($consulenteUserId);
            if (!$i || empty($i['is_active']) || empty($i['config']['on_hire'])) return;

            $who = trim(($hr['employee_first_name'] ?? '') . ' ' . ($hr['employee_last_name'] ?? ''));
            if ($who === '') $who = 'richiesta #' . $hireRequestId;
            $company = $hr['employer_name'] ?? '';
            $link = function_exists('buildPublicUrl')
                ? buildPublicUrl('/consulente-lavoro/hire-requests.php?id=' . $hireRequestId)
                : '/consulente-lavoro/hire-requests.php?id=' . $hireRequestId;

            // Re-invio: commenta il task esistente invece di crearne un altro
            $existingTaskId = $i['config']['hire_tasks'][(string)$hireRequestId] ?? null;
            if ($existingTaskId) {
                $comment = self::mention($i) . '<b>' . htmlspecialchars($company ?: 'L\'azienda') . '</b> ha aggiornato la richiesta per <b>' . htmlspecialchars($who) . '</b>. '
                         . 'Ore settimanali: ' . htmlspecialchars((string)($hr['weekly_hours'] ?? '—'))
                         . ' — Inizio: ' . htmlspecialchars((string)($hr['start_date'] ?? '—')) . '. '
                         . '<a href="' . htmlspecialchars($link) . '">Vedi i dati aggiornati</a>';
                if (self::addComment($consulenteUserId, $existingTaskId, $comment)) return;
                // task eliminato su Wrike: prosegui e ricreane uno
            }

            // Titolo "base" (nome + azienda): salvato per ricostruire il titolo a ogni step
            $baseTitle = $who . ($company ? ' (' . $company . ')' : '');
            $status = $hr['status'] ?? 'awaiting_prospects';
            $title = self::hirePrefix($status) . ' — ' . $baseTitle;
            $desc = '<b>' . htmlspecialchars($company) . '</b> ha ' . ($isResend ? 'aggiornato la' : 'inviato una') . ' richiesta di simulazione/assunzione per <b>' . htmlspecialchars($who) . '</b>.<br>'
                  . 'Mansioni: ' . htmlspecialchars((string)($hr['role_description'] ?? '—')) . '<br>'
                  . 'Ore settimanali: ' . htmlspecialchars((string)($hr['weekly_hours'] ?? '—'))
                  . ' — Inizio: ' . htmlspecialchars((string)($hr['start_date'] ?? '—')) . '<br><br>'
                  . '<a href="' . htmlspecialchars($link) . '">Apri la richiesta sul gestionale</a>';

            $taskId = self::createTask($consulenteUserId, $title, $desc, 'High');
            if ($taskId) {
                self::storeConfigKey($consulenteUserId, 'hire_tasks', (string)$hireRequestId, $taskId);
                self::storeConfigKey($consulenteUserId, 'hire_titles', (string)$hireRequestId, $baseTitle);
            }
        } catch (Throwable $e) {
            error_log('[Wrike::taskForHireRequest] ' . $e->getMessage());
        }
    }

    /** Prefisso di stato per il titolo del task, per riflettere la fase corrente. */
    private static function hirePrefix(string $status): string
    {
        switch ($status) {
            case 'awaiting_prospects': return '🟡 Prospetti da caricare';
            case 'prospects_review':   return '🔵 Prospetti da approvare';
            case 'approved':           return '🟢 Da contrattualizzare';
            case 'contract_pending':   return '✍️ Contratto da firmare';
            case 'contract_signed':    return '✅ Firmato';
            default:                   return 'Assunzione';
        }
    }

    /** Aggiorna il titolo del task in base allo stato (prefisso + nome base salvato). */
    private static function updateTaskTitle(array $integration, int $userId, int $hireRequestId, string $taskId, string $status): void
    {
        $base = $integration['config']['hire_titles'][(string)$hireRequestId] ?? null;
        if (!$base) return;
        $title = self::hirePrefix($status) . ' — ' . $base;
        try {
            self::api($integration['token'], self::hostOf($integration), 'PUT', '/tasks/' . rawurlencode($taskId), ['title' => mb_substr($title, 0, 250)]);
        } catch (Throwable $e) {
            self::markError($userId, $e->getMessage());
        }
    }

    /**
     * Menzione HTML di un utente Wrike per generare la notifica in Inbox.
     * Il task viene auto-assegnato al consulente, quindi me_id e' il destinatario.
     */
    private static function mention(array $integration): string
    {
        $meId = $integration['config']['me_id'] ?? null;
        if (!$meId) return '';
        return '<a class="stream-user-id avatar" rel="' . htmlspecialchars($meId) . '">@' . htmlspecialchars($integration['config']['account_name'] ?? 'tu') . '</a> ';
    }

    /**
     * Aggiornamento di step sul task della richiesta: aggiorna il titolo con lo stato,
     * aggiunge un commento (con @menzione per notificare in Inbox) ed eventualmente
     * completa il task. No-op se il consulente non ha Wrike o il task non esiste.
     */
    public static function hireStep(int $consulenteUserId, int $hireRequestId, string $commentHtml, bool $complete = false, ?string $newStatus = null): void
    {
        try {
            $i = self::getForUser($consulenteUserId);
            if (!$i || empty($i['is_active']) || empty($i['config']['on_hire'])) return;
            $taskId = $i['config']['hire_tasks'][(string)$hireRequestId] ?? null;
            if (!$taskId) return;
            if ($newStatus) self::updateTaskTitle($i, $consulenteUserId, $hireRequestId, $taskId, $newStatus);
            self::addComment($consulenteUserId, $taskId, self::mention($i) . $commentHtml);
            if ($complete) {
                try {
                    self::api($i['token'], self::hostOf($i), 'PUT', '/tasks/' . rawurlencode($taskId), ['status' => 'Completed']);
                } catch (Throwable $e) {
                    self::markError($consulenteUserId, $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[Wrike::hireStep] ' . $e->getMessage());
        }
    }

    /** Aggiunge un commento a un task. Ritorna false se il task non esiste piu'. */
    public static function addComment(int $userId, string $taskId, string $html): bool
    {
        $i = self::getForUser($userId);
        if (!$i || empty($i['token']) || empty($i['is_active'])) return false;
        try {
            self::api($i['token'], self::hostOf($i), 'POST', '/tasks/' . rawurlencode($taskId) . '/comments', ['plainText' => 'false', 'text' => $html]);
            return true;
        } catch (Throwable $e) {
            self::markError($userId, $e->getMessage());
            return false;
        }
    }

    /**
     * Approvazione assunzione: riporta sul task il riepilogo COMPLETO dei dati
     * inseriti dall'azienda e carica i documenti come allegati del task Wrike.
     * @param array $hr     riga hire_requests completa (dati gia' salvati)
     * @param array $docs   righe hire_request_files dei documenti da allegare
     */
    public static function hireApproved(int $consulenteUserId, int $hireRequestId, array $hr, array $docs): void
    {
        try {
            $i = self::getForUser($consulenteUserId);
            if (!$i || empty($i['is_active']) || empty($i['config']['on_hire'])) return;
            $taskId = $i['config']['hire_tasks'][(string)$hireRequestId] ?? null;
            if (!$taskId) return;

            // Titolo -> "Da contrattualizzare"
            self::updateTaskTitle($i, $consulenteUserId, $hireRequestId, $taskId, 'approved');

            // 1) Commento con il riepilogo completo dei dati di assunzione (con @menzione)
            $mention = self::mention($i);
            $row = static function (string $label, $val): string {
                $val = trim((string)$val);
                return $val === '' ? '' : '<li><b>' . htmlspecialchars($label) . ':</b> ' . htmlspecialchars($val) . '</li>';
            };
            $contratti = class_exists('HireRequest') ? HireRequest::contractTypesLabels($hr) : '';
            $giorni = class_exists('HireRequest') ? HireRequest::workDaysLabels($hr['work_days'] ?? '') : '';
            $residenza = class_exists('HireRequest') ? HireRequest::composeAddress($hr) : '';
            $html = $mention . '✅ <b>Prospetti approvati dall\'azienda — dati completi per il contratto:</b><ul>'
                  . $row('Nome', trim(($hr['employee_first_name'] ?? '') . ' ' . ($hr['employee_last_name'] ?? '')))
                  . $row('Codice fiscale', $hr['fiscal_code'] ?? '')
                  . $row('Data di nascita', $hr['employee_birth_date'] ?? '')
                  . $row('Nato a', trim(($hr['birth_city'] ?? '') . ' ' . (!empty($hr['birth_state']) ? '(' . $hr['birth_state'] . ')' : '')))
                  . $row('Residenza', $residenza)
                  . $row('Stato civile', !empty($hr['marital_status']) ? ucfirst(str_replace('_', ' ', $hr['marital_status'])) : '')
                  . $row('Istruzione', !empty($hr['education_level']) ? ucfirst(str_replace('_', ' ', $hr['education_level'])) : '')
                  . $row('Email', $hr['employee_email'] ?? '')
                  . $row('Tipologia contratto', $contratti)
                  . $row('Inizio', $hr['start_date'] ?? '')
                  . $row('Fine', $hr['end_date'] ?? '')
                  . $row('Ore settimanali', $hr['weekly_hours'] ?? '')
                  . $row('Giorni', $giorni)
                  . $row('Mansioni', $hr['role_description'] ?? '')
                  . $row('Sede di lavoro', $hr['workplace'] ?? '')
                  . $row('IBAN', $hr['iban'] ?? '')
                  . '</ul>Carica ora il contratto da firmare.';
            self::addComment($consulenteUserId, $taskId, $html);

            // 2) Carica i documenti come allegati del task
            foreach ($docs as $d) {
                $fs = class_exists('HireRequest') ? HireRequest::fileFsPath($d) : null;
                if ($fs && is_file($fs)) {
                    self::uploadAttachment($i, $taskId, $fs, $d['original_name'] ?? 'documento', $d['mime_type'] ?? null);
                }
            }
        } catch (Throwable $e) {
            error_log('[Wrike::hireApproved] ' . $e->getMessage());
        }
    }

    /** Carica un file come allegato di un task (body binario + X-File-Name). */
    private static function uploadAttachment(array $integration, string $taskId, string $filePath, string $fileName, ?string $mime): bool
    {
        if (!function_exists('curl_init')) return false;
        $data = @file_get_contents($filePath);
        if ($data === false) return false;
        // Wrike: max 50MB per allegato via API
        if (strlen($data) > 50 * 1024 * 1024) return false;
        $url = rtrim(self::hostOf($integration), '/') . '/api/v4/tasks/' . rawurlencode($taskId) . '/attachments';
        $safeName = preg_replace('/[^\x20-\x7E]/', '_', $fileName); // header ASCII-safe
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $integration['token'],
                'X-Requested-With: XMLHttpRequest',
                'X-File-Name: ' . $safeName,
                'Content-Type: application/octet-stream',
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $http >= 400) {
            error_log('[Wrike::uploadAttachment] HTTP ' . $http . ' su ' . $fileName);
            return false;
        }
        return true;
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
                self::storeConfigKey($consulenteUserId, 'chat_tasks', (string)$conversationId, $taskId);
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

    /** Salva config[$mapKey][$key] = $value rileggendo la riga fresca (no lost update). */
    private static function storeConfigKey(int $userId, string $mapKey, string $key, string $value): void
    {
        try {
            $fresh = self::getForUser($userId);
            if (!$fresh) return;
            $config = $fresh['config'];
            $config[$mapKey][$key] = $value;
            Database::update('user_integrations',
                ['config' => json_encode($config, JSON_UNESCAPED_UNICODE)],
                'id = ?', [(int)$fresh['id']]);
        } catch (Throwable $e) {}
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
