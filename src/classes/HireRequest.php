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
        if (Database::exists('hire_requests', "fiscal_code = ? AND company_id = ? AND status NOT IN ('rejected','cancelled')", [$fc, $companyId])) {
            return ['success' => false, 'error' => 'Esiste gia una richiesta di assunzione in corso per questo codice fiscale'];
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

    // === Fase 2 / 3 (saranno completate prossimo step) ===
    // - addProspect(hireRequestId, files, displayNames) -> consulente carica prospetti -> stato prospects_review
    // - approveProspects(hireRequestId) -> admin approva -> crea employee + sposta files al profilo, stato approved
    // - rejectProspects(hireRequestId, reason) -> stato rejected
    // - addContract(hireRequestId, file) -> consulente carica contratto -> stato contract_pending
    // - signContract(hireRequestId, signatureImageDataUrl, ip, ua) -> dipendente firma -> stato contract_signed
}
