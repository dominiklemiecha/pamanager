<?php
/**
 * HireRequest - Flusso di assunzione admin -> consulente -> firma contratto.
 *
 * Stati:
 *   awaiting_prospects -> prospects_review -> approved -> contract_pending -> contract_signed
 *   (rejected / cancelled in qualsiasi punto)
 *
 * Admin: crea richiesta con anagrafica + allegati identita/CF/etc.
 * Consulente: carica prospetti di assunzione, poi (dopo approvazione admin) carica contratto.
 * Dipendente (creato dopo approvazione): firma il contratto.
 */
class HireRequest
{
    public const UPLOAD_BASE = 'hire-requests';

    public static function statuses(): array
    {
        return [
            'awaiting_prospects' => 'In attesa prospetti',
            'prospects_review'   => 'Prospetti da approvare',
            'approved'           => 'Da contrattualizzare',
            'contract_pending'   => 'Contratto da firmare',
            'contract_signed'    => 'Contratto firmato',
            'rejected'           => 'Rifiutata',
            'cancelled'          => 'Annullata',
        ];
    }

    public static function statusLabel(string $s): string
    {
        return self::statuses()[$s] ?? $s;
    }

    public static function getById(int $id): ?array
    {
        $r = Database::fetchOne("SELECT * FROM hire_requests WHERE id = ?", [$id]);
        if (!$r) return null;
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $accessible = class_exists('Tenant') ? Tenant::accessibleCompanyIdsForCurrentUser() : [$cid];
        if (!in_array((int)$r['company_id'], array_map('intval', $accessible), true)) return null;
        return $r;
    }

