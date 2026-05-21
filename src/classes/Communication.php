<?php
/**
 * Classe Communication - Gestione comunicazioni
 * PAManager - Comune
 */

class Communication
{
    /**
     * Priorità disponibili
     */
    public const PRIORITIES = [
        'low' => 'Bassa',
        'normal' => 'Normale',
        'high' => 'Alta'
    ];

    /**
     * Ottiene una comunicazione per ID
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT c.*, u.name AS author_name
             FROM communications c
             JOIN users u ON c.created_by = u.id
             WHERE c.id = ?",
            [$id]
        );
    }

    /**
     * Lista comunicazioni attive (per dipendenti)
     */
    public static function getActive(?int $employeeId = null, ?int $departmentId = null): array
    {
        $sql = "SELECT c.*, u.name AS author_name, d.name AS department_name, d.code AS department_code";

        if ($employeeId !== null) {
            $sql .= ", (SELECT 1 FROM communication_reads cr
                        WHERE cr.communication_id = c.id AND cr.employee_id = ?) AS is_read";
        }

        $sql .= " FROM communications c
                  JOIN users u ON c.created_by = u.id
                  LEFT JOIN departments d ON c.department_id = d.id
                  WHERE c.company_id = ?
                    AND c.is_published = TRUE
                    AND c.publish_date <= CURDATE()
                    AND (c.expire_date IS NULL OR c.expire_date >= CURDATE())";

        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $params = $employeeId !== null ? [$employeeId, $cid] : [$cid];

        // Filtra per reparto: mostra comunicazioni globali O del reparto specifico
        if ($departmentId !== null) {
            $sql .= " AND (c.is_global = TRUE OR c.department_id = ?)";
            $params[] = $departmentId;
        }

