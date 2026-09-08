<?php
/**
 * EmployeeDocument - documenti generici associati a un dipendente (contratti,
 * documenti identita, certificati). Separato dal sistema buste paga/CUD.
 */

class EmployeeDocument
{
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT ed.*, u.name AS uploaded_by_name,
                    e.first_name, e.last_name, e.fiscal_code,
                    CONCAT(e.last_name, ' ', e.first_name) AS employee_name
             FROM employee_documents ed
             JOIN employees e ON ed.employee_id = e.id
             JOIN users u ON ed.uploaded_by = u.id
             WHERE ed.id = ?",
            [$id]
        );
    }

    public static function getByEmployee(int $employeeId, ?bool $onlyVisible = null): array
    {
        $sql = "SELECT ed.*, u.name AS uploaded_by_name
                FROM employee_documents ed
                JOIN users u ON ed.uploaded_by = u.id
                WHERE ed.employee_id = ?";
        $params = [$employeeId];

        if ($onlyVisible === true) {
            $sql .= " AND ed.visible_to_employee = 1";
        }

        $sql .= " ORDER BY ed.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    public static function upload(array $file, array $data): array
    {
        if (empty($data['employee_id'])) {
            return ['success' => false, 'error' => 'Dipendente non specificato'];
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'Nome documento obbligatorio'];
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $employee = Employee::getById((int) $data['employee_id']);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        $fileValidation = self::validateFile($file);
        if (!$fileValidation['valid']) {
            return ['success' => false, 'error' => $fileValidation['error']];
        }

        $expiresOn = !empty($data['expires_on']) ? $data['expires_on'] : null;
        if ($expiresOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
            return ['success' => false, 'error' => 'Data scadenza non valida'];
        }

        $visible = !empty($data['visible_to_employee']) ? 1 : 0;

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = bin2hex(random_bytes(16)) . '.' . strtolower($extension);

        $directory = DOCUMENTS_PATH . '/employee-docs/' . (int) $data['employee_id'];
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio del file'];
        }

        $user = Auth::getUser();
        $companyId = (int) ($employee['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1));

        try {
            $id = Database::insert('employee_documents', [
                'company_id' => $companyId,
                'employee_id' => (int) $data['employee_id'],
                'name' => $name,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'original_name' => $file['name'],
                'file_size' => $file['size'],
                'mime_type' => $fileValidation['mime_type'],
                'visible_to_employee' => $visible,
                'expires_on' => $expiresOn,
                'uploaded_by' => $user['id']
            ]);

            self::logAction('employee_document_uploaded', $id, null, [
                'employee_id' => $data['employee_id'],
                'name' => $name,
                'visible' => $visible
            ]);

            if ($visible) {
                self::notifyEmployee($employee, $name);
            }

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante il salvataggio: ' . $e->getMessage()];
        }
    }

    /**
     * Carica un documento e lo aggancia a tutti i dipendenti attivi dell'azienda
     * corrente. Il file viene salvato una sola volta su disco: le righe generate
     * condividono file_path e sono raggruppate da bulk_group.
     *
     * @param array $data name, visible_to_employee, expires_on, send_email
     * @return array success, count, bulk_group oppure error
     */
    public static function uploadForAllEmployees(array $file, array $data): array
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'Nome documento obbligatorio'];
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $fileValidation = self::validateFile($file);
        if (!$fileValidation['valid']) {
            return ['success' => false, 'error' => $fileValidation['error']];
        }

        $expiresOn = !empty($data['expires_on']) ? $data['expires_on'] : null;
        if ($expiresOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
            return ['success' => false, 'error' => 'Data scadenza non valida'];
        }

        $visible = array_key_exists('visible_to_employee', $data)
            ? (!empty($data['visible_to_employee']) ? 1 : 0)
            : 1;
        $sendEmail = !empty($data['send_email']);

        $companyId = (int) (class_exists('Tenant') ? Tenant::currentCompanyId() : 1);
        $employees = Employee::getAll(true);
        if (empty($employees)) {
            return ['success' => false, 'error' => 'Nessun dipendente attivo a cui agganciare il documento'];
        }

        $user = Auth::getUser();
        if (!$user) {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        $group = bin2hex(random_bytes(8));
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = DOCUMENTS_PATH . '/employee-docs/_bulk/' . $companyId;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['success' => false, 'error' => 'Impossibile creare la cartella di destinazione'];
        }

        $filePath = $directory . '/' . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio del file'];
        }

        $inserted = [];
        try {
            foreach ($employees as $employee) {
                $inserted[] = Database::insert('employee_documents', [
                    'company_id' => $companyId,
                    'bulk_group' => $group,
                    'employee_id' => (int) $employee['id'],
                    'name' => $name,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'original_name' => $file['name'],
                    'file_size' => $file['size'],
                    'mime_type' => $fileValidation['mime_type'],
                    'visible_to_employee' => $visible,
                    'notify_pending' => $visible,
                    'notify_email' => $sendEmail ? 1 : 0,
                    'expires_on' => $expiresOn,
                    'uploaded_by' => $user['id']
                ]);
            }
        } catch (Exception $e) {
            foreach ($inserted as $rowId) {
                try { Database::delete('employee_documents', 'id = ?', [$rowId]); } catch (Throwable $ignored) {}
            }
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante il salvataggio: ' . $e->getMessage()];
        }

        self::logAction('employee_document_bulk_uploaded', (int) ($inserted[0] ?? 0), null, [
            'bulk_group' => $group,
            'name' => $name,
            'employees' => count($inserted),
            'visible' => $visible
        ]);

        // Le notifiche NON partono qui: sarebbero N invii SMTP dentro la request
        // dell'upload. Restano in coda (notify_pending) e vengono processate a
        // batch da processNotificationBatch().

        return [
            'success' => true,
            'count' => count($inserted),
            'pending_notifications' => $visible ? count($inserted) : 0,
            'bulk_group' => $group
        ];
    }

    /**
     * Processa un blocco di notifiche in coda per una distribuzione massiva.
     * Chiamato in loop dal browser (AJAX) cosi l'upload resta immediato.
     *
     * @return array success, sent, remaining
     */
    public static function processNotificationBatch(string $group, int $limit = 4): array
    {
        $group = trim($group);
        if ($group === '') {
            return ['success' => false, 'error' => 'Gruppo non valido'];
        }
        $limit = max(1, min(20, $limit));
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;

        $rows = Database::fetchAll(
            "SELECT id, employee_id, name, notify_email
             FROM employee_documents
             WHERE company_id = ? AND bulk_group = ? AND notify_pending = 1
             ORDER BY id
             LIMIT " . (int) $limit,
            [$cid, $group]
        );

        $sent = 0;
        foreach ($rows as $row) {
            // Prima sblocco la riga: se l'invio va in timeout non resta in loop
            Database::update('employee_documents', ['notify_pending' => 0], 'id = ?', [$row['id']]);
            $employee = Employee::getById((int) $row['employee_id']);
            if ($employee) {
                self::notifyEmployee($employee, $row['name'], !empty($row['notify_email']));
                $sent++;
            }
        }

        return [
            'success' => true,
            'sent' => $sent,
            'remaining' => self::countPendingNotifications($group)
        ];
    }

    /**
     * Notifiche ancora in coda: per un gruppo, o per tutta l'azienda.
     */
    public static function countPendingNotifications(?string $group = null): int
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        if ($group !== null && trim($group) !== '') {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM employee_documents
                 WHERE company_id = ? AND bulk_group = ? AND notify_pending = 1",
                [$cid, trim($group)]
            );
        }
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM employee_documents
             WHERE company_id = ? AND bulk_group IS NOT NULL AND notify_pending = 1",
            [$cid]
        );
    }

    /**
     * Primo gruppo con notifiche ancora da smaltire (per riprendere il lavoro).
     */
    public static function getPendingNotificationGroup(): ?string
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $group = Database::fetchColumn(
            "SELECT bulk_group FROM employee_documents
             WHERE company_id = ? AND bulk_group IS NOT NULL AND notify_pending = 1
             ORDER BY id LIMIT 1",
            [$cid]
        );
        return $group !== null && $group !== false ? (string) $group : null;
    }

    /**
     * Elenco delle distribuzioni massive dell'azienda corrente.
     */
    public static function getBulkGroups(): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        return Database::fetchAll(
            "SELECT ed.bulk_group,
                    MIN(ed.name) AS name,
                    MIN(ed.original_name) AS original_name,
                    MIN(ed.file_size) AS file_size,
                    MIN(ed.mime_type) AS mime_type,
                    MIN(ed.expires_on) AS expires_on,
                    MAX(ed.visible_to_employee) AS visible_to_employee,
                    MIN(ed.created_at) AS created_at,
                    MIN(ed.id) AS sample_id,
                    COUNT(*) AS employee_count,
                    COUNT(DISTINCT dd.user_id) AS downloaded_count,
                    SUM(ed.notify_pending) AS pending_notifications,
                    MIN(u.name) AS uploaded_by_name
             FROM employee_documents ed
             JOIN users u ON ed.uploaded_by = u.id
             LEFT JOIN employee_document_downloads dd
                    ON dd.employee_document_id = ed.id AND dd.user_type = 'employee'
             WHERE ed.company_id = ? AND ed.bulk_group IS NOT NULL
             GROUP BY ed.bulk_group
             ORDER BY MIN(ed.created_at) DESC",
            [$cid]
        );
    }

    /**
     * Elimina tutte le righe di una distribuzione massiva (e il file condiviso).
     */
    public static function deleteBulkGroup(string $group): array
    {
        $group = trim($group);
        if ($group === '') {
            return ['success' => false, 'error' => 'Gruppo non valido'];
        }
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $rows = Database::fetchAll(
            "SELECT id, file_path FROM employee_documents WHERE company_id = ? AND bulk_group = ?",
            [$cid, $group]
        );
        if (empty($rows)) {
            return ['success' => false, 'error' => 'Distribuzione non trovata'];
        }
        try {
            Database::delete('employee_documents', 'company_id = ? AND bulk_group = ?', [$cid, $group]);
            $filePath = $rows[0]['file_path'];
            if (!self::filePathStillUsed($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            self::logAction('employee_document_bulk_deleted', (int) $rows[0]['id'], null, [
                'bulk_group' => $group,
                'rows' => count($rows)
            ]);
            return ['success' => true, 'count' => count($rows)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    /**
     * True se il file su disco e ancora referenziato da almeno una riga.
     */
    private static function filePathStillUsed(string $filePath, int $excludeId = 0): bool
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM employee_documents WHERE file_path = ? AND id <> ?",
                [$filePath, $excludeId]
            ) > 0;
        } catch (Throwable $e) {
            // In caso di dubbio non cancelliamo il file
            return true;
        }
    }

    public static function update(int $id, array $data): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        $updateData = [];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') {
                return ['success' => false, 'error' => 'Il nome non puo essere vuoto'];
            }
            $updateData['name'] = mb_substr($name, 0, 255);
        }

        $visibilityChangedToVisible = false;
        if (array_key_exists('visible_to_employee', $data)) {
            $newVisible = !empty($data['visible_to_employee']) ? 1 : 0;
            $updateData['visible_to_employee'] = $newVisible;
            if ($newVisible === 1 && (int) $document['visible_to_employee'] === 0) {
                $visibilityChangedToVisible = true;
            }
        }

        if (array_key_exists('expires_on', $data)) {
            $exp = $data['expires_on'];
            if ($exp === '' || $exp === null) {
                $updateData['expires_on'] = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                $updateData['expires_on'] = $exp;
            } else {
                return ['success' => false, 'error' => 'Data scadenza non valida'];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('employee_documents', $updateData, 'id = ?', [$id]);
            self::logAction('employee_document_updated', $id, $document, $updateData);

            if ($visibilityChangedToVisible) {
                $employee = Employee::getById((int) $document['employee_id']);
                if ($employee) {
                    $newName = $updateData['name'] ?? $document['name'];
                    self::notifyEmployee($employee, $newName);
                }
            }

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    public static function delete(int $id): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }
        try {
            Database::delete('employee_documents', 'id = ?', [$id]);
            if (!self::filePathStillUsed($document['file_path'], $id) && file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            self::logAction('employee_document_deleted', $id, $document, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    public static function download(int $id): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        $userType = 'unknown';
        $userId = 0;

        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
            // Check tenant: il documento deve appartenere alla company corrente del caller
            $callerCid = class_exists('Tenant') ? Tenant::currentCompanyId() : null;
            if ($callerCid !== null && isset($document['company_id']) && (int) $document['company_id'] !== (int) $callerCid) {
                if (class_exists('AuditLog')) {
                    AuditLog::logUnauthorizedAccess('employee_document', [
                        'document_id' => $id,
                        'user_id' => $userId,
                        'reason' => 'cross_tenant'
                    ]);
                }
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
            if ($userType === 'admin_reparto') {
                $emp = Employee::getById((int) $document['employee_id']);
                if (!$emp || (int) ($emp['department_id'] ?? 0) !== (int) ($user['department_id'] ?? -1)) {
                    AuditLog::logUnauthorizedAccess('employee_document', [
                        'document_id' => $id,
                        'user_id' => $userId,
                        'reason' => 'department_scope'
                    ]);
                    return ['success' => false, 'error' => 'Accesso non autorizzato'];
                }
            }
        } elseif ($employee) {
            $userType = 'employee';
            $userId = $employee['id'];
            if ((int) $document['employee_id'] !== (int) $employee['id']
                || (int) $document['visible_to_employee'] !== 1) {
                AuditLog::logUnauthorizedAccess('employee_document', [
                    'document_id' => $id,
                    'employee_id' => $employee['id'],
                    'owner_id' => $document['employee_id'],
                    'visible' => $document['visible_to_employee']
                ]);
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        if (function_exists('checkDownloadRateLimit')) {
            $rateCheck = checkDownloadRateLimit($userId, $userType);
            if (!$rateCheck['allowed']) {
                return ['success' => false, 'error' => $rateCheck['message']];
            }
        }

        if (!file_exists($document['file_path'])) {
            return ['success' => false, 'error' => 'File non trovato sul server'];
        }

        self::trackDownload($id, $userType, $userId);
        self::logAction('employee_document_downloaded', $id, null, ['user_type' => $userType, 'user_id' => $userId]);

        return [
            'success' => true,
            'document' => $document,
            'file_path' => $document['file_path']
        ];
    }

    private static function trackDownload(int $documentId, string $userType, int $userId): void
    {
        try {
            Database::insert('employee_document_downloads', [
                'employee_document_id' => $documentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'ip_address' => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('[EmployeeDocument] download tracking failed: ' . $e->getMessage());
        }
    }

    private const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'odt', 'ods'];
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'text/plain',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/octet-stream',
    ];
    private const MAX_SIZE = 30 * 1024 * 1024; // 30MB

    private static function validateFile(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Errore upload (codice ' . $file['error'] . ')'];
        }
        if ($file['size'] > self::MAX_SIZE) {
            $maxMb = self::MAX_SIZE / 1024 / 1024;
            return ['valid' => false, 'error' => "File troppo grande. Massimo {$maxMb}MB"];
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXT, true)) {
            return ['valid' => false, 'error' => 'Estensione non consentita: .' . $extension . ' (permesse: ' . implode(', ', self::ALLOWED_EXT) . ')'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            return ['valid' => false, 'error' => 'Tipo MIME non consentito: ' . $mimeType];
        }
        return ['valid' => true, 'mime_type' => $mimeType];
    }

    private static function logAction(string $action, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        $userType = 'employee';
        $userId = 0;
        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
        } elseif ($employee) {
            $userId = $employee['id'];
        }
        try {
            AuditLog::logEntityChange($action, $userType, $userId, 'employee_document', $entityId, $oldValues, $newValues);
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] audit log failed: ' . $e->getMessage());
        }
    }

    private static function notifyEmployee(array $employee, string $documentName, bool $sendEmail = true): void
    {
        try {
            if (class_exists('PushNotification')) {
                PushNotification::notifyNewDocument(
                    (int) $employee['id'],
                    'altro',
                    $documentName
                );
            }
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] push error: ' . $e->getMessage());
        }

        try {
            if ($sendEmail && class_exists('Mailer') && Mailer::isConfigured()) {
                $loginUrl = function_exists('buildPublicUrl')
                    ? buildPublicUrl('/auth/login.php')
                    : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login.php' : '');
                $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                $nameSafe = htmlspecialchars($fullName);
                $docSafe = htmlspecialchars($documentName);
                $html = "<p>Ciao {$nameSafe},</p>"
                      . "<p>E' stato caricato un nuovo documento nella tua area personale:</p>"
                      . "<p><strong>{$docSafe}</strong></p>"
                      . "<p><a href=\"{$loginUrl}\">Accedi al portale per consultarlo</a></p>";
                $text = "Ciao {$fullName},\n\nNuovo documento disponibile: {$documentName}\n\nAccedi: {$loginUrl}";
                Mailer::sendToEmployee((int) $employee['id'], "Nuovo documento disponibile: {$documentName}", $html, $text);
            }
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] mail error: ' . $e->getMessage());
        }
    }

    /**
     * Conta documenti personali visibili al dipendente non ancora scaricati.
     * Usato per il badge nella sidebar.
     */
    public static function getUnreadCountForEmployee(int $employeeId): int
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM employee_documents ed
                 WHERE ed.employee_id = ?
                   AND ed.visible_to_employee = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM employee_document_downloads dd
                       WHERE dd.employee_document_id = ed.id
                         AND dd.user_type = 'employee'
                         AND dd.user_id = ?
                   )",
                [$employeeId, $employeeId]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }
}