    public static function listForCurrent(?string $status = null): array
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $sql = "SELECT hr.*, u.name AS created_by_name
                FROM hire_requests hr
                LEFT JOIN users u ON u.id = hr.created_by_user_id
                WHERE hr.company_id = ?";
        $args = [$cid];
        if ($status !== null && $status !== '') {
            $sql .= " AND hr.status = ?";
            $args[] = $status;
        }
        $sql .= " ORDER BY hr.created_at DESC";
        return Database::fetchAll($sql, $args);
    }

    /**
     * Genera username unico "nome.cognome" con suffisso .2/.3 in caso di collisione.
     * Cerca sia in employees che hire_requests della stessa azienda.
     */
    public static function generateUsername(string $firstName, string $lastName, int $companyId): string
    {
        $norm = function(string $s): string {
            $s = mb_strtolower(trim($s), 'UTF-8');
            $tr = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                   'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
                   'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c'];
            $s = strtr($s, $tr);
            $s = preg_replace('/[^a-z0-9]+/', '', $s);
            return $s ?: 'user';
        };
        $base = $norm($firstName) . '.' . $norm($lastName);
        $candidate = $base;
        $n = 2;
        while (
            Database::exists('employees', 'username = ? AND company_id = ?', [$candidate, $companyId]) ||
            Database::exists('hire_requests', 'generated_username = ? AND company_id = ?', [$candidate, $companyId])
        ) {
            $candidate = $base . '.' . $n;
            $n++;
            if ($n > 999) break;
        }
        return $candidate;
    }

    /** Trova il consulente assegnato a un'azienda (primo trovato). */
    public static function findConsulenteForCompany(int $companyId): ?int
    {
        $row = Database::fetchOne(
            "SELECT u.id FROM users u
             JOIN user_companies uc ON uc.user_id = u.id
             WHERE uc.company_id = ? AND u.role = 'consulente_lavoro' AND u.is_active = 1
             LIMIT 1",
            [$companyId]
        );
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Crea una nuova richiesta di assunzione (stato awaiting_prospects).
     * @param array $data tutti i campi del form
     * @param array $files array di file caricati (id_doc obbligatorio, fiscal_code_doc obbligatorio, permit/c2 opt)
     * @return array ['success'=>bool, 'error'=>?string, 'id'=>?int]
     */
    public static function create(array $data, array $files): array
    {
        $required = ['employer_name','employee_first_name','employee_last_name','employee_birth_date',
                     'birth_state','birth_city','fiscal_code','residence_address','residence_cap',
                     'residence_city','residence_province','marital_status','education_level',
                     'start_date','role_description','weekly_hours','workplace','employee_email'];
        foreach ($required as $f) {
            if (empty(trim((string)($data[$f] ?? '')))) {
                return ['success' => false, 'error' => "Campo obbligatorio mancante: $f"];
            }
        }
        $fc = strtoupper(trim((string)$data['fiscal_code']));
        if (!preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $fc)) {
            return ['success' => false, 'error' => 'Codice fiscale non valido'];
        }
        if (!filter_var($data['employee_email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email non valida'];
        }
        $contractFlags = ['contract_indeterminato','contract_determinato','contract_apprendistato','contract_tirocinio','contract_agevolata'];
        $anyContract = false;
        foreach ($contractFlags as $cf) if (!empty($data[$cf])) { $anyContract = true; break; }
        if (!$anyContract) return ['success' => false, 'error' => 'Seleziona almeno una tipologia di contratto'];

        $workDaysIn = $data['work_days'] ?? [];
        $allowedDays = ['mon','tue','wed','thu','fri','sat','sun'];
        $workDays = array_values(array_intersect($workDaysIn, $allowedDays));
        if (empty($workDays)) return ['success' => false, 'error' => 'Seleziona almeno un giorno di lavoro'];

        $u = Auth::getUser();
        if (!$u) return ['success' => false, 'error' => 'Non autorizzato'];
        $companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        if ($companyId <= 0) return ['success' => false, 'error' => 'Azienda non valida'];

        if (Database::exists('employees', 'fiscal_code = ? AND company_id = ?', [$fc, $companyId])) {
            return ['success' => false, 'error' => 'Esiste gia un dipendente con questo codice fiscale'];
        }
        // Blocca solo se esiste una richiesta REALMENTE in corso (non firmata/rifiutata/annullata,
        // e con l'employee_id ancora esistente — se il dipendente e stato eliminato dall'admin
        // la richiesta non e piu vincolante).
        $__dup = Database::fetchAll(
            "SELECT hr.id, hr.employee_id
             FROM hire_requests hr
             WHERE hr.fiscal_code = ? AND hr.company_id = ?
               AND hr.status NOT IN ('rejected','cancelled','contract_signed')",
            [$fc, $companyId]
        );
        foreach ($__dup as $__row) {
            $__empExists = !empty($__row['employee_id'])
                ? (bool) Database::fetchColumn("SELECT 1 FROM employees WHERE id = ?", [(int)$__row['employee_id']])
                : true; // nessun employee collegato => richiesta ancora "viva"
            if ($__empExists) {
                return ['success' => false, 'error' => 'Esiste gia una richiesta di assunzione in corso per questo codice fiscale'];
            }
            // Dipendente eliminato: cancella la richiesta orfana per sbloccare il riuso
            Database::update('hire_requests', ['status' => 'cancelled'], 'id = ?', [(int)$__row['id']]);
        }

        $idDocFiles = self::normalizeMulti($files['id_doc'] ?? []);
        $fcDocFiles = self::normalizeMulti($files['fiscal_code_doc'] ?? []);
        $permitFiles = self::normalizeMulti($files['permit'] ?? []);
        $c2Files = self::normalizeMulti($files['c2'] ?? []);
        if (empty($idDocFiles)) return ['success' => false, 'error' => 'Documento di riconoscimento obbligatorio'];
        if (empty($fcDocFiles)) return ['success' => false, 'error' => 'Codice fiscale (PDF/immagine) obbligatorio'];

        $username = self::generateUsername($data['employee_first_name'], $data['employee_last_name'], $companyId);
        $consulenteId = self::findConsulenteForCompany($companyId);

        try {
            Database::beginTransaction();
            $id = Database::insert('hire_requests', [
                'company_id' => $companyId,
                'status' => 'awaiting_prospects',
                'created_by_user_id' => (int)$u['id'],
                'assigned_consulente_user_id' => $consulenteId,
                'employer_name' => trim($data['employer_name']),
                'employee_first_name' => trim($data['employee_first_name']),
                'employee_last_name' => trim($data['employee_last_name']),
                'employee_birth_date' => $data['employee_birth_date'],
                'birth_state' => trim($data['birth_state']),
                'birth_city' => trim($data['birth_city']),
                'fiscal_code' => $fc,
                'residence_address' => trim($data['residence_address']),
                'residence_cap' => trim($data['residence_cap']),
                'residence_city' => trim($data['residence_city']),
                'residence_province' => trim($data['residence_province']),
                'marital_status' => $data['marital_status'],
                'education_level' => $data['education_level'],
                'contract_indeterminato' => !empty($data['contract_indeterminato']) ? 1 : 0,
                'contract_determinato' => !empty($data['contract_determinato']) ? 1 : 0,
                'contract_apprendistato' => !empty($data['contract_apprendistato']) ? 1 : 0,
                'contract_tirocinio' => !empty($data['contract_tirocinio']) ? 1 : 0,
                'contract_agevolata' => !empty($data['contract_agevolata']) ? 1 : 0,
                'start_date' => $data['start_date'],
                'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
                'role_description' => trim($data['role_description']),
                'weekly_hours' => (float) str_replace(',', '.', (string)$data['weekly_hours']),
                'work_days' => implode(',', $workDays),
                'workplace' => trim($data['workplace']),
                'cost_center' => !empty($data['cost_center']) ? trim($data['cost_center']) : null,
                'iban' => !empty($data['iban']) ? strtoupper(preg_replace('/\s+/', '', $data['iban'])) : null,
                'employee_email' => trim($data['employee_email']),
                'personal_email' => !empty($data['personal_email']) ? trim($data['personal_email']) : null,
                'generated_username' => $username,
                'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
            ]);

            // Allegati iniziali (multipli per categoria)
            foreach ($idDocFiles as $f) self::saveUploadedFile($id, $f, 'id_doc');
            foreach ($fcDocFiles as $f) self::saveUploadedFile($id, $f, 'fiscal_code_doc');
            foreach ($permitFiles as $f) self::saveUploadedFile($id, $f, 'permit');
            foreach ($c2Files as $f) self::saveUploadedFile($id, $f, 'c2');

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            error_log('[HireRequest::create] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore creazione richiesta: ' . $e->getMessage()];
        }

        // Notifica consulente
        if ($consulenteId && class_exists('Notification')) {
            try {
                Notification::create([
                    'recipient_type' => 'consulente_lavoro',
                    'recipient_id'   => $consulenteId,
                    'type'           => 'hire_request_new',
                    'title'          => 'Nuova richiesta di assunzione',
                    'message'        => 'L\'admin ha avviato l\'assunzione di ' . $data['employee_first_name'] . ' ' . $data['employee_last_name'] . '. Carica i prospetti.',
                    'link'           => '/consulente-lavoro/hire-requests.php?id=' . $id,
                ]);
            } catch (Throwable $e) {}
        }

        return ['success' => true, 'id' => $id];
    }

    /**
     * Converte $_FILES['campo'] (sia singolo che multiplo name="campo[]")
     * in array di file singoli, ognuno con {name, tmp_name, type, size, error}.
     * Filtra entries vuote (UPLOAD_ERR_NO_FILE) o senza tmp_name.
     */
    private static function normalizeMulti(array $f): array
    {
        if (empty($f)) return [];
        // Multi: $f['name'] e' un array
        if (isset($f['name']) && is_array($f['name'])) {
            $out = [];
            $n = count($f['name']);
            for ($i = 0; $i < $n; $i++) {
                if (empty($f['tmp_name'][$i])) continue;
                if (($f['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) continue;
                $out[] = [
                    'name'     => $f['name'][$i],
                    'tmp_name' => $f['tmp_name'][$i],
                    'type'     => $f['type'][$i] ?? null,
                    'size'     => $f['size'][$i] ?? 0,
                    'error'    => $f['error'][$i] ?? UPLOAD_ERR_OK,
                ];
            }
            return $out;
        }
        // Singolo
        if (empty($f['tmp_name'])) return [];
        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [];
        return [$f];
    }

    /** Salva un file caricato in storage/hire-requests/{id}/{category}/. */
    private static function saveUploadedFile(int $hireRequestId, array $file, string $category, ?string $displayName = null): int
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException("File $category non caricato");
        }
        $dir = self::storageDir($hireRequestId, $category);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Impossibile creare directory: $dir");
            }
        }
        $orig = $file['name'] ?? 'file';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowedExt = ['pdf','jpg','jpeg','png','webp','heic','heif','doc','docx'];
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException("Estensione non consentita per $category: .$ext");
        }
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new RuntimeException("File $category troppo grande (max 20MB)");
        }
        $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException("Impossibile salvare il file");
        }
        $u = Auth::getUser();
        return Database::insert('hire_request_files', [
            'hire_request_id' => $hireRequestId,
            'category' => $category,
            'file_path' => self::relPath($hireRequestId, $category, $safeName),
            'original_name' => $orig,
            'display_name' => $displayName,
            'mime_type' => $file['type'] ?? null,
            'file_size' => (int)($file['size'] ?? 0),
            'uploaded_by_user_id' => $u ? (int)$u['id'] : null,
        ]);
    }

    public static function storageDir(int $hireRequestId, string $category): string
    {
        $base = defined('UPLOAD_PATH') ? UPLOAD_PATH : (dirname(__DIR__, 2) . '/public/uploads');
        return $base . '/' . self::UPLOAD_BASE . '/' . $hireRequestId . '/' . $category;
    }

    public static function relPath(int $hireRequestId, string $category, string $filename): string
    {
        return self::UPLOAD_BASE . '/' . $hireRequestId . '/' . $category . '/' . $filename;
    }

    public static function fileFsPath(array $fileRow): string
    {
        $base = defined('UPLOAD_PATH') ? UPLOAD_PATH : (dirname(__DIR__, 2) . '/public/uploads');
        return $base . '/' . $fileRow['file_path'];
    }

    public static function getFiles(int $hireRequestId, ?string $category = null): array
    {
        $sql = "SELECT * FROM hire_request_files WHERE hire_request_id = ?";
        $args = [$hireRequestId];
        if ($category !== null) { $sql .= " AND category = ?"; $args[] = $category; }
        $sql .= " ORDER BY uploaded_at ASC";
        return Database::fetchAll($sql, $args);
    }

    /**
     * Elimina una richiesta di assunzione: cancella files su disco, righe DB (cascade) e cartella.
     * Permesso solo all'admin sull'azienda corrente.
     */
    public static function delete(int $hireRequestId): array
    {
        $u = Auth::getUser();
        if (!$u || ($u['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Non autorizzato'];
        }
        $hr = self::getById($hireRequestId);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        // Nessuno stato e protetto: l'admin puo' sempre eliminare una richiesta
        // (es. correzione errore di compilazione, rimozione storica, dipendente gia cancellato).

        try {
            // Cancella file su disco
            $files = self::getFiles($hireRequestId);
            foreach ($files as $f) {
                $path = self::fileFsPath($f);
                if (is_file($path)) @unlink($path);
            }
            // Cancella la cartella della richiesta (best effort)
            $base = defined('UPLOAD_PATH') ? UPLOAD_PATH : (dirname(__DIR__, 2) . '/public/uploads');
            $dir = $base . '/' . self::UPLOAD_BASE . '/' . $hireRequestId;
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
                @rmdir($dir);
            }
            // Cancella le righe: FK ON DELETE CASCADE su hire_request_files
            Database::delete('hire_requests', 'id = ?', [$hireRequestId]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Errore eliminazione: ' . $e->getMessage()];
        }

        if (class_exists('AuditLog')) {
            try { AuditLog::log('hire_request_deleted', 'hire_request', $hireRequestId, $hr, null); } catch (Throwable $e) {}
        }
        return ['success' => true];
    }

    /**
     * Crea un nuovo PDF identico all'originale con la firma sovrapposta in fondo all'ultima pagina
     * (sfondo PNG trasparente) + metadata legali. Ritorna il path del file generato o null.
     */
    private static function buildSignedContractPdf(string $contractFs, string $sigPngPath, array $hr, array $emp, string $ip, string $hash): ?string
    {
        if (!class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (is_file($autoload)) require_once $autoload;
        }
        if (!class_exists('setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            error_log('[HireRequest::buildSignedContractPdf] FPDI/TCPDF non disponibile');
            return null;
        }

        try {
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->SetMargins(0, 0, 0);

            $pageCount = $pdf->setSourceFile($contractFs);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tplId = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tplId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);

                // Solo sull'ultima pagina sovrapponi la firma
                if ($p === $pageCount) {
                    $pageW = $size['width'];
                    $pageH = $size['height'];
                    // Firma in basso a destra (~ 65mm di larghezza)
                    $sigW = 65;
                    $sigH = 22;
                    $marginR = 12;
                    $marginB = 14;
                    $x = $pageW - $sigW - $marginR;
                    $y = $pageH - $sigH - $marginB;
                    $pdf->Image($sigPngPath, $x, $y, $sigW, $sigH, 'PNG', '', '', false, 300, '', false, false, 0);

                    // Etichetta firma + metadata legali sotto la firma
                    $pdf->SetTextColor(60, 60, 60);
                    $pdf->SetFont('helvetica', 'B', 8);
                    $pdf->SetXY($x, $y + $sigH + 0.5);
                    $pdf->Cell($sigW, 4, 'Firmato digitalmente da:', 0, 1, 'L');
                    $pdf->SetFont('helvetica', '', 8);
                    $pdf->SetX($x);
                    $pdf->Cell($sigW, 4, $emp['first_name'] . ' ' . $emp['last_name'], 0, 1, 'L');
                    $pdf->SetFont('helvetica', '', 6.5);
                    $pdf->SetX($x);
                    $pdf->Cell($sigW, 3, date('d/m/Y H:i:s') . ' - IP ' . $ip, 0, 1, 'L');
                    $pdf->SetX($x);
                    $pdf->Cell($sigW, 3, 'SHA256: ' . substr($hash, 0, 32) . '...', 0, 1, 'L');
                }
            }

            $outDir = self::storageDir((int)$hr['id'], 'signed_contract');
            if (!is_dir($outDir)) mkdir($outDir, 0775, true);
            $outName = 'contratto-firmato.pdf';
            $outPath = $outDir . '/' . $outName;
            $pdf->Output($outPath, 'F');

            // Salva riga signed_contract
            Database::insert('hire_request_files', [
                'hire_request_id' => (int)$hr['id'],
                'category' => 'signed_contract',
                'file_path' => self::relPath((int)$hr['id'], 'signed_contract', $outName),
                'original_name' => 'contratto-firmato.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => filesize($outPath) ?: 0,
                'uploaded_by_employee_id' => (int)$emp['id'],
                'signed_ip' => $ip,
                'signature_hash' => $hash,
            ]);

            return $outPath;
        } catch (Throwable $e) {
            error_log('[HireRequest::buildSignedContractPdf] ' . $e->getMessage());
            return null;
        }
    }

    public static function workDaysLabels(string $set): string
    {
        $m = ['mon'=>'Lun','tue'=>'Mar','wed'=>'Mer','thu'=>'Gio','fri'=>'Ven','sat'=>'Sab','sun'=>'Dom'];
        $out = [];
        foreach (explode(',', $set) as $d) {
            $d = trim($d);
            if (isset($m[$d])) $out[] = $m[$d];
        }
        return implode(', ', $out);
    }

    public static function contractTypesLabels(array $hr): string
    {
        $map = [
            'contract_indeterminato' => 'Indeterminato',
            'contract_determinato' => 'Determinato',
            'contract_apprendistato' => 'Apprendistato',
            'contract_tirocinio' => 'Tirocinio/Stage',
            'contract_agevolata' => 'Agevolata da discutere',
        ];
        $out = [];
        foreach ($map as $k => $lbl) if (!empty($hr[$k])) $out[] = $lbl;
        return implode(', ', $out);
    }

    // ====== FASE 2 ======

    /**
     * Consulente carica prospetti di assunzione (multipli).
     * @param int $hireRequestId
     * @param array $files array di $_FILES (es. $_FILES['prospects'])
     * @param array $displayNames array di stringhe, uno per file, opzionali
     * @return array success/error
     */
    public static function addProspects(int $hireRequestId, array $files, array $displayNames = []): array
    {
        $u = Auth::getUser();
        if (!$u || ($u['role'] ?? '') !== 'consulente_lavoro') {
            return ['success' => false, 'error' => 'Solo il consulente puo caricare i prospetti'];
        }
        $hr = self::getById($hireRequestId);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        if (!in_array($hr['status'], ['awaiting_prospects', 'prospects_review'], true)) {
            return ['success' => false, 'error' => 'Non puoi caricare prospetti in questo stato'];
        }
        $list = self::normalizeMulti($files);
        if (empty($list)) return ['success' => false, 'error' => 'Nessun file caricato'];

        try {
            foreach ($list as $i => $f) {
                $name = $displayNames[$i] ?? null;
                self::saveUploadedFile($hireRequestId, $f, 'prospect', $name);
            }
            if ($hr['status'] !== 'prospects_review') {
                Database::update('hire_requests', ['status' => 'prospects_review'], 'id = ?', [$hireRequestId]);
            }
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Errore upload: ' . $e->getMessage()];
        }

        // Notifica admin/i dell'azienda
        try {
            $admins = Database::fetchAll(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1
                 AND (company_id = ? OR company_id IS NULL)",
                [(int)$hr['company_id']]
            );
            foreach ($admins as $a) {
                Notification::create([
                    'recipient_type' => 'admin',
                    'recipient_id'   => (int)$a['id'],
                    'type'           => 'hire_prospects_uploaded',
                    'title'          => 'Prospetti assunzione da approvare',
                    'message'        => 'Il consulente ha caricato i prospetti per ' . $hr['employee_first_name'] . ' ' . $hr['employee_last_name'],
                    'link'           => '/admin/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}

        return ['success' => true];
    }

    /**
     * Admin approva i prospetti: crea l'employee, trasferisce i documenti al profilo, sblocca fase contratto.
     * @param int $hireRequestId
     * @param array $extra ['department_id' (opt), 'position' (opt), 'monthly_salary' (opt), 'ral_amount' (opt), 'job_level' (opt)]
     */
    public static function approveProspects(int $hireRequestId, array $extra = []): array
    {
        $u = Auth::getUser();
        if (!$u || ($u['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Solo admin puo approvare'];
        }
        $hr = self::getById($hireRequestId);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        if ($hr['status'] !== 'prospects_review') {
            return ['success' => false, 'error' => 'La richiesta non e in stato di approvazione'];
        }

        // Setta tenant corrente sul company_id della richiesta (per Employee::create)
        $_SESSION['tenant_company_id'] = (int)$hr['company_id'];

        // Map work_days SET (es. "mon,tue,wed,thu,fri")
        $workDays = $hr['work_days'];
        $weeklyHours = (float)$hr['weekly_hours'];
        $daysCount = count(array_filter(explode(',', $workDays)));
        $hoursPerDay = $daysCount > 0 ? round($weeklyHours / $daysCount, 2) : null;

        $payload = [
            'username'      => $hr['generated_username'],
            'first_name'    => $hr['employee_first_name'],
            'last_name'     => $hr['employee_last_name'],
            'fiscal_code'   => $hr['fiscal_code'],
            'email'         => $hr['employee_email'],
            'birth_date'    => $hr['employee_birth_date'],
            'address'       => trim($hr['residence_address'] . ', ' . $hr['residence_cap'] . ' ' . $hr['residence_city'] . ' (' . $hr['residence_province'] . ')'),
            'iban'          => $hr['iban'] ?? null,
            'hire_date'     => $hr['start_date'],
            'position'      => $extra['position'] ?? $hr['role_description'],
            'department_id' => $extra['department_id'] ?? null,
            'job_level'     => $extra['job_level'] ?? null,
            'ral_amount'    => $extra['ral_amount'] ?? null,
            'monthly_salary'=> $extra['monthly_salary'] ?? null,
        ];

        // department_id puo' essere obbligatorio per Employee::create. Se manca, prendi il primo attivo dell'azienda
        if (empty($payload['department_id'])) {
            $dept = Database::fetchOne(
                "SELECT id FROM departments WHERE company_id = ? AND is_active = TRUE ORDER BY id LIMIT 1",
                [(int)$hr['company_id']]
            );
            if ($dept) $payload['department_id'] = (int)$dept['id'];
        }
        if (empty($payload['department_id'])) {
            return ['success' => false, 'error' => 'Devi prima creare almeno un reparto nell\'azienda'];
        }

        $created = Employee::create($payload);
        if (!$created['success']) {
            return ['success' => false, 'error' => 'Errore creazione dipendente: ' . ($created['error'] ?? 'sconosciuto')];
        }
        $empId = (int)($created['id'] ?? 0);
        if ($empId <= 0) return ['success' => false, 'error' => 'Creazione dipendente fallita'];

        $emailSent = !empty($created['email_sent']);
        $emailError = $created['email_error'] ?? null;

        // Aggiorna working_days e hours_per_day (non gestiti da Employee::create direttamente)
        try {
            Database::update('employees', [
                'working_days'  => $workDays,
                'hours_per_day' => $hoursPerDay,
            ], 'id = ?', [$empId]);
        } catch (Throwable $e) {}

        // Trasferisci gli allegati admin (id_doc, fiscal_code_doc, permit, c2) come documenti del dipendente
        $now = new DateTime();
        $month = (int)$now->format('n');
        $year  = (int)$now->format('Y');
        $labelMap = [
            'id_doc'          => 'Documento di riconoscimento',
            'fiscal_code_doc' => 'Codice fiscale',
            'permit'          => 'Permesso di soggiorno',
            'c2'              => 'Modello C2',
        ];
        foreach ($labelMap as $cat => $label) {
            $rows = self::getFiles($hireRequestId, $cat);
            foreach ($rows as $f) {
                $src = self::fileFsPath($f);
                if (!is_file($src)) continue;
                try {
                    $created = Document::uploadFromPath($src, [
                        'employee_id'   => $empId,
                        'type'          => 'other',
                        'month'         => $month,
                        'year'          => $year,
                        'title'         => $label,
                        'description'   => 'Caricato in fase di assunzione (richiesta #' . $hireRequestId . ')',
                        'original_name' => $f['original_name'],
                    ]);
                    if (!empty($created['id'])) {
                        Database::update('documents', ['notify_employee' => 0], 'id = ?', [(int)$created['id']]);
                    }
                } catch (Throwable $e) {}
            }
        }

        // Aggiorna richiesta
        Database::update('hire_requests', [
            'status'             => 'approved',
            'employee_id'        => $empId,
            'decided_at'         => date('Y-m-d H:i:s'),
            'decided_by_user_id' => (int)$u['id'],
        ], 'id = ?', [$hireRequestId]);

        // Notifica consulente
        try {
            if (!empty($hr['assigned_consulente_user_id'])) {
                Notification::create([
                    'recipient_type' => 'consulente_lavoro',
                    'recipient_id'   => (int)$hr['assigned_consulente_user_id'],
                    'type'           => 'hire_approved',
                    'title'          => 'Assunzione approvata',
                    'message'        => 'Carica il contratto per ' . $hr['employee_first_name'] . ' ' . $hr['employee_last_name'],
                    'link'           => '/consulente-lavoro/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}

        return ['success' => true, 'employee_id' => $empId, 'email_sent' => $emailSent, 'email_error' => $emailError];
    }

    /**
     * Consulente carica il contratto (un solo PDF). Stato -> contract_pending.
     */
    public static function addContract(int $hireRequestId, array $file): array
    {
        $u = Auth::getUser();
        if (!$u || ($u['role'] ?? '') !== 'consulente_lavoro') {
            return ['success' => false, 'error' => 'Solo il consulente puo caricare il contratto'];
        }
        $hr = self::getById($hireRequestId);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        if ($hr['status'] !== 'approved' && $hr['status'] !== 'contract_pending') {
            return ['success' => false, 'error' => 'Stato non valido per caricare contratto'];
        }
        if (empty($file['tmp_name']) || empty($file['name'])) {
            return ['success' => false, 'error' => 'File contratto obbligatorio'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Errore upload contratto'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return ['success' => false, 'error' => 'Il contratto deve essere un PDF'];

        try {
            // Se gia esiste un contratto precedente, sostituisci
            $existing = self::getFiles($hireRequestId, 'contract');
            foreach ($existing as $e) {
                $p = self::fileFsPath($e);
                if (is_file($p)) @unlink($p);
                Database::delete('hire_request_files', 'id = ?', [(int)$e['id']]);
            }
            self::saveUploadedFile($hireRequestId, $file, 'contract');
            Database::update('hire_requests', ['status' => 'contract_pending'], 'id = ?', [$hireRequestId]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Errore upload: ' . $e->getMessage()];
        }

        // Notifica dipendente
        try {
            if (!empty($hr['employee_id'])) {
                Notification::create([
                    'recipient_type' => 'employee',
                    'recipient_id'   => (int)$hr['employee_id'],
                    'type'           => 'contract_to_sign',
                    'title'          => 'Contratto da firmare',
                    'message'        => 'Il tuo contratto e\' pronto. Aprilo e firmalo dal portale.',
                    'link'           => '/employee/contract-sign.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}
        // Notifica admin
        try {
            $admins = Database::fetchAll(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND (company_id = ? OR company_id IS NULL)",
                [(int)$hr['company_id']]
            );
            foreach ($admins as $a) {
                Notification::create([
                    'recipient_type' => 'admin',
                    'recipient_id'   => (int)$a['id'],
                    'type'           => 'contract_uploaded',
                    'title'          => 'Contratto caricato',
                    'message'        => 'Il consulente ha caricato il contratto per ' . $hr['employee_first_name'] . ' ' . $hr['employee_last_name'] . '. In attesa firma dipendente.',
                    'link'           => '/admin/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}

        return ['success' => true];
    }

    /**
     * Dipendente firma il contratto: salva immagine firma (PNG da canvas),
     * registra IP/UA/timestamp/hash SHA256 del contratto, status -> contract_signed.
     * Trasferisce il contratto come Document del dipendente (visibile dal profilo).
     */
    public static function signContract(int $hireRequestId, string $signatureDataUrl): array
    {
        $emp = Auth::getEmployee();
        if (!$emp) return ['success' => false, 'error' => 'Solo il dipendente puo firmare'];
        $hr = Database::fetchOne("SELECT * FROM hire_requests WHERE id = ?", [$hireRequestId]);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        if ((int)$hr['employee_id'] !== (int)$emp['id']) {
            return ['success' => false, 'error' => 'Questo contratto non e tuo'];
        }
        if ($hr['status'] !== 'contract_pending') {
            return ['success' => false, 'error' => 'Stato non valido per firmare'];
        }

        if (!preg_match('#^data:image/png;base64,(.+)$#', $signatureDataUrl, $m)) {
            return ['success' => false, 'error' => 'Firma non valida (atteso PNG base64)'];
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) < 200) {
            return ['success' => false, 'error' => 'Firma troppo breve o corrotta'];
        }
        if (strlen($png) > 2 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Firma troppo grande'];
        }

        // Trova il contratto
        $contractRow = Database::fetchOne(
            "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1",
            [$hireRequestId]
        );
        if (!$contractRow) return ['success' => false, 'error' => 'Contratto non disponibile'];
        $contractFs = self::fileFsPath($contractRow);
        if (!is_file($contractFs)) return ['success' => false, 'error' => 'File contratto non trovato sul filesystem'];

        $hash = hash_file('sha256', $contractFs);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // Salva firma PNG (sfondo gia trasparente dal canvas toDataURL('image/png'))
        $dir = self::storageDir($hireRequestId, 'signature_image');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $sigName = 'signature.png';
        $sigPath = $dir . '/' . $sigName;
        if (file_put_contents($sigPath, $png) === false) {
            return ['success' => false, 'error' => 'Impossibile salvare la firma'];
        }

        // Genera contratto firmato (overlay firma + metadata legali in fondo)
        $signedPath = self::buildSignedContractPdf($contractFs, $sigPath, $hr, $emp, $ip, $hash);
        Database::insert('hire_request_files', [
            'hire_request_id' => $hireRequestId,
            'category' => 'signature_image',
            'file_path' => self::relPath($hireRequestId, 'signature_image', $sigName),
            'original_name' => 'firma.png',
            'mime_type' => 'image/png',
            'file_size' => strlen($png),
            'uploaded_by_employee_id' => (int)$emp['id'],
            'signed_ip' => $ip,
            'signed_user_agent' => $ua,
            'signature_hash' => $hash,
        ]);

        // Aggiorna richiesta
        Database::update('hire_requests', ['status' => 'contract_signed'], 'id = ?', [$hireRequestId]);

        // Crea Document del dipendente (visibile dal profilo) - usa il PDF con firma overlay se generato
        try {
            $now = new DateTime();
            $sourceForDoc = ($signedPath && is_file($signedPath)) ? $signedPath : $contractFs;
            $docUploadedBy = !empty($hr['decided_by_user_id'])
                ? (int)$hr['decided_by_user_id']
                : (!empty($hr['created_by_user_id']) ? (int)$hr['created_by_user_id'] : 0);
            Document::uploadFromPath($sourceForDoc, [
                'employee_id'   => (int)$emp['id'],
                'type'          => 'other',
                'month'         => (int)$now->format('n'),
                'year'          => (int)$now->format('Y'),
                'title'         => 'Contratto di assunzione firmato',
                'description'   => 'Firmato il ' . $now->format('d/m/Y H:i') . ' - IP: ' . $ip . ' - SHA256 originale: ' . substr($hash, 0, 16) . '...',
                'original_name' => 'contratto-firmato.pdf',
                'uploaded_by'   => $docUploadedBy,
            ]);
        } catch (Throwable $e) {
            error_log('[HireRequest::signContract] Document::uploadFromPath fallito: ' . $e->getMessage());
        }

        // Notifica admin e consulente
        try {
            $admins = Database::fetchAll(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND (company_id = ? OR company_id IS NULL)",
                [(int)$hr['company_id']]
            );
            foreach ($admins as $a) {
                Notification::create([
                    'recipient_type' => 'admin',
                    'recipient_id'   => (int)$a['id'],
                    'type'           => 'contract_signed',
                    'title'          => 'Contratto firmato',
                    'message'        => $emp['first_name'] . ' ' . $emp['last_name'] . ' ha firmato il contratto.',
                    'link'           => '/admin/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
            if (!empty($hr['assigned_consulente_user_id'])) {
                Notification::create([
                    'recipient_type' => 'consulente_lavoro',
                    'recipient_id'   => (int)$hr['assigned_consulente_user_id'],
                    'type'           => 'contract_signed',
                    'title'          => 'Contratto firmato',
                    'message'        => $emp['first_name'] . ' ' . $emp['last_name'] . ' ha firmato il contratto.',
                    'link'           => '/consulente-lavoro/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}

        return ['success' => true];
    }

    public static function rejectProspects(int $hireRequestId, string $reason): array
    {
        $u = Auth::getUser();
        if (!$u || ($u['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Solo admin puo rifiutare'];
        }
        $hr = self::getById($hireRequestId);
        if (!$hr) return ['success' => false, 'error' => 'Richiesta non trovata'];
        if ($hr['status'] !== 'prospects_review') {
            return ['success' => false, 'error' => 'Stato non valido per rifiuto'];
        }
        $reason = trim($reason);
        if ($reason === '') return ['success' => false, 'error' => 'Motivazione obbligatoria'];

        Database::update('hire_requests', [
            'status'             => 'rejected',
            'rejection_reason'   => $reason,
            'decided_at'         => date('Y-m-d H:i:s'),
            'decided_by_user_id' => (int)$u['id'],
        ], 'id = ?', [$hireRequestId]);

        // Notifica consulente
        try {
            if (!empty($hr['assigned_consulente_user_id'])) {
                Notification::create([
                    'recipient_type' => 'consulente_lavoro',
                    'recipient_id'   => (int)$hr['assigned_consulente_user_id'],
                    'type'           => 'hire_rejected',
                    'title'          => 'Prospetti respinti',
                    'message'        => 'Motivo: ' . mb_substr($reason, 0, 200),
                    'link'           => '/consulente-lavoro/hire-requests.php?id=' . $hireRequestId,
                ]);
            }
        } catch (Throwable $e) {}

        return ['success' => true];
    }
}
