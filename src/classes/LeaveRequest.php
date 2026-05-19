<?php
/**
 * Classe LeaveRequest - Gestione richieste ferie/permessi
 * PAManager - Comune
 */

class LeaveRequest
{
    /**
     * Tipi di assenza disponibili
     */
    public const LEAVE_TYPES = [
        'ferie' => 'Ferie',
        'permesso' => 'Permesso',
        'malattia' => 'Malattia',
        'permesso_104' => 'Permesso L.104',
        'congedo_parentale' => 'Congedo Parentale',
        'congedo_separazione' => 'Congedo Separazione',
        'congedo_mestruale' => 'Congedo Mestruale',
        'altro' => 'Altro',
        'chiusura' => 'Chiusura aziendale'
    ];

    /**
     * Stati richiesta
     */
    public const STATUSES = [
        'pending' => 'In Attesa',
        'approved' => 'Approvata',
        'rejected' => 'Rifiutata',
        'cancelled' => 'Annullata'
    ];

    /**
     * Ottiene una richiesta per ID
     */
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT lr.*,
                    e.first_name, e.last_name, e.fiscal_code, e.email AS employee_email,
                    e.department_id,
                    d.name AS department_name, d.code AS department_code,
                    u.name AS approved_by_name
             FROM leave_requests lr
             JOIN employees e ON lr.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN users u ON lr.approved_by = u.id
             WHERE lr.id = ?",
            [$id]
        );
    }

    /**
     * Ottiene richieste per dipendente
     */
    public static function getByEmployee(int $employeeId, ?string $status = null, ?int $year = null): array
    {
        $sql = "SELECT lr.*,
                       u.name AS approved_by_name
                FROM leave_requests lr
                LEFT JOIN users u ON lr.approved_by = u.id
                WHERE lr.employee_id = ?";
        $params = [$employeeId];

        if ($status !== null) {
            $sql .= " AND lr.status = ?";
            $params[] = $status;
        }

        if ($year !== null) {
            $sql .= " AND YEAR(lr.start_date) = ?";
            $params[] = $year;
        }

        $sql .= " ORDER BY lr.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Ottiene richieste per reparto (per admin reparto)
     */
    public static function getByDepartment(int $departmentId, ?string $status = null, ?int $limit = null): array
    {
        $sql = "SELECT lr.*,
                       e.first_name, e.last_name, e.fiscal_code, e.photo_path,
                       u.name AS approved_by_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN users u ON lr.approved_by = u.id
                WHERE e.department_id = ?";
        $params = [$departmentId];

        if ($status !== null) {
            $sql .= " AND lr.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY lr.created_at DESC";

        if ($limit !== null) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }

        return Database::fetchAll($sql, $params);
    }

    /**
     * Ottiene tutte le richieste approvate (per commercialista)
     */
    public static function getApproved(?int $year = null, ?int $month = null): array
    {
        $sql = "SELECT lr.*,
                       e.first_name, e.last_name, e.fiscal_code, e.photo_path,
                       d.name AS department_name, d.code AS department_code,
                       u.name AS approved_by_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN users u ON lr.approved_by = u.id
                WHERE lr.status = 'approved'";
        $params = [];

        if ($year !== null) {
            $sql .= " AND YEAR(lr.start_date) = ?";
            $params[] = $year;
        }

        if ($month !== null) {
            $sql .= " AND MONTH(lr.start_date) = ?";
            $params[] = $month;
        }

        $sql .= " ORDER BY lr.start_date DESC, e.last_name, e.first_name";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Ottiene tutte le richieste (per admin)
     */
    public static function getAll(?string $status = null, ?int $departmentId = null): array
    {
        $sql = "SELECT lr.*,
                       e.first_name, e.last_name, e.fiscal_code, e.photo_path,
                       d.name AS department_name, d.code AS department_code,
                       u.name AS approved_by_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN users u ON lr.approved_by = u.id
                WHERE e.company_id = ?";
        $params = [class_exists('Tenant') ? Tenant::currentCompanyId() : 1];

        if ($status !== null) {
            $sql .= " AND lr.status = ?";
            $params[] = $status;
        }

        if ($departmentId !== null) {
            $sql .= " AND e.department_id = ?";
            $params[] = $departmentId;
        }

        $sql .= " ORDER BY lr.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Conta richieste pending per reparto
     */
    public static function countPendingByDepartment(int $departmentId): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM leave_requests lr
             JOIN employees e ON lr.employee_id = e.id
             WHERE e.department_id = ? AND lr.status = 'pending'",
            [$departmentId]
        );
    }

    /**
     * Crea una nuova richiesta
     */
    /**
     * Crea una richiesta inserita direttamente dall'admin, gia approvata.
     * Bypassa lo stato 'pending' (l'admin sta registrando un fatto avvenuto).
     */
    public static function createByAdmin(array $data, int $adminUserId): array
    {
        $result = self::create($data);
        if (!$result['success']) return $result;
        try {
            Database::update('leave_requests', [
                'status'           => 'approved',
                'approved_by'      => $adminUserId,
                'approved_at'      => date('Y-m-d H:i:s'),
                'created_by_admin' => 1,
            ], 'id = ?', [$result['id']]);
        } catch (Throwable $e) {
            error_log('createByAdmin update failed: ' . $e->getMessage());
        }
        return $result;
    }

    public static function create(array $data): array
    {
        // Validazioni
        if (empty($data['employee_id'])) {
            return ['success' => false, 'error' => 'Dipendente obbligatorio'];
        }

        if (empty($data['leave_type']) || !isset(self::LEAVE_TYPES[$data['leave_type']])) {
            return ['success' => false, 'error' => 'Tipo assenza non valido'];
        }

        if (empty($data['start_date'])) {
            return ['success' => false, 'error' => 'Data inizio obbligatoria'];
        }

        if (empty($data['end_date'])) {
            return ['success' => false, 'error' => 'Data fine obbligatoria'];
        }

        if ($data['start_date'] > $data['end_date']) {
            return ['success' => false, 'error' => 'La data di fine deve essere uguale o successiva alla data di inizio'];
        }

        if (empty($data['reason'])) {
            return ['success' => false, 'error' => 'Motivazione obbligatoria'];
        }

        // Verifica che il dipendente esista
        $employee = Employee::getById((int) $data['employee_id']);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        // Verifica sovrapposizioni
        if (self::hasOverlap((int) $data['employee_id'], $data['start_date'], $data['end_date'])) {
            return ['success' => false, 'error' => 'Esiste già una richiesta per questo periodo'];
        }

        $isFullDay = isset($data['is_full_day']) ? (bool) $data['is_full_day'] : true;

        try {
            // Resolve company_id dall'employee (autoritativo: ogni leave appartiene alla company dell'employee)
            $__emp = Database::fetchOne("SELECT company_id FROM employees WHERE id = ?", [(int)$data['employee_id']]);
            $id = Database::insert('leave_requests', [
                'company_id'  => (int)($__emp['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1)),
                'employee_id' => (int) $data['employee_id'],
                'leave_type' => $data['leave_type'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_full_day' => $isFullDay ? 1 : 0,
                'start_time' => !$isFullDay && !empty($data['start_time']) ? $data['start_time'] : null,
                'end_time' => !$isFullDay && !empty($data['end_time']) ? $data['end_time'] : null,
                'reason' => trim($data['reason']),
                'notes' => trim($data['notes'] ?? '') ?: null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                'protocol_number' => isset($data['protocol_number']) && trim((string)$data['protocol_number']) !== ''
                    ? trim((string)$data['protocol_number'])
                    : null,
                'status' => 'pending'
            ]);

            self::logAction('leave_request_created', $id, null, $data);

            // Notifica admin_reparto del dipendente
            self::notifyDepartmentAdmins($employee, $data);

            return ['success' => true, 'id' => $id];
        } catch (Throwable $e) {
            $detail = get_class($e) . ' - ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            error_log('LeaveRequest::create failed: ' . $detail);
            @file_put_contents(
                ROOT_PATH . '/logs/leave_debug.log',
                '[' . date('Y-m-d H:i:s') . '] ' . $detail . "\n",
                FILE_APPEND
            );
            return ['success' => false, 'error' => 'Errore durante la creazione della richiesta'];
        }
    }

    /**
     * Approva una richiesta
     */
    public static function approve(int $id, int $approvedBy): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Solo le richieste in attesa possono essere approvate'];
        }

        try {
            Database::update('leave_requests', [
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);

            self::logAction('leave_request_approved', $id, ['status' => 'pending'], ['status' => 'approved']);

            // Crea notifica per il dipendente
            self::createNotification($request['employee_id'], 'leave_approved', $request);

            // Notifica commercialisti della richiesta approvata
            self::notifyAccountants($request);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'approvazione'];
        }
    }

    /**
     * Rifiuta una richiesta
     */
    public static function reject(int $id, int $rejectedBy, string $reason = ''): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Solo le richieste in attesa possono essere rifiutate'];
        }

        try {
            Database::update('leave_requests', [
                'status' => 'rejected',
                'approved_by' => $rejectedBy,
                'approved_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => trim($reason) ?: null
            ], 'id = ?', [$id]);

            self::logAction('leave_request_rejected', $id, ['status' => 'pending'], [
                'status' => 'rejected',
                'rejection_reason' => $reason
            ]);

            // Crea notifica per il dipendente
            self::createNotification($request['employee_id'], 'leave_rejected', $request, $reason);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante il rifiuto'];
        }
    }

    /**
     * Annulla una richiesta (da parte del dipendente)
     */
    public static function cancel(int $id, int $employeeId): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        if ($request['employee_id'] !== $employeeId) {
            return ['success' => false, 'error' => 'Non puoi annullare richieste di altri dipendenti'];
        }

        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Solo le richieste in attesa possono essere annullate'];
        }

        try {
            Database::update('leave_requests', [
                'status' => 'cancelled'
            ], 'id = ?', [$id]);

            self::logAction('leave_request_cancelled', $id, ['status' => 'pending'], ['status' => 'cancelled']);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'annullamento'];
        }
    }

    /**
     * Elimina una richiesta
     */
    public static function delete(int $id): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        try {
            // Elimina allegato se presente
            if (!empty($request['attachment_path']) && file_exists($request['attachment_path'])) {
                unlink($request['attachment_path']);
            }

            Database::delete('leave_requests', 'id = ?', [$id]);
            self::logAction('leave_request_deleted', $id, $request, null);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    /**
     * Aggiornamento forzato lato admin: tipo, date, orari, motivazione, note, stato.
     * Salta il check delle sovrapposizioni e l'approvazione (admin sa cosa sta facendo).
     */
    public static function adminUpdate(int $id, array $data): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }

        $update = [];

        if (isset($data['leave_type']) && isset(self::LEAVE_TYPES[$data['leave_type']])) {
            $update['leave_type'] = $data['leave_type'];
        }
        if (!empty($data['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date'])) {
            $update['start_date'] = $data['start_date'];
        }
        if (!empty($data['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['end_date'])) {
            $update['end_date'] = $data['end_date'];
        }
        if (isset($data['is_full_day'])) {
            $update['is_full_day'] = !empty($data['is_full_day']) ? 1 : 0;
        }
        if (array_key_exists('start_time', $data)) {
            $update['start_time'] = $data['start_time'] ?: null;
        }
        if (array_key_exists('end_time', $data)) {
            $update['end_time'] = $data['end_time'] ?: null;
        }
        if (isset($data['reason'])) {
            $update['reason'] = trim($data['reason']);
        }
        if (array_key_exists('notes', $data)) {
            $update['notes'] = $data['notes'] !== '' ? $data['notes'] : null;
        }
        if (isset($data['status']) && isset(self::STATUSES[$data['status']])) {
            $update['status'] = $data['status'];
        }

        if (empty($update)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('leave_requests', $update, 'id = ?', [$id]);
            self::logAction('leave_request_admin_update', $id, $request, $update);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento: ' . $e->getMessage()];
        }
    }

    /**
     * Verifica sovrapposizioni di date
     */
    public static function hasOverlap(int $employeeId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM leave_requests
                WHERE employee_id = ?
                  AND status NOT IN ('rejected', 'cancelled')
                  AND ((start_date <= ? AND end_date >= ?)
                       OR (start_date <= ? AND end_date >= ?)
                       OR (start_date >= ? AND end_date <= ?))";
        $params = [$employeeId, $endDate, $startDate, $startDate, $startDate, $startDate, $endDate];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        return (int) Database::fetchColumn($sql, $params) > 0;
    }

    /**
     * Calcola giorni lavorativi tra due date
     */
    public static function calculateWorkingDays(string $startDate, string $endDate): int
    {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day');

        $days = 0;
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        foreach ($period as $date) {
            // Escludi sabato (6) e domenica (0)
            if ($date->format('N') < 6) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Upload allegato
     */
    public static function uploadAttachment(array $file, int $requestId): array
    {
        $request = self::getById($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
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
        $directory = STORAGE_PATH . '/leave_requests';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName;

        // Elimina vecchio allegato
        if (!empty($request['attachment_path']) && file_exists($request['attachment_path'])) {
            unlink($request['attachment_path']);
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio'];
        }

        try {
            Database::update('leave_requests', [
                'attachment_path' => $filePath,
                'attachment_name' => $file['name']
            ], 'id = ?', [$requestId]);

            return ['success' => true, 'path' => $filePath];
        } catch (Exception $e) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Download allegato richiesta. Controlli accesso:
     *  - admin: tutto
     *  - admin_reparto: solo richieste del proprio reparto
     *  - employee: solo le proprie
     */
    public static function downloadAttachment(int $id): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }
        if (empty($request['attachment_path']) || !file_exists($request['attachment_path'])) {
            return ['success' => false, 'error' => 'Allegato non disponibile'];
        }

        $user = Auth::getUser();
        $employee = Auth::getEmployee();

        if ($user) {
            if ($user['role'] === 'admin_reparto') {
                $emp = Employee::getById((int) $request['employee_id']);
                if (!$emp || (int) ($emp['department_id'] ?? 0) !== (int) ($user['department_id'] ?? -1)) {
                    if (class_exists('AuditLog')) {
                        AuditLog::logUnauthorizedAccess('leave_attachment', [
                            'request_id' => $id, 'user_id' => $user['id'], 'reason' => 'department_scope'
                        ]);
                    }
                    return ['success' => false, 'error' => 'Accesso non autorizzato'];
                }
            }
            // admin e accountant: ok
        } elseif ($employee) {
            if ((int) $request['employee_id'] !== (int) $employee['id']) {
                if (class_exists('AuditLog')) {
                    AuditLog::logUnauthorizedAccess('leave_attachment', [
                        'request_id' => $id, 'employee_id' => $employee['id']
                    ]);
                }
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($request['attachment_path']) ?: 'application/octet-stream';

        return [
            'success' => true,
            'request' => $request,
            'file_path' => $request['attachment_path'],
            'filename' => $request['attachment_name'] ?? basename($request['attachment_path']),
            'mime' => $mime
        ];
    }

    /**
     * Salva i documenti malattia (numero protocollo + file certificato) su una richiesta esistente.
     * Verifica ownership: il dipendente puo' aggiornare solo le proprie richieste pending.
     */
    public static function saveSickDocs(int $requestId, int $employeeId, ?string $protocolNumber, ?array $certFile): array
    {
        $request = self::getById($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }
        if ((int) $request['employee_id'] !== $employeeId) {
            return ['success' => false, 'error' => 'Accesso non autorizzato'];
        }
        if ($request['leave_type'] !== 'malattia') {
            return ['success' => false, 'error' => 'I documenti sono richiesti solo per le malattie'];
        }
        if (!in_array($request['status'], ['pending', 'approved'], true)) {
            return ['success' => false, 'error' => 'Non puoi aggiornare richieste in questo stato'];
        }

        $update = [];

        $protocolNumber = $protocolNumber !== null ? trim($protocolNumber) : null;
        if ($protocolNumber !== null && $protocolNumber !== '') {
            $update['protocol_number'] = $protocolNumber;
        }

        if (is_array($certFile) && isset($certFile['error']) && $certFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($certFile['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Errore durante l\'upload del certificato'];
            }
            if ($certFile['size'] > MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'Certificato troppo grande'];
            }
            $ext = strtolower(pathinfo($certFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXTENSIONS)) {
                return ['success' => false, 'error' => 'Tipo file certificato non consentito'];
            }
            $dir = STORAGE_PATH . '/leave_requests';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $fileName = 'cert_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $path = $dir . '/' . $fileName;
            if (!move_uploaded_file($certFile['tmp_name'], $path)) {
                return ['success' => false, 'error' => 'Errore salvataggio certificato'];
            }
            if (!empty($request['certificate_path']) && file_exists($request['certificate_path'])) {
                @unlink($request['certificate_path']);
            }
            $update['certificate_path'] = $path;
            $update['certificate_name'] = $certFile['name'];
        }

        if (empty($update)) {
            return ['success' => false, 'error' => 'Nessun documento fornito'];
        }

        Database::update('leave_requests', $update, 'id = ?', [$requestId]);
        self::logAction('leave_sick_docs_updated', $requestId, $request, array_keys($update));
        return ['success' => true];
    }

    /**
     * Lista richieste di malattia approvate/pending senza protocollo o certificato da piu' di N ore.
     * Scope tenant. Esclude righe con certificate_waived = 1 quando manca solo il certificato:
     * se manca anche il protocollo, viene comunque inclusa.
     */
    public static function sickPendingDocs(int $hoursOld = 24, ?int $employeeId = null): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT lr.*, e.first_name, e.last_name
                FROM leave_requests lr
                JOIN employees e ON e.id = lr.employee_id
                WHERE lr.company_id = ?
                  AND lr.leave_type = 'malattia'
                  AND lr.status IN ('pending','approved')
                  AND (
                        (lr.protocol_number IS NULL OR lr.protocol_number = '')
                        OR (
                            (lr.certificate_path IS NULL OR lr.certificate_path = '')
                            AND COALESCE(lr.certificate_waived, 0) = 0
                        )
                      )
                  AND lr.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$cid, $hoursOld];
        if ($employeeId !== null) {
            $sql .= " AND lr.employee_id = ?";
            $params[] = $employeeId;
        }
        $sql .= " ORDER BY lr.created_at ASC";
        return Database::fetchAll($sql, $params);
    }

    /**
     * Versione admin di saveSickDocs: nessun check di ownership, ma verifica scope tenant
     * e che il tipo sia malattia. Permette anche di sovrascrivere documenti esistenti.
     */
    public static function adminSaveSickDocs(int $requestId, ?string $protocolNumber, ?array $certFile): array
    {
        $request = self::getById($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }
        if (class_exists('Tenant')) {
            $cid = Tenant::currentCompanyId();
            if ((int) $request['company_id'] !== (int) $cid) {
                return ['success' => false, 'error' => 'Richiesta non appartiene all\'azienda attiva'];
            }
        }
        if ($request['leave_type'] !== 'malattia') {
            return ['success' => false, 'error' => 'Operazione valida solo per richieste di malattia'];
        }

        $update = [];
        $protocolNumber = $protocolNumber !== null ? trim($protocolNumber) : null;
        if ($protocolNumber !== null && $protocolNumber !== '') {
            $update['protocol_number'] = $protocolNumber;
        }

        if (is_array($certFile) && isset($certFile['error']) && $certFile['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($certFile['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Errore upload certificato'];
            }
            if ($certFile['size'] > MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'Certificato troppo grande'];
            }
            $ext = strtolower(pathinfo($certFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXTENSIONS)) {
                return ['success' => false, 'error' => 'Tipo file non consentito'];
            }
            $dir = STORAGE_PATH . '/leave_requests';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $fileName = 'cert_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $path = $dir . '/' . $fileName;
            if (!move_uploaded_file($certFile['tmp_name'], $path)) {
                return ['success' => false, 'error' => 'Errore salvataggio certificato'];
            }
            if (!empty($request['certificate_path']) && file_exists($request['certificate_path'])) {
                @unlink($request['certificate_path']);
            }
            $update['certificate_path'] = $path;
            $update['certificate_name'] = $certFile['name'];
        }

        if (empty($update)) {
            return ['success' => false, 'error' => 'Nessun documento fornito'];
        }

        Database::update('leave_requests', $update, 'id = ?', [$requestId]);
        self::logAction('leave_sick_docs_admin_updated', $requestId, $request, array_keys($update));
        return ['success' => true];
    }

    /**
     * Marca/toglie il flag "certificato non richiesto" per una richiesta malattia.
     * Solo admin/admin_reparto. Scope tenant verificato.
     */
    public static function setCertificateWaived(int $requestId, bool $waived): array
    {
        $request = self::getById($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }
        if (class_exists('Tenant')) {
            $cid = Tenant::currentCompanyId();
            if ((int) $request['company_id'] !== (int) $cid) {
                return ['success' => false, 'error' => 'Richiesta non appartiene all\'azienda attiva'];
            }
        }
        if ($request['leave_type'] !== 'malattia') {
            return ['success' => false, 'error' => 'Operazione valida solo per richieste di malattia'];
        }
        Database::update('leave_requests', ['certificate_waived' => $waived ? 1 : 0], 'id = ?', [$requestId]);
        self::logAction($waived ? 'leave_sick_cert_waived' : 'leave_sick_cert_required', $requestId, $request, []);
        return ['success' => true];
    }

    /**
     * Download del certificato medico. Riusa controlli accesso simili a downloadAttachment.
     */
    public static function downloadCertificate(int $id): array
    {
        $request = self::getById($id);
        if (!$request) {
            return ['success' => false, 'error' => 'Richiesta non trovata'];
        }
        if (empty($request['certificate_path']) || !file_exists($request['certificate_path'])) {
            return ['success' => false, 'error' => 'Certificato non disponibile'];
        }

        $user = Auth::getUser();
        $employee = Auth::getEmployee();

        if ($user) {
            if ($user['role'] === 'admin_reparto') {
                $emp = Employee::getById((int) $request['employee_id']);
                if (!$emp || (int) ($emp['department_id'] ?? 0) !== (int) ($user['department_id'] ?? -1)) {
                    return ['success' => false, 'error' => 'Accesso non autorizzato'];
                }
            }
        } elseif ($employee) {
            if ((int) $request['employee_id'] !== (int) $employee['id']) {
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($request['certificate_path']) ?: 'application/octet-stream';

        return [
            'success' => true,
            'request' => $request,
            'file_path' => $request['certificate_path'],
            'filename' => $request['certificate_name'] ?? basename($request['certificate_path']),
            'mime' => $mime
        ];
    }

    /**
     * Crea notifica per il dipendente
     */
    private static function createNotification(int $employeeId, string $type, array $request, string $reason = ''): void
    {
        try {
            $titles = [
                'leave_approved' => 'Richiesta Approvata',
                'leave_rejected' => 'Richiesta Rifiutata'
            ];

            $leaveTypeName = self::LEAVE_TYPES[$request['leave_type']] ?? $request['leave_type'];
            $dateRange = date('d/m/Y', strtotime($request['start_date']));
            if ($request['start_date'] !== $request['end_date']) {
                $dateRange .= ' - ' . date('d/m/Y', strtotime($request['end_date']));
            }

            $message = "La tua richiesta di {$leaveTypeName} per il {$dateRange} è stata ";
            $message .= $type === 'leave_approved' ? 'approvata.' : "rifiutata.";

            if ($type === 'leave_rejected' && !empty($reason)) {
                $message .= " Motivo: {$reason}";
            }

            $title = $titles[$type] ?? 'Notifica';
            $link = PUBLIC_URL . '/employee/leave-requests.php';

            // Notifica in-app
            Database::insert('notifications', [
                'recipient_type' => 'employee',
                'recipient_id' => $employeeId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link
            ]);

            // Push notification
            $status = $type === 'leave_approved' ? 'approved' : 'rejected';
            PushNotification::notifyLeaveRequestResponse($employeeId, $status, $request['leave_type']);

        } catch (Exception $e) {
            error_log('Notification creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Notifica gli admin del reparto di una nuova richiesta
     */
    private static function notifyDepartmentAdmins(array $employee, array $requestData): void
    {
        try {
            $admins = !empty($employee['department_id'])
                ? Department::getAdmins((int) $employee['department_id'])
                : [];

            // Notifica SEMPRE anche gli admin della company del dipendente
            // (admin globali con company_id NULL + admin specifici della stessa company)
            $empCid = (int)($employee['company_id'] ?? 1);
            $companyAdmins = Database::fetchAll(
                "SELECT id, name, email, role FROM users
                 WHERE role = 'admin' AND is_active = TRUE
                   AND (company_id = ? OR company_id IS NULL)",
                [$empCid]
            );

            // Merge evitando duplicati (per id+role)
            $seen = [];
            foreach ($admins as $a) {
                $seen[($a['role'] ?? 'admin_reparto') . ':' . $a['id']] = true;
            }
            foreach ($companyAdmins as $a) {
                $key = ($a['role'] ?? 'admin') . ':' . $a['id'];
                if (!isset($seen[$key])) {
                    $admins[] = $a;
                    $seen[$key] = true;
                }
            }

            $employeeName = $employee['first_name'] . ' ' . $employee['last_name'];
            $leaveTypeName = self::LEAVE_TYPES[$requestData['leave_type']] ?? $requestData['leave_type'];
            $dateRange = date('d/m/Y', strtotime($requestData['start_date']));
            if ($requestData['start_date'] !== $requestData['end_date']) {
                $dateRange .= ' - ' . date('d/m/Y', strtotime($requestData['end_date']));
            }

            foreach ($admins as $admin) {
                $recipientType = $admin['role'] ?? 'admin_reparto';
                $link = $recipientType === 'admin'
                    ? PUBLIC_URL . '/admin/'
                    : PUBLIC_URL . '/admin-reparto/leave-requests.php';

                // Notifica in-app
                Notification::create([
                    'recipient_type' => $recipientType,
                    'recipient_id' => $admin['id'],
                    'type' => 'new_leave_request',
                    'title' => 'Nuova Richiesta ' . $leaveTypeName,
                    'message' => "{$employeeName} ha richiesto {$leaveTypeName} per il {$dateRange}",
                    'link' => $link
                ]);

                // Push notification (gestita silenziosamente in caso di errore)
                try {
                    PushNotification::notifyNewLeaveRequest(
                        (int) $admin['id'],
                        $employeeName,
                        $requestData['leave_type'],
                        $recipientType
                    );
                } catch (Throwable $pushErr) {
                    error_log('Push notify leave request failed: ' . $pushErr->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('Failed to notify department admins: ' . $e->getMessage());
        }
    }

    /**
     * Notifica i commercialisti di una richiesta approvata
     */
    private static function notifyAccountants(array $request): void
    {
        try {
            // Ottieni tutti i commercialisti
            $accountants = User::getAccountants();

            $employeeName = $request['first_name'] . ' ' . $request['last_name'];
            $leaveTypeName = self::LEAVE_TYPES[$request['leave_type']] ?? $request['leave_type'];
            $dateRange = date('d/m/Y', strtotime($request['start_date']));
            if ($request['start_date'] !== $request['end_date']) {
                $dateRange .= ' - ' . date('d/m/Y', strtotime($request['end_date']));
            }

            foreach ($accountants as $acc) {
                Notification::create([
                    'recipient_type' => 'accountant',
                    'recipient_id' => $acc['id'],
                    'type' => 'leave_approved',
                    'title' => $leaveTypeName . ' Approvata',
                    'message' => "{$employeeName}: {$leaveTypeName} approvata per il {$dateRange}",
                    'link' => PUBLIC_URL . '/accountant/leave-requests.php'
                ]);
            }
        } catch (Exception $e) {
            error_log('Failed to notify accountants: ' . $e->getMessage());
        }
    }

    /**
     * Log azioni
     */
    private static function logAction(string $action, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::getUser();
        $employee = Auth::getEmployee();

        $userType = 'unknown';
        $userId = 0;

        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
        } elseif ($employee) {
            $userType = 'employee';
            $userId = $employee['id'];
        }

        try {
            Database::insert('audit_log', [
                'user_type' => $userType,
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => 'leave_request',
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
