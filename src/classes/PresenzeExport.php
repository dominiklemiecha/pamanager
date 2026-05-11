<?php
/**
 * PresenzeExport — riempie il template del consulente con i dati DB del mese selezionato.
 * Output: file XLSX con UN SOLO foglio (quello del mese richiesto), preservando grafica/colori
 * del template originale.
 *
 * Codici scritti:
 *   F  = Ferie | M = Malattia | ROL = permesso intero | ROL hh-hh = parziale
 *   P104 = L.104 | CP = Congedo parentale | A = altro
 */
class PresenzeExport
{
    private int $month;
    private int $year;
    private array $employees = [];
    /** @var array<int, array<string, string>> [empId][YYYY-MM-DD] = code */
    private array $cells = [];
    public int $writtenEmployees = 0;
    public int $templateRowsAvailable = 0;
    public bool $overflow = false;

    private string $templatePath;

    public function __construct(int $month, int $year)
    {
        $this->month = $month;
        $this->year  = $year;
        $this->templatePath = ROOT_PATH . '/database/templates/presenze_template.xlsx';
    }

    public function build(): void
    {
        $this->loadEmployees();
        $this->loadLeaves();
    }

    public function streamToBrowser(): void
    {
        $bin = $this->buildXlsxBinary();
        $monthLabel = self::italianMonth($this->month);
        $filename = "Presenze_{$monthLabel}_{$this->year}.xlsx";

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
        exit;
    }

    // ============== Caricamento dati ==============

