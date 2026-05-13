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
     * Ottiene le conversazioni di un utente
     */
    public static function getConversations(string $userType, int $userId): array
    {
        $sql = "SELECT cc.*,
                       (SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id
                          AND cm.is_read = FALSE
                          AND NOT (cm.sender_type = ? AND cm.sender_id = ?)) AS unread_count,
                       (SELECT cm.message FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id
                        ORDER BY cm.created_at DESC LIMIT 1) AS last_message,
                       (SELECT cm.created_at FROM chat_messages cm
                        WHERE cm.conversation_id = cc.id
                        ORDER BY cm.created_at DESC LIMIT 1) AS last_message_time
                FROM chat_conversations cc
                WHERE cc.company_id = ?
                  AND ((cc.participant1_type = ? AND cc.participant1_id = ?)
                       OR (cc.participant2_type = ? AND cc.participant2_id = ?))
                ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC";

        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $conversations = Database::fetchAll($sql, [
            $userType, $userId,
            $cid,
            $userType, $userId,
            $userType, $userId
        ]);

        // Aggiungi info sull'altro partecipante
        foreach ($conversations as &$conv) {
            if ($conv['participant1_type'] === $userType && (int)$conv['participant1_id'] === (int)$userId) {
                $conv['other_type'] = $conv['participant2_type'];
                $conv['other_id'] = $conv['participant2_id'];
            } else {
                $conv['other_type'] = $conv['participant1_type'];
                $conv['other_id'] = $conv['participant1_id'];
            }

            // Ottieni nome altro partecipante
            $conv['other_name'] = self::getParticipantName($conv['other_type'], $conv['other_id']);
            $conv['other_photo'] = self::getParticipantPhoto($conv['other_type'], (int) $conv['other_id']);
        }

        return $conversations;
    }

    /**
     * Ottiene i messaggi di una conversazione
     */
    public static function getMessages(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT * FROM chat_messages
             WHERE conversation_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            [$conversationId, $limit, $offset]
        );
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
        ?string $attachmentMime = null
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

        // Verifica che l'utente sia un partecipante
        $isParticipant = ($conv['participant1_type'] === $senderType && $conv['participant1_id'] == $senderId)
                      || ($conv['participant2_type'] === $senderType && $conv['participant2_id'] == $senderId);

        if (!$isParticipant) {
            return ['success' => false, 'error' => 'Non sei un partecipante di questa conversazione'];
        }

        try {
            $msgId = Database::insert('chat_messages', [
                'company_id' => (int)($conv['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1)),
                'conversation_id' => $conversationId,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'message' => trim($message),
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'attachment_size' => $attachmentSize,
                'attachment_mime' => $attachmentMime,
                'is_read' => 0
            ]);

            // Aggiorna timestamp ultima attività
            Database::update('chat_conversations', [
                'last_message_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$conversationId]);

            // Crea notifica per l'altro partecipante (ignora errori)
            try {
                self::createMessageNotification($conv, $senderType, $senderId, $message);
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
        Database::query(
            "UPDATE chat_messages
             SET is_read = TRUE
             WHERE conversation_id = ?
               AND NOT (sender_type = ? AND sender_id = ?)
               AND is_read = FALSE",
            [$conversationId, $readerType, $readerId]
        );
    }

    /**
     * Conta messaggi non letti totali
     */
    public static function countUnread(string $userType, int $userId): int
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM chat_messages cm
             JOIN chat_conversations cc ON cm.conversation_id = cc.id
             WHERE cc.company_id = ?
               AND ((cc.participant1_type = ? AND cc.participant1_id = ?)
                    OR (cc.participant2_type = ? AND cc.participant2_id = ?))
               AND NOT (cm.sender_type = ? AND cm.sender_id = ?)
               AND cm.is_read = FALSE",
            [$cid, $userType, $userId, $userType, $userId, $userType, $userId]
        );
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

        // Filtro tenant: per ogni gruppo, escludi entita' di altre aziende quando applicabile
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : null;
        if ($cid) {
            foreach (['admin_reparto', 'accountant', 'consulente_lavoro'] as $g) {
                if (!empty($contacts[$g])) {
                    $contacts[$g] = array_values(array_filter($contacts[$g], function($u) use ($cid) {
                        return empty($u['company_id']) || (int)$u['company_id'] === (int)$cid;
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
        string $message
    ): void {
        // Determina destinatario
        if ($conversation['participant1_type'] === $senderType && $conversation['participant1_id'] == $senderId) {
            $recipientType = $conversation['participant2_type'];
            $recipientId = $conversation['participant2_id'];
        } else {
            $recipientType = $conversation['participant1_type'];
            $recipientId = $conversation['participant1_id'];
        }

        $senderName = self::getParticipantName($senderType, $senderId);
        $preview = mb_strlen($message) > 50 ? mb_substr($message, 0, 47) . '...' : $message;
        $chatUrl = self::getChatUrl($recipientType) . '?conv=' . $conversation['id'];

        // Notifica in-app (database)
        Notification::create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'type' => 'new_message',
            'title' => 'Nuovo messaggio da ' . $senderName,
            'message' => $preview,
            'link' => $chatUrl
        ]);

        // Push notification (browser)
        try {
            error_log('[Chat] Invio push a ' . $recipientType . ' #' . $recipientId);
            $pushResult = PushNotification::sendToUser($recipientType, (int)$recipientId, [
                'title' => 'Nuovo messaggio da ' . $senderName,
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

                    $html = "<p>Ciao {$nameSafe},</p>"
                          . "<p>Hai ricevuto un nuovo messaggio da <strong>{$senderSafe}</strong>:</p>"
                          . "<blockquote style=\"border-left:3px solid #ccc;padding-left:1em;color:#444;\">{$previewSafe}</blockquote>"
                          . "<p><a href=\"{$loginUrl}\">Apri la chat per rispondere</a></p>";
                    $text = "Ciao {$recipient['name']},\n\nNuovo messaggio da {$senderName}:\n\n{$preview}\n\nApri la chat: {$loginUrl}";

                    if ($recipientType === 'employee') {
                        Mailer::sendToEmployee((int) $recipientId, 'Nuovo messaggio da ' . $senderName, $html, $text);
                    } else {
                        Mailer::send($recipient['email'], $recipient['name'], 'Nuovo messaggio da ' . $senderName, $html, $text);
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

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'odt', 'ods', 'zip'];
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
            "SELECT m.*, c.participant1_type, c.participant1_id, c.participant2_type, c.participant2_id
             FROM chat_messages m
             JOIN chat_conversations c ON c.id = m.conversation_id
             WHERE m.id = ?",
            [$messageId]
        );

        if (!$msg) return ['success' => false, 'error' => 'Messaggio non trovato'];
        if (empty($msg['attachment_path']) || !file_exists($msg['attachment_path'])) {
            return ['success' => false, 'error' => 'Allegato non disponibile'];
        }

        $isParticipant = ($msg['participant1_type'] === $userType && (int)$msg['participant1_id'] === $userId)
                      || ($msg['participant2_type'] === $userType && (int)$msg['participant2_id'] === $userId);

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
}
