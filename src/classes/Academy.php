<?php
/**
 * Academy - corsi interni con materiali, frequenza tracciata e registro eventi.
 *
 * Flusso: HR/consulente crea il corso (bozza), carica materiali (file o link),
 * lo pubblica e lo assegna ai dipendenti. Il dipendente preme "Inizia", apre
 * ogni materiale e solo dopo puo premere "Completato" con la dichiarazione.
 *
 * Ogni passaggio finisce in academy_events: registro solo-INSERT con ora del
 * server, IP, user agent e catena di hash (ogni riga include l'hash della
 * precedente della stessa azienda), cosi una modifica manuale al DB e rilevabile.
 *
 * Gli attestati (modelli + PDF) stanno in AcademyCertificate.
 */

class Academy
{
    public const COURSE_STATUSES = [
        'draft' => 'Bozza',
        'published' => 'Pubblicato',
        'archived' => 'Archiviato',
    ];

    public const DECLARATION_TEXT = 'Dichiaro di aver seguito integralmente il corso e di aver consultato tutti i materiali didattici.';

    /** Giorni di anticipo del promemoria automatico prima della scadenza. */
    public const DUE_REMINDER_DAYS = 3;

    public const EVENT_LABELS = [
        'course_created' => 'Corso creato',
        'course_published' => 'Corso pubblicato',
        'course_archived' => 'Corso archiviato',
        'material_added' => 'Materiale aggiunto',
        'material_removed' => 'Materiale rimosso',
        'assigned' => 'Corso assegnato',
        'unassigned' => 'Assegnazione rimossa',
        'notified' => 'Notifica inviata',
        'started' => 'Corso iniziato',
        'material_opened' => 'Materiale aperto',
        'completed' => 'Corso completato',
        'reminded' => 'Sollecito inviato',
        'due_reminder' => 'Promemoria scadenza inviato',
        'certificate_generated' => 'Attestato generato',
        'certificate_downloaded' => 'Attestato scaricato',
    ];

    private const ALLOWED_EXT = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'odp', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mp3'];
    private const MAX_SIZE = 200 * 1024 * 1024; // 200MB: i video pesano

    /** Tipi che il browser mostra dentro la pagina (gli altri si scaricano). */
    public const INLINE_MIME_PREFIXES = ['application/pdf', 'image/', 'video/', 'audio/'];

    private static function cid(): int
    {
        return (int) Tenant::currentCompanyId();
    }

    // =====================================================================
    // Corsi
    // =====================================================================

