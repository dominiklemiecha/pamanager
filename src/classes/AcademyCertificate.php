<?php
/**
 * Attestati dell'Academy: modelli (editor visuale, layout_json), generazione
 * PDF con TCPDF, numerazione per azienda, verifica pubblica via QR.
 *
 * Il layout è lo stesso formato dell'editor di formacamere: foglio A4 di
 * 842x595 px (orizzontale) o 595x842 (verticale), elementi text/title/image/
 * line/rect posizionati in px, piu il tipo 'qr' (QR di verifica).
 *
 * Il PDF viene generato UNA volta e salvato su disco con il suo SHA-256:
 * modificare il modello dopo non cambia gli attestati già emessi.
 */

class AcademyCertificate
{
    public const PLACEHOLDERS = [
        'Dipendente' => ['{NOME}', '{COGNOME}', '{NOME_COGNOME}', '{COGNOME_NOME}', '{CF}', '{DATA_NASCITA}', '{LUOGO_NASCITA}', '{MANSIONE}', '{REPARTO}'],
        'Corso' => ['{CORSO}', '{ORE}', '{DATA_INIZIO}', '{DATA_COMPLETAMENTO}', '{AZIENDA}'],
        'Attestato' => ['{NUMERO_ATTESTATO}', '{DATA_EMISSIONE}', '{CODICE_VERIFICA}', '{URL_VERIFICA}'],
    ];

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private static function cid(): int
    {
        return (int) Tenant::currentCompanyId();
    }

    // =====================================================================
    // Modelli
    // =====================================================================

