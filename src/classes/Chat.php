<?php
/**
 * Classe Chat - Gestione conversazioni e messaggi
 * PAManager - Comune
 */

class Chat
{
    /**
     * Ottiene o crea una conversazione tra due partecipanti
     */
    public static function getOrCreateConversation(
        string $type1, int $id1,
        string $type2, int $id2
    ): array {
        // Ordina i partecipanti per consistenza
        if ($type1 > $type2 || ($type1 === $type2 && $id1 > $id2)) {
            [$type1, $id1, $type2, $id2] = [$type2, $id2, $type1, $id1];
        }

        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;

        // Cerca conversazione esistente nella company corrente
        $conversation = Database::fetchOne(
            "SELECT * FROM chat_conversations
             WHERE company_id = ?
               AND participant1_type = ? AND participant1_id = ?
               AND participant2_type = ? AND participant2_id = ?",
            [$cid, $type1, $id1, $type2, $id2]
        );

        if ($conversation) {
            return ['success' => true, 'conversation' => $conversation];
        }

        // Crea nuova conversazione
        try {
            $convId = Database::insert('chat_conversations', [
                'company_id' => $cid,
                'participant1_type' => $type1,
                'participant1_id' => $id1,
                'participant2_type' => $type2,
                'participant2_id' => $id2
            ]);

            return [
                'success' => true,
                'conversation' => [
                    'id' => $convId,
                    'participant1_type' => $type1,
                    'participant1_id' => $id1,
                    'participant2_type' => $type2,
                    'participant2_id' => $id2
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore nella creazione della conversazione'];
        }
    }

    /**
     * Partecipanti di una conversazione come lista [ [type, id], ... ]:
     * i 2 originali + gli extra (illimitati) dalla tabella chat_extra_participants.
     */
    public static function participantsOf(array $conv): array
    {
        // Gruppi: i due posti fissi tengono solo il creatore, i membri sono nell'elenco
        $isGroup = ($conv['conv_type'] ?? 'direct') === 'group';
        $out = $isGroup ? [] : [
            ['type' => $conv['participant1_type'], 'id' => (int) $conv['participant1_id']],
            ['type' => $conv['participant2_type'], 'id' => (int) $conv['participant2_id']],
        ];
        if (!empty($conv['id'])) {
            try {
                $extras = Database::fetchAll(
                    "SELECT participant_type, participant_id FROM chat_extra_participants
                     WHERE conversation_id = ? ORDER BY id",
                    [(int) $conv['id']]
                );
                $seen = [];
                foreach ($out as $o) $seen[$o['type'] . ':' . $o['id']] = true;
                foreach ($extras as $e) {
                    $k = $e['participant_type'] . ':' . (int) $e['participant_id'];
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $out[] = ['type' => $e['participant_type'], 'id' => (int) $e['participant_id']];
                }
            } catch (Throwable $e) {
                // Tabella non ancora migrata: fallback allo slot legacy participant3
                if (!empty($conv['participant3_type']) && !empty($conv['participant3_id'])) {
                    $out[] = ['type' => $conv['participant3_type'], 'id' => (int) $conv['participant3_id']];
                }
            }
        }
        return $out;
    }

    /**
     * Ottiene le conversazioni di un utente
     */
    public static function getConversations(string $userType, int $userId): array
    {
        // Il dipendente non deve MAI vedere i messaggi interni dello staff:
        // esclusi da unread count e anteprima ultimo messaggio.
        $intFilter = $userType === 'employee' ? ' AND cm.is_internal = 0' : '';
        $sql = "SELECT cc.*,
                       (SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id
                          AND " . self::unreadSql('cm', 'cr') . "
                          AND NOT (cm.sender_type = ? AND cm.sender_id = ?)$intFilter) AS unread_count,
                       (SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id
                          AND " . self::unreadSql('cm', 'cr') . "
                          AND cm.mentions LIKE ?
                          AND NOT (cm.sender_type = ? AND cm.sender_id = ?)$intFilter) AS mention_count,
                       (SELECT cm.attachment_name FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id$intFilter
                        ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_attachment,
                       (SELECT cm.message FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id$intFilter
                        ORDER BY cm.created_at DESC LIMIT 1) AS last_message,
                       (SELECT cm.created_at FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id$intFilter
                        ORDER BY cm.created_at DESC LIMIT 1) AS last_message_time
                FROM chat_conversations cc
                LEFT JOIN chat_reads cr ON cr.conversation_id = cc.id AND cr.user_type = ? AND cr.user_id = ?
                WHERE cc.company_id = ?
                  AND ((cc.conv_type <> 'group' AND ((cc.participant1_type = ? AND cc.participant1_id = ?)
                       OR (cc.participant2_type = ? AND cc.participant2_id = ?)))
                       OR EXISTS (SELECT 1 FROM chat_extra_participants ep
                                  WHERE ep.conversation_id = cc.id
                                    AND ep.participant_type = ? AND ep.participant_id = ?))
                ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC";

        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $conversations = Database::fetchAll($sql, [
            $userType, $userId,
            '%"' . $userType . ':' . $userId . '"%', $userType, $userId,
            $userType, $userId,
            $cid,
            $userType, $userId,
            $userType, $userId,
            $userType, $userId
        ]);

        // Aggiungi info sull'altro partecipante. Nelle chat a 3, per lo staff
        // il "contatto" mostrato è il dipendente; per il dipendente l'interlocutore originale.
        foreach ($conversations as &$conv) {
            $others = array_values(array_filter(self::participantsOf($conv), function ($p) use ($userType, $userId) {
                return !($p['type'] === $userType && $p['id'] === (int) $userId);
            }));
            $primary = $others[0] ?? ['type' => $conv['participant1_type'], 'id' => (int) $conv['participant1_id']];
            foreach ($others as $p) {
                if ($p['type'] === 'employee') { $primary = $p; break; }
            }
            $conv['other_type'] = $primary['type'];
            $conv['other_id']   = $primary['id'];
            $conv['other_name'] = self::getParticipantName($primary['type'], $primary['id']);
            $conv['others'] = $others;
            $conv['other_photo'] = self::getParticipantPhoto($primary['type'], $primary['id']);
            // Altri partecipanti oltre al principale (per header/lista chat a 3)
            $conv['extra_participants'] = [];
            foreach ($others as $p) {
                if ($p['type'] === $primary['type'] && $p['id'] === $primary['id']) continue;
                $conv['extra_participants'][] = $p + ['name' => self::getParticipantName($p['type'], $p['id'])];
            }
        }
        unset($conv);

        // Preferenze dell'utente: fissate in alto ed "elimina per me"
        // (una chat eliminata ricompare quando arriva un nuovo messaggio).
        $prefs = self::getPrefs($userType, $userId);
        foreach ($conversations as &$conv) {
            $pr = $prefs[(int) $conv['id']] ?? null;
            $conv['pinned_at'] = $pr['pinned_at'] ?? null;
            $conv['hidden_at'] = $pr['hidden_at'] ?? null;
        }
        unset($conv);
        $conversations = array_values(array_filter($conversations, function ($conv) {
            if (empty($conv['hidden_at'])) return true;
            return !empty($conv['last_message_time']) && strtotime($conv['last_message_time']) > strtotime($conv['hidden_at']);
        }));
        usort($conversations, function ($a, $b) {
            $pa = $a['pinned_at'] ?? null;
            $pb = $b['pinned_at'] ?? null;
            if ($pa && !$pb) return -1;
            if ($pb && !$pa) return 1;
            if ($pa && $pb) return strcmp($pb, $pa);
            return strcmp($b['last_message_at'] ?? $b['created_at'] ?? '', $a['last_message_at'] ?? $a['created_at'] ?? '');
        });

        // Conversazioni con dipendenti DISATTIVATI: nascoste dalla chat
        // (riappaiono se il dipendente viene riattivato).
        $conversations = array_values(array_filter($conversations, function ($conv) {
            if (($conv['conv_type'] ?? 'direct') === 'group') return true;
            if ($conv['other_type'] !== 'employee') return true;
            $emp = Employee::getById((int) $conv['other_id']);
            return $emp && !empty($emp['is_active']);
        }));

        return $conversations;
    }

    /**
     * Ottiene i messaggi di una conversazione.
     * $includeInternal=false esclude i messaggi interni dello staff (vista dipendente).
     */
    public static function getMessages(int $conversationId, int $limit = 50, int $offset = 0, bool $includeInternal = true): array
    {
        $intFilter = $includeInternal ? '' : ' AND is_internal = 0';
        return Database::fetchAll(
            "SELECT * FROM chat_messages
             WHERE conversation_id = ?$intFilter
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset]
        );
    }

    /**
     * Verifica che (userType, userId) sia partecipante della conversazione.
     * Restituisce anche il record conversazione per uso successivo.
     */
    public static function isParticipant(int $conversationId, string $userType, int $userId): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM chat_conversations cc
             WHERE cc.id = ?
               AND (
                  (cc.conv_type <> 'group' AND ((cc.participant1_type = ? AND cc.participant1_id = ?)
                  OR (cc.participant2_type = ? AND cc.participant2_id = ?)))
                  OR EXISTS (SELECT 1 FROM chat_extra_participants ep
                             WHERE ep.conversation_id = cc.id
                               AND ep.participant_type = ? AND ep.participant_id = ?)
               )",
            [$conversationId, $userType, $userId, $userType, $userId, $userType, $userId]
        );
        return $row ?: null;
    }