        $sql .= " ORDER BY c.priority DESC, c.publish_date DESC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Lista tutte le comunicazioni (per admin)
     */
    public static function getAll(bool $includePast = true, string $search = '', ?int $departmentId = null): array
    {
        $sql = "SELECT c.*, u.name AS author_name,
                       d.name AS department_name, d.code AS department_code,
                       (SELECT COUNT(*) FROM communication_reads cr WHERE cr.communication_id = c.id) AS read_count,
                       (SELECT COUNT(*) FROM employees e WHERE e.is_active = TRUE) AS total_employees
                FROM communications c
                JOIN users u ON c.created_by = u.id
                LEFT JOIN departments d ON c.department_id = d.id
                WHERE c.company_id = ?";
        $params = [class_exists('Tenant') ? Tenant::currentCompanyId() : 1];

        if (!$includePast) {
            $sql .= " AND (c.expire_date IS NULL OR c.expire_date >= CURDATE())";
        }

        if (!empty($search)) {
            $sql .= " AND (c.title LIKE ? OR c.content LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = array_merge($params, [$searchTerm, $searchTerm]);
        }

        // Filtro per reparto (per admin reparto)
        if ($departmentId !== null) {
            $sql .= " AND (c.is_global = TRUE OR c.department_id = ?)";
            $params[] = $departmentId;
        }

        $sql .= " ORDER BY c.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Conta comunicazioni non lette per un dipendente
     */
    public static function countUnread(int $employeeId, ?int $departmentId = null): int
    {
        $sql = "SELECT COUNT(*)
                FROM communications c
                WHERE c.is_published = TRUE
                  AND c.publish_date <= CURDATE()
                  AND (c.expire_date IS NULL OR c.expire_date >= CURDATE())
                  AND NOT EXISTS (
                      SELECT 1 FROM communication_reads cr
                      WHERE cr.communication_id = c.id AND cr.employee_id = ?
                  )";
        $params = [$employeeId];

        // Filtra per reparto se specificato
        if ($departmentId !== null) {
            $sql .= " AND (c.is_global = TRUE OR c.department_id = ?)";
            $params[] = $departmentId;
        }

        return (int) Database::fetchColumn($sql, $params);
    }

    /**
     * Crea una nuova comunicazione
     */
    public static function create(array $data): array
    {
        if (empty($data['title'])) {
            return ['success' => false, 'error' => 'Titolo obbligatorio'];
        }

        if (empty($data['content'])) {
            return ['success' => false, 'error' => 'Contenuto obbligatorio'];
        }

        if (empty($data['publish_date'])) {
            $data['publish_date'] = date('Y-m-d');
        }

        if (!empty($data['expire_date']) && $data['expire_date'] < $data['publish_date']) {
            return ['success' => false, 'error' => 'Data scadenza non valida'];
        }

        $user = Auth::getUser();
        if (!$user) {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        // Determina se è globale o per reparto
        $isGlobal = !isset($data['department_id']) || empty($data['department_id']);
        $departmentId = $isGlobal ? null : (int) $data['department_id'];

        try {
            $insertData = [
                'company_id' => class_exists('Tenant') ? Tenant::currentCompanyId() : 1,
                'title' => trim($data['title']),
                'content' => $data['content'],
                'priority' => $data['priority'] ?? 'normal',
                'is_published' => isset($data['is_published']) ? 1 : 0,
                'publish_date' => $data['publish_date'],
                'expire_date' => !empty($data['expire_date']) ? $data['expire_date'] : null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                'is_global' => $isGlobal ? 1 : 0,
                'department_id' => $departmentId,
                'created_by' => $user['id']
            ];

            error_log('Communication::create insertData: ' . print_r($insertData, true));

            $id = Database::insert('communications', $insertData);

            self::logAction('communication_created', $id, null, $data);

            // Invia push notifications se la comunicazione è pubblicata e data di pubblicazione è oggi o passata
            if ($insertData['is_published'] && $insertData['publish_date'] <= date('Y-m-d')) {
                self::sendPushNotifications($id, $insertData['title'], $isGlobal, $departmentId);
                self::sendEmailNotifications($id, $insertData['title'], $insertData['content'], $insertData['priority'], $isGlobal, $departmentId);
            }

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            error_log('Communication::create error: ' . $e->getMessage());
            error_log('Communication::create trace: ' . $e->getTraceAsString());
            return ['success' => false, 'error' => 'Errore DB: ' . $e->getMessage()];
        }
    }

    /**
     * Aggiorna una comunicazione
     */
    /**
     * Verifica scope tenant: il record deve appartenere alla company corrente.
     */
    private static function inCurrentTenant(array $row): bool
    {
        if (!class_exists('Tenant')) return true;
        $cid = Tenant::currentCompanyId();
        return (int) ($row['company_id'] ?? 0) === (int) $cid;
    }

    public static function update(int $id, array $data): array
    {
        $communication = self::getById($id);
        if (!$communication) {
            return ['success' => false, 'error' => 'Comunicazione non trovata'];
        }
        if (!self::inCurrentTenant($communication)) {
            return ['success' => false, 'error' => 'Comunicazione non appartiene all\'azienda corrente'];
        }

        $updateData = [];

        if (isset($data['title']) && !empty($data['title'])) {
            $updateData['title'] = trim($data['title']);
        }

        if (isset($data['content']) && !empty($data['content'])) {
            $updateData['content'] = $data['content'];
        }

        if (isset($data['priority']) && isset(self::PRIORITIES[$data['priority']])) {
            $updateData['priority'] = $data['priority'];
        }

        if (isset($data['is_published'])) {
            $updateData['is_published'] = (bool) $data['is_published'];
        }

        if (isset($data['publish_date'])) {
            $updateData['publish_date'] = $data['publish_date'];
        }

        if (array_key_exists('expire_date', $data)) {
            $updateData['expire_date'] = $data['expire_date'] ?: null;
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('communications', $updateData, 'id = ?', [$id]);
            self::logAction('communication_updated', $id, $communication, $updateData);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Elimina una comunicazione
     */
    public static function delete(int $id): array
    {
        $communication = self::getById($id);
        if (!$communication) {
            return ['success' => false, 'error' => 'Comunicazione non trovata'];
        }
        if (!self::inCurrentTenant($communication)) {
            return ['success' => false, 'error' => 'Comunicazione non appartiene all\'azienda corrente'];
        }

        try {
            // Elimina allegato se presente
            if (!empty($communication['attachment_path']) && file_exists($communication['attachment_path'])) {
                unlink($communication['attachment_path']);
            }

            // Le letture vengono eliminate automaticamente (ON DELETE CASCADE)
            Database::delete('communications', 'id = ?', [$id]);

            self::logAction('communication_deleted', $id, $communication, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    /**
     * Pubblica/Nasconde una comunicazione
     */
    public static function togglePublish(int $id): array
    {
        $communication = self::getById($id);
        if (!$communication) {
            return ['success' => false, 'error' => 'Comunicazione non trovata'];
        }
        if (!self::inCurrentTenant($communication)) {
            return ['success' => false, 'error' => 'Comunicazione non appartiene all\'azienda corrente'];
        }

        $newStatus = !$communication['is_published'];

        try {
            Database::update('communications', ['is_published' => $newStatus], 'id = ?', [$id]);
            self::logAction('communication_toggled', $id, ['is_published' => $communication['is_published']], ['is_published' => $newStatus]);
            return ['success' => true, 'is_published' => $newStatus];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Segna una comunicazione come letta
     */
    public static function markAsRead(int $communicationId, int $employeeId): array
    {
        try {
            // INSERT IGNORE per evitare duplicati
            Database::query(
                "INSERT IGNORE INTO communication_reads (communication_id, employee_id) VALUES (?, ?)",
                [$communicationId, $employeeId]
            );
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Ottiene statistiche lettura per una comunicazione
     */
    public static function getReadStats(int $communicationId): array
    {
        $totalEmployees = Employee::count(true);
        $readCount = Database::count('communication_reads', 'communication_id = ?', [$communicationId]);

        $readers = Database::fetchAll(
            "SELECT e.id, e.first_name, e.last_name, cr.read_at
             FROM communication_reads cr
             JOIN employees e ON cr.employee_id = e.id
             WHERE cr.communication_id = ?
             ORDER BY cr.read_at DESC",
            [$communicationId]
        );

        return [
            'total_employees' => $totalEmployees,
            'read_count' => $readCount,
            'unread_count' => $totalEmployees - $readCount,
            'percentage' => $totalEmployees > 0 ? round(($readCount / $totalEmployees) * 100, 1) : 0,
            'readers' => $readers
        ];
    }

    /**
     * Upload allegato comunicazione
     */
    public static function uploadAttachment(array $file, int $communicationId): array
    {
        $communication = self::getById($communicationId);
        if (!$communication) {
            return ['success' => false, 'error' => 'Comunicazione non trovata'];
        }

        // Validazione file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Errore durante l\'upload'];
        }

        if ($file['size'] > MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'File troppo grande'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            return ['success' => false, 'error' => 'Tipo file non consentito'];
        }

        // Genera nome file
        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = STORAGE_PATH . '/communications';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName;

        // Elimina vecchio allegato
        if (!empty($communication['attachment_path']) && file_exists($communication['attachment_path'])) {
            unlink($communication['attachment_path']);
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio'];
        }

        try {
            Database::update('communications', [
                'attachment_path' => $filePath,
                'attachment_name' => $file['name']
            ], 'id = ?', [$communicationId]);

            return ['success' => true];
        } catch (Exception $e) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Lista allegati (escluse immagini inline)
     */
    public static function getAttachments(int $communicationId, bool $includeInline = false): array
    {
        $sql = "SELECT id, original_name, stored_name, mime_type, size_bytes, is_inline_image, created_at
                FROM communication_attachments WHERE communication_id = ?";
        if (!$includeInline) $sql .= " AND is_inline_image = 0";
        $sql .= " ORDER BY id ASC";
        return Database::fetchAll($sql, [$communicationId]);
    }

    /**
     * Aggiunge un allegato (file caricato).
     * @return array{success:bool, id?:int, url?:string, error?:string}
     */
    public static function addUploadedFile(array $file, int $communicationId, bool $isInlineImage = false): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Errore upload'];
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'File troppo grande'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = $isInlineImage ? ['jpg','jpeg','png','gif','webp'] : ALLOWED_EXTENSIONS;
        if (!in_array($ext, $allowed, true)) {
            return ['success' => false, 'error' => 'Tipo file non consentito'];
        }

        $dir = STORAGE_PATH . '/communications/' . $communicationId;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . '/' . $stored;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return ['success' => false, 'error' => 'Salvataggio fallito'];
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($dest) ?: 'application/octet-stream') : 'application/octet-stream';

        try {
            $id = Database::insert('communication_attachments', [
                'communication_id' => $communicationId,
                'original_name' => $file['name'],
                'stored_name' => $stored,
                'mime_type' => $mime,
                'size_bytes' => (int)$file['size'],
                'is_inline_image' => $isInlineImage ? 1 : 0,
            ]);
            return [
                'success' => true,
                'id' => $id,
                'url' => self::attachmentUrl($id),
            ];
        } catch (Exception $e) {
            if (file_exists($dest)) @unlink($dest);
            return ['success' => false, 'error' => 'Errore DB'];
        }
    }

    public static function getAttachment(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM communication_attachments WHERE id = ?",
            [$id]
        );
    }

    public static function deleteAttachment(int $id): bool
    {
        $a = self::getAttachment($id);
        if (!$a) return false;
        $path = STORAGE_PATH . '/communications/' . $a['communication_id'] . '/' . $a['stored_name'];
        if (file_exists($path)) @unlink($path);
        Database::delete('communication_attachments', 'id = ?', [$id]);
        return true;
    }

    public static function attachmentUrl(int $id): string
    {
        $base = defined('PUBLIC_URL') ? PUBLIC_URL : '';
        return $base . '/communication_file.php?id=' . $id;
    }

    /**
     * Invia push notifications per nuova comunicazione
     */
    private static function sendPushNotifications(int $commId, string $title, bool $isGlobal, ?int $departmentId): void
    {
        try {
            $payload = [
                'title' => 'Nuova comunicazione',
                'body' => $title,
                'url' => PUBLIC_URL . '/employee/communications.php?id=' . $commId,
                'tag' => 'communication-' . $commId,
                'icon' => '/assets/images/icon.php?size=192'
            ];

            error_log('[Communication] Invio push - isGlobal: ' . ($isGlobal ? 'yes' : 'no') . ', dept: ' . ($departmentId ?? 'null'));

            if ($isGlobal) {
                // Invia a tutti i dipendenti
                $result = PushNotification::broadcastToEmployees(
                    $payload['title'],
                    $payload['body'],
                    $payload['url']
                );
                error_log('[Communication] Broadcast result: ' . json_encode($result));
            } elseif ($departmentId) {
                // Invia solo ai dipendenti del reparto
                $employees = Department::getEmployees($departmentId, true);
                error_log('[Communication] Invio a ' . count($employees) . ' dipendenti del reparto');
                foreach ($employees as $emp) {
                    $result = PushNotification::sendToUser('employee', (int)$emp['id'], $payload);
                    error_log('[Communication] Push a employee #' . $emp['id'] . ': ' . json_encode($result));
                }
            } else {
                error_log('[Communication] Nessun invio: non globale e nessun reparto');
            }
        } catch (Exception $e) {
            error_log('[Communication] Push notification error: ' . $e->getMessage());
        }
    }

    /**
     * Invia email di notifica ai dipendenti destinatari della comunicazione
     */
    private static function sendEmailNotifications(int $commId, string $title, string $content, string $priority, bool $isGlobal, ?int $departmentId): void
    {
        try {
            if (!class_exists('Mailer') || !Mailer::isConfigured()) {
                return;
            }

            // Recupera destinatari
            if ($isGlobal) {
                $recipients = Database::fetchAll(
                    "SELECT id, first_name, last_name, email FROM employees
                     WHERE is_active = 1 AND email IS NOT NULL AND email <> ''"
                );
            } elseif ($departmentId) {
                $recipients = Database::fetchAll(
                    "SELECT id, first_name, last_name, email FROM employees
                     WHERE is_active = 1 AND email IS NOT NULL AND email <> '' AND department_id = ?",
                    [$departmentId]
                );
            } else {
                return;
            }

            if (empty($recipients)) {
                return;
            }

            $loginUrl = function_exists('buildPublicUrl')
                ? buildPublicUrl('/auth/login.php')
                : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login.php' : '');

            $priorityLabels = [
                'low' => 'Bassa',
                'normal' => 'Normale',
                'high' => 'Alta',
                'urgent' => 'Urgente'
            ];
            $priorityLabel = $priorityLabels[$priority] ?? ucfirst($priority);

            // Crea estratto del contenuto (testo, max ~400 caratteri)
            $plainContent = trim(strip_tags($content));
            $excerpt = mb_strlen($plainContent) > 400 ? mb_substr($plainContent, 0, 400) . '…' : $plainContent;

            $titleSafe = htmlspecialchars($title);
            $excerptHtml = nl2br(htmlspecialchars($excerpt));
            $prioSafe = htmlspecialchars($priorityLabel);

            $subjectPrefix = ($priority === 'urgent') ? '[URGENTE] ' : '';
            $subject = $subjectPrefix . 'Nuova comunicazione: ' . $title;

            foreach ($recipients as $emp) {
                $fullName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                $nameSafe = htmlspecialchars($fullName);

                $html = "<p>Ciao {$nameSafe},</p>"
                      . "<p>È stata pubblicata una nuova comunicazione" . ($isGlobal ? '' : ' per il tuo reparto') . ":</p>"
                      . "<p><strong>{$titleSafe}</strong> <small style=\"color:#666;\">(priorità: {$prioSafe})</small></p>"
                      . "<blockquote style=\"border-left:3px solid #ccc;padding-left:1em;color:#444;\">{$excerptHtml}</blockquote>"
                      . "<p><a href=\"{$loginUrl}\">Accedi al portale per leggere la comunicazione completa</a></p>";

                $text = "Ciao {$fullName},\n\n"
                      . "Nuova comunicazione" . ($isGlobal ? '' : ' per il tuo reparto') . ":\n\n"
                      . "Titolo: {$title}\n"
                      . "Priorità: {$priorityLabel}\n\n"
                      . $excerpt . "\n\n"
                      . "Accedi: {$loginUrl}";

                Mailer::sendToEmployee((int) $emp['id'], $subject, $html, $text);
            }
        } catch (Exception $e) {
            error_log('[Communication] Email notification error: ' . $e->getMessage());
        }
    }

    /**
     * Log azioni
     */
    private static function logAction(string $action, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::getUser();
        if ($user) {
            try {
                Database::insert('audit_log', [
                    'user_type' => $user['role'],
                    'user_id' => $user['id'],
                    'action' => $action,
                    'entity_type' => 'communication',
                    'entity_id' => $entityId,
                    'old_values' => $oldValues ? json_encode($oldValues) : null,
                    'new_values' => $newValues ? json_encode($newValues) : null,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                error_log('Audit log failed: ' . $e->getMessage());
            }
        }
    }
}