    /**
     * Corsi dell'azienda corrente con contatori di avanzamento.
     * Con $departmentId i contatori considerano solo i dipendenti di quel reparto.
     */
    public static function getCourses(?string $status = null, ?int $departmentId = null): array
    {
        $params = [];
        $deptJoin = '';
        if ($departmentId !== null) {
            $deptJoin = ' AND aa.employee_id IN (SELECT id FROM employees WHERE department_id = ?)';
            $params[] = $departmentId;
        }
        $params[] = self::cid();
        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM academy_materials m WHERE m.course_id = c.id) AS material_count,
                       COUNT(aa.id) AS assigned_count,
                       SUM(aa.completed_at IS NOT NULL) AS completed_count,
                       SUM(aa.started_at IS NOT NULL AND aa.completed_at IS NULL) AS in_progress_count,
                       (SELECT COUNT(*) FROM academy_certificates ce
                          JOIN academy_assignments a2 ON a2.id = ce.assignment_id
                         WHERE a2.course_id = c.id) AS certificate_count,
                       SUM(aa.completed_at IS NOT NULL
                           AND NOT EXISTS (SELECT 1 FROM academy_certificates c3 WHERE c3.assignment_id = aa.id)) AS to_generate_count
                FROM academy_courses c
                LEFT JOIN academy_assignments aa ON aa.course_id = c.id{$deptJoin}
                WHERE c.company_id = ?";
        if ($status !== null) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        $sql .= ' GROUP BY c.id ORDER BY FIELD(c.status, "published", "draft", "archived"), c.created_at DESC';
        return Database::fetchAll($sql, $params);
    }

    public static function getCourse(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT c.*, t.name AS template_name
             FROM academy_courses c
             LEFT JOIN academy_certificate_templates t ON t.id = c.certificate_template_id
             WHERE c.id = ? AND c.company_id = ?",
            [$id, self::cid()]
        );
    }

    public static function saveCourse(array $data, ?int $id = null): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'error' => 'Il titolo del corso è obbligatorio'];
        }
        $hours = trim((string) ($data['duration_hours'] ?? ''));
        $hours = $hours === '' ? null : (float) str_replace(',', '.', $hours);
        if ($hours !== null && ($hours < 0 || $hours > 9999)) {
            return ['success' => false, 'error' => 'Durata non valida'];
        }
        $due = trim((string) ($data['due_date'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return ['success' => false, 'error' => 'Data di scadenza non valida'];
        }
        $templateId = (int) ($data['certificate_template_id'] ?? 0);
        if ($templateId > 0 && !AcademyCertificate::getTemplate($templateId)) {
            return ['success' => false, 'error' => 'Modello attestato non valido'];
        }

        $row = [
            'title' => mb_substr($title, 0, 200),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'duration_hours' => $hours,
            'due_date' => $due !== '' ? $due : null,
            'certificate_template_id' => $templateId > 0 ? $templateId : null,
        ];

        if ($id !== null) {
            $course = self::getCourse($id);
            if (!$course) {
                return ['success' => false, 'error' => 'Corso non trovato'];
            }
            // Cambiando la scadenza il promemoria automatico deve poter ripartire
            if (($course['due_date'] ?? null) !== $row['due_date']) {
                Database::update('academy_assignments', ['due_reminder_sent_at' => null], 'course_id = ? AND company_id = ?', [$id, self::cid()]);
            }
            Database::update('academy_courses', $row, 'id = ? AND company_id = ?', [$id, self::cid()]);
            return ['success' => true, 'id' => $id];
        }

        $user = Auth::getUser();
        $row['company_id'] = self::cid();
        $row['status'] = 'draft';
        $row['created_by_user_id'] = $user['id'] ?? null;
        $newId = Database::insert('academy_courses', $row);
        self::logEvent('course_created', ['course_id' => $newId, 'details' => $row['title']]);
        return ['success' => true, 'id' => $newId];
    }

    public static function setCourseStatus(int $id, string $status): array
    {
        if (!isset(self::COURSE_STATUSES[$status])) {
            return ['success' => false, 'error' => 'Stato non valido'];
        }
        $course = self::getCourse($id);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        if ($status === 'published' && (int) Database::fetchColumn("SELECT COUNT(*) FROM academy_materials WHERE course_id = ?", [$id]) === 0) {
            return ['success' => false, 'error' => 'Aggiungi almeno un materiale prima di pubblicare il corso'];
        }
        Database::update('academy_courses', ['status' => $status], 'id = ? AND company_id = ?', [$id, self::cid()]);
        if ($status === 'published') {
            self::logEvent('course_published', ['course_id' => $id]);
        } elseif ($status === 'archived') {
            self::logEvent('course_archived', ['course_id' => $id]);
        }
        return ['success' => true];
    }

    /** Elimina un corso solo se non è mai stato assegnato (altrimenti si archivia). */
    public static function deleteCourse(int $id): array
    {
        $course = self::getCourse($id);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        if ((int) Database::fetchColumn("SELECT COUNT(*) FROM academy_assignments WHERE course_id = ?", [$id]) > 0) {
            return ['success' => false, 'error' => 'Il corso è già stato assegnato: puoi solo archiviarlo'];
        }
        foreach (self::getMaterials($id) as $m) {
            if ($m['kind'] === 'file' && !empty($m['file_path']) && is_file($m['file_path'])) {
                @unlink($m['file_path']);
            }
        }
        Database::delete('academy_courses', 'id = ? AND company_id = ?', [$id, self::cid()]);
        return ['success' => true];
    }

    // =====================================================================
    // Materiali
    // =====================================================================

    public static function getMaterials(int $courseId): array
    {
        return Database::fetchAll(
            "SELECT * FROM academy_materials WHERE course_id = ? AND company_id = ? ORDER BY sort_order, id",
            [$courseId, self::cid()]
        );
    }

    public static function getMaterial(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM academy_materials WHERE id = ? AND company_id = ?",
            [$id, self::cid()]
        );
    }

    /**
     * Carica uno o piu file sul corso. Ogni file diventa un materiale col
     * proprio nome (senza estensione) come titolo.
     */
    public static function addFiles(int $courseId, array $input): array
    {
        $course = self::getCourse($courseId);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        $files = EmployeeDocument::normalizeUploadedFiles($input);
        if (empty($files)) {
            return ['success' => false, 'error' => 'Seleziona almeno un file'];
        }

        $dir = DOCUMENTS_PATH . '/academy/' . self::cid() . '/materials/' . $courseId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Impossibile creare la cartella dei materiali'];
        }

        $order = (int) Database::fetchColumn("SELECT COALESCE(MAX(sort_order), 0) FROM academy_materials WHERE course_id = ?", [$courseId]);
        $added = 0;
        $errors = [];
        foreach ($files as $file) {
            $check = self::validateFile($file);
            if (!$check['valid']) {
                $errors[] = $file['name'] . ': ' . $check['error'];
                continue;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $path = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                $errors[] = $file['name'] . ': errore di salvataggio';
                continue;
            }
            $title = trim(pathinfo($file['name'], PATHINFO_FILENAME)) ?: $file['name'];
            $materialId = Database::insert('academy_materials', [
                'company_id' => self::cid(),
                'course_id' => $courseId,
                'kind' => 'file',
                'title' => mb_substr($title, 0, 255),
                'file_path' => $path,
                'original_name' => mb_substr($file['name'], 0, 255),
                'mime_type' => $check['mime_type'],
                'file_size' => (int) $file['size'],
                'sort_order' => ++$order,
            ]);
            self::logEvent('material_added', ['course_id' => $courseId, 'material_id' => $materialId, 'details' => $file['name']]);
            $added++;
        }

        return ['success' => $added > 0, 'added' => $added, 'errors' => $errors,
                'error' => $added === 0 ? implode(' - ', $errors) : null];
    }

    public static function addLink(int $courseId, string $title, string $url): array
    {
        $course = self::getCourse($courseId);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return ['success' => false, 'error' => 'Inserisci un link valido (http:// o https://)'];
        }
        $title = trim($title) ?: (parse_url($url, PHP_URL_HOST) ?: 'Link');
        $order = (int) Database::fetchColumn("SELECT COALESCE(MAX(sort_order), 0) FROM academy_materials WHERE course_id = ?", [$courseId]);
        $materialId = Database::insert('academy_materials', [
            'company_id' => self::cid(),
            'course_id' => $courseId,
            'kind' => 'link',
            'title' => mb_substr($title, 0, 255),
            'url' => mb_substr($url, 0, 1000),
            'sort_order' => $order + 1,
        ]);
        self::logEvent('material_added', ['course_id' => $courseId, 'material_id' => $materialId, 'details' => $url]);
        return ['success' => true];
    }

    public static function renameMaterial(int $id, string $title): array
    {
        $title = trim($title);
        if ($title === '' || !self::getMaterial($id)) {
            return ['success' => false, 'error' => 'Titolo non valido'];
        }
        Database::update('academy_materials', ['title' => mb_substr($title, 0, 255)], 'id = ? AND company_id = ?', [$id, self::cid()]);
        return ['success' => true];
    }

    public static function deleteMaterial(int $id): array
    {
        $m = self::getMaterial($id);
        if (!$m) {
            return ['success' => false, 'error' => 'Materiale non trovato'];
        }
        Database::delete('academy_materials', 'id = ? AND company_id = ?', [$id, self::cid()]);
        if ($m['kind'] === 'file' && !empty($m['file_path']) && is_file($m['file_path'])) {
            @unlink($m['file_path']);
        }
        self::logEvent('material_removed', ['course_id' => (int) $m['course_id'], 'material_id' => $id, 'details' => $m['title']]);
        return ['success' => true];
    }

    public static function moveMaterial(int $id, string $direction): void
    {
        $m = self::getMaterial($id);
        if (!$m) return;
        $list = self::getMaterials((int) $m['course_id']);
        $ids = array_map(fn($r) => (int) $r['id'], $list);
        $pos = array_search($id, $ids, true);
        $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($pos === false || $swap < 0 || $swap >= count($ids)) return;
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $i => $mid) {
            Database::update('academy_materials', ['sort_order' => $i + 1], 'id = ?', [$mid]);
        }
    }

    public static function isInlineMime(?string $mime): bool
    {
        foreach (self::INLINE_MIME_PREFIXES as $p) {
            if ($mime !== null && str_starts_with($mime, $p)) return true;
        }
        return false;
    }

    private static function validateFile(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'errore upload (codice ' . ($file['error'] ?? '?') . ')'];
        }
        if ($file['size'] > self::MAX_SIZE) {
            return ['valid' => false, 'error' => 'file troppo grande (massimo ' . (self::MAX_SIZE / 1024 / 1024) . 'MB)'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['valid' => false, 'error' => 'formato .' . $ext . ' non consentito'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        // Niente script/HTML travestiti da documento
        if (preg_match('#^(text/html|application/x-php|text/x-php|application/javascript|text/javascript)#', $mime)) {
            return ['valid' => false, 'error' => 'contenuto non consentito'];
        }
        return ['valid' => true, 'mime_type' => $mime];
    }

    // =====================================================================
    // Assegnazioni
    // =====================================================================

    /**
     * Assegna il corso ai dipendenti indicati (solo attivi della stessa azienda).
     * Le notifiche restano in coda (notify_pending) e partono a batch via AJAX.
     */
    public static function assign(int $courseId, array $employeeIds, bool $sendEmail = true): array
    {
        $course = self::getCourse($courseId);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        if ($course['status'] !== 'published') {
            return ['success' => false, 'error' => 'Pubblica il corso prima di assegnarlo'];
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (empty($employeeIds)) {
            return ['success' => false, 'error' => 'Seleziona almeno un dipendente'];
        }

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $valid = Database::fetchAll(
            "SELECT e.id FROM employees e
             WHERE e.company_id = ? AND e.is_active = 1 AND e.id IN ($placeholders)
               AND NOT EXISTS (SELECT 1 FROM academy_assignments a WHERE a.course_id = ? AND a.employee_id = e.id)",
            array_merge([self::cid()], $employeeIds, [$courseId])
        );

        $user = Auth::getUser();
        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($valid as $row) {
            $assignmentId = Database::insert('academy_assignments', [
                'company_id' => self::cid(),
                'course_id' => $courseId,
                'employee_id' => (int) $row['id'],
                'assigned_by_user_id' => $user['id'] ?? null,
                'assigned_at' => $now,
                'notify_pending' => 1,
                'notify_email' => $sendEmail ? 1 : 0,
            ]);
            self::logEvent('assigned', ['course_id' => $courseId, 'assignment_id' => $assignmentId, 'employee_id' => (int) $row['id']]);
            $count++;
        }

        return ['success' => true, 'count' => $count, 'skipped' => count($employeeIds) - $count];
    }

    /** Rimuove un'assegnazione solo se il dipendente non ha ancora iniziato. */
    public static function unassign(int $assignmentId): array
    {
        $a = self::getAssignment($assignmentId);
        if (!$a) {
            return ['success' => false, 'error' => 'Assegnazione non trovata'];
        }
        if (!empty($a['started_at'])) {
            return ['success' => false, 'error' => 'Il dipendente ha già iniziato il corso: l\'assegnazione resta nel registro'];
        }
        Database::delete('academy_assignments', 'id = ? AND company_id = ?', [$assignmentId, self::cid()]);
        self::logEvent('unassigned', ['course_id' => (int) $a['course_id'], 'assignment_id' => $assignmentId, 'employee_id' => (int) $a['employee_id']]);
        return ['success' => true];
    }

    public static function getAssignment(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT a.*, e.first_name, e.last_name, e.email, e.fiscal_code, e.department_id
             FROM academy_assignments a
             JOIN employees e ON e.id = a.employee_id
             WHERE a.id = ? AND a.company_id = ?",
            [$id, self::cid()]
        );
    }

    /**
     * Partecipanti di un corso con avanzamento (materiali aperti) e attestato.
     */
    public static function getAssignments(int $courseId, ?int $departmentId = null): array
    {
        $params = [$courseId, $courseId, self::cid()];
        $sql = "SELECT a.*, e.first_name, e.last_name, e.fiscal_code, e.is_active, d.name AS department_name,
                       (SELECT COUNT(*) FROM academy_material_views v
                          JOIN academy_materials m ON m.id = v.material_id
                         WHERE v.assignment_id = a.id AND m.course_id = ?) AS opened_count,
                       ce.id AS certificate_id, ce.number AS certificate_number, ce.issued_at AS certificate_issued_at
                FROM academy_assignments a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
                WHERE a.course_id = ? AND a.company_id = ?";
        if ($departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $sql .= ' ORDER BY e.last_name, e.first_name';
        return Database::fetchAll($sql, $params);
    }

    /** Stato leggibile di un'assegnazione: not_started | in_progress | completed | overdue. */
    public static function statusOf(array $assignment, ?string $dueDate): string
    {
        if (!empty($assignment['completed_at'])) return 'completed';
        if ($dueDate && $dueDate < date('Y-m-d')) return 'overdue';
        return !empty($assignment['started_at']) ? 'in_progress' : 'not_started';
    }

    public const STATUS_LABELS = [
        'not_started' => 'Da iniziare',
        'in_progress' => 'In corso',
        'completed' => 'Completato',
        'overdue' => 'Scaduto',
    ];

    // =====================================================================
    // Notifiche (assegnazione, solleciti, promemoria scadenza)
    // =====================================================================

    public static function processNotificationBatch(int $courseId, int $limit = 4): array
    {
        $limit = max(1, min(20, $limit));
        $course = self::getCourse($courseId);
        if (!$course) {
            return ['success' => false, 'error' => 'Corso non trovato'];
        }
        $rows = Database::fetchAll(
            "SELECT id, employee_id, notify_email FROM academy_assignments
             WHERE company_id = ? AND course_id = ? AND notify_pending = 1
             ORDER BY id LIMIT " . (int) $limit,
            [self::cid(), $courseId]
        );
        $sent = 0;
        foreach ($rows as $row) {
            // Prima sblocco la riga: se l'invio va in timeout non resta in loop
            Database::update('academy_assignments', ['notify_pending' => 0], 'id = ?', [$row['id']]);
            $employee = Employee::getById((int) $row['employee_id']);
            if (!$employee) continue;
            $dueText = $course['due_date'] ? ' Da completare entro il ' . date('d/m/Y', strtotime($course['due_date'])) . '.' : '';
            self::notifyEmployee(
                $employee,
                'Nuovo corso: ' . $course['title'],
                'Ti è stato assegnato il corso "' . $course['title'] . '".' . $dueText,
                (int) $course['id'],
                !empty($row['notify_email'])
            );
            self::logEvent('notified', ['course_id' => $courseId, 'assignment_id' => (int) $row['id'], 'employee_id' => (int) $row['employee_id']]);
            $sent++;
        }
        return ['success' => true, 'sent' => $sent, 'remaining' => self::countPendingNotifications($courseId)];
    }

    public static function countPendingNotifications(int $courseId): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM academy_assignments WHERE company_id = ? AND course_id = ? AND notify_pending = 1",
            [self::cid(), $courseId]
        );
    }

    /** Sollecito manuale di HR/consulente a un dipendente che non ha completato. */
    public static function remind(int $assignmentId): array
    {
        $a = self::getAssignment($assignmentId);
        if (!$a) {
            return ['success' => false, 'error' => 'Assegnazione non trovata'];
        }
        if (!empty($a['completed_at'])) {
            return ['success' => false, 'error' => 'Il corso è già completato'];
        }
        $course = self::getCourse((int) $a['course_id']);
        $employee = Employee::getById((int) $a['employee_id']);
        if (!$course || !$employee) {
            return ['success' => false, 'error' => 'Dati non trovati'];
        }
        $dueText = $course['due_date'] ? ' La scadenza è il ' . date('d/m/Y', strtotime($course['due_date'])) . '.' : '';
        self::notifyEmployee(
            $employee,
            'Promemoria corso: ' . $course['title'],
            'Ti ricordiamo di completare il corso "' . $course['title'] . '".' . $dueText,
            (int) $course['id'],
            true
        );
        Database::execute(
            "UPDATE academy_assignments SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ?",
            [$assignmentId]
        );
        self::logEvent('reminded', ['course_id' => (int) $a['course_id'], 'assignment_id' => $assignmentId, 'employee_id' => (int) $a['employee_id']]);
        return ['success' => true];
    }

    /** Sollecita tutti i non completati di un corso. */
    public static function remindAllPending(int $courseId): array
    {
        $ids = Database::fetchAll(
            "SELECT id FROM academy_assignments WHERE company_id = ? AND course_id = ? AND completed_at IS NULL",
            [self::cid(), $courseId]
        );
        $n = 0;
        foreach ($ids as $r) {
            if (self::remind((int) $r['id'])['success']) $n++;
        }
        return ['success' => true, 'count' => $n];
    }

    /**
     * Promemoria automatico a N giorni dalla scadenza, una sola volta per
     * assegnazione. Idempotente: chiamato al load delle dashboard (niente cron).
     */
    public static function runDueReminders(int $companyId): void
    {
        if ($companyId <= 0) return;
        try {
            $rows = Database::fetchAll(
                "SELECT a.id, a.employee_id, a.course_id, c.title, c.due_date
                 FROM academy_assignments a
                 JOIN academy_courses c ON c.id = a.course_id
                 JOIN employees e ON e.id = a.employee_id
                 WHERE a.company_id = ? AND c.status = 'published' AND c.due_date IS NOT NULL
                   AND a.completed_at IS NULL AND a.due_reminder_sent_at IS NULL AND e.is_active = 1
                   AND c.due_date >= CURDATE() AND c.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 LIMIT 30",
                [$companyId, self::DUE_REMINDER_DAYS]
            );
            foreach ($rows as $r) {
                // Prima marco, poi invio: due tab aperte non mandano il promemoria due volte
                $claimed = Database::execute(
                    "UPDATE academy_assignments SET due_reminder_sent_at = NOW() WHERE id = ? AND due_reminder_sent_at IS NULL",
                    [$r['id']]
                )->rowCount();
                if ($claimed === 0) continue;
                $employee = Employee::getById((int) $r['employee_id']);
                if (!$employee) continue;
                self::notifyEmployee(
                    $employee,
                    'Corso in scadenza: ' . $r['title'],
                    'Il corso "' . $r['title'] . '" va completato entro il ' . date('d/m/Y', strtotime($r['due_date'])) . '.',
                    (int) $r['course_id'],
                    true,
                    $companyId
                );
                self::logEvent('due_reminder', [
                    'company_id' => $companyId, 'course_id' => (int) $r['course_id'],
                    'assignment_id' => (int) $r['id'], 'employee_id' => (int) $r['employee_id'],
                    'system' => true,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[Academy::runDueReminders] ' . $e->getMessage());
        }
    }

    /**
     * Notifica in-app + push + email al dipendente. La notifica è salvata
     * sempre con il company_id del dipendente (mai quello della sessione).
     */
    public static function notifyEmployee(array $employee, string $title, string $message, int $courseId, bool $sendEmail, ?int $companyId = null): void
    {
        $link = (defined('PUBLIC_URL') ? PUBLIC_URL : '') . '/employee/academy.php?course=' . $courseId;
        try {
            Database::insert('notifications', [
                'company_id' => $companyId ?? (int) $employee['company_id'],
                'recipient_type' => 'employee',
                'recipient_id' => (int) $employee['id'],
                'type' => 'academy',
                'title' => mb_substr($title, 0, 255),
                'message' => $message,
                'link' => $link,
                'is_read' => 0,
            ]);
        } catch (Throwable $e) {
            error_log('[Academy] notifica in-app fallita: ' . $e->getMessage());
        }
        try {
            if (class_exists('PushNotification')) {
                PushNotification::sendToUser('employee', (int) $employee['id'], [
                    'title' => $title,
                    'body' => $message,
                    'url' => '/employee/academy.php?course=' . $courseId,
                    'tag' => 'academy-' . $courseId,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[Academy] push fallita: ' . $e->getMessage());
        }
        try {
            if ($sendEmail && class_exists('Mailer') && Mailer::isConfigured()) {
                $url = function_exists('buildPublicUrl') ? buildPublicUrl('/employee/academy.php?course=' . $courseId) : $link;
                $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                $html = '<p>Ciao ' . htmlspecialchars($fullName) . ',</p>'
                      . '<p>' . htmlspecialchars($message) . '</p>'
                      . '<p><a href="' . htmlspecialchars($url) . '">Apri il corso nell\'Academy</a></p>';
                $text = "Ciao {$fullName},\n\n{$message}\n\nApri il corso: {$url}";
                Mailer::sendToEmployee((int) $employee['id'], $title, $html, $text);
            }
        } catch (Throwable $e) {
            error_log('[Academy] email fallita: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // Lato dipendente
    // =====================================================================

    /** Corsi assegnati al dipendente (pubblicati, o archiviati se già iniziati). */
    public static function getEmployeeCourses(int $employeeId): array
    {
        return Database::fetchAll(
            "SELECT a.*, c.title, c.description, c.duration_hours, c.due_date, c.status AS course_status,
                    (SELECT COUNT(*) FROM academy_materials m WHERE m.course_id = c.id) AS material_count,
                    (SELECT COUNT(*) FROM academy_material_views v JOIN academy_materials m ON m.id = v.material_id
                      WHERE v.assignment_id = a.id AND m.course_id = c.id) AS opened_count,
                    ce.id AS certificate_id, ce.employee_downloaded_at
             FROM academy_assignments a
             JOIN academy_courses c ON c.id = a.course_id
             LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
             WHERE a.employee_id = ? AND a.company_id = ?
               AND (c.status = 'published' OR a.started_at IS NOT NULL)
             ORDER BY (a.completed_at IS NOT NULL), c.due_date IS NULL, c.due_date, a.assigned_at DESC",
            [$employeeId, self::cid()]
        );
    }

    public static function getEmployeeAssignment(int $employeeId, int $courseId): ?array
    {
        return Database::fetchOne(
            "SELECT a.*, ce.id AS certificate_id, ce.number AS certificate_number, ce.employee_downloaded_at
             FROM academy_assignments a
             JOIN academy_courses c ON c.id = a.course_id
             LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
             WHERE a.employee_id = ? AND a.course_id = ? AND a.company_id = ?
               AND (c.status = 'published' OR a.started_at IS NOT NULL)",
            [$employeeId, $courseId, self::cid()]
        );
    }

    public static function countToDo(int $employeeId): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM academy_assignments a JOIN academy_courses c ON c.id = a.course_id
             WHERE a.employee_id = ? AND a.company_id = ? AND a.completed_at IS NULL AND c.status = 'published'",
            [$employeeId, self::cid()]
        );
    }

    /** ID dei materiali già aperti in questa assegnazione. */
    public static function openedMaterialIds(int $assignmentId): array
    {
        $rows = Database::fetchAll("SELECT material_id FROM academy_material_views WHERE assignment_id = ?", [$assignmentId]);
        return array_map(fn($r) => (int) $r['material_id'], $rows);
    }

    public static function start(array $assignment): void
    {
        if (!empty($assignment['started_at'])) return;
        $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null);
        $done = Database::execute(
            "UPDATE academy_assignments SET started_at = NOW(), started_ip = ? WHERE id = ? AND started_at IS NULL",
            [$ip, $assignment['id']]
        )->rowCount();
        if ($done > 0) {
            self::logEvent('started', [
                'course_id' => (int) $assignment['course_id'],
                'assignment_id' => (int) $assignment['id'],
                'employee_id' => (int) $assignment['employee_id'],
            ]);
        }
    }

    /** Registra l'apertura di un materiale (avvia il corso se non ancora iniziato). */
    public static function recordMaterialOpen(array $assignment, array $material): void
    {
        self::start($assignment);
        Database::execute(
            "INSERT INTO academy_material_views (assignment_id, material_id, first_opened_at, last_opened_at, open_count)
             VALUES (?, ?, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE last_opened_at = NOW(), open_count = open_count + 1",
            [$assignment['id'], $material['id']]
        );
        self::logEvent('material_opened', [
            'course_id' => (int) $assignment['course_id'],
            'assignment_id' => (int) $assignment['id'],
            'employee_id' => (int) $assignment['employee_id'],
            'material_id' => (int) $material['id'],
            'details' => $material['title'],
        ]);
    }

    /**
     * Il dipendente conferma il completamento: serve aver aperto tutti i
     * materiali e accettato la dichiarazione.
     */
    public static function complete(array $assignment, bool $declarationAccepted): array
    {
        if (!empty($assignment['completed_at'])) {
            return ['success' => true];
        }
        if (!$declarationAccepted) {
            return ['success' => false, 'error' => 'Per completare devi spuntare la dichiarazione'];
        }
        $materials = self::getMaterials((int) $assignment['course_id']);
        $opened = self::openedMaterialIds((int) $assignment['id']);
        foreach ($materials as $m) {
            if (!in_array((int) $m['id'], $opened, true)) {
                return ['success' => false, 'error' => 'Apri tutti i materiali del corso prima di completarlo'];
            }
        }
        $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null);
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $done = Database::execute(
            "UPDATE academy_assignments
                SET completed_at = NOW(), completed_ip = ?, completed_user_agent = ?, declaration_text = ?,
                    started_at = COALESCE(started_at, NOW())
              WHERE id = ? AND completed_at IS NULL",
            [$ip, $ua, self::DECLARATION_TEXT, $assignment['id']]
        )->rowCount();
        if ($done > 0) {
            self::logEvent('completed', [
                'course_id' => (int) $assignment['course_id'],
                'assignment_id' => (int) $assignment['id'],
                'employee_id' => (int) $assignment['employee_id'],
                'details' => 'Dichiarazione accettata: ' . self::DECLARATION_TEXT
                    . ' | Materiali aperti: ' . count($opened) . '/' . count($materials),
            ]);
            self::notifyStaffCompleted($assignment);
        }
        return ['success' => true];
    }

    /**
     * HR e consulenti da avvisare per un'azienda: admin dell'azienda (company_id o
     * user_companies), admin globali veri (company_id NULL senza user_companies),
     * consulenti del lavoro collegati all'azienda e responsabili formazione.
     */
    public static function staffRecipients(int $companyId): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.role, u.name, u.username, u.email
             FROM users u
             WHERE u.is_active = 1 AND u.role IN ('admin', 'consulente_lavoro', 'formatore')
               AND (u.company_id = ?
                    OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = ?)
                    OR (u.role = 'admin' AND u.company_id IS NULL
                        AND NOT EXISTS (SELECT 1 FROM user_companies uc2 WHERE uc2.user_id = u.id)))",
            [$companyId, $companyId]
        );
    }

    /**
     * Avvisa HR e consulenti che un dipendente ha completato il corso:
     * riga in notifications + push web + email, con link alla scheda partecipanti.
     */
    private static function notifyStaffCompleted(array $assignment): void
    {
        try {
            $course = self::getCourse((int) $assignment['course_id']);
            $employee = Employee::getById((int) $assignment['employee_id']);
            if (!$course || !$employee) return;
            $companyId = (int) $assignment['company_id'];
            $who = trim($employee['first_name'] . ' ' . $employee['last_name']);
            $title = 'Corso completato: ' . $course['title'];
            $message = $who . ' ha completato il corso "' . $course['title'] . '". Puoi generare l\'attestato.';

            foreach (self::staffRecipients($companyId) as $u) {
                $area = ['consulente_lavoro' => 'consulente-lavoro', 'formatore' => 'formatore'][$u['role']] ?? 'admin';
                $path = '/' . $area . '/academy.php?course=' . (int) $course['id'] . '&tab=people';
                try {
                    Database::insert('notifications', [
                        'company_id' => $companyId,
                        'recipient_type' => $u['role'],
                        'recipient_id' => (int) $u['id'],
                        'type' => 'academy_completed',
                        'title' => mb_substr($title, 0, 255),
                        'message' => $message,
                        'link' => PUBLIC_URL . $path,
                        'is_read' => 0,
                    ]);
                } catch (Throwable $e) {
                    error_log('[Academy] notifica staff fallita: ' . $e->getMessage());
                }
                try {
                    if (class_exists('PushNotification')) {
                        PushNotification::sendToUser($u['role'], (int) $u['id'], [
                            'title' => $title,
                            'body' => $message,
                            'url' => $path,
                            'tag' => 'academy-completed-' . (int) $assignment['id'],
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log('[Academy] push staff fallita: ' . $e->getMessage());
                }
                try {
                    if (!empty($u['email']) && class_exists('Mailer') && Mailer::isConfigured()) {
                        $url = function_exists('buildPublicUrl') ? buildPublicUrl($path) : PUBLIC_URL . $path;
                        $name = $u['name'] ?: $u['username'];
                        $html = '<p>Ciao ' . htmlspecialchars($name) . ',</p>'
                              . '<p>' . htmlspecialchars($message) . '</p>'
                              . '<p><a href="' . htmlspecialchars($url) . '">Apri il corso nell\'Academy</a></p>';
                        Mailer::send($u['email'], $name, $title, $html, $message . "\n\n" . $url);
                    }
                } catch (Throwable $e) {
                    error_log('[Academy] email staff fallita: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[Academy] notifica completamento fallita: ' . $e->getMessage());
        }
    }

    /** Completati senza attestato nell'azienda corrente (badge menu/corso). */
    public static function countToGenerate(?int $courseId = null): int
    {
        $sql = "SELECT COUNT(*) FROM academy_assignments a
                LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
                WHERE a.company_id = ? AND a.completed_at IS NOT NULL AND ce.id IS NULL";
        $params = [self::cid()];
        if ($courseId !== null) {
            $sql .= ' AND a.course_id = ?';
            $params[] = $courseId;
        }
        return (int) Database::fetchColumn($sql, $params);
    }

    /** Attestati emessi che il dipendente non ha ancora scaricato. */
    public static function countNewCertificates(int $employeeId): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM academy_certificates ce
             JOIN academy_assignments a ON a.id = ce.assignment_id
             WHERE a.employee_id = ? AND ce.company_id = ? AND ce.employee_downloaded_at IS NULL",
            [$employeeId, self::cid()]
        );
    }

    // =====================================================================
    // Registro eventi (append-only, catena di hash)
    // =====================================================================

    /**
     * Scrive un evento nel registro. L'hash concatena quello della riga
     * precedente della stessa azienda: un lock con nome serializza le scritture
     * cosi la catena non si biforca con richieste concorrenti.
     */
    public static function logEvent(string $event, array $ctx = []): void
    {
        try {
            $companyId = (int) ($ctx['company_id'] ?? self::cid());
            $user = Auth::getUser();
            $employee = Auth::getEmployee();
            if (!empty($ctx['system'])) {
                // Azioni automatiche (promemoria): non vanno attribuite a chi ha aperto la pagina
                $actorType = 'system';
                $actorId = 0;
            } elseif ($user) {
                $actorType = (string) $user['role'];
                $actorId = (int) $user['id'];
            } elseif ($employee) {
                $actorType = 'employee';
                $actorId = (int) $employee['id'];
            } else {
                $actorType = 'system';
                $actorId = 0;
            }
            $row = [
                'company_id' => $companyId,
                'course_id' => isset($ctx['course_id']) ? (int) $ctx['course_id'] : null,
                'assignment_id' => isset($ctx['assignment_id']) ? (int) $ctx['assignment_id'] : null,
                'employee_id' => isset($ctx['employee_id']) ? (int) $ctx['employee_id'] : null,
                'material_id' => isset($ctx['material_id']) ? (int) $ctx['material_id'] : null,
                'event' => $event,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'details' => isset($ctx['details']) ? mb_substr((string) $ctx['details'], 0, 1000) : null,
                'ip_address' => PHP_SAPI === 'cli' ? null : (function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null)),
                'user_agent' => PHP_SAPI === 'cli' ? null : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $lock = 'academy_chain_' . $companyId;
            Database::fetchColumn("SELECT GET_LOCK(?, 5)", [$lock]);
            try {
                $prev = Database::fetchColumn(
                    "SELECT hash FROM academy_events WHERE company_id = ? ORDER BY id DESC LIMIT 1",
                    [$companyId]
                );
                $row['prev_hash'] = $prev ?: null;
                $row['hash'] = self::hashRow($row);
                Database::insert('academy_events', $row);
            } finally {
                Database::fetchColumn("SELECT RELEASE_LOCK(?)", [$lock]);
            }
        } catch (Throwable $e) {
            error_log('[Academy::logEvent] ' . $e->getMessage());
        }
    }

    private static function hashRow(array $r): string
    {
        return hash('sha256', implode('|', [
            $r['prev_hash'] ?? '', $r['company_id'], $r['course_id'] ?? '', $r['assignment_id'] ?? '',
            $r['employee_id'] ?? '', $r['material_id'] ?? '', $r['event'], $r['actor_type'], $r['actor_id'],
            $r['details'] ?? '', $r['ip_address'] ?? '', $r['user_agent'] ?? '', $r['created_at'],
        ]));
    }

    /**
     * Ricalcola la catena dell'azienda corrente.
     * @return array ok, checked, broken_id (prima riga alterata o null)
     */
    public static function verifyChain(?int $companyId = null): array
    {
        $rows = Database::fetchAll("SELECT * FROM academy_events WHERE company_id = ? ORDER BY id", [$companyId ?? self::cid()]);
        $prev = null;
        foreach ($rows as $r) {
            if (($r['prev_hash'] ?? null) !== $prev || self::hashRow($r) !== $r['hash']) {
                return ['ok' => false, 'checked' => count($rows), 'broken_id' => (int) $r['id']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'checked' => count($rows), 'broken_id' => null];
    }

    /** Eventi del registro filtrati per corso e/o assegnazione, con nomi leggibili. */
    public static function getEvents(?int $courseId = null, ?int $assignmentId = null, ?int $departmentId = null): array
    {
        $sql = "SELECT ev.*, e.first_name, e.last_name, e.fiscal_code, m.title AS material_title,
                       c.title AS course_title, u.username AS actor_username
                FROM academy_events ev
                LEFT JOIN employees e ON e.id = ev.employee_id
                LEFT JOIN academy_materials m ON m.id = ev.material_id
                LEFT JOIN academy_courses c ON c.id = ev.course_id
                LEFT JOIN users u ON u.id = ev.actor_id AND ev.actor_type <> 'employee'
                WHERE ev.company_id = ?";
        $params = [self::cid()];
        if ($courseId !== null) {
            $sql .= ' AND ev.course_id = ?';
            $params[] = $courseId;
        }
        if ($assignmentId !== null) {
            $sql .= ' AND ev.assignment_id = ?';
            $params[] = $assignmentId;
        }
        if ($departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $sql .= ' ORDER BY ev.id';
        return Database::fetchAll($sql, $params);
    }

    public static function actorLabel(array $ev): string
    {
        if ($ev['actor_type'] === 'employee') {
            return trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? '')) ?: 'Dipendente #' . $ev['actor_id'];
        }
        if ($ev['actor_type'] === 'system') return 'Sistema';
        $roles = ['admin' => 'HR', 'consulente_lavoro' => 'Consulente', 'admin_reparto' => 'Resp. reparto',
                  'accountant' => 'Commercialista', 'formatore' => 'Resp. formazione'];
        return ($roles[$ev['actor_type']] ?? $ev['actor_type']) . ($ev['actor_username'] ? ' (' . $ev['actor_username'] . ')' : '');
    }

    /** Esporta il registro in CSV (separatore ; con BOM: si apre direttamente in Excel). */
    public static function streamEventsCsv(array $events, string $filename): void
    {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['N.', 'Data e ora (server)', 'Evento', 'Corso', 'Dipendente', 'Codice fiscale', 'Materiale', 'Eseguito da', 'Dettagli', 'IP', 'Browser', 'Hash'], ';');
        foreach ($events as $ev) {
            fputcsv($out, [
                $ev['id'],
                date('d/m/Y H:i:s', strtotime($ev['created_at'])),
                self::EVENT_LABELS[$ev['event']] ?? $ev['event'],
                $ev['course_title'] ?? '',
                trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? '')),
                $ev['fiscal_code'] ?? '',
                $ev['material_title'] ?? '',
                self::actorLabel($ev),
                $ev['details'] ?? '',
                $ev['ip_address'] ?? '',
                $ev['user_agent'] ?? '',
                $ev['hash'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    /** Esporta il registro in PDF (A4 orizzontale) con l'esito della verifica catena. */
    public static function streamEventsPdf(array $events, string $title, string $filename): void
    {
        $chain = self::verifyChain();
        $company = Database::fetchOne("SELECT name FROM companies WHERE id = ?", [self::cid()]);

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Academy');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, $title, 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, ($company['name'] ?? '') . ' - generato il ' . date('d/m/Y H:i:s') . ' - ' . count($events) . ' eventi', 0, 1);
        $pdf->Cell(0, 5, 'Integrità registro: ' . ($chain['ok']
            ? 'verificata (' . $chain['checked'] . ' righe, catena di hash SHA-256 integra)'
            : 'ATTENZIONE: catena interrotta alla riga ' . $chain['broken_id']), 0, 1);
        $pdf->Ln(2);

        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<table border="0.3" cellpadding="3" style="font-size:7.5pt;">'
              . '<tr style="background-color:#e8edf7;font-weight:bold;">'
              . '<td width="7%">N.</td><td width="11%">Data e ora</td><td width="13%">Evento</td>'
              . '<td width="14%">Dipendente</td><td width="16%">Materiale / dettagli</td>'
              . '<td width="12%">Eseguito da</td><td width="9%">IP</td><td width="18%">Browser</td></tr>';
        foreach ($events as $ev) {
            $detail = $ev['material_title'] ?: ($ev['event'] === 'completed' ? 'Dichiarazione accettata' : ($ev['details'] ?? ''));
            $html .= '<tr>'
                  . '<td>' . (int) $ev['id'] . '</td>'
                  . '<td>' . date('d/m/Y H:i:s', strtotime($ev['created_at'])) . '</td>'
                  . '<td>' . $esc(self::EVENT_LABELS[$ev['event']] ?? $ev['event']) . '</td>'
                  . '<td>' . $esc(trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''))) . '<br>' . $esc($ev['fiscal_code'] ?? '') . '</td>'
                  . '<td>' . $esc(mb_substr((string) $detail, 0, 120)) . '</td>'
                  . '<td>' . $esc(self::actorLabel($ev)) . '</td>'
                  . '<td>' . $esc($ev['ip_address'] ?? '') . '</td>'
                  . '<td>' . $esc(mb_substr((string) ($ev['user_agent'] ?? ''), 0, 90)) . '</td>'
                  . '</tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        while (ob_get_level()) { ob_end_clean(); }
        $pdf->Output($filename, 'D');
        exit;
    }
}