    /**
     * Aggiunge un partecipante extra (admin o consulente lavoro) a una conversazione
     * con un dipendente. Solo admin/consulente già partecipanti possono invitare;
     * nessun limite di numero. Il nuovo entrato vede tutta la cronologia.
     */
    public static function addStaffParticipant(
        int $conversationId,
        string $byType, int $byId,
        string $newType, int $newId
    ): array {
        $conv = self::isParticipant($conversationId, $byType, $byId);
        if (!$conv) {
            return ['success' => false, 'error' => 'Accesso non autorizzato'];
        }
        if (!in_array($byType, ['admin', 'consulente_lavoro'], true)) {
            return ['success' => false, 'error' => 'Solo admin e consulente del lavoro possono aggiungere partecipanti'];
        }
        if (!in_array($newType, ['admin', 'consulente_lavoro'], true)) {
            return ['success' => false, 'error' => 'Puoi aggiungere solo un admin o un consulente del lavoro'];
        }
        // La chat di gruppo esiste solo attorno a un dipendente
        $hasEmployee = false;
        foreach (self::participantsOf($conv) as $p) {
            if ($p['type'] === 'employee') $hasEmployee = true;
            if ($p['type'] === $newType && $p['id'] === $newId) {
                return ['success' => false, 'error' => 'Utente già presente nella conversazione'];
            }
        }
        if (!$hasEmployee) {
            return ['success' => false, 'error' => 'Puoi aggiungere partecipanti solo nelle chat con un dipendente'];
        }

        // Verifica che il nuovo utente esista ed è attivo
        $u = User::getById($newId);
        if (!$u || empty($u['is_active']) || $u['role'] !== $newType) {
            return ['success' => false, 'error' => 'Utente non valido'];
        }

        Database::insert('chat_extra_participants', [
            'conversation_id'  => $conversationId,
            'participant_type' => $newType,
            'participant_id'   => $newId,
            'added_by_type'    => $byType,
            'added_by_id'      => $byId,
        ]);

        // Notifica l'invitato (best-effort)
        try {
            $byName = self::getParticipantName($byType, $byId);
            $empName = '';
            foreach (self::participantsOf($conv) as $p) {
                if ($p['type'] === 'employee') { $empName = self::getParticipantName('employee', $p['id']); break; }
            }
            Notification::create([
                'recipient_type' => $newType,
                'recipient_id'   => $newId,
                'type'           => 'new_message',
                'title'          => $byName . ' ti ha aggiunto a una conversazione',
                'message'        => 'Chat con ' . $empName,
                'link'           => self::getChatUrl($newType) . '?conv=' . $conversationId,
            ]);
        } catch (Throwable $e) {
            error_log('[Chat] addThirdParticipant notify error: ' . $e->getMessage());
        }

        return ['success' => true];
    }

    /**
     * Rimuove un partecipante EXTRA dalla conversazione (i 2 originali non si toccano).
     * Può farlo qualsiasi admin/consulente partecipante; un invitato può anche
     * rimuovere se stesso ("esci dalla conversazione"). Il rimosso non vede più
     * la chat e non riceve più notifiche; rientra solo con un nuovo invito.
     */
    public static function removeStaffParticipant(
        int $conversationId,
        string $byType, int $byId,
        string $remType, int $remId
    ): array {
        $conv = self::isParticipant($conversationId, $byType, $byId);
        if (!$conv) {
            return ['success' => false, 'error' => 'Accesso non autorizzato'];
        }
        if (!in_array($byType, ['admin', 'consulente_lavoro'], true)) {
            return ['success' => false, 'error' => 'Solo admin e consulente del lavoro possono rimuovere partecipanti'];
        }
        $isExtra = Database::exists(
            'chat_extra_participants',
            'conversation_id = ? AND participant_type = ? AND participant_id = ?',
            [$conversationId, $remType, $remId]
        );
        if (!$isExtra) {
            return ['success' => false, 'error' => 'Puoi rimuovere solo i partecipanti aggiunti alla conversazione'];
        }

        Database::delete(
            'chat_extra_participants',
            'conversation_id = ? AND participant_type = ? AND participant_id = ?',
            [$conversationId, $remType, $remId]
        );

        // Notifica il rimosso (se non sta uscendo da solo) — best-effort
        $isSelf = ($remType === $byType && $remId === $byId);
        if (!$isSelf) {
            try {
                $byName = self::getParticipantName($byType, $byId);
                Notification::create([
                    'recipient_type' => $remType,
                    'recipient_id'   => $remId,
                    'type'           => 'new_message',
                    'title'          => $byName . ' ti ha rimosso da una conversazione',
                    'message'        => 'Non riceverai più notifiche da questa chat',
                    'link'           => self::getChatUrl($remType),
                ]);
            } catch (Throwable $e) {
                error_log('[Chat] removeStaffParticipant notify error: ' . $e->getMessage());
            }
        }

        return ['success' => true, 'left' => $isSelf];
    }

    /**
     * Invia un messaggio
     */
    public static function sendMessage(
        int $conversationId,
        string $senderType,
        int $senderId,
        string $message,
        ?string $attachmentPath = null,
        ?string $attachmentName = null,
        ?int $attachmentSize = null,
        ?string $attachmentMime = null,
        bool $isInternal = false,
        ?int $replyToId = null,
        bool $forwarded = false,
        ?string $clientId = null,
        array $mentions = []
    ): array {
        if (empty(trim($message)) && empty($attachmentPath)) {
            return ['success' => false, 'error' => 'Messaggio vuoto'];
        }

        // Verifica che la conversazione esista
        $conv = Database::fetchOne(
            "SELECT * FROM chat_conversations WHERE id = ?",
            [$conversationId]
        );

        if (!$conv) {
            return ['success' => false, 'error' => 'Conversazione non trovata'];
        }

        // Verifica che l'utente sia un partecipante (incluso il terzo)
        $isParticipant = false;
        foreach (self::participantsOf($conv) as $p) {
            if ($p['type'] === $senderType && $p['id'] === $senderId) { $isParticipant = true; break; }
        }

        if (!$isParticipant) {
            return ['success' => false, 'error' => 'Non sei un partecipante di questa conversazione'];
        }

        // I messaggi interni sono riservati allo staff: un dipendente non può inviarli
        if ($senderType === 'employee') {
            $isInternal = false;
        }

        // Stesso invio ripetuto (rete caduta a meta'): si restituisce quello gia' salvato
        if ($clientId !== null && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientId)) $clientId = null;
        if ($clientId) {
            $gia = Database::fetchColumn(
                "SELECT id FROM chat_messages WHERE conversation_id = ? AND sender_type = ? AND sender_id = ? AND client_id = ?",
                [$conversationId, $senderType, $senderId, $clientId]
            );
            if ($gia) return ['success' => true, 'id' => (int) $gia, 'duplicate' => true];
        }

