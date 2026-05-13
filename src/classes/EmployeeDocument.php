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
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            Database::delete('employee_documents', 'id = ?', [$id]);
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

    private static function notifyEmployee(array $employee, string $documentName): void
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
            if (!empty($employee['email']) && class_exists('Mailer') && Mailer::isConfigured()) {
                $loginUrl = function_exists('buildPublicUrl')
                    ? buildPublicUrl('/auth/login-employee.php')
                    : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login-employee.php' : '');
                $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                $nameSafe = htmlspecialchars($fullName);
                $docSafe = htmlspecialchars($documentName);
                $html = "<p>Ciao {$nameSafe},</p>"
                      . "<p>E' stato caricato un nuovo documento nella tua area personale:</p>"
                      . "<p><strong>{$docSafe}</strong></p>"
                      . "<p><a href=\"{$loginUrl}\">Accedi al portale per consultarlo</a></p>";
                $text = "Ciao {$fullName},\n\nNuovo documento disponibile: {$documentName}\n\nAccedi: {$loginUrl}";
                Mailer::send($employee['email'], $fullName, "Nuovo documento disponibile: {$documentName}", $html, $text);
            }
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] mail error: ' . $e->getMessage());
        }
    }
}