    private function loadEmployees(): void
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $this->employees = Database::fetchAll(
            "SELECT id, first_name, last_name FROM employees
             WHERE is_active = TRUE AND company_id = ?
             ORDER BY last_name, first_name",
            [$cid]
        );
    }

    private function loadLeaves(): void
    {
        $cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $start = sprintf('%04d-%02d-01', $this->year, $this->month);
        $end   = date('Y-m-t', strtotime($start));
        $rows = Database::fetchAll(
            "SELECT employee_id, leave_type, start_date, end_date, is_full_day, start_time, end_time
             FROM leave_requests
             WHERE company_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?",
            [$cid, $end, $start]
        );
        $monthYM = sprintf('%04d-%02d', $this->year, $this->month);
        foreach ($rows as $r) {
            $code = $this->codeForLeave($r);
            if ($code === '') continue;
            $period = new DatePeriod(
                new DateTime($r['start_date']),
                new DateInterval('P1D'),
                (new DateTime($r['end_date']))->modify('+1 day')
            );
            foreach ($period as $d) {
                if ($d->format('Y-m') !== $monthYM) continue;
                $key = $d->format('Y-m-d');
                $empId = (int)$r['employee_id'];
                $existing = $this->cells[$empId][$key] ?? '';
                if ($existing === '') $this->cells[$empId][$key] = $code;
                elseif (strpos($existing, $code) === false) $this->cells[$empId][$key] = $existing . '/' . $code;
            }
        }
    }

    private function codeForLeave(array $r): string
    {
        switch ($r['leave_type']) {
            case 'ferie':              return 'F';
            case 'malattia':           return 'M';
            case 'permesso_104':       return 'P104';
            case 'congedo_parentale':  return 'CP';
            case 'altro':              return 'A';
            case 'chiusura':           return 'C';
            case 'permesso':
                $isFull = !empty($r['is_full_day']) || empty($r['start_time']) || empty($r['end_time']);
                if ($isFull) return 'ROL';
                $sh = (int)substr($r['start_time'], 0, 2);
                $eh = (int)substr($r['end_time'],   0, 2);
                return 'ROL ' . $sh . '-' . $eh;
            default: return '';
        }
    }

    // ============== Build XLSX ==============

    private function buildXlsxBinary(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("Estensione ZipArchive non disponibile sul server.");
        }
        if (!file_exists($this->templatePath)) {
            throw new RuntimeException("Template non trovato: {$this->templatePath}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pamtpl');
        copy($this->templatePath, $tmp);

        // === FASE 1: leggi tutto in memoria ===
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Impossibile aprire il template XLSX.');
        }
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $ctXml       = $zip->getFromName('[Content_Types].xml');
        $ssXml       = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $sheetsXml = []; // file => xml
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/sheet') === 0 && substr($name, -4) === '.xml') {
                $sheetsXml[basename($name)] = $zip->getFromName($name);
            }
        }
        $zip->close();

        // === FASE 2: usa sempre MAGGIO 2026 come canonical source per layout uniforme.
        // Cloniamo MAGGIO con titolo + date adattati al mese richiesto. Se MAGGIO non esiste,
        // usiamo il primo foglio mese disponibile.
        $sourceFile = $this->resolveSheetFileByMonth($workbookXml, $relsXml, 5, $this->year)
                   ?? $this->resolveAnyMonthSheet($workbookXml, $relsXml);
        if (!$sourceFile || !isset($sheetsXml[$sourceFile])) {
            throw new RuntimeException('Nessun foglio mese disponibile nel template.');
        }

        // Rileva gli stili weekend/weekday dalle colonne ORIGINALI di Maggio
        // (prima di redatare), basandoci sui giorni della settimana dell'anno corrente.
        $detectedStyles = $this->detectWeekendStyles($sheetsXml[$sourceFile]);

        // Adatta titolo + serial date al mese/anno richiesto
        $newTitle = strtoupper(self::italianMonth($this->month)) . ' ' . $this->year;
        $sheetsXml[$sourceFile] = $this->retitleAndRedateSheet($sheetsXml[$sourceFile], $this->month, $this->year);

        // Aggiorna il name del <sheet> nel workbook al titolo nuovo (qualsiasi sia l'originale)
        $workbookXml = $this->renameSheetByFile($workbookXml, $relsXml, $sourceFile, $newTitle);

        // Rimuovi tutti gli altri fogli (tieni solo quello che useremo come target)
        [$workbookXml, $relsXml, $ctXml, $deletedFiles] = $this->removeAllExcept($workbookXml, $relsXml, $ctXml, $sourceFile);
        foreach ($deletedFiles as $f) unset($sheetsXml[$f]);

        $targetFile = $sourceFile;

        // Imposta activeTab=0 (unico foglio rimasto)
        $workbookXml = $this->setActiveTabZero($workbookXml);

        // === FASE 3: fill del foglio target ===
        $shared = $this->parseSharedStrings($ssXml);
        $sheetsXml[$targetFile] = $this->fillSheetXml($sheetsXml[$targetFile], $shared, $detectedStyles);

        // === FASE 4: scrivi tutto e chiudi ===
        $zip = new ZipArchive();
        $zip->open($tmp);
        foreach (['xl/workbook.xml' => $workbookXml,
                  'xl/_rels/workbook.xml.rels' => $relsXml,
                  '[Content_Types].xml' => $ctXml] as $name => $xml) {
            $zip->deleteName($name);
            $zip->addFromString($name, $xml);
        }
        foreach ($deletedFiles as $f) {
            $zip->deleteName('xl/worksheets/' . $f);
        }
        foreach ($sheetsXml as $f => $xml) {
            $zip->deleteName('xl/worksheets/' . $f);
            $zip->addFromString('xl/worksheets/' . $f, $xml);
        }
        $zip->close();

        $bin = file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private function resolveSheetFileByMonth(string $workbookXml, string $relsXml, int $month, int $year): ?string
    {
        $name = strtoupper(self::italianMonth($month)) . ' ' . $year;
        $patterns = [$name, str_replace(' ', '', $name)];
        $rId = null;
        foreach ($patterns as $p) {
            if (preg_match('#<sheet\s+name="' . preg_quote($p, '#') . '"[^>]*r:id="(rId\d+)"#i', $workbookXml, $m)) {
                $rId = $m[1]; break;
            }
        }
        if (!$rId) return null;
        if (preg_match('#<Relationship\s+Id="' . $rId . '"[^>]*Target="worksheets/([^"]+)"#', $relsXml, $m)) {
            return $m[1];
        }
        return null;
    }

    private function resolveAnyMonthSheet(string $workbookXml, string $relsXml): ?string
    {
        $monthsRegex = '/(GENNAIO|FEBBRAIO|MARZO|APRILE|MAGGIO|GIUGNO|LUGLIO|AGOSTO|SETTEMBRE|OTTOBRE|NOVEMBRE|DICEMBRE)\s*\d{4}/i';
        if (!preg_match_all('#<sheet\s+name="([^"]+)"[^>]*r:id="(rId\d+)"\s*/>#', $workbookXml, $sm, PREG_SET_ORDER)) return null;
        foreach ($sm as $s) {
            if (!preg_match($monthsRegex, $s[1])) continue;
            if (preg_match('#<Relationship\s+Id="' . $s[2] . '"[^>]*Target="worksheets/([^"]+)"#', $relsXml, $rm)) {
                return $rm[1];
            }
        }
        return null;
    }

    /**
     * Modifica i serial date in riga 3 e i titoli nei testi inline per il mese/anno target.
     * Mantiene i nomi degli employee in colonna A invariati (saranno sovrascritti da fillSheetXml).
     */
    private function retitleAndRedateSheet(string $sheetXml, int $month, int $year): string
    {
        $newTitle = strtoupper(self::italianMonth($month)) . ' ' . $year;

        // Sostituisci nomi mese in <t>...</t> testi inline (es. titoli)
        $sheetXml = preg_replace_callback(
            '#<t[^>]*>([^<]+)</t>#i',
            function ($m) use ($newTitle) {
                if (preg_match('/(GENNAIO|FEBBRAIO|MARZO|APRILE|MAGGIO|GIUGNO|LUGLIO|AGOSTO|SETTEMBRE|OTTOBRE|NOVEMBRE|DICEMBRE)\s*\d{4}/i', $m[1])) {
                    return '<t xml:space="preserve">' . self::escapeText($newTitle) . '</t>';
                }
                return $m[0];
            },
            $sheetXml
        );

        // Aggiorna serial date in riga 3 (skip celle con t="s" come "Connecteed")
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        $firstSerial = self::dateToExcelSerial(sprintf('%04d-%02d-01', $year, $month));

        $sheetXml = preg_replace_callback(
            '#<row\s+r="3"[^>]*>(.*?)</row>#s',
            function ($m) use ($firstSerial, $daysInMonth) {
                $rowContent = $m[1];
                $i = 0;
                $rowContent = preg_replace_callback(
                    '#<c\s+r="([A-Z]+)3"([^>]*)>\s*<v>(\d+)</v>\s*</c>#',
                    function ($cm) use (&$i, $firstSerial, $daysInMonth) {
                        if (strpos($cm[2], 't="s"') !== false) return $cm[0];
                        if ($i >= $daysInMonth) { $i++; return ''; }
                        $newSerial = $firstSerial + $i;
                        $i++;
                        return '<c r="' . $cm[1] . '3"' . $cm[2] . '><v>' . $newSerial . '</v></c>';
                    },
                    $rowContent
                );
                return preg_replace('#<row\s+r="3"([^>]*)>.*</row>#s', '<row r="3"$1>' . $rowContent . '</row>', $m[0]);
            },
            $sheetXml,
            1
        );

        return $sheetXml;
    }

    private function renameSheetByFile(string $workbookXml, string $relsXml, string $sheetFile, string $newName): string
    {
        // Trova rId che ha Target=worksheets/$sheetFile
        if (!preg_match('#<Relationship\s+Id="(rId\d+)"[^>]*Target="worksheets/' . preg_quote($sheetFile, '#') . '"#', $relsXml, $rm)) {
            return $workbookXml;
        }
        $rId = $rm[1];
        // Sostituisci name del <sheet> con quel rId
        return preg_replace_callback(
            '#<sheet\s+name="[^"]*"([^>]*r:id="' . $rId . '"[^>]*/>)#',
            function ($m) use ($newName) {
                return '<sheet name="' . self::escapeText($newName) . '"' . $m[1];
            },
            $workbookXml,
            1
        );
    }

    private static function dateToExcelSerial(string $ymd): int
    {
        $ts = strtotime($ymd . ' UTC');
        return (int)floor($ts / 86400) + 25569;
    }

    /**
     * Rimuove dal workbook tutti i fogli tranne quello indicato (target file).
     * Aggiorna workbook.xml, rels, [Content_Types].xml. Restituisce i file rimossi.
     */
    private function removeAllExcept(string $workbookXml, string $relsXml, string $ctXml, string $keepFile): array
    {
        preg_match_all('#<sheet\s+name="([^"]+)"[^>]*r:id="(rId\d+)"\s*/>#', $workbookXml, $sm, PREG_SET_ORDER);
        $deletedFiles = [];
        foreach ($sm as $s) {
            $name = $s[1];
            $rId  = $s[2];
            if (!preg_match('#<Relationship\s+Id="' . $rId . '"[^>]*Target="(worksheets/[^"]+)"#', $relsXml, $rm)) continue;
            $target = $rm[1];
            $file = basename($target);
            if ($file === $keepFile) continue;

            // Rimuovi <sheet>
            $workbookXml = preg_replace(
                '#<sheet\s+name="' . preg_quote($name, '#') . '"[^>]*r:id="' . $rId . '"\s*/>#',
                '', $workbookXml, 1
            );
            // Rimuovi <Relationship>
            $relsXml = preg_replace(
                '#<Relationship\s+Id="' . $rId . '"[^>]*/>#',
                '', $relsXml, 1
            );
            // Rimuovi <Override> in content types
            $ctXml = preg_replace(
                '#<Override\s+PartName="/xl/' . preg_quote($target, '#') . '"[^>]*/>#',
                '', $ctXml, 1
            );
            $deletedFiles[] = $file;
        }
        return [$workbookXml, $relsXml, $ctXml, $deletedFiles];
    }

    private function setActiveTabZero(string $workbookXml): string
    {
        if (preg_match('#<workbookView\b[^/]*/>#', $workbookXml, $vm)) {
            $tag = $vm[0];
            $newTag = preg_replace('#\s(activeTab|firstSheet)="\d+"#', '', $tag);
            $newTag = preg_replace('#/>$#', ' activeTab="0" firstSheet="0"/>', $newTag, 1);
            $workbookXml = str_replace($tag, $newTag, $workbookXml);
        }
        return $workbookXml;
    }

    private function parseSharedStrings(string $xml): array
    {
        $strings = [];
        if ($xml === '') return $strings;
        preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $items);
        foreach ($items[1] as $item) {
            preg_match_all('#<t[^>]*>(.*?)</t>#s', $item, $tt);
            $strings[] = html_entity_decode(implode('', $tt[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $strings;
    }

    /**
     * Riempie il foglio target: scrive nomi DB nelle righe dipendente, codici nelle giornate,
     * preserva style weekend, applica colori legenda per i codici, nasconde righe non usate,
     * imposta col A larghezza 28.5 + freeze + altezza riga date, rimuove righe header
     * decorativo (1,2,4) e renumera.
     */
    /**
     * Rileva gli stili tipici di celle weekend / weekday leggendo il foglio sorgente
     * (ancora con date originali). Restituisce ['weekend'=>int|null, 'weekday'=>int|null].
     */
    private function detectWeekendStyles(string $sheetXml): array
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument(); $dom->loadXML($sheetXml);
        libxml_use_internal_errors($prev);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        // Mappa colonne -> dow (1=Mon..7=Sun) dalle date in row 3
        $colDow = [];
        foreach ($xpath->query('//s:sheetData/s:row[@r="3"]/s:c') as $c) {
            $ref = $c->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            $t   = $c->getAttribute('t');
            $vNode = $c->getElementsByTagName('v')->item(0);
            if (!$vNode) continue;
            if ($t === '' || $t === 'n') {
                $val = $vNode->nodeValue;
                if (is_numeric($val) && (int)$val > 30000) {
                    $date = self::excelSerialToDate((int)$val);
                    $colDow[$col] = (int)date('N', strtotime($date));
                }
            }
        }
        if (empty($colDow)) return ['weekend' => null, 'weekday' => null];

        // Per ogni cella di una riga dipendente (5..22), conta frequenze stili separando weekend/weekday.
        // Solo celle SENZA contenuto (per evitare di prendere lo stile dei codici).
        $weekendCount = []; $weekdayCount = [];
        for ($r = 5; $r <= 22; $r++) {
            foreach ($xpath->query("//s:sheetData/s:row[@r=\"$r\"]/s:c") as $c) {
                $ref = $c->getAttribute('r');
                $col = preg_replace('/\d+/', '', $ref);
                if ($col === 'A' || !isset($colDow[$col])) continue;
                // Skip se ha contenuto
                $hasV = $c->getElementsByTagName('v')->length > 0;
                $hasIs = $c->getElementsByTagName('is')->length > 0;
                if ($hasV || $hasIs) continue;
                $s = $c->getAttribute('s');
                if ($s === '') continue;
                $dow = $colDow[$col];
                if ($dow >= 6) $weekendCount[$s] = ($weekendCount[$s] ?? 0) + 1;
                else $weekdayCount[$s] = ($weekdayCount[$s] ?? 0) + 1;
            }
        }
        $best = function (array $counts) {
            if (empty($counts)) return null;
            arsort($counts);
            return (int)array_key_first($counts);
        };
        return ['weekend' => $best($weekendCount), 'weekday' => $best($weekdayCount)];
    }

    private function fillSheetXml(string $sheetXml, array $shared, array $detectedStyles = []): string
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($sheetXml);
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        // 1) Mappa colonne -> data dalla riga 3
        $colDate = [];
        $row3 = $xpath->query('//s:sheetData/s:row[@r="3"]/s:c');
        foreach ($row3 as $c) {
            $ref = $c->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            $t   = $c->getAttribute('t');
            $vNode = $c->getElementsByTagName('v')->item(0);
            if (!$vNode) continue;
            if ($t === '' || $t === 'n') {
                $val = $vNode->nodeValue;
                if (is_numeric($val) && (int)$val > 30000) {
                    $colDate[$col] = self::excelSerialToDate((int)$val);
                }
            }
        }
        if (empty($colDate)) {
            throw new RuntimeException('Riga date (riga 3) non riconosciuta nel template.');
        }

        // 1b) Colonne weekend
        $weekendCols = [];
        foreach ($colDate as $col => $date) {
            $dow = (int)date('N', strtotime($date));
            if ($dow >= 6) $weekendCols[$col] = true;
        }

        // 1c) Stili dei codici dalla legenda (B23..B26)
        $codeStyles = $this->detectCodeStyles($xpath, $shared);

        // 1d) Larghezza colonna A 28.5 + freeze + altezza riga date
        $this->setColumnAWidth($dom, $xpath, 28.5);
        $this->setFreezeFirstColumn($dom, $xpath);
        $this->setRowHeight($xpath, 3, 36.75);

        // 2) Identifica righe dipendente del template
        $empRowsTemplate = [];
        for ($r = 5; $r <= 50; $r++) {
            $name = $this->readCellText($xpath, "A$r", $shared);
            if ($this->isLegendRow($name)) break;
            $empRowsTemplate[] = $r;
        }
        $this->templateRowsAvailable = count($empRowsTemplate);

        $dbCount = count($this->employees);
        $this->overflow = $dbCount > $this->templateRowsAvailable;
        $maxWrite = min($dbCount, $this->templateRowsAvailable);

        // 3) Scrivi i dipendenti DB nelle righe template
        for ($i = 0; $i < $maxWrite; $i++) {
            $row = $empRowsTemplate[$i];
            $emp = $this->employees[$i];
            $empId = (int)$emp['id'];
            $fi = mb_strtoupper(mb_substr($emp['first_name'], 0, 1));
            $name = $fi . '. ' . $emp['last_name'];

            $this->writeCell($dom, $xpath, 'A' . $row, $name);

            foreach ($colDate as $col => $date) {
                $isWeekend = isset($weekendCols[$col]);
                $code = $this->cells[$empId][$date] ?? '';

                // Stile per questa cella: weekend rilevato, weekday rilevato, o default.
                $weekendStyle = $detectedStyles['weekend'] ?? null;
                $weekdayStyle = $detectedStyles['weekday'] ?? null;
                $baseStyle    = $isWeekend ? $weekendStyle : $weekdayStyle;

                if ($code === '') {
                    // Cella vuota: applica stile weekend (grigio) o weekday (default)
                    $this->writeCell($dom, $xpath, $col . $row, '', $baseStyle);
                } else {
                    // Cella con codice:
                    //  - Weekday: usa stile legenda (colorato)
                    //  - Weekend: usa stile weekend (preserva grigio anche con codice)
                    $styleIdx = $isWeekend ? $weekendStyle : $this->styleForCode($code, $codeStyles);
                    $this->writeCell($dom, $xpath, $col . $row, $code, $styleIdx);
                }
            }
        }
        $this->writtenEmployees = $maxWrite;

        // 4) Cancella righe decorative (1,2,4) + righe dipendente non usate.
        // Renumera tutto in modo che la riga date diventi riga 1.
        $rowsToRemove = [1, 2, 4];
        for ($i = $maxWrite; $i < $this->templateRowsAvailable; $i++) {
            $rowsToRemove[] = $empRowsTemplate[$i];
        }
        $this->renumberRows($xpath, $rowsToRemove);

        return $dom->saveXML();
    }

    private function readCellText(DOMXPath $xpath, string $cellRef, array $shared): string
    {
        $rowNum = (int)preg_replace('/\D+/', '', $cellRef);
        $cells = $xpath->query("//s:sheetData/s:row[@r=\"$rowNum\"]/s:c[@r=\"$cellRef\"]");
        if ($cells->length === 0) return '';
        return $this->cellTextValue($cells->item(0), $shared);
    }

    private function cellTextValue(DOMElement $cell, array $shared = []): string
    {
        $t = $cell->getAttribute('t');
        $vNode = $cell->getElementsByTagName('v')->item(0);
        $isNode = $cell->getElementsByTagName('is')->item(0);
        if ($t === 's' && $vNode) {
            $idx = (int)$vNode->nodeValue;
            return $shared[$idx] ?? '';
        }
        if ($t === 'inlineStr' && $isNode) {
            $tNode = $isNode->getElementsByTagName('t')->item(0);
            return $tNode ? $tNode->nodeValue : '';
        }
        if ($vNode) return $vNode->nodeValue;
        return '';
    }

    private function isLegendRow(string $name): bool
    {
        $low = mb_strtolower(trim($name));
        if ($low === '') return false;
        $kw = ['smart working','ferie/rol/malattia','fiera/evento','festivit','chiusura','legenda','totale','presenze'];
        foreach ($kw as $k) if (strpos($low, $k) !== false) return true;
        return false;
    }

    private function detectCodeStyles(DOMXPath $xpath, array $shared): array
    {
        $styles = [];
        $codeMap = [
            'F'=>['ferie/rol/malattia','f/rol/m'],
            'M'=>['ferie/rol/malattia','f/rol/m'],
            'ROL'=>['ferie/rol/malattia','f/rol/m'],
            'P104'=>['ferie/rol/malattia','f/rol/m'],
            'CP'=>['ferie/rol/malattia','f/rol/m'],
            'A'=>['ferie/rol/malattia','f/rol/m'],
            'SW'=>['smart working','sw'],
            'C'=>['festivit','chiusura'],
        ];
        for ($r = 20; $r <= 40; $r++) {
            foreach (['A','B'] as $col) {
                $cells = $xpath->query("//s:sheetData/s:row[@r=\"$r\"]/s:c[@r=\"$col$r\"]");
                if ($cells->length === 0) continue;
                $cell = $cells->item(0);
                $text = $this->cellTextValue($cell, $shared);
                if ($text === '') continue;
                $low = mb_strtolower($text);
                foreach ($codeMap as $code => $kws) {
                    if (isset($styles[$code])) continue;
                    foreach ($kws as $kw) {
                        if (strpos($low, $kw) !== false) {
                            $styleCell = $col === 'B' ? $cell : null;
                            if ($styleCell === null) {
                                $bCells = $xpath->query("//s:sheetData/s:row[@r=\"$r\"]/s:c[@r=\"B$r\"]");
                                if ($bCells->length > 0) $styleCell = $bCells->item(0);
                            }
                            if ($styleCell) {
                                $sAttr = $styleCell->getAttribute('s');
                                if ($sAttr !== '') $styles[$code] = (int)$sAttr;
                            }
                            break;
                        }
                    }
                }
            }
        }
        return $styles;
    }

    private function styleForCode(string $code, array $codeStyles): ?int
    {
        $first = trim(explode('/', $code)[0]);
        foreach (['ROL','P104','CP','SW','F','M','A','C'] as $k) {
            if ($first === $k || strpos($first, $k . ' ') === 0) return $codeStyles[$k] ?? null;
        }
        return null;
    }

    private function writeCell(DOMDocument $dom, DOMXPath $xpath, string $cellRef, string $value, ?int $forceStyle = null): void
    {
        $col = preg_replace('/\d+/', '', $cellRef);
        $rowNum = (int)preg_replace('/\D+/', '', $cellRef);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $cells = $xpath->query("//s:sheetData/s:row[@r=\"$rowNum\"]/s:c[@r=\"$cellRef\"]");
        if ($cells->length > 0) {
            $cell = $cells->item(0);
            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
            $cell->setAttribute('t', 'inlineStr');
            if ($forceStyle !== null) $cell->setAttribute('s', (string)$forceStyle);
            $is = $dom->createElementNS($ns, 'is');
            $t  = $dom->createElementNS($ns, 't', self::escapeText($value));
            $t->setAttribute('xml:space', 'preserve');
            $is->appendChild($t);
            $cell->appendChild($is);
            return;
        }
        // Crea cella nuova
        $rows = $xpath->query("//s:sheetData/s:row[@r=\"$rowNum\"]");
        if ($rows->length === 0) return;
        $row = $rows->item(0);
        $newCell = $dom->createElementNS($ns, 'c');
        $newCell->setAttribute('r', $cellRef);
        $newCell->setAttribute('t', 'inlineStr');
        if ($forceStyle !== null) $newCell->setAttribute('s', (string)$forceStyle);
        $is = $dom->createElementNS($ns, 'is');
        $t  = $dom->createElementNS($ns, 't', self::escapeText($value));
        $t->setAttribute('xml:space', 'preserve');
        $is->appendChild($t);
        $newCell->appendChild($is);
        $inserted = false;
        foreach ($row->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $existingCol = preg_replace('/\d+/', '', $child->getAttribute('r'));
            if (self::colCompare($existingCol, $col) > 0) {
                $row->insertBefore($newCell, $child);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) $row->appendChild($newCell);
    }

    private function deleteCell(DOMXPath $xpath, string $cellRef): void
    {
        $rowNum = (int)preg_replace('/\D+/', '', $cellRef);
        $cells = $xpath->query("//s:sheetData/s:row[@r=\"$rowNum\"]/s:c[@r=\"$cellRef\"]");
        if ($cells->length > 0) {
            $cell = $cells->item(0);
            $cell->parentNode->removeChild($cell);
        }
    }

    private function setColumnAWidth(DOMDocument $dom, DOMXPath $xpath, float $width): void
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $colsList = $xpath->query('//s:cols');
        if ($colsList->length === 0) {
            $cols = $dom->createElementNS($ns, 'cols');
            $sheetData = $xpath->query('//s:sheetData')->item(0);
            if ($sheetData) $sheetData->parentNode->insertBefore($cols, $sheetData);
        } else {
            $cols = $colsList->item(0);
        }
        $found = false;
        foreach ($cols->getElementsByTagName('col') as $col) {
            $min = (int)$col->getAttribute('min');
            $max = (int)$col->getAttribute('max');
            if ($min === 1 && $max === 1) {
                $col->setAttribute('width', (string)$width);
                $col->setAttribute('customWidth', '1');
                $found = true;
                break;
            }
        }
        if (!$found) {
            $newCol = $dom->createElementNS($ns, 'col');
            $newCol->setAttribute('min', '1');
            $newCol->setAttribute('max', '1');
            $newCol->setAttribute('width', (string)$width);
            $newCol->setAttribute('customWidth', '1');
            if ($cols->firstChild) $cols->insertBefore($newCol, $cols->firstChild);
            else $cols->appendChild($newCol);
        }
    }

    private function setFreezeFirstColumn(DOMDocument $dom, DOMXPath $xpath): void
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheetViews = $xpath->query('//s:sheetViews/s:sheetView');
        if ($sheetViews->length > 0) {
            $view = $sheetViews->item(0);
        } else {
            $sv = $dom->createElementNS($ns, 'sheetViews');
            $view = $dom->createElementNS($ns, 'sheetView');
            $view->setAttribute('workbookViewId', '0');
            $sv->appendChild($view);
            $worksheet = $xpath->query('//s:worksheet')->item(0);
            $sheetData = $xpath->query('//s:sheetData')->item(0);
            $worksheet->insertBefore($sv, $sheetData);
        }
        foreach ($view->getElementsByTagName('pane') as $p) {
            $view->removeChild($p);
            break;
        }
        $pane = $dom->createElementNS($ns, 'pane');
        $pane->setAttribute('xSplit', '1');
        $pane->setAttribute('topLeftCell', 'B1');
        $pane->setAttribute('activePane', 'topRight');
        $pane->setAttribute('state', 'frozen');
        if ($view->firstChild) $view->insertBefore($pane, $view->firstChild);
        else $view->appendChild($pane);
    }

    private function setRowHeight(DOMXPath $xpath, int $rowNum, float $height): void
    {
        $rows = $xpath->query("//s:sheetData/s:row[@r=\"$rowNum\"]");
        if ($rows->length > 0) {
            $row = $rows->item(0);
            $row->setAttribute('ht', (string)$height);
            $row->setAttribute('customHeight', '1');
        }
    }

    private function renumberRows(DOMXPath $xpath, array $rowsToDelete): void
    {
        foreach ($rowsToDelete as $rNum) {
            $rows = $xpath->query("//s:sheetData/s:row[@r=\"$rNum\"]");
            if ($rows->length > 0) {
                $row = $rows->item(0);
                $row->parentNode->removeChild($row);
            }
        }
        $allRows = $xpath->query('//s:sheetData/s:row');
        $list = [];
        foreach ($allRows as $row) $list[] = $row;
        $newNum = 1;
        foreach ($list as $row) {
            $row->setAttribute('r', (string)$newNum);
            foreach ($row->getElementsByTagName('c') as $c) {
                $oldRef = $c->getAttribute('r');
                $col = preg_replace('/\d+/', '', $oldRef);
                $c->setAttribute('r', $col . $newNum);
            }
            $newNum++;
        }
    }

    private static function colCompare(string $a, string $b): int
    {
        return strlen($a) === strlen($b) ? strcmp($a, $b) : (strlen($a) - strlen($b));
    }

    private static function excelSerialToDate(int $serial): string
    {
        return gmdate('Y-m-d', ($serial - 25569) * 86400);
    }

    private static function escapeText(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function italianMonth(int $m): string
    {
        $names = ['', 'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                  'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        return $names[$m] ?? '';
    }
}