        // La risposta deve citare un messaggio della stessa conversazione
        if ($replyToId) {
            $ok = Database::fetchColumn(
                "SELECT COUNT(*) FROM chat_messages WHERE id = ? AND conversation_id = ?",
                [$replyToId, $conversationId]
            );
            if (!$ok) $replyToId = null;
        }

        try {
            $extra = [];
            if ($replyToId) $extra['reply_to_id'] = $replyToId;
            if ($forwarded) $extra['forwarded'] = 1;
            if ($clientId) $extra['client_id'] = $clientId;
            // Solo membri della conversazione
            $validi = [];
            foreach (self::participantsOf($conv) as $pp) $validi[$pp['type'] . ':' . $pp['id']] = true;
            $mentions = array_values(array_unique(array_filter($mentions, fn($k) => isset($validi[$k]) && $k !== $senderType . ':' . $senderId)));
            if ($mentions) $extra['mentions'] = json_encode($mentions);
            $msgId = Database::insert('chat_messages', $extra + [
                'company_id' => (int)($conv['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1)),
                'conversation_id' => $conversationId,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'message' => trim($message),
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'attachment_size' => $attachmentSize,
                'attachment_mime' => $attachmentMime,
                'is_read' => 0,
                'is_internal' => $isInternal ? 1 : 0
            ]);

            // Aggiorna timestamp ultima attività
            Database::update('chat_conversations', [
                'last_message_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$conversationId]);

            // Il mittente ha letto fino al suo messaggio
            try {
                Database::query(
                    "INSERT INTO chat_reads (conversation_id, user_type, user_id, last_read_at) VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE last_read_at = NOW()",
                    [$conversationId, $senderType, $senderId]
                );
            } catch (Throwable $e) {}

            // Tempo reale: il messaggio a tutti i partecipanti, con "mine" giusto per ciascuno
            $cidMsg = (int) ($conv['company_id'] ?? 0);
            if (class_exists('Realtime') && Realtime::enabled()) {
                try {
                    $row = Database::fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
                    $per = [];
                    foreach (self::participantsOf($conv) as $pp) {
                        if ($isInternal && $pp['type'] === 'employee') continue;
                        $sub = $pp['type'] . ':' . $pp['id'];
                        $mine = $pp['type'] === $senderType && $pp['id'] === $senderId;
                        $per[$sub] = [
                            'conversationId' => $conversationId,
                            'clientId'       => $mine ? $clientId : null,
                            'message'        => CoraChat::messages([$row], $pp['type'], $pp['id'])[0] ?? null,
                        ];
                    }
                    Realtime::emitEach($cidMsg, 'chat.message.created', $per);
                } catch (Throwable $e) {
                    error_log('[Chat] realtime: ' . $e->getMessage());
                }
            }

            // Crea notifica per gli altri partecipanti (ignora errori)
            try {
                self::createMessageNotification($conv, $senderType, $senderId, $message, $isInternal, $mentions);
            } catch (Exception $notifErr) {
                // Ignora errori notifica, il messaggio è già stato inviato
                error_log('Chat notification error: ' . $notifErr->getMessage());
            }

            return ['success' => true, 'id' => $msgId];
        } catch (Exception $e) {
            error_log('Chat sendMessage error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()];
        }
    }

    /**
     * Marca i messaggi come letti
     */
    public static function markAsRead(int $conversationId, string $readerType, int $readerId): void
    {
        // Fin dove ha letto questa persona ("Visto da" e non letti nei gruppi)
        $prima = null;
        try {
            $prima = Database::fetchColumn(
                "SELECT last_read_at FROM chat_reads WHERE conversation_id = ? AND user_type = ? AND user_id = ?",
                [$conversationId, $readerType, $readerId]
            );
            $ultimo = Database::fetchColumn(
                "SELECT MAX(created_at) FROM chat_messages WHERE conversation_id = ? AND NOT (sender_type = ? AND sender_id = ?)",
                [$conversationId, $readerType, $readerId]
            );
            $adesso = date('Y-m-d H:i:s');
            Database::query(
                "INSERT INTO chat_reads (conversation_id, user_type, user_id, last_read_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)",
                [$conversationId, $readerType, $readerId, $adesso]
            );
            // Agli altri arriva la spunta solo se c'era davvero qualcosa di nuovo da leggere
            if ($ultimo && (!$prima || strtotime($ultimo) > strtotime((string) $prima))) {
                self::emitToConversation($conversationId, 'chat.conversation.read', function () use ($conversationId, $readerType, $readerId, $adesso) {
                    return ['conversationId' => $conversationId, 'userId' => $readerType . ':' . $readerId, 'readAt' => date('c', strtotime($adesso))];
                });
            }
        } catch (Throwable $e) {
            // tabella chat_reads non ancora migrata: resta il flag is_read
        }

        // Il dipendente non vede (e non deve "leggere") i messaggi interni dello staff
        $intFilter = $readerType === 'employee' ? ' AND is_internal = 0' : '';
        Database::query(
            "UPDATE chat_messages
             SET is_read = TRUE, read_at = NOW()
             WHERE conversation_id = ?
               AND NOT (sender_type = ? AND sender_id = ?)
               AND is_read = FALSE$intFilter",
            [$conversationId, $readerType, $readerId]
        );
    }

    /**
     * Conta messaggi non letti totali
     */
    public static function countUnread(string $userType, int $userId): int
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $intFilter = $userType === 'employee' ? ' AND cm.is_internal = 0' : '';
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM chat_messages cm
             JOIN chat_conversations cc ON cm.conversation_id = cc.id
             LEFT JOIN chat_reads cr ON cr.conversation_id = cc.id AND cr.user_type = ? AND cr.user_id = ?
             WHERE cc.company_id = ?
               AND ((cc.conv_type <> 'group' AND ((cc.participant1_type = ? AND cc.participant1_id = ?)
                    OR (cc.participant2_type = ? AND cc.participant2_id = ?)))
                    OR EXISTS (SELECT 1 FROM chat_extra_participants ep
                               WHERE ep.conversation_id = cc.id
                                 AND ep.participant_type = ? AND ep.participant_id = ?))
               AND NOT (cm.sender_type = ? AND cm.sender_id = ?)
               AND " . self::unreadSql('cm', 'cr') . "$intFilter
               AND NOT EXISTS (SELECT 1 FROM chat_conversation_prefs hp
                               WHERE hp.conversation_id = cc.id AND hp.user_type = ? AND hp.user_id = ?
                                 AND hp.hidden_at IS NOT NULL AND hp.hidden_at >= cm.created_at)
               AND NOT EXISTS (SELECT 1 FROM employees ei
                               WHERE ei.is_active = FALSE
                                 AND ((cc.participant1_type = 'employee' AND cc.participant1_id = ei.id)
                                      OR (cc.participant2_type = 'employee' AND cc.participant2_id = ei.id)))",
            [$userType, $userId, $cid, $userType, $userId, $userType, $userId, $userType, $userId, $userType, $userId, $userType, $userId]
        );
    }

    /** Messaggio non letto da chi ha la riga $r in chat_reads (senza riga: vecchio flag is_read). */
    private static function unreadSql(string $m, string $r): string
    {
        return "(($r.last_read_at IS NULL AND $m.is_read = 0) OR ($r.last_read_at IS NOT NULL AND $m.created_at > $r.last_read_at))";
    }

    /**
     * Ottiene il nome di un partecipante
     */
    public static function getParticipantName(string $type, int $id): string
    {
        if ($type === 'employee') {
            $emp = Employee::getById($id);
            return $emp ? $emp['first_name'] . ' ' . $emp['last_name'] : 'Dipendente #' . $id;
        } else {
            $user = User::getById($id);
            return $user ? $user['name'] : 'Utente #' . $id;
        }
    }

    /**
     * Restituisce il path della foto profilo di un partecipante (solo employee).
     * Per altri tipi (admin/admin_reparto/accountant) ritorna null (usa iniziali).
     */
    public static function getParticipantPhoto(string $type, int $id): ?string
    {
        if ($type !== 'employee') return null;
        $emp = Employee::getById($id);
        return !empty($emp['photo_path']) ? $emp['photo_path'] : null;
    }

    /**
     * Ottiene i contatti disponibili per un utente
     */
    public static function getAvailableContacts(string $userType, int $userId, ?int $departmentId = null): array
    {
        $contacts = [];

        switch ($userType) {
            case 'admin':
                $contacts['admin_reparto']     = User::getAdminReparto();
                $contacts['accountant']        = User::getAccountants();
                $contacts['consulente_lavoro'] = method_exists('User', 'getConsulentiLavoro') ? User::getConsulentiLavoro() : [];
                $contacts['employee']          = Employee::getAll(true);
                break;

            case 'accountant':
                $contacts['admin']             = User::getAll('admin');
                $contacts['admin_reparto']     = User::getAdminReparto();
                $contacts['consulente_lavoro'] = method_exists('User', 'getConsulentiLavoro') ? User::getConsulentiLavoro() : [];
                $contacts['employee']          = Employee::getAll(true);
                break;

            case 'consulente_lavoro':
                $contacts['admin']             = User::getAll('admin');
                $contacts['admin_reparto']     = User::getAdminReparto();
                $contacts['accountant']        = User::getAccountants();
                $contacts['employee']          = Employee::getAll(true);
                break;

            case 'admin_reparto':
                $contacts['admin']             = User::getAll('admin');
                $contacts['accountant']        = User::getAccountants();
                $contacts['consulente_lavoro'] = method_exists('User', 'getConsulentiLavoro') ? User::getConsulentiLavoro() : [];
                if ($departmentId) {
                    $contacts['employee'] = Department::getEmployees($departmentId, true);
                }
                break;

            case 'employee':
                // Dipendente puo' contattare admin, admin_reparto, accountant, consulente e ALTRI dipendenti
                $contacts['admin']             = User::getAll('admin');
                $contacts['admin_reparto']     = User::getAdminReparto();
                $contacts['accountant']        = User::getAccountants();
                $contacts['consulente_lavoro'] = method_exists('User', 'getConsulentiLavoro') ? User::getConsulentiLavoro() : [];
                $allEmployees = Employee::getAll(true);
                // Escludi se stesso dalla lista
                $contacts['employee'] = array_values(array_filter($allEmployees, fn($e) => (int)$e['id'] !== $userId));
                break;
        }

        // Filtro tenant: per ogni gruppo, escludi entita' di altre aziende quando applicabile.
        // Gli utenti CONDIVISI (company_id di un'altra azienda ma collegati alla corrente
        // via user_companies, es. consulenti multi-tenant) devono restare visibili.
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : null;
        if ($cid) {
            $linkedToCurrent = [];
            try {
                foreach (Database::fetchAll("SELECT user_id FROM user_companies WHERE company_id = ?", [$cid]) as $lr) {
                    $linkedToCurrent[(int) $lr['user_id']] = true;
                }
            } catch (Throwable $e) {}
            foreach (['admin_reparto', 'accountant', 'consulente_lavoro'] as $g) {
                if (!empty($contacts[$g])) {
                    $contacts[$g] = array_values(array_filter($contacts[$g], function($u) use ($cid, $linkedToCurrent) {
                        return empty($u['company_id'])
                            || (int)$u['company_id'] === (int)$cid
                            || isset($linkedToCurrent[(int)($u['id'] ?? 0)]);
                    }));
                }
            }
            if (!empty($contacts['employee'])) {
                $contacts['employee'] = array_values(array_filter($contacts['employee'], function($e) use ($cid) {
                    return (int)($e['company_id'] ?? 0) === (int)$cid;
                }));
            }
        }

        return $contacts;
    }

    /**
     * Verifica se un utente può contattare un altro
     */
    public static function canContact(
        string $fromType, int $fromId, ?int $fromDeptId,
        string $toType, int $toId
    ): bool {
        // Niente self-chat
        if ($fromType === $toType && $fromId === $toId) {
            return false;
        }

        // Dipendenti disattivati: non contattabili da nessuno
        if ($toType === 'employee') {
            $target = Employee::getById($toId);
            if (!$target || empty($target['is_active'])) {
                return false;
            }
        }

        // Admin / accountant / consulente_lavoro: contattano tutti
        if (in_array($fromType, ['admin', 'accountant', 'consulente_lavoro'], true)) {
            return true;
        }

        // Admin reparto: puo' contattare staff e dipendenti del proprio reparto
        if ($fromType === 'admin_reparto') {
            if (in_array($toType, ['admin', 'accountant', 'consulente_lavoro', 'admin_reparto'], true)) {
                return true;
            }
            if ($toType === 'employee' && $fromDeptId) {
                $employee = Employee::getById($toId);
                return $employee && ($employee['department_id'] ?? null) == $fromDeptId;
            }
            return false;
        }

        // Dipendente: puo' contattare staff e ALTRI dipendenti
        if ($fromType === 'employee') {
            if (in_array($toType, ['admin', 'admin_reparto', 'accountant', 'consulente_lavoro'], true)) {
                return true;
            }
            if ($toType === 'employee') {
                return $toId !== $fromId; // se stesso no, altri si
            }
            return false;
        }

        return false;
    }

    /**
     * Crea notifica per nuovo messaggio
     */
    private static function createMessageNotification(
        array $conversation,
        string $senderType,
        int $senderId,
        string $message,
        bool $isInternal = false,
        array $mentions = []
    ): void {
        // Destinatari: tutti i partecipanti tranne il mittente.
        // I messaggi interni non arrivano MAI al dipendente.
        $recipients = array_values(array_filter(self::participantsOf($conversation), function ($p) use ($senderType, $senderId, $isInternal) {
            if ($p['type'] === $senderType && $p['id'] === $senderId) return false;
            if ($isInternal && $p['type'] === 'employee') return false;
            return true;
        }));
        // Chi ha la chat aperta in primo piano non riceve il push del telefono (come nel CRM)
        $guardano = [];
        if (class_exists('Realtime') && Realtime::enabled()) {
            $subs = array_map(fn($r) => $r['type'] . ':' . $r['id'], $recipients);
            foreach (Realtime::viewing((int) ($conversation['company_id'] ?? 0), (int) $conversation['id'], $subs) as $sub => $st) {
                if (!empty($st['guarda'])) $guardano[$sub] = true;
            }
        }
        foreach ($recipients as $r) {
            try {
                $sub = $r['type'] . ':' . $r['id'];
                self::notifyRecipient($conversation, $senderType, $senderId, $message, $r['type'], $r['id'], $isInternal, in_array($sub, $mentions, true), isset($guardano[$sub]));
            } catch (Throwable $e) {
                error_log('[Chat] notifyRecipient error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Notifica un singolo destinatario di un nuovo messaggio (in-app + Wrike + push + email).
     */
    private static function notifyRecipient(
        array $conversation,
        string $senderType,
        int $senderId,
        string $message,
        string $recipientType,
        int $recipientId,
        bool $isInternal = false,
        bool $mentioned = false,
        bool $viewing = false
    ): void {
        $senderName = self::getParticipantName($senderType, $senderId);
        $preview = mb_strlen($message) > 50 ? mb_substr($message, 0, 47) . '...' : $message;
        $chatUrl = self::getChatUrl($recipientType) . '?conv=' . $conversation['id'];
        // Le note interne (solo staff) hanno un titolo distinto, così non si
        // confondono con i messaggi normali della conversazione col dipendente.
        $notifTitle = ($isInternal ? 'Nota interna da ' : 'Nuovo messaggio da ') . $senderName;
        if ($mentioned) $notifTitle = $senderName . ' ti ha menzionato';
        if (($conversation['conv_type'] ?? '') === 'group' && !empty($conversation['name'])) {
            $notifTitle .= ' · ' . $conversation['name'];
        }

        // Notifica in-app (database)
        Notification::create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'type' => 'new_message',
            'title' => $notifTitle,
            'message' => $preview,
            'link' => $chatUrl
        ]);

        // Integrazione Wrike del consulente (best-effort): una attivita' per
        // conversazione finche' il task resta aperto sulla sua board.
        if ($recipientType === 'consulente_lavoro' && class_exists('Wrike')) {
            try {
                Wrike::taskForChatMessage((int)$recipientId, (int)$conversation['id'], $senderName, $preview, $chatUrl);
            } catch (Throwable $e) {
                error_log('[Chat] Wrike task fallito: ' . $e->getMessage());
            }
        }

        // Push notification (browser): non a chi sta gia' leggendo questa chat
        if (!$viewing) try {
            error_log('[Chat] Invio push a ' . $recipientType . ' #' . $recipientId);
            $pushResult = PushNotification::sendToUser($recipientType, (int)$recipientId, [
                'title' => $notifTitle,
                'body' => $preview,
                'url' => $chatUrl,
                'tag' => 'chat-' . $conversation['id'],
                'icon' => '/assets/images/icon.php?size=192'
            ]);
            error_log('[Chat] Push result: ' . json_encode($pushResult));
        } catch (Exception $e) {
            error_log('[Chat] Push notification error: ' . $e->getMessage());
        }

        // Email notification
        try {
            if (class_exists('Mailer') && Mailer::isConfigured()) {
                $recipient = self::getRecipientForEmail($recipientType, (int)$recipientId);
                if ($recipient && !empty($recipient['email'])) {
                    $loginUrl = function_exists('buildPublicUrl')
                        ? buildPublicUrl('/' . ($recipientType === 'employee' ? 'employee' : ($recipientType === 'accountant' ? 'accountant' : ($recipientType === 'admin_reparto' ? 'admin-reparto' : 'admin'))) . '/chat.php')
                        : $chatUrl;
                    $nameSafe    = htmlspecialchars($recipient['name']);
                    $senderSafe  = htmlspecialchars($senderName);
                    $previewSafe = nl2br(htmlspecialchars($preview));

                    $whatSafe = $isInternal ? 'una <strong>nota interna</strong> (invisibile al dipendente) da' : 'un nuovo messaggio da';
                    $html = "<p>Ciao {$nameSafe},</p>"
                          . "<p>Hai ricevuto {$whatSafe} <strong>{$senderSafe}</strong>:</p>"
                          . "<blockquote style=\"border-left:3px solid #ccc;padding-left:1em;color:#444;\">{$previewSafe}</blockquote>"
                          . "<p><a href=\"{$loginUrl}\">Apri la chat per rispondere</a></p>";
                    $text = "Ciao {$recipient['name']},\n\n{$notifTitle}:\n\n{$preview}\n\nApri la chat: {$loginUrl}";

                    if ($recipientType === 'employee') {
                        Mailer::sendToEmployee((int) $recipientId, $notifTitle, $html, $text);
                    } else {
                        Mailer::send($recipient['email'], $recipient['name'], $notifTitle, $html, $text);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[Chat] Email notification error: ' . $e->getMessage());
        }
    }

    /**
     * Recupera dati destinatario per email (nome + email)
     */
    private static function getRecipientForEmail(string $type, int $id): ?array
    {
        try {
            if ($type === 'employee') {
                $r = Database::fetchOne("SELECT first_name, last_name, email FROM employees WHERE id = ?", [$id]);
                if ($r) return ['name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => $r['email']];
            } else {
                $r = Database::fetchOne("SELECT name, email FROM users WHERE id = ?", [$id]);
                if ($r) return ['name' => $r['name'], 'email' => $r['email']];
            }
        } catch (Exception $e) {
            error_log('[Chat] getRecipientForEmail error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Ottiene URL chat per tipo utente
     */
    private static function getChatUrl(string $userType): string
    {
        return match($userType) {
            'admin' => PUBLIC_URL . '/admin/chat.php',
            'accountant' => PUBLIC_URL . '/accountant/chat.php',
            'admin_reparto' => PUBLIC_URL . '/admin-reparto/chat.php',
            'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/chat.php',
            'employee' => PUBLIC_URL . '/employee/chat.php',
            default => PUBLIC_URL
        };
    }

    /**
     * Salva un file allegato sul filesystem (sotto storage/chat-attachments/<conv_id>/).
     * Ritorna array con i metadati da passare a sendMessage().
     */
    public static function handleAttachmentUpload(array $file, int $conversationId): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Errore upload (codice ' . ($file['error'] ?? '?') . ')'];
        }

        $maxSize = 20 * 1024 * 1024; // 20MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File troppo grande (max 20MB)'];
        }

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'odt', 'ods', 'zip', 'webm', 'ogg', 'm4a', 'mp3', 'mp4', 'wav'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExt, true)) {
            return ['success' => false, 'error' => 'Estensione non consentita (.' . $extension . ')'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

        $dir = STORAGE_PATH . '/chat-attachments/' . $conversationId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileName = bin2hex(random_bytes(12)) . '.' . $extension;
        $filePath = $dir . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore salvataggio file'];
        }

        return [
            'success' => true,
            'path' => $filePath,
            'name' => $file['name'],
            'size' => (int) $file['size'],
            'mime' => $mime
        ];
    }

    /**
     * Recupera l'allegato di un messaggio con controllo accesso (solo partecipante).
     */
    public static function getAttachment(int $messageId, string $userType, int $userId): array
    {
        $msg = Database::fetchOne(
            "SELECT m.* FROM chat_messages m WHERE m.id = ?",
            [$messageId]
        );

        if (!$msg) return ['success' => false, 'error' => 'Messaggio non trovato'];
        if (empty($msg['attachment_path']) || !file_exists($msg['attachment_path'])) {
            return ['success' => false, 'error' => 'Allegato non disponibile'];
        }

        // Partecipanti originali + extra (chat di gruppo)
        $isParticipant = self::isParticipant((int) $msg['conversation_id'], $userType, $userId) !== null;

        // Allegati di messaggi interni: mai accessibili al dipendente
        if ($isParticipant && !empty($msg['is_internal']) && $userType === 'employee') {
            $isParticipant = false;
        }

        if (!$isParticipant) {
            if (class_exists('AuditLog')) {
                AuditLog::logUnauthorizedAccess('chat_attachment', ['message_id' => $messageId, 'user' => $userType . ':' . $userId]);
            }
            return ['success' => false, 'error' => 'Accesso non autorizzato'];
        }

        return [
            'success' => true,
            'file_path' => $msg['attachment_path'],
            'filename' => $msg['attachment_name'] ?? basename($msg['attachment_path']),
            'mime' => $msg['attachment_mime'] ?? 'application/octet-stream',
        ];
    }

    /** Minuti entro cui si puo' modificare un proprio messaggio */
    public const EDIT_MINUTES = 15;

    /**
     * Preferenze per conversazione dell'utente: [conversation_id => row]
     */
    public static function getPrefs(string $userType, int $userId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT conversation_id, pinned_at, hidden_at FROM chat_conversation_prefs
                 WHERE user_type = ? AND user_id = ?",
                [$userType, $userId]
            );
        } catch (Throwable $e) {
            return []; // migration 064 non ancora applicata
        }
        $out = [];
        foreach ($rows as $r) $out[(int) $r['conversation_id']] = $r;
        return $out;
    }

    /**
     * Imposta pinned_at / hidden_at (null per togliere) per l'utente.
     */
    public static function setPref(int $conversationId, string $userType, int $userId, string $field, ?string $value): void
    {
        if (!in_array($field, ['pinned_at', 'hidden_at'], true)) return;
        Database::query(
            "INSERT INTO chat_conversation_prefs (conversation_id, user_type, user_id, $field)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE $field = VALUES($field)",
            [$conversationId, $userType, $userId, $value]
        );
    }

    /**
     * Segna come letti i messaggi di tutte le conversazioni dell'utente.
     */
    public static function markAllAsRead(string $userType, int $userId): void
    {
        foreach (self::getConversations($userType, $userId) as $c) {
            if ((int) ($c['unread_count'] ?? 0) > 0) {
                self::markAsRead((int) $c['id'], $userType, $userId);
            }
        }
    }

    /**
     * Pagina di messaggi in ordine cronologico.
     * $beforeId: i precedenti a quel messaggio; $afterId: solo i successivi (aggiornamento).
     */
    public static function getMessagesPage(int $conversationId, bool $includeInternal, ?int $beforeId = null, ?int $afterId = null, int $limit = 50): array
    {
        $where = 'conversation_id = ?';
        $params = [$conversationId];
        if (!$includeInternal) $where .= ' AND is_internal = 0';
        if ($afterId) {
            $where .= ' AND id > ?';
            $params[] = $afterId;
            return Database::fetchAll("SELECT * FROM chat_messages WHERE $where ORDER BY id ASC LIMIT 200", $params);
        }
        if ($beforeId) {
            $where .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $params[] = $limit;
        return array_reverse(Database::fetchAll("SELECT * FROM chat_messages WHERE $where ORDER BY id DESC LIMIT ?", $params));
    }

    /**
     * Messaggio di una conversazione a cui l'utente partecipa (null se non accessibile).
     */
    public static function getAccessibleMessage(int $messageId, string $userType, int $userId): ?array
    {
        $msg = Database::fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
        if (!$msg || !self::isParticipant((int) $msg['conversation_id'], $userType, $userId)) return null;
        if (!empty($msg['is_internal']) && $userType === 'employee') return null;
        return $msg;
    }

    /**
     * Aggiunge o toglie la reazione dell'utente con quell'emoji.
     */
    public static function toggleReaction(int $messageId, string $userType, int $userId, string $emoji): array
    {
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 16) return ['success' => false, 'error' => 'Reazione non valida'];
        $msg = self::getAccessibleMessage($messageId, $userType, $userId);
        if (!$msg) return ['success' => false, 'error' => 'Messaggio non trovato'];

        $me = $userType . ':' . $userId;
        $reactions = json_decode((string) ($msg['reactions'] ?? ''), true) ?: [];
        $list = $reactions[$emoji] ?? [];
        if (in_array($me, $list, true)) {
            $list = array_values(array_diff($list, [$me]));
        } else {
            $list[] = $me;
        }
        if ($list) $reactions[$emoji] = $list; else unset($reactions[$emoji]);
        Database::update('chat_messages', ['reactions' => $reactions ? json_encode($reactions, JSON_UNESCAPED_UNICODE) : null], 'id = ?', [$messageId]);
        self::emitMessageUpdated($messageId);
        return ['success' => true, 'reactions' => $reactions];
    }

    /**
     * Modifica il testo di un proprio messaggio entro EDIT_MINUTES minuti.
     */
    public static function editMessage(int $messageId, string $userType, int $userId, string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['success' => false, 'error' => 'Il messaggio non può essere vuoto'];
        $msg = self::getAccessibleMessage($messageId, $userType, $userId);
        if (!$msg || $msg['sender_type'] !== $userType || (int) $msg['sender_id'] !== $userId) {
            return ['success' => false, 'error' => 'Puoi modificare solo i tuoi messaggi'];
        }
        if (time() - strtotime($msg['created_at']) > self::EDIT_MINUTES * 60) {
            return ['success' => false, 'error' => 'Il tempo per modificare il messaggio è scaduto'];
        }
        Database::update('chat_messages', ['message' => $text, 'edited_at' => date('Y-m-d H:i:s')], 'id = ?', [$messageId]);
        self::emitMessageUpdated($messageId);
        return ['success' => true];
    }

    /**
     * Elimina un proprio messaggio per tutti, solo finche' nessuno l'ha letto.
     */
    public static function deleteMessage(int $messageId, string $userType, int $userId): array
    {
        $msg = self::getAccessibleMessage($messageId, $userType, $userId);
        if (!$msg || $msg['sender_type'] !== $userType || (int) $msg['sender_id'] !== $userId) {
            return ['success' => false, 'error' => 'Puoi eliminare solo i tuoi messaggi'];
        }
        if (!empty($msg['is_read']) || self::seenCount($msg) > 0) {
            return ['success' => false, 'error' => 'Il messaggio è già stato letto'];
        }
        Database::delete('chat_messages', 'id = ?', [$messageId]);
        if (!empty($msg['attachment_path']) && is_file($msg['attachment_path'])) {
            @unlink($msg['attachment_path']);
        }
        $convId = (int) $msg['conversation_id'];
        self::emitToConversation($convId, 'chat.message.deleted', fn() => ['conversationId' => $convId, 'messageId' => $messageId]);
        return ['success' => true];
    }

    /**
     * Inoltra un messaggio (testo e allegato) in altre conversazioni dell'utente.
     */
    public static function forwardMessage(int $messageId, string $userType, int $userId, array $conversationIds): array
    {
        $msg = self::getAccessibleMessage($messageId, $userType, $userId);
        if (!$msg) return ['success' => false, 'error' => 'Messaggio non trovato'];
        $sent = 0;
        foreach (array_unique(array_map('intval', $conversationIds)) as $convId) {
            if (!$convId || !self::isParticipant($convId, $userType, $userId)) continue;
            $path = null;
            if (str_starts_with((string) $msg['attachment_path'], 'giphy:')) {
                $path = $msg['attachment_path'];
            } elseif (!empty($msg['attachment_path']) && is_file($msg['attachment_path'])) {
                $dir = STORAGE_PATH . '/chat-attachments/' . $convId;
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $path = $dir . '/' . bin2hex(random_bytes(12)) . '.' . strtolower(pathinfo($msg['attachment_path'], PATHINFO_EXTENSION));
                if (!copy($msg['attachment_path'], $path)) $path = null;
            }
            $r = self::sendMessage(
                $convId, $userType, $userId, (string) $msg['message'],
                $path, $path ? $msg['attachment_name'] : null, $path ? (int) $msg['attachment_size'] : null, $path ? $msg['attachment_mime'] : null,
                false, null, true
            );
            if (!empty($r['success'])) $sent++;
        }
        return $sent ? ['success' => true, 'sent' => $sent] : ['success' => false, 'error' => 'Nessuna chat valida'];
    }

    // ─── Tempo reale ──────────────────────────────────────────────────────────

    /**
     * Evento a tutti i partecipanti della conversazione. $data riceve
     * (type, id) del destinatario, cosi' il contenuto puo' cambiare per persona.
     */
    public static function emitToConversation(int $conversationId, string $type, callable $data): void
    {
        if (!class_exists('Realtime') || !Realtime::enabled()) return;
        try {
            $conv = Database::fetchOne("SELECT * FROM chat_conversations WHERE id = ?", [$conversationId]);
            if (!$conv) return;
            $per = [];
            foreach (self::participantsOf($conv) as $p) {
                $d = $data($p['type'], $p['id']);
                if ($d !== null) $per[$p['type'] . ':' . $p['id']] = $d;
            }
            Realtime::emitEach((int) $conv['company_id'], $type, $per);
        } catch (Throwable $e) {
            error_log('[Chat] realtime: ' . $e->getMessage());
        }
    }

    /** Testo, modifica o reazioni cambiati: il messaggio aggiornato a ciascuno. */
    public static function emitMessageUpdated(int $messageId): void
    {
        $row = Database::fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
        if (!$row) return;
        self::emitToConversation((int) $row['conversation_id'], 'chat.message.updated', function ($t, $id) use ($row) {
            if (!empty($row['is_internal']) && $t === 'employee') return null;
            return ['conversationId' => (int) $row['conversation_id'], 'message' => CoraChat::messages([$row], $t, $id)[0] ?? null];
        });
    }

    /** L'elenco chat di queste persone e' cambiato (nuova chat, membri, foto, fissata). */
    public static function emitChanged(int $conversationId, array $extraSubs = []): void
    {
        if (!class_exists('Realtime') || !Realtime::enabled()) return;
        $conv = Database::fetchOne("SELECT * FROM chat_conversations WHERE id = ?", [$conversationId]);
        if (!$conv) return;
        $subs = $extraSubs;
        foreach (self::participantsOf($conv) as $p) $subs[] = $p['type'] . ':' . $p['id'];
        Realtime::emit((int) $conv['company_id'], 'chat.conversation.changed', $subs, ['conversationId' => $conversationId]);
    }

    // ─── Letture ──────────────────────────────────────────────────────────────

    /**
     * Chi, oltre al mittente, ha letto il messaggio e quando:
     * [ ['type','id','seen_at'] ]. Senza riga in chat_reads vale il vecchio is_read.
     */
    public static function seenBy(array $msg, ?array $conv = null, ?array $reads = null): array
    {
        $conv = $conv ?? Database::fetchOne("SELECT * FROM chat_conversations WHERE id = ?", [(int) $msg['conversation_id']]);
        if (!$conv) return [];
        if ($reads === null) $reads = self::readsOf((int) $msg['conversation_id']);
        $out = [];
        $created = strtotime((string) $msg['created_at']);
        foreach (self::participantsOf($conv) as $p) {
            if ($p['type'] === $msg['sender_type'] && $p['id'] === (int) $msg['sender_id']) continue;
            if (!empty($msg['is_internal']) && $p['type'] === 'employee') continue;
            $k = $p['type'] . ':' . $p['id'];
            if (isset($reads[$k])) {
                if (strtotime($reads[$k]) >= $created) $out[] = ['type' => $p['type'], 'id' => $p['id'], 'seen_at' => $reads[$k]];
            } elseif (!empty($msg['is_read']) && ($conv['conv_type'] ?? 'direct') !== 'group') {
                $out[] = ['type' => $p['type'], 'id' => $p['id'], 'seen_at' => $msg['read_at'] ?? null];
            }
        }
        return $out;
    }

    /** [ "type:id" => last_read_at ] della conversazione. */
    public static function readsOf(int $conversationId): array
    {
        $out = [];
        try {
            foreach (Database::fetchAll("SELECT user_type, user_id, last_read_at FROM chat_reads WHERE conversation_id = ?", [$conversationId]) as $r) {
                $out[$r['user_type'] . ':' . $r['user_id']] = $r['last_read_at'];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    private static function seenCount(array $msg): int
    {
        return count(self::seenBy($msg));
    }

    // ─── Gruppi ───────────────────────────────────────────────────────────────

    /**
     * Nuovo gruppo: nome e almeno un'altra persona. I due posti fissi tengono
     * il creatore, i membri (creatore compreso) stanno nell'elenco dei partecipanti.
     */
    public static function createGroup(string $byType, int $byId, ?int $deptId, string $name, array $members): array
    {
        $name = trim(mb_substr($name, 0, 120));
        if ($name === '') return ['success' => false, 'error' => 'Dai un nome al gruppo'];
        $valid = [];
        foreach ($members as $m) {
            [$t, $i] = array_pad(explode(':', (string) $m, 2), 2, 0);
            $i = (int) $i;
            if (!$t || !$i || ($t === $byType && $i === $byId)) continue;
            if (!self::canContact($byType, $byId, $deptId, $t, $i)) continue;
            $valid[$t . ':' . $i] = [$t, $i];
        }
        if (!$valid) return ['success' => false, 'error' => 'Scegli almeno una persona'];
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $convId = Database::insert('chat_conversations', [
            'company_id'        => $cid,
            'participant1_type' => $byType,
            'participant1_id'   => $byId,
            'participant2_type' => $byType,
            'participant2_id'   => $byId,
            'conv_type'         => 'group',
            'name'              => $name,
            'created_by'        => $byType . ':' . $byId,
            'group_key'         => bin2hex(random_bytes(10)),
            'last_message_at'   => date('Y-m-d H:i:s'),
        ]);
        foreach (array_merge([[$byType, $byId]], array_values($valid)) as [$t, $i]) {
            Database::insert('chat_extra_participants', [
                'conversation_id'  => $convId,
                'participant_type' => $t,
                'participant_id'   => $i,
                'added_by_type'    => $byType,
                'added_by_id'      => $byId,
            ]);
        }
        self::emitChanged($convId);
        return ['success' => true, 'id' => $convId];
    }

    /** Il gruppo lo gestisce chi l'ha creato (e l'HR). */
    public static function canManageGroup(array $conv, string $type, int $id): bool
    {
        if (($conv['conv_type'] ?? '') !== 'group') return false;
        return ($conv['created_by'] ?? '') === $type . ':' . $id || $type === 'admin';
    }

    /** Aggiungere persone: chiunque faccia parte del gruppo. */
    public static function addGroupMembers(int $convId, string $byType, int $byId, ?int $deptId, array $members): array
    {
        $conv = self::isParticipant($convId, $byType, $byId);
        if (!$conv || ($conv['conv_type'] ?? '') !== 'group') return ['success' => false, 'error' => 'Gruppo non trovato'];
        $gia = [];
        foreach (self::participantsOf($conv) as $p) $gia[$p['type'] . ':' . $p['id']] = true;
        $n = 0;
        foreach ($members as $m) {
            [$t, $i] = array_pad(explode(':', (string) $m, 2), 2, 0);
            $i = (int) $i;
            if (!$t || !$i || isset($gia[$t . ':' . $i])) continue;
            if (!self::canContact($byType, $byId, $deptId, $t, $i)) continue;
            Database::insert('chat_extra_participants', [
                'conversation_id' => $convId, 'participant_type' => $t, 'participant_id' => $i,
                'added_by_type' => $byType, 'added_by_id' => $byId,
            ]);
            $gia[$t . ':' . $i] = true;
            $n++;
        }
        if (!$n) return ['success' => false, 'error' => 'Nessuna persona da aggiungere'];
        self::emitChanged($convId);
        return ['success' => true];
    }

    /** Togliere una persona: chi gestisce il gruppo; uscire: chiunque, da se'. */
    public static function removeGroupMember(int $convId, string $byType, int $byId, string $remType, int $remId): array
    {
        $conv = self::isParticipant($convId, $byType, $byId);
        if (!$conv || ($conv['conv_type'] ?? '') !== 'group') return ['success' => false, 'error' => 'Gruppo non trovato'];
        $self = $remType === $byType && $remId === $byId;
        if (!$self && !self::canManageGroup($conv, $byType, $byId)) return ['success' => false, 'error' => 'Solo chi ha creato il gruppo può togliere persone'];
        if (!$self && ($conv['created_by'] ?? '') === $remType . ':' . $remId) return ['success' => false, 'error' => 'Chi ha creato il gruppo non si può togliere'];
        $prima = [];
        foreach (self::participantsOf($conv) as $p) $prima[] = $p['type'] . ':' . $p['id'];
        Database::delete('chat_extra_participants', 'conversation_id = ? AND participant_type = ? AND participant_id = ?', [$convId, $remType, $remId]);
        $resto = (int) Database::fetchColumn("SELECT COUNT(*) FROM chat_extra_participants WHERE conversation_id = ?", [$convId]);
        if ($resto === 0) {
            Database::delete('chat_conversations', 'id = ?', [$convId]);
            return ['success' => true];
        }
        self::emitChanged($convId, $prima);
        return ['success' => true];
    }

    /** Foto del gruppo (immagine ridotta dal browser), solo per chi lo gestisce. */
    public static function setGroupAvatar(int $convId, string $byType, int $byId, array $file): array
    {
        $conv = self::isParticipant($convId, $byType, $byId);
        if (!$conv || !self::canManageGroup($conv, $byType, $byId)) return ['success' => false, 'error' => 'Non puoi cambiare la foto di questo gruppo'];
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024) return ['success' => false, 'error' => 'Immagine troppo grande (max 8MB)'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext) return ['success' => false, 'error' => 'Seleziona un\'immagine'];
        $dir = STORAGE_PATH . '/chat-attachments/' . $convId;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/gruppo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $path)) return ['success' => false, 'error' => 'Errore salvataggio'];
        if (!empty($conv['avatar_path']) && is_file($conv['avatar_path'])) @unlink($conv['avatar_path']);
        Database::update('chat_conversations', ['avatar_path' => $path], 'id = ?', [$convId]);
        self::emitChanged($convId);
        return ['success' => true];
    }

    // ─── Presenza (stile Teams, come nel CRM) ─────────────────────────────────

    public const PRESENCE_OFFLINE_AFTER = 120;   // secondi senza segnale: app chiusa
    public const PRESENCE_AWAY_AFTER = 300;      // app aperta ma ferma: assente

    public static function presenceState(?array $p, ?int $now = null): string
    {
        $now = $now ?? time();
        if (!$p || empty($p['last_seen_at']) || $now - strtotime($p['last_seen_at']) > self::PRESENCE_OFFLINE_AFTER) return 'offline';
        if (($p['manual_status'] ?? null) === 'non_disturbare') return 'non_disturbare';
        if (!empty($p['in_call_until']) && strtotime($p['in_call_until']) > $now) return 'in_chiamata';
        if (!empty($p['manual_status'])) return $p['manual_status'];
        if (empty($p['last_active_at']) || $now - strtotime($p['last_active_at']) > self::PRESENCE_AWAY_AFTER) return 'assente';
        return 'online';
    }

    private static function presenceRow(int $cid, string $type, int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM chat_presence WHERE company_id = ? AND user_type = ? AND user_id = ?", [$cid, $type, $id]) ?: null;
    }

    /** Segnale dall'app aperta (ogni 45 secondi). "In chiamata" segue lo stato del dipendente. */
    public static function presencePing(string $type, int $id, bool $active): array
    {
        $cid = class_exists('Tenant') ? (int) Tenant::currentCompanyId() : 1;
        $prima = self::presenceRow($cid, $type, $id);
        $statoPrima = self::presenceState($prima);
        $inCall = false;
        if ($type === 'employee') {
            $inCall = Database::fetchColumn("SELECT availability_status FROM employees WHERE id = ?", [$id]) === 'in_call';
        }
        $now = date('Y-m-d H:i:s');
        Database::query(
            "INSERT INTO chat_presence (company_id, user_type, user_id, last_seen_at, last_active_at, in_call_until)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at),
                 last_active_at = COALESCE(VALUES(last_active_at), last_active_at),
                 in_call_until = VALUES(in_call_until)",
            [$cid, $type, $id, $now, ($active || $inCall) ? $now : null, $inCall ? date('Y-m-d H:i:s', time() + 90) : null]
        );
        $dopo = self::presenceRow($cid, $type, $id);
        $stato = self::presenceState($dopo);
        if ($stato !== $statoPrima) self::emitPresence($cid, $type, $id, $stato, $dopo['last_seen_at'] ?? null);
        return ['stato' => $stato, 'manuale' => $dopo['manual_status'] ?? null];
    }

    /** Stato scelto a mano; null torna all'automatico. */
    public static function presenceSetStatus(string $type, int $id, ?string $stato): array
    {
        if ($stato !== null && !in_array($stato, ['occupato', 'non_disturbare', 'assente'], true)) return ['success' => false, 'error' => 'Stato non valido'];
        $cid = class_exists('Tenant') ? (int) Tenant::currentCompanyId() : 1;
        $statoPrima = self::presenceState(self::presenceRow($cid, $type, $id));
        $now = date('Y-m-d H:i:s');
        Database::query(
            "INSERT INTO chat_presence (company_id, user_type, user_id, last_seen_at, last_active_at, manual_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE manual_status = VALUES(manual_status)",
            [$cid, $type, $id, $now, $now, $stato]
        );
        $dopo = self::presenceRow($cid, $type, $id);
        $ora = self::presenceState($dopo);
        if ($ora !== $statoPrima) self::emitPresence($cid, $type, $id, $ora, $dopo['last_seen_at'] ?? null);
        return ['success' => true, 'stato' => $ora, 'manuale' => $stato];
    }

    /** Stato di tutti quelli che hanno mandato almeno un segnale: [ "type:id" => [stato, ultimoAccesso] ]. */
    public static function presenceAll(): array
    {
        $cid = class_exists('Tenant') ? (int) Tenant::currentCompanyId() : 1;
        $out = [];
        try {
            $now = time();
            foreach (Database::fetchAll("SELECT * FROM chat_presence WHERE company_id = ?", [$cid]) as $p) {
                $out[$p['user_type'] . ':' . $p['user_id']] = [
                    'stato' => self::presenceState($p, $now),
                    'ultimoAccesso' => !empty($p['last_seen_at']) ? date('c', strtotime($p['last_seen_at'])) : null,
                ];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    private static function emitPresence(int $cid, string $type, int $id, string $stato, ?string $lastSeen): void
    {
        if (!class_exists('Realtime') || !Realtime::enabled()) return;
        Realtime::emitCompany($cid, 'presence.changed', [
            'userId' => $type . ':' . $id,
            'stato' => $stato,
            'ultimoAccesso' => $lastSeen ? date('c', strtotime($lastSeen)) : null,
        ]);
    }
}
