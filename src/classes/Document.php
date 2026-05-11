<?php
/**
 * Classe Document - Gestione documenti
 * PAManager - Comune
 *
 * Funzionalità sicurezza:
 * - Download logging dettagliato
 * - Rate limiting per download
 * - Watermark PDF
 * - Verifica accesso one-to-one
 */

class Document
{
    /**
     * Tipi documento disponibili
     */
    public const TYPES = [
        'payslip' => 'Busta Paga',
        'cud' => 'CUD/CU',
        'other' => 'Altro'
    ];

    /**
     * Libreria FPDI per watermark (se disponibile)
     */
    private static ?bool $fpdiAvailable = null;

    /**
     * Ottiene un documento per ID
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT d.*, e.fiscal_code, e.first_name, e.last_name,
                    CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
                    u.name AS uploaded_by_name
             FROM documents d
             JOIN employees e ON d.employee_id = e.id
             JOIN users u ON d.uploaded_by = u.id
             WHERE d.id = ?",
            [$id]
        );
    }

    /**
     * Ottiene documenti di un dipendente
     */
    public static function getByEmployee(int $employeeId, ?int $year = null, ?int $month = null, ?string $type = null): array
    {
        $sql = "SELECT d.*, u.name AS uploaded_by_name
                FROM documents d
                JOIN users u ON d.uploaded_by = u.id
                WHERE d.employee_id = ?";
        $params = [$employeeId];

        if ($year !== null) {
            $sql .= " AND d.year = ?";
            $params[] = $year;
        }

        if ($month !== null) {
            $sql .= " AND d.month = ?";
            $params[] = $month;
        }

        if ($type !== null) {
            $sql .= " AND d.type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY d.year DESC, d.month DESC, d.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Lista tutti i documenti
     */
    public static function getAll(?int $year = null, ?int $month = null, ?string $type = null, string $search = ''): array
    {
        $sql = "SELECT d.*,
                       e.fiscal_code, e.first_name, e.last_name,
                       CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
                       u.name AS uploaded_by_name
                FROM documents d
                JOIN employees e ON d.employee_id = e.id
                JOIN users u ON d.uploaded_by = u.id
                WHERE d.company_id = ?";
        $params = [class_exists('Tenant') ? Tenant::currentCompanyId() : 1];

        if ($year !== null) {
            $sql .= " AND d.year = ?";
            $params[] = $year;
        }

        if ($month !== null) {
            $sql .= " AND d.month = ?";
            $params[] = $month;
        }

        if ($type !== null) {
            $sql .= " AND d.type = ?";
            $params[] = $type;
        }

        if (!empty($search)) {
            $sql .= " AND (e.fiscal_code LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR d.title LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        $sql .= " ORDER BY d.year DESC, d.month DESC, e.last_name ASC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Conta documenti
     */
    public static function count(?int $employeeId = null): int
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        if ($employeeId !== null) {
            return Database::count('documents', 'company_id = ? AND employee_id = ?', [$cid, $employeeId]);
        }
        return Database::count('documents', 'company_id = ?', [$cid]);
    }

    /**
     * Carica un documento
     */
    public static function upload(array $file, array $data): array
    {
        // Validazione dati
        if (empty($data['employee_id'])) {
            return ['success' => false, 'error' => 'Dipendente non specificato'];
        }

        if (empty($data['type']) || !isset(self::TYPES[$data['type']])) {
            return ['success' => false, 'error' => 'Tipo documento non valido'];
        }

        if (empty($data['month']) || $data['month'] < 1 || $data['month'] > 12) {
            return ['success' => false, 'error' => 'Mese non valido'];
        }

        if (empty($data['year']) || $data['year'] < 2000 || $data['year'] > 2100) {
            return ['success' => false, 'error' => 'Anno non valido'];
        }

        // Verifica dipendente
        $employee = Employee::getById((int) $data['employee_id']);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        // Validazione file
        $fileValidation = self::validateFile($file);
        if (!$fileValidation['valid']) {
            return ['success' => false, 'error' => $fileValidation['error']];
        }

        // Genera nome file sicuro
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = bin2hex(random_bytes(16)) . '.' . strtolower($extension);

        // Crea directory
        $directory = DOCUMENTS_PATH . '/' . $data['employee_id'] . '/' . $data['year'] . '/' . $data['month'];
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName;

        // Sposta file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio del file'];
        }

        $user = Auth::getUser();

        // Genera titolo automatico se non fornito o vuoto
        $title = !empty(trim($data['title'] ?? ''))
            ? trim($data['title'])
            : self::generateTitle($data['type'], $data['month'], $data['year']);

        try {
            // company_id eredita dall'employee per coerenza tra tenant
            $__emp = Database::fetchOne("SELECT company_id FROM employees WHERE id = ?", [(int)$data['employee_id']]);
            $id = Database::insert('documents', [
                'company_id'  => (int)($__emp['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1)),
                'employee_id' => (int) $data['employee_id'],
                'type' => $data['type'],
                'title' => $title,
                'description' => $data['description'] ?? null,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'original_name' => $file['name'],
                'file_size' => $file['size'],
                'mime_type' => $fileValidation['mime_type'],
                'month' => (int) $data['month'],
                'year' => (int) $data['year'],
                'uploaded_by' => $user['id']
            ]);

            self::logAction('document_uploaded', $id, null, [
                'employee_id' => $data['employee_id'],
                'type' => $data['type'],
                'original_name' => $file['name']
            ]);

            // Invia push notification al dipendente
            try {
                $monthNames = [
                    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
                    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
                    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
                ];
                $period = $monthNames[(int)$data['month']] . ' ' . $data['year'];

                // Mappa tipi documento per push notification
                $typeMap = [
                    'payslip' => 'busta_paga',
                    'cud' => 'cud',
                    'other' => 'altro'
                ];
                $pushType = $typeMap[$data['type']] ?? 'altro';

                error_log('[Document] Invio push a employee #' . $data['employee_id'] . ' per ' . $pushType . ' ' . $period);
                $result = PushNotification::notifyNewDocument(
                    (int) $data['employee_id'],
                    $pushType,
                    $period
                );
                error_log('[Document] Push result: ' . json_encode($result));
            } catch (Exception $pushErr) {
                error_log('[Document] Push notification error: ' . $pushErr->getMessage());
            }

            // Invia email di notifica al dipendente
            try {
                if (!empty($employee['email']) && class_exists('Mailer') && Mailer::isConfigured()) {
                    $typeLabel = self::TYPES[$data['type']] ?? $data['type'];
                    $loginUrl = function_exists('buildPublicUrl')
                        ? buildPublicUrl('/auth/login-employee.php')
                        : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login-employee.php' : '');
                    $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                    $nameSafe = htmlspecialchars($fullName);
                    $titleSafe = htmlspecialchars($title);
                    $typeSafe = htmlspecialchars($typeLabel);
                    $periodSafe = htmlspecialchars($period);

                    $html = "<p>Ciao {$nameSafe},</p>"
                          . "<p>È stato caricato un nuovo documento nella tua area personale:</p>"
                          . "<ul>"
                          . "<li><strong>Tipo:</strong> {$typeSafe}</li>"
                          . "<li><strong>Titolo:</strong> {$titleSafe}</li>"
                          . "<li><strong>Periodo:</strong> {$periodSafe}</li>"
                          . "</ul>"
                          . "<p><a href=\"{$loginUrl}\">Accedi al portale per consultarlo</a></p>";
                    $text = "Ciao {$fullName},\n\nNuovo documento disponibile:\n"
                          . "- Tipo: {$typeLabel}\n"
                          . "- Titolo: {$title}\n"
                          . "- Periodo: {$period}\n\n"
                          . "Accedi: {$loginUrl}";

                    Mailer::send($employee['email'], $fullName, "Nuovo documento disponibile: {$typeLabel} {$period}", $html, $text);
                }
            } catch (Exception $mailErr) {
                error_log('[Document] Email notification error: ' . $mailErr->getMessage());
            }

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            // Elimina file in caso di errore DB
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante il salvataggio del documento'];
        }
    }

    /**
     * Aggiorna metadati documento
     */
    public static function update(int $id, array $data): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        $updateData = [];

        if (isset($data['title']) && !empty($data['title'])) {
            $updateData['title'] = $data['title'];
        }

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'] ?: null;
        }

        if (isset($data['type']) && isset(self::TYPES[$data['type']])) {
            $updateData['type'] = $data['type'];
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('documents', $updateData, 'id = ?', [$id]);
            self::logAction('document_updated', $id, $document, $updateData);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Elimina un documento
     */
    public static function delete(int $id): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        try {
            // Elimina file fisico
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }

            Database::delete('documents', 'id = ?', [$id]);

            self::logAction('document_deleted', $id, $document, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    /**
     * Scarica un documento (verifica accesso con sicurezza avanzata)
     *
     * @param int $id ID documento
     * @param bool $applyWatermark Applicare watermark (solo PDF)
     * @return array Risultato operazione
     */
    public static function download(int $id, bool $applyWatermark = true): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        // Verifica accesso
        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        $userType = 'unknown';
        $userId = 0;

        if ($user) {
            // Admin e commercialista possono vedere tutto
            $userType = $user['role'];
            $userId = $user['id'];
        } elseif ($employee) {
            $userType = 'employee';
            $userId = $employee['id'];

            // Dipendente può vedere solo i propri documenti (one-to-one)
            if ($document['employee_id'] !== $employee['id']) {
                // Log tentativo di accesso non autorizzato
                AuditLog::logUnauthorizedAccess('document', [
                    'document_id' => $id,
                    'employee_id' => $employee['id'],
                    'owner_id' => $document['employee_id'],
                    'action' => 'download_attempt'
                ]);
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        // Verifica rate limiting
        $rateCheck = checkDownloadRateLimit($userId, $userType);
        if (!$rateCheck['allowed']) {
            AuditLog::log(
                AuditLog::ACTION_RATE_LIMIT_EXCEEDED,
                $userType,
                $userId,
                'document',
                $id,
                null,
                ['action' => 'download', 'limit_type' => 'hourly'],
                AuditLog::SEVERITY_WARNING
            );
            return ['success' => false, 'error' => $rateCheck['message']];
        }

        if (!file_exists($document['file_path'])) {
            return ['success' => false, 'error' => 'File non trovato sul server'];
        }

        // Traccia download nella tabella dedicata
        self::trackDownload($id, $userType, $userId);

        // Log audit dettagliato
        AuditLog::logDocumentDownload($userType, $userId, $id, [
            'title' => $document['title'],
            'type' => $document['type'],
            'employee_id' => $document['employee_id'],
            'file_size' => $document['file_size'],
            'mime_type' => $document['mime_type']
        ]);

        // Prepara percorso file (con eventuale watermark)
        $filePath = $document['file_path'];
        $tempFile = null;

        // Applica watermark se è un PDF e richiesto
        if ($applyWatermark && $document['mime_type'] === 'application/pdf') {
            $watermarkResult = self::applyWatermark($document, $userType, $userId);
            if ($watermarkResult['success']) {
                $filePath = $watermarkResult['file_path'];
                $tempFile = $watermarkResult['temp_file'] ?? null;
            }
        }

        return [
            'success' => true,
            'document' => $document,
            'file_path' => $filePath,
            'temp_file' => $tempFile,
            'watermarked' => $filePath !== $document['file_path']
        ];
    }

    /**
     * Traccia download documento nel database
     */
    private static function trackDownload(int $documentId, string $userType, int $userId): void
    {
        try {
            Database::insert('document_downloads', [
                'document_id' => $documentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'ip_address' => getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Tabella potrebbe non esistere ancora
            error_log('Document download tracking failed: ' . $e->getMessage());
        }
    }

    /**
     * Applica watermark a PDF
     * Aggiunge: nome utente, data/ora, IP (trasparente)
     */
    private static function applyWatermark(array $document, string $userType, int $userId): array
    {
        // Verifica se FPDI è disponibile
        if (!self::isFpdiAvailable()) {
            // Fallback: restituisci file originale
            return [
                'success' => false,
                'file_path' => $document['file_path'],
                'reason' => 'FPDI library not available'
            ];
        }

        try {
            // Ottieni info utente per watermark
            $userName = 'Unknown';
            if ($userType === 'employee') {
                $emp = Employee::getById($userId);
                if ($emp) {
                    $userName = $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['fiscal_code'] . ')';
                }
            } else {
                $user = Auth::getUserById($userId);
                if ($user) {
                    $userName = $user['name'] . ' (' . $user['role'] . ')';
                }
            }

            // Crea file temporaneo
            $tempDir = sys_get_temp_dir();
            $tempFile = $tempDir . '/pamanager_' . bin2hex(random_bytes(8)) . '.pdf';

            // Testo watermark
            $watermarkText = sprintf(
                "Scaricato da: %s | Data: %s | IP: %s | Doc ID: %d",
                $userName,
                date('d/m/Y H:i:s'),
                getClientIp(),
                $document['id']
            );

            // Applica watermark usando FPDI/FPDF
            $result = self::addPdfWatermark(
                $document['file_path'],
                $tempFile,
                $watermarkText
            );

            if ($result) {
                return [
                    'success' => true,
                    'file_path' => $tempFile,
                    'temp_file' => $tempFile
                ];
            }

            return [
                'success' => false,
                'file_path' => $document['file_path'],
                'reason' => 'Watermark application failed'
            ];

        } catch (Exception $e) {
            error_log('Watermark error: ' . $e->getMessage());
            return [
                'success' => false,
                'file_path' => $document['file_path'],
                'reason' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica disponibilità FPDI
     */
    private static function isFpdiAvailable(): bool
    {
        if (self::$fpdiAvailable === null) {
            self::$fpdiAvailable = class_exists('setasign\\Fpdi\\Fpdi') ||
                                   class_exists('FPDI') ||
                                   class_exists('setasign\\Fpdi\\TcpdfFpdi');
        }
        return self::$fpdiAvailable;
    }

    /**
     * Aggiunge watermark a PDF usando FPDI
     */
    private static function addPdfWatermark(string $inputPath, string $outputPath, string $text): bool
    {
        // Tenta diversi namespace FPDI
        $fpdiClass = null;

        if (class_exists('setasign\\Fpdi\\Fpdi')) {
            $fpdiClass = 'setasign\\Fpdi\\Fpdi';
        } elseif (class_exists('FPDI')) {
            $fpdiClass = 'FPDI';
        } elseif (class_exists('setasign\\Fpdi\\TcpdfFpdi')) {
            $fpdiClass = 'setasign\\Fpdi\\TcpdfFpdi';
        }

        if (!$fpdiClass) {
            return false;
        }

        try {
            $pdf = new $fpdiClass();
            $pageCount = $pdf->setSourceFile($inputPath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);

                // Aggiungi watermark in basso
                $pdf->SetFont('Helvetica', '', 6);
                $pdf->SetTextColor(128, 128, 128);

                // Posiziona in basso
                $pdf->SetXY(5, $size['height'] - 5);
                $pdf->Cell(0, 3, $text, 0, 0, 'L');

                // Watermark diagonale trasparente (se supportato)
                $pdf->SetTextColor(200, 200, 200);
                $pdf->SetFont('Helvetica', 'B', 40);

                // Ruota e posiziona al centro
                if (method_exists($pdf, 'Rotate')) {
                    $pdf->Rotate(45, $size['width'] / 2, $size['height'] / 2);
                    $pdf->SetXY($size['width'] / 4, $size['height'] / 2);
                    $pdf->Cell(0, 0, 'COPIA PERSONALE', 0, 0, 'C');
                    $pdf->Rotate(0);
                }
            }

            $pdf->Output($outputPath, 'F');
            return file_exists($outputPath);

        } catch (Exception $e) {
            error_log('PDF watermark error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottieni statistiche download per un documento
     */
    public static function getDownloadStats(int $documentId): array
    {
        try {
            $total = Database::fetchColumn(
                "SELECT COUNT(*) FROM document_downloads WHERE document_id = ?",
                [$documentId]
            );

            $recent = Database::fetchAll(
                "SELECT dd.*,
                        CASE WHEN dd.user_type = 'employee'
                             THEN CONCAT(e.last_name, ' ', e.first_name)
                             ELSE u.name END as user_name
                 FROM document_downloads dd
                 LEFT JOIN employees e ON dd.user_type = 'employee' AND dd.user_id = e.id
                 LEFT JOIN users u ON dd.user_type != 'employee' AND dd.user_id = u.id
                 WHERE dd.document_id = ?
                 ORDER BY dd.downloaded_at DESC
                 LIMIT 20",
                [$documentId]
            );

            return [
                'total_downloads' => (int) $total,
                'recent_downloads' => $recent
            ];
        } catch (Exception $e) {
            return ['total_downloads' => 0, 'recent_downloads' => []];
        }
    }

    /**
     * Verifica se un dipendente ha accesso a un documento (one-to-one)
     */
    public static function canEmployeeAccess(int $documentId, int $employeeId): bool
    {
        $document = self::getById($documentId);
        if (!$document) {
            return false;
        }
        return $document['employee_id'] === $employeeId;
    }

    /**
     * Valida file upload
     */
    private static function validateFile(array $file): array
    {
        // Verifica errori upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => self::getUploadError($file['error'])];
        }

        // Verifica dimensione
        if ($file['size'] > MAX_FILE_SIZE) {
            $maxMb = MAX_FILE_SIZE / 1024 / 1024;
            return ['valid' => false, 'error' => "File troppo grande. Massimo {$maxMb}MB"];
        }

        // Verifica estensione
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            return ['valid' => false, 'error' => 'Estensione file non consentita'];
        }

        // Verifica MIME type reale
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES)) {
            return ['valid' => false, 'error' => 'Tipo file non consentito'];
        }

        return ['valid' => true, 'mime_type' => $mimeType];
    }

    /**
     * Ottiene messaggio errore upload
     */
    private static function getUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File troppo grande',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nessun file caricato',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Errore scrittura su disco',
            default => 'Errore durante l\'upload'
        };
    }

    /**
     * Genera titolo automatico
     */
    private static function generateTitle(string $type, int $month, int $year): string
    {
        $monthNames = [
            1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
            5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
            9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
        ];

        $typeName = self::TYPES[$type] ?? 'Documento';
        $monthName = $monthNames[$month] ?? 'Mese';

        // Formato: "Busta Paga - Marzo 2026"
        return "{$typeName} - {$monthName} {$year}";
    }

    /**
     * Ottiene anni disponibili per un dipendente
     */
    public static function getAvailableYears(int $employeeId): array
    {
        return Database::fetchAll(
            "SELECT DISTINCT year FROM documents WHERE employee_id = ? ORDER BY year DESC",
            [$employeeId]
        );
    }

    /**
     * Log azioni usando AuditLog
     */
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

        // Mappa azioni legacy alle costanti AuditLog
        $auditAction = match($action) {
            'document_uploaded' => AuditLog::ACTION_DOCUMENT_UPLOAD,
            'document_updated' => 'document_update',
            'document_deleted' => AuditLog::ACTION_DOCUMENT_DELETE,
            'document_downloaded' => AuditLog::ACTION_DOCUMENT_DOWNLOAD,
            default => $action
        };

        AuditLog::logEntityChange(
            $auditAction,
            $userType,
            $userId,
            'document',
            $entityId,
            $oldValues,
            $newValues
        );
    }

    /**
     * Pulisci file temporanei watermark (chiamare dopo download)
     */
    public static function cleanupTempFile(?string $tempFile): void
    {
        if ($tempFile && file_exists($tempFile) && strpos($tempFile, 'pamanager_') !== false) {
            @unlink($tempFile);
        }
    }

    /**
     * Ottieni download giornalieri per report
     */
    public static function getDailyDownloadReport(int $days = 30): array
    {
        try {
            return Database::fetchAll(
                "SELECT DATE(downloaded_at) as date,
                        COUNT(*) as total,
                        COUNT(DISTINCT document_id) as unique_documents,
                        COUNT(DISTINCT CONCAT(user_type, '_', user_id)) as unique_users
                 FROM document_downloads
                 WHERE downloaded_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(downloaded_at)
                 ORDER BY date DESC",
                [$days]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Ottieni download sospetti (anomalie)
     */
    public static function getSuspiciousDownloads(): array
    {
        try {
            // Utenti con più di 20 download nell'ultima ora
            return Database::fetchAll(
                "SELECT user_type, user_id, COUNT(*) as download_count,
                        MIN(downloaded_at) as first_download,
                        MAX(downloaded_at) as last_download
                 FROM document_downloads
                 WHERE downloaded_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 GROUP BY user_type, user_id
                 HAVING download_count > 20
                 ORDER BY download_count DESC"
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Verifica se un documento è stato scaricato da un dipendente
     */
    public static function isDownloadedByEmployee(int $documentId, int $employeeId): bool
    {
        try {
            $count = Database::fetchColumn(
                "SELECT COUNT(*) FROM document_downloads
                 WHERE document_id = ? AND user_type = 'employee' AND user_id = ?",
                [$documentId, $employeeId]
            );
            return (int) $count > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Conta documenti non scaricati per un dipendente
     */
    public static function getUnreadCountForEmployee(int $employeeId): int
    {
        try {
            return (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM documents d
                 WHERE d.employee_id = ?
                 AND NOT EXISTS (
                     SELECT 1 FROM document_downloads dd
                     WHERE dd.document_id = d.id
                     AND dd.user_type = 'employee'
                     AND dd.user_id = ?
                 )",
                [$employeeId, $employeeId]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Ottieni documenti non scaricati per un dipendente
     */
    public static function getUnreadDocumentsForEmployee(int $employeeId, int $limit = 10): array
    {
        try {
            return Database::fetchAll(
                "SELECT d.*,
                        CONCAT(e.last_name, ' ', e.first_name) AS employee_name
                 FROM documents d
                 JOIN employees e ON d.employee_id = e.id
                 WHERE d.employee_id = ?
                 AND NOT EXISTS (
                     SELECT 1 FROM document_downloads dd
                     WHERE dd.document_id = d.id
                     AND dd.user_type = 'employee'
                     AND dd.user_id = ?
                 )
                 ORDER BY d.created_at DESC
                 LIMIT ?",
                [$employeeId, $employeeId, $limit]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Ottieni documenti di un dipendente con stato download
     */
    public static function getByEmployeeWithStatus(int $employeeId, ?int $year = null, ?int $month = null, ?string $type = null): array
    {
        $sql = "SELECT d.*,
                       CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
                       u.name AS uploaded_by_name,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM document_downloads dd
                           WHERE dd.document_id = d.id
                           AND dd.user_type = 'employee'
                           AND dd.user_id = ?
                       ) THEN 1 ELSE 0 END AS is_downloaded,
                       (SELECT MAX(dd.downloaded_at) FROM document_downloads dd
                        WHERE dd.document_id = d.id
                        AND dd.user_type = 'employee'
                        AND dd.user_id = ?) AS last_download_at
                FROM documents d
                JOIN employees e ON d.employee_id = e.id
                JOIN users u ON d.uploaded_by = u.id
                WHERE d.employee_id = ?";
        $params = [$employeeId, $employeeId, $employeeId];

        if ($year !== null) {
            $sql .= " AND d.year = ?";
            $params[] = $year;
        }

        if ($month !== null) {
            $sql .= " AND d.month = ?";
            $params[] = $month;
        }

        if ($type !== null) {
            $sql .= " AND d.type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY d.year DESC, d.month DESC, d.created_at DESC";

        try {
            return Database::fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }
}