    public static function getTemplates(): array
    {
        return Database::fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM academy_courses c WHERE c.certificate_template_id = t.id) AS course_count
             FROM academy_certificate_templates t WHERE t.company_id = ? ORDER BY t.name",
            [self::cid()]
        );
    }

    public static function getTemplate(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM academy_certificate_templates WHERE id = ? AND company_id = ?",
            [$id, self::cid()]
        );
    }

    public static function saveTemplate(string $name, string $orientation, string $layoutJson, ?int $id = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Il nome del modello è obbligatorio'];
        }
        $orientation = $orientation === 'portrait' ? 'portrait' : 'landscape';
        $layout = json_decode($layoutJson, true);
        if (!is_array($layout)) {
            return ['success' => false, 'error' => 'Layout non valido'];
        }
        $row = [
            'name' => mb_substr($name, 0, 150),
            'orientation' => $orientation,
            'layout_json' => json_encode(self::sanitizeLayout($layout), JSON_UNESCAPED_UNICODE),
        ];
        if ($id !== null) {
            if (!self::getTemplate($id)) {
                return ['success' => false, 'error' => 'Modello non trovato'];
            }
            Database::update('academy_certificate_templates', $row, 'id = ? AND company_id = ?', [$id, self::cid()]);
            return ['success' => true, 'id' => $id];
        }
        $user = Auth::getUser();
        $row['company_id'] = self::cid();
        $row['created_by_user_id'] = $user['id'] ?? null;
        return ['success' => true, 'id' => Database::insert('academy_certificate_templates', $row)];
    }

    public static function duplicateTemplate(int $id): array
    {
        $t = self::getTemplate($id);
        if (!$t) {
            return ['success' => false, 'error' => 'Modello non trovato'];
        }
        return self::saveTemplate($t['name'] . ' (copia)', $t['orientation'], $t['layout_json'] ?: '[]');
    }

    public static function deleteTemplate(int $id): array
    {
        if (!self::getTemplate($id)) {
            return ['success' => false, 'error' => 'Modello non trovato'];
        }
        // I corsi che lo usavano tornano al modello predefinito (FK ON DELETE SET NULL)
        Database::delete('academy_certificate_templates', 'id = ? AND company_id = ?', [$id, self::cid()]);
        return ['success' => true];
    }

    /** Tiene solo i campi noti e limita l'HTML dei testi ai tag inline ammessi. */
    private static function sanitizeLayout(array $layout): array
    {
        $out = [];
        foreach ($layout as $el) {
            if (!is_array($el)) continue;
            $type = in_array($el['type'] ?? '', ['text', 'title', 'image', 'line', 'rect', 'qr'], true) ? $el['type'] : 'text';
            $out[] = [
                'id' => (int) ($el['id'] ?? 0),
                'type' => $type,
                'name' => mb_substr(strip_tags((string) ($el['name'] ?? '')), 0, 80),
                'x' => (float) ($el['x'] ?? 0),
                'y' => (float) ($el['y'] ?? 0),
                'width' => max(1, (float) ($el['width'] ?? 100)),
                'height' => max(1, (float) ($el['height'] ?? 30)),
                'content' => self::cleanHtml((string) ($el['content'] ?? '')),
                'fontSize' => max(4, min(200, (float) ($el['fontSize'] ?? 14))),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($el['color'] ?? '')) ? $el['color'] : '#1f2937',
                'bold' => !empty($el['bold']),
                'italic' => !empty($el['italic']),
                'align' => in_array($el['align'] ?? '', ['left', 'center', 'right'], true) ? $el['align'] : 'left',
                'src' => self::isOwnImageSrc((string) ($el['src'] ?? '')) ? (string) $el['src'] : '',
                'locked' => !empty($el['locked']),
                'visible' => !array_key_exists('visible', $el) || !empty($el['visible']),
            ];
        }
        return $out;
    }

    private static function cleanHtml(string $html): string
    {
        $html = strip_tags($html, '<b><i><u><strong><em><span><br><p><div><font>');
        // Via attributi evento/javascript: restano solo style, color, size
        $html = preg_replace('/\s(on\w+|href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        return preg_replace('/javascript:/i', '', $html);
    }

    // =====================================================================
    // Immagini dei modelli (loghi, sfondi, firme)
    // =====================================================================

    private static function imageDir(int $companyId): string
    {
        return DOCUMENTS_PATH . '/academy/' . $companyId . '/images';
    }

    public static function uploadImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Nessun file ricevuto'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Immagine troppo grande (massimo 5MB)'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($ext, self::IMAGE_EXT, true) || !str_starts_with((string) $mime, 'image/')) {
            return ['success' => false, 'error' => 'Formato non supportato (jpg, png, gif, webp)'];
        }
        $dir = self::imageDir(self::cid());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Impossibile creare la cartella immagini'];
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            return ['success' => false, 'error' => 'Errore di salvataggio'];
        }
        return ['success' => true, 'url' => PUBLIC_URL . '/academy-file.php?img=' . $name];
    }

    /** Il src deve puntare a un'immagine caricata nell'Academy (niente URL esterni). */
    private static function isOwnImageSrc(string $src): bool
    {
        return self::imageNameFromSrc($src) !== null;
    }

    private static function imageNameFromSrc(string $src): ?string
    {
        if (preg_match('/academy-file\.php\?img=([a-f0-9]{24}\.(?:jpe?g|png|gif|webp))$/i', $src, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Percorso su disco di un'immagine dei modelli, solo se esiste nell'azienda indicata. */
    public static function imagePath(string $name, int $companyId): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}\.(jpe?g|png|gif|webp)$/i', $name)) return null;
        $path = self::imageDir($companyId) . '/' . $name;
        return is_file($path) ? $path : null;
    }

    // =====================================================================
    // Generazione attestati
    // =====================================================================

    /**
     * Genera (una volta sola) l'attestato di un'assegnazione completata.
     * @return array success, id, created (false se esisteva già)
     */
    public static function generate(int $assignmentId): array
    {
        $a = self::loadAssignmentData($assignmentId);
        if (!$a) {
            return ['success' => false, 'error' => 'Assegnazione non trovata'];
        }
        if (empty($a['completed_at'])) {
            return ['success' => false, 'error' => 'Il dipendente non ha ancora completato il corso'];
        }
        $existing = Database::fetchOne("SELECT id FROM academy_certificates WHERE assignment_id = ?", [$assignmentId]);
        if ($existing) {
            return ['success' => true, 'id' => (int) $existing['id'], 'created' => false];
        }

        $companyId = self::cid();
        $lock = 'academy_cert_' . $companyId;
        Database::fetchColumn("SELECT GET_LOCK(?, 10)", [$lock]);
        try {
            // Ricontrollo dentro il lock: due generazioni in parallelo non duplicano
            if (Database::fetchOne("SELECT id FROM academy_certificates WHERE assignment_id = ?", [$assignmentId])) {
                return ['success' => true, 'id' => 0, 'created' => false];
            }
            $year = (int) date('Y');
            $seq = (int) Database::fetchColumn(
                "SELECT COALESCE(MAX(seq_number), 0) + 1 FROM academy_certificates WHERE company_id = ? AND seq_year = ?",
                [$companyId, $year]
            );
            $number = 'ACD-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(16));
            $issuedAt = date('Y-m-d H:i:s');

            $template = !empty($a['certificate_template_id']) ? self::getTemplate((int) $a['certificate_template_id']) : null;
            $values = self::placeholderValues($a, $number, $issuedAt, $token);
            $binary = self::renderPdf($template, $values, self::verifyUrl($token), $companyId);

            $dir = DOCUMENTS_PATH . '/academy/' . $companyId . '/certificates/' . $year;
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['success' => false, 'error' => 'Impossibile creare la cartella attestati'];
            }
            $path = $dir . '/' . $number . '_' . $token . '.pdf';
            if (file_put_contents($path, $binary) === false) {
                return ['success' => false, 'error' => 'Errore di salvataggio del PDF'];
            }

            $user = Auth::getUser();
            $id = Database::insert('academy_certificates', [
                'company_id' => $companyId,
                'assignment_id' => $assignmentId,
                'number' => $number,
                'seq_year' => $year,
                'seq_number' => $seq,
                'issued_at' => $issuedAt,
                'issued_by_user_id' => $user['id'] ?? null,
                'file_path' => $path,
                'sha256' => hash('sha256', $binary),
                'verify_token' => $token,
            ]);
        } finally {
            Database::fetchColumn("SELECT RELEASE_LOCK(?)", [$lock]);
        }

        Academy::logEvent('certificate_generated', [
            'course_id' => (int) $a['course_id'],
            'assignment_id' => $assignmentId,
            'employee_id' => (int) $a['employee_id'],
            'details' => $number,
        ]);
        $employee = Employee::getById((int) $a['employee_id']);
        if ($employee) {
            Academy::notifyEmployee(
                $employee,
                'Attestato disponibile',
                'L\'attestato del corso "' . $a['course_title'] . '" è disponibile nella sezione Academy.',
                (int) $a['course_id'],
                false
            );
        }
        return ['success' => true, 'id' => $id, 'created' => true];
    }

    /**
     * Genera un blocco di attestati mancanti per un corso (completati senza attestato).
     * Con $assignmentIds limita ai selezionati. Chiamato in loop via AJAX.
     */
    public static function generateBatch(int $courseId, array $assignmentIds = [], int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT a.id FROM academy_assignments a
                LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
                WHERE a.company_id = ? AND a.course_id = ? AND a.completed_at IS NOT NULL AND ce.id IS NULL";
        $params = [self::cid(), $courseId];
        $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds)));
        if (!empty($assignmentIds)) {
            $sql .= ' AND a.id IN (' . implode(',', array_fill(0, count($assignmentIds), '?')) . ')';
            $params = array_merge($params, $assignmentIds);
        }
        $all = Database::fetchAll($sql . ' ORDER BY a.id', $params);
        $done = 0;
        $errors = [];
        foreach (array_slice($all, 0, $limit) as $row) {
            $r = self::generate((int) $row['id']);
            if ($r['success']) {
                $done++;
            } else {
                $errors[] = $r['error'];
            }
        }
        return [
            'success' => empty($errors) || $done > 0,
            'generated' => $done,
            'remaining' => max(0, count($all) - $done - count($errors)),
            'errors' => $errors,
        ];
    }

    private static function loadAssignmentData(int $assignmentId): ?array
    {
        return Database::fetchOne(
            "SELECT a.*, c.title AS course_title, c.duration_hours, c.certificate_template_id,
                    e.first_name, e.last_name, e.fiscal_code, e.birth_date, e.position,
                    d.name AS department_name, co.name AS company_name
             FROM academy_assignments a
             JOIN academy_courses c ON c.id = a.course_id
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN companies co ON co.id = a.company_id
             WHERE a.id = ? AND a.company_id = ?",
            [$assignmentId, self::cid()]
        );
    }

    public static function verifyUrl(string $token): string
    {
        $path = '/verifica-attestato.php?t=' . $token;
        return function_exists('buildPublicUrl') ? buildPublicUrl($path) : PUBLIC_URL . $path;
    }

    public static function placeholderValues(array $a, string $number, string $issuedAt, string $token): array
    {
        $fmt = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '';
        $birthPlace = '';
        $birthDate = $a['birth_date'] ?? null;
        if (!empty($a['fiscal_code']) && class_exists('FiscalCodeDecoder')) {
            $dec = FiscalCodeDecoder::decode((string) $a['fiscal_code']);
            if ($dec) {
                $birthPlace = trim(($dec['birth_city'] ?? '') . (!empty($dec['birth_province']) ? ' (' . $dec['birth_province'] . ')' : ''))
                    ?: (string) ($dec['birth_state'] ?? '');
                $birthDate = $birthDate ?: ($dec['birth_date'] ?? null);
            }
        }
        $hours = $a['duration_hours'] !== null && $a['duration_hours'] !== ''
            ? rtrim(rtrim(number_format((float) $a['duration_hours'], 2, ',', ''), '0'), ',')
            : '';
        return [
            '{NOME}' => $a['first_name'] ?? '',
            '{COGNOME}' => $a['last_name'] ?? '',
            '{NOME_COGNOME}' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')),
            '{COGNOME_NOME}' => trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')),
            '{CF}' => $a['fiscal_code'] ?? '',
            '{DATA_NASCITA}' => $fmt($birthDate),
            '{LUOGO_NASCITA}' => $birthPlace,
            '{MANSIONE}' => $a['position'] ?? '',
            '{REPARTO}' => $a['department_name'] ?? '',
            '{CORSO}' => $a['course_title'] ?? '',
            '{ORE}' => $hours,
            '{DATA_INIZIO}' => $fmt($a['started_at'] ?? null),
            '{DATA_COMPLETAMENTO}' => $fmt($a['completed_at'] ?? null),
            '{AZIENDA}' => $a['company_name'] ?? '',
            '{NUMERO_ATTESTATO}' => $number,
            '{DATA_EMISSIONE}' => $fmt($issuedAt),
            '{CODICE_VERIFICA}' => strtoupper(substr($token, 0, 12)),
            '{URL_VERIFICA}' => self::verifyUrl($token),
        ];
    }

    /** Valori d'esempio per l'anteprima del modello. */
    public static function sampleValues(): array
    {
        $company = Database::fetchOne("SELECT name FROM companies WHERE id = ?", [self::cid()]);
        return [
            '{NOME}' => 'Mario', '{COGNOME}' => 'Rossi', '{NOME_COGNOME}' => 'Mario Rossi', '{COGNOME_NOME}' => 'Rossi Mario',
            '{CF}' => 'RSSMRA80A01H501U', '{DATA_NASCITA}' => '01/01/1980', '{LUOGO_NASCITA}' => 'Roma (RM)',
            '{MANSIONE}' => 'Impiegato', '{REPARTO}' => 'Amministrazione',
            '{CORSO}' => 'Corso di esempio', '{ORE}' => '4', '{DATA_INIZIO}' => date('d/m/Y'),
            '{DATA_COMPLETAMENTO}' => date('d/m/Y'), '{AZIENDA}' => $company['name'] ?? 'Azienda',
            '{NUMERO_ATTESTATO}' => 'ACD-' . date('Y') . '-0001', '{DATA_EMISSIONE}' => date('d/m/Y'),
            '{CODICE_VERIFICA}' => 'A1B2C3D4E5F6', '{URL_VERIFICA}' => self::verifyUrl(str_repeat('0', 32)),
        ];
    }

    /** Anteprima PDF di un modello (dati d'esempio, niente salvataggio). */
    public static function previewPdf(string $orientation, string $layoutJson): string
    {
        $layout = json_decode($layoutJson, true);
        $template = [
            'orientation' => $orientation === 'portrait' ? 'portrait' : 'landscape',
            'layout_json' => json_encode(self::sanitizeLayout(is_array($layout) ? $layout : [])),
        ];
        return self::renderPdf($template, self::sampleValues(), self::verifyUrl(str_repeat('0', 32)), self::cid());
    }

    /**
     * Disegna il PDF dal layout (porting di generatePdfFromLayout di formacamere).
     * Senza modello usa un layout predefinito sobrio.
     */
    public static function renderPdf(?array $template, array $values, string $verifyUrl, int $companyId): string
    {
        $layout = $template && !empty($template['layout_json']) ? json_decode($template['layout_json'], true) : null;
        if (!is_array($layout) || empty($layout)) {
            $template = ['orientation' => 'landscape'];
            $layout = self::defaultLayout();
        }

        $orientation = ($template['orientation'] ?? 'landscape') === 'portrait' ? 'P' : 'L';
        $canvasW = $orientation === 'L' ? 842 : 595;
        $canvasH = $orientation === 'L' ? 595 : 842;
        $pageW = $orientation === 'L' ? 297 : 210;
        $pageH = $orientation === 'L' ? 210 : 297;
        $sx = $pageW / $canvasW;
        $sy = $pageH / $canvasH;

        $pdf = new TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Academy');
        $pdf->SetTitle('Attestato ' . ($values['{NUMERO_ATTESTATO}'] ?? ''));
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage($orientation, 'A4');

        foreach ($layout as $el) {
            if (isset($el['visible']) && !$el['visible']) continue;
            $type = $el['type'] ?? 'text';
            $x = ($el['x'] ?? 0) * $sx;
            $y = ($el['y'] ?? 0) * $sy;
            $w = ($el['width'] ?? 100) * $sx;
            $h = ($el['height'] ?? 30) * $sy;
            $color = self::hexToRgb($el['color'] ?? '#000000');
            $align = strtoupper(substr($el['align'] ?? 'L', 0, 1));
            if (!in_array($align, ['L', 'C', 'R'], true)) $align = 'L';

            switch ($type) {
                case 'text':
                case 'title':
                    $content = (string) ($el['content'] ?? '');
                    foreach ($values as $ph => $value) {
                        $content = str_replace($ph, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $content);
                    }
                    $content = strip_tags($content, '<b><i><u><strong><em><span><br><p><div><font>');
                    $fontSizePt = ($el['fontSize'] ?? 14) * min($sx, $sy) * 2.8346;
                    $style = (!empty($el['bold']) ? 'B' : '') . (!empty($el['italic']) ? 'I' : '');
                    $pdf->SetFont('helvetica', $style, $fontSizePt);
                    $pdf->SetTextColor($color[0], $color[1], $color[2]);

                    // Testo su una riga piu largo del box: riduco il font invece di andare a capo
                    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($plain !== '' && strpos($plain, "\n") === false && !preg_match('/<br|<\/p>|<\/div>/i', $content)) {
                        $textW = $pdf->GetStringWidth($plain, 'helvetica', $style, $fontSizePt);
                        $usable = max(1, $w - 2);
                        if ($textW > $usable) {
                            $fontSizePt *= ($usable / $textW) * 0.98;
                            $pdf->SetFont('helvetica', $style, $fontSizePt);
                        }
                    }
                    $pdf->writeHTMLCell($w, $h, $x, $y, $content, 0, 0, false, true, $align, true);
                    break;

                case 'image':
                    $name = self::imageNameFromSrc((string) ($el['src'] ?? ''));
                    $path = $name ? self::imagePath($name, $companyId) : null;
                    if ($path) {
                        try {
                            $pdf->Image($path, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, 'CM');
                        } catch (Throwable $e) {
                            // immagine non leggibile: la salto
                        }
                    }
                    break;

                case 'qr':
                    $side = min($w, $h);
                    $pdf->write2DBarcode($verifyUrl, 'QRCODE,M', $x, $y, $side, $side, [
                        'border' => false, 'padding' => 0,
                        'fgcolor' => $color, 'bgcolor' => false,
                    ], 'N');
                    break;

                case 'line':
                    $pdf->SetDrawColor($color[0], $color[1], $color[2]);
                    $pdf->SetLineWidth(max(0.2, $h));
                    $pdf->Line($x, $y + $h / 2, $x + $w, $y + $h / 2);
                    break;

                case 'rect':
                    $pdf->SetDrawColor($color[0], $color[1], $color[2]);
                    $pdf->SetLineWidth(0.5);
                    $pdf->Rect($x, $y, $w, $h, 'D');
                    break;
            }
        }

        return $pdf->Output('', 'S');
    }

    /** Layout predefinito (A4 orizzontale) usato quando il corso non ha un modello. */
    public static function defaultLayout(): array
    {
        $navy = '#0b3aa4';
        $txt = function (int $id, string $content, float $y, float $h, float $size, bool $bold = false, string $color = '#1f2937') {
            return ['id' => $id, 'type' => 'text', 'x' => 60, 'y' => $y, 'width' => 722, 'height' => $h,
                    'content' => $content, 'fontSize' => $size, 'color' => $color, 'bold' => $bold,
                    'align' => 'center', 'visible' => true];
        };
        return [
            ['id' => 1, 'type' => 'rect', 'x' => 24, 'y' => 24, 'width' => 794, 'height' => 547, 'color' => $navy, 'visible' => true],
            $txt(2, '{AZIENDA}', 60, 24, 14, true, $navy),
            $txt(3, 'ATTESTATO DI PARTECIPAZIONE', 110, 44, 30, true, $navy),
            $txt(4, 'Si attesta che', 185, 22, 13),
            $txt(5, '{NOME_COGNOME}', 212, 36, 26, true),
            $txt(6, 'Codice fiscale {CF}', 252, 20, 11, false, '#4b5563'),
            $txt(7, 'ha completato il corso', 290, 22, 13),
            $txt(8, '{CORSO}', 316, 32, 20, true, $navy),
            $txt(9, 'Durata: {ORE} ore - iniziato il {DATA_INIZIO}, completato il {DATA_COMPLETAMENTO}', 358, 20, 11, false, '#4b5563'),
            ['id' => 10, 'type' => 'line', 'x' => 321, 'y' => 410, 'width' => 200, 'height' => 1, 'color' => '#cbd5e1', 'visible' => true],
            ['id' => 11, 'type' => 'text', 'x' => 60, 'y' => 470, 'width' => 400, 'height' => 54,
             'content' => 'Attestato n. {NUMERO_ATTESTATO}<br>Emesso il {DATA_EMISSIONE}<br>Codice di verifica {CODICE_VERIFICA}',
             'fontSize' => 10, 'color' => '#4b5563', 'bold' => false, 'align' => 'left', 'visible' => true],
            ['id' => 12, 'type' => 'qr', 'x' => 712, 'y' => 450, 'width' => 76, 'height' => 76, 'color' => '#111827', 'visible' => true],
        ];
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return [0, 0, 0];
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    // =====================================================================
    // Download, ZIP, verifica
    // =====================================================================

    public static function getForAssignment(int $assignmentId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM academy_certificates WHERE assignment_id = ? AND company_id = ?",
            [$assignmentId, self::cid()]
        );
    }

    /**
     * Controlla l'accesso e restituisce il certificato da scaricare.
     * Staff: stessa azienda (resp. reparto solo il suo reparto). Dipendente: solo il proprio.
     */
    public static function authorizeDownload(int $certificateId): array
    {
        $c = Database::fetchOne(
            "SELECT ce.*, a.employee_id, a.course_id, e.department_id, e.first_name, e.last_name
             FROM academy_certificates ce
             JOIN academy_assignments a ON a.id = ce.assignment_id
             JOIN employees e ON e.id = a.employee_id
             WHERE ce.id = ? AND ce.company_id = ?",
            [$certificateId, self::cid()]
        );
        if (!$c || !is_file($c['file_path'])) {
            return ['success' => false, 'error' => 'Attestato non trovato'];
        }
        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        if ($user) {
            if (!in_array($user['role'], ['admin', 'consulente_lavoro', 'admin_reparto', 'formatore'], true)) {
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
            if ($user['role'] === 'admin_reparto' && (int) $c['department_id'] !== (int) ($user['department_id'] ?? -1)) {
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } elseif ($employee) {
            if ((int) $c['employee_id'] !== (int) $employee['id']) {
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
            if (empty($c['employee_downloaded_at'])) {
                Database::update('academy_certificates', ['employee_downloaded_at' => date('Y-m-d H:i:s')], 'id = ?', [$certificateId]);
            }
            Academy::logEvent('certificate_downloaded', [
                'course_id' => (int) $c['course_id'],
                'assignment_id' => (int) $c['assignment_id'],
                'employee_id' => (int) $c['employee_id'],
                'details' => $c['number'],
            ]);
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }
        return ['success' => true, 'certificate' => $c];
    }

    public static function downloadName(array $c): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', 'attestato_' . $c['number'] . '_' . $c['last_name'] . '_' . $c['first_name']) . '.pdf';
    }

    /** ZIP con gli attestati del corso (o solo delle assegnazioni indicate). */
    public static function streamZip(int $courseId, array $assignmentIds = [], ?int $departmentId = null): void
    {
        $course = Academy::getCourse($courseId);
        if (!$course) {
            http_response_code(404);
            exit('Corso non trovato');
        }
        $sql = "SELECT ce.*, e.first_name, e.last_name FROM academy_certificates ce
                JOIN academy_assignments a ON a.id = ce.assignment_id
                JOIN employees e ON e.id = a.employee_id
                WHERE ce.company_id = ? AND a.course_id = ?";
        $params = [self::cid(), $courseId];
        $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds)));
        if (!empty($assignmentIds)) {
            $sql .= ' AND a.id IN (' . implode(',', array_fill(0, count($assignmentIds), '?')) . ')';
            $params = array_merge($params, $assignmentIds);
        }
        if ($departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $rows = Database::fetchAll($sql, $params);
        if (empty($rows)) {
            http_response_code(404);
            exit('Nessun attestato da scaricare');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'acz');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($rows as $r) {
            if (is_file($r['file_path'])) {
                $zip->addFile($r['file_path'], self::downloadName($r));
            }
        }
        $zip->close();
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', 'attestati_' . $course['title']) . '.zip';
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * Verifica pubblica dal QR: nessuna sessione, cerca per token.
     * Ricalcola lo SHA-256 del file per confermare che non è stato alterato.
     */
    public static function verify(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $c = Database::fetchOne(
            "SELECT ce.number, ce.issued_at, ce.file_path, ce.sha256, ce.company_id, ce.assignment_id,
                    a.assigned_at, a.completed_at, a.started_at, a.declaration_text,
                    c.title AS course_title, c.duration_hours, e.first_name, e.last_name, e.fiscal_code,
                    co.name AS company_name
             FROM academy_certificates ce
             JOIN academy_assignments a ON a.id = ce.assignment_id
             JOIN academy_courses c ON c.id = a.course_id
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN companies co ON co.id = ce.company_id
             WHERE ce.verify_token = ?",
            [$token]
        );
        if (!$c) return null;
        $c['file_intact'] = is_file($c['file_path']) && hash_file('sha256', $c['file_path']) === $c['sha256'];
        unset($c['file_path']);

        // Registro di frequenza dell'attestato (pagina pubblica: niente browser,
        // niente username dello staff, IP mascherato nell'ultima parte)
        $events = Database::fetchAll(
            "SELECT ev.event, ev.actor_type, ev.ip_address, ev.created_at, ev.details, m.title AS material_title
             FROM academy_events ev
             LEFT JOIN academy_materials m ON m.id = ev.material_id
             WHERE ev.company_id = ? AND ev.assignment_id = ?
             ORDER BY ev.id",
            [(int) $c['company_id'], (int) $c['assignment_id']]
        );
        $actors = ['employee' => 'Dipendente', 'admin' => 'HR', 'consulente_lavoro' => 'Consulente del lavoro',
                   'admin_reparto' => 'Responsabile reparto', 'formatore' => 'Responsabile formazione', 'system' => 'Sistema'];
        $c['events'] = array_map(fn($ev) => [
            'at' => $ev['created_at'],
            'label' => Academy::EVENT_LABELS[$ev['event']] ?? $ev['event'],
            'event' => $ev['event'],
            'detail' => $ev['material_title'] ?: ($ev['event'] === 'certificate_generated' ? $ev['details'] : ''),
            'actor' => $actors[$ev['actor_type']] ?? 'Staff',
            'ip' => self::maskIp($ev['ip_address']),
        ], $events);
        $c['chain'] = Academy::verifyChain((int) $c['company_id']);
        return $c;
    }

    /** 93.41.12.200 -> 93.41.12.*** ; IPv6 -> primi 3 blocchi. */
    private static function maskIp(?string $ip): string
    {
        if (!$ip) return '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.***', $ip);
        }
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3)) . ':****';
    }
}
