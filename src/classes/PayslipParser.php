<?php
/**
 * PayslipParser - estrae codice fiscale e mensilita' da PDF di busta paga.
 *
 * Strategia:
 *  - estrazione testo con smalot/pdfparser (PDF con text layer; no OCR)
 *  - CF dipendente: pattern CF italiano 16 char (esclude P.IVA 11 cifre)
 *  - Mensilita': nomi mese italiani in maiuscolo/minuscolo + anno 4 cifre,
 *    o formati MM/YYYY come fallback. Restringe l'anno a [oggi-2 anni, oggi+1 anno].
 */
class PayslipParser
{
    private const CF_REGEX = '/\b([A-Z]{6}\d{2}[A-Z]\d{2}[A-Z][0-9A-Z]{3}[A-Z])\b/u';

    private const MONTHS = [
        'gennaio' => 1, 'genn' => 1,
        'febbraio' => 2, 'febb' => 2,
        'marzo' => 3, 'mar' => 3,
        'aprile' => 4, 'apr' => 4,
        'maggio' => 5, 'mag' => 5,
        'giugno' => 6, 'giu' => 6,
        'luglio' => 7, 'lug' => 7,
        'agosto' => 8, 'ago' => 8,
        'settembre' => 9, 'sett' => 9, 'set' => 9,
        'ottobre' => 10, 'ott' => 10,
        'novembre' => 11, 'nov' => 11,
        'dicembre' => 12, 'dic' => 12,
    ];

    public static function extractText(string $pdfPath): string
    {
        // 1) pdftotext (poppler): molto piu' robusto sui PDF generati dai software paghe.
        //    Richiede pacchetto 'poppler-utils' installato (vedi Dockerfile).
        $text = self::extractWithPdftotext($pdfPath);
        if ($text !== null && trim($text) !== '') return $text;

        // 2) Fallback smalot/pdfparser (puro PHP, ok per PDF semplici).
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $doc = $parser->parseFile($pdfPath);
                return $doc->getText();
            } catch (Throwable $e) {
                error_log('[PayslipParser] smalot fallback failed: ' . $e->getMessage());
            }
        }
        return '';
    }

    private static function extractWithPdftotext(string $pdfPath): ?string
    {
        if (!is_readable($pdfPath)) return null;
        $bin = '/usr/bin/pdftotext';
        if (!is_executable($bin)) {
            $found = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
            if ($found === '') return null;
            $bin = $found;
        }
        $cmd = escapeshellcmd($bin) . ' -layout -nopgbrk ' . escapeshellarg($pdfPath) . ' - 2>/dev/null';
        $out = @shell_exec($cmd);
        return $out === null ? null : (string) $out;
    }

    /** Trova il primo CF persona valido nel testo. Ignora P.IVA (11 cifre). */
    public static function findFiscalCode(string $text): ?string
    {
        if (!preg_match_all(self::CF_REGEX, mb_strtoupper($text), $m)) return null;
        foreach ($m[1] as $cand) {
            if (self::isValidCfStructure($cand)) return $cand;
        }
        return null;
    }

    /**
     * Trova mensilita': cerca pattern "MESE ANNO" (italiano) o "MM/YYYY"
     * vicino alla parola MENSILITA' / COMPETENZA / PERIODO. Fallback: prima
     * occorrenza nel testo.
     * @return array{month:int,year:int}|null
     */
    public static function findPeriod(string $text): ?array
    {
        $now = (int) date('Y');
        $yMin = $now - 2; $yMax = $now + 1;
        $lower = mb_strtolower($text);

        // 1) MESE ANNO (es. "APRILE 2026")
        $monthsAlt = implode('|', array_keys(self::MONTHS));
        $pattern = '/(?P<m>' . $monthsAlt . ')\s*[\.\-\/]?\s*(?P<y>\d{4})/u';
        if (preg_match_all($pattern, $lower, $matches, PREG_OFFSET_CAPTURE)) {
            // Preferisci match vicini a parole chiave
            $keywords = ['mensilita', 'mensilità', 'competenza', 'periodo'];
            $bestIdx = self::pickBestMatchByKeyword($lower, $matches, $keywords);
            $rawMonth = $matches['m'][$bestIdx][0];
            $year = (int) $matches['y'][$bestIdx][0];
            $month = self::MONTHS[$rawMonth] ?? 0;
            if ($month >= 1 && $month <= 12 && $year >= $yMin && $year <= $yMax) {
                return ['month' => $month, 'year' => $year];
            }
        }

        // 2) MM/YYYY o MM-YYYY (es. "04/2026")
        if (preg_match_all('/\b(0[1-9]|1[0-2])[\/\-\.](\d{4})\b/u', $text, $m)) {
            foreach ($m[2] as $i => $y) {
                $year = (int) $y; $month = (int) $m[1][$i];
                if ($year >= $yMin && $year <= $yMax) {
                    return ['month' => $month, 'year' => $year];
                }
            }
        }

        return null;
    }

    /** Parse completo: CF + periodo + saldi ferie/permessi. */
    public static function parse(string $pdfPath): array
    {
        $text = self::extractText($pdfPath);
        return [
            'cf'       => self::findFiscalCode($text),
            'period'   => self::findPeriod($text),
            'balances' => self::findBalances($text),
        ];
    }

    /**
     * Cerca saldi ferie/permessi nel testo. Prova in cascata i layout noti:
     *   A) Teamsystem "tabellare": header ANNO PREC. MATURATO GODUTO RESIDUO
     *      PROIEZIONE + righe-marker g (ferie) / h (permessi).
     *   B) Teamsystem "Mod. Cedolino TS" a griglia: contatori FERIE/PERM./ROL e
     *      FEST.(ex festivita')/FLESS./B.ORE, ognuno con 4 sotto-colonne
     *      A.P. | MAT. | GOD. | RES.
     * Restituisce sempre ['ferie' => ?array, 'permesso' => ?array] dove ogni
     * array ha almeno la chiave 'residuo' (gli unici consumer usano quella).
     */
    public static function findBalances(string $text): array
    {
        $byHeader = self::findBalancesHeader($text);
        if ($byHeader['ferie'] !== null || $byHeader['permesso'] !== null) return $byHeader;

        $byGrid = self::findBalancesGrid($text);
        if ($byGrid !== null) return $byGrid;

        return ['ferie' => null, 'permesso' => null];
    }

    /**
     * Formato A (tabellare):
     *   ANNO PREC. MATURATO GODUTO RESIDUO PROIEZIONE
     *   FERIE
     *   g  7,36  1,83  5,53  20,17     <- numeri allineati posizionalmente
     *   PERMESSI
     *   h  10,64  9,33  1,31  22,67
     *
     * NB: celle con valore 0 vengono OMESSE dalla stampa (non rese come '0'),
     * quindi servono X-position per mappare i numeri alle colonne giuste.
     * Restituisce: ['ferie' => ['residuo' => float|null, 'maturato' => ..., ...], 'permesso' => same]
     */
    private static function findBalancesHeader(string $text): array
    {
        $out = ['ferie' => null, 'permesso' => null];
        $lines = explode("\n", $text);

        // 1) Cerca la riga header
        $headerIdx = -1; $headerLine = '';
        foreach ($lines as $i => $ln) {
            if (preg_match('/ANNO\s*PREC\.?\s+MATURATO\s+GODUTO\s+RESIDUO\s+PROIEZIONE/i', $ln)) {
                $headerIdx = $i; $headerLine = $ln; break;
            }
        }
        if ($headerIdx < 0) return $out;

        // 2) X position (right edge) di ciascuna colonna nell'header
        $cols = [
            'anno_prec'  => 'ANNO PREC.',
            'maturato'   => 'MATURATO',
            'goduto'     => 'GODUTO',
            'residuo'    => 'RESIDUO',
            'proiezione' => 'PROIEZIONE',
        ];
        $colPos = [];
        foreach ($cols as $key => $label) {
            $p = stripos($headerLine, $label);
            if ($p !== false) $colPos[$key] = $p + strlen($label) - 1; // right edge
        }
        if (count($colPos) < 3) return $out;

        // 3) Scansiona righe successive in cerca di marker g (ferie) / h (permesso)
        for ($i = $headerIdx + 1; $i < min($headerIdx + 30, count($lines)); $i++) {
            $ln = $lines[$i];
            // Marker: una sola lettera g/h preceduta da spazi e seguita da numeri
            if (!preg_match('/^(\s*)([gh])\s+(\d)/', $ln, $m)) continue;
            $marker = $m[2];
            $row = self::parseBalanceRow($ln, $colPos);
            $key = ($marker === 'g') ? 'ferie' : 'permesso';
            $out[$key] = $row;
        }
        return $out;
    }

    /** Estrae i numeri dalla riga, ognuno mappato alla colonna piu' vicina per X. */
    private static function parseBalanceRow(string $line, array $colPos): array
    {
        $row = ['anno_prec' => null, 'maturato' => null, 'goduto' => null, 'residuo' => null, 'proiezione' => null];
        if (!preg_match_all('/(\d+(?:[\.,]\d+)?)/u', $line, $matches, PREG_OFFSET_CAPTURE)) return $row;
        foreach ($matches[1] as $match) {
            [$val, $pos] = $match;
            $rightEdge = $pos + strlen($val) - 1;
            // Trova la colonna con right-edge piu' vicino al right-edge del numero
            $bestKey = null; $bestDist = PHP_INT_MAX;
            foreach ($colPos as $key => $cpos) {
                $d = abs($cpos - $rightEdge);
                if ($d < $bestDist) { $bestDist = $d; $bestKey = $key; }
            }
            // Tolleranza: il numero deve essere ragionevolmente vicino alla colonna
            if ($bestKey !== null && $bestDist <= 14) {
                $row[$bestKey] = (float) str_replace(',', '.', $val);
            }
        }
        return $row;
    }

    /**
     * Formato B ("Mod. Cedolino TS"): due righe-griglia di contatori, ognuna con
     * 4 sotto-colonne A.P. | MAT. | GOD. | RES. per ciascun istituto.
     *   riga 1: FERIE | PERM. | ROL
     *   riga 2: FEST. (ex festivita') | FLESS. | B. ORE
     * Le celle a 0 sono OMESSE, quindi si mappano i numeri alle colonne per
     * posizione X (assegnazione monotona sinistra->destra, vedi assignByColumn).
     *
     * Regole concordate col cliente:
     *   - "ferie"    = residuo della colonna FERIE RES.
     *   - "permesso" = SOMMA dei residui VALORIZZATI degli istituti di permesso
     *                  (PERM. + ROL + EX FESTIVITA'). Le colonne vuote non contano.
     *                  FLESS. e B.ORE (banca ore) sono ESCLUSE: non sono permessi.
     * @return array{ferie: ?array, permesso: ?array}|null  null se la griglia non c'e'
     */
    private static function findBalancesGrid(string $text): ?array
    {
        $lines = explode("\n", $text);
        $row1 = -1; $row2 = -1;
        foreach ($lines as $i => $ln) {
            if ($row1 < 0 && stripos($ln, 'FERIE A.P.') !== false && stripos($ln, 'FERIE RES.') !== false) $row1 = $i;
            if ($row2 < 0 && stripos($ln, 'FEST. A.P.') !== false) $row2 = $i;
        }
        if ($row1 < 0) return null;

        $res = []; // istituto => residuo (float)
        $grab = function (int $hdrIdx, array $labels, array $resMap) use ($lines, &$res) {
            if ($hdrIdx < 0) return;
            $valLine = self::gridValueLine($lines, $hdrIdx);
            if ($valLine === null) return;
            $cols = self::gridColumns($lines[$hdrIdx], $labels);
            if (count($cols) < 2) return;
            $assigned = self::assignByColumn($valLine, $cols); // label => float
            foreach ($resMap as $resLabel => $istituto) {
                if (isset($assigned[$resLabel])) $res[$istituto] = $assigned[$resLabel];
            }
        };

        $grab($row1, [
            'FERIE A.P.', 'FERIE MAT.', 'FERIE GOD.', 'FERIE RES.',
            'PERM. A.P.', 'PERM. MAT.', 'PERM. GOD.', 'PERM. RES.',
            'ROL. A.P.', 'ROL. MAT.', 'ROL GOD.', 'ROL RES.',
        ], ['FERIE RES.' => 'ferie', 'PERM. RES.' => 'perm', 'ROL RES.' => 'rol']);

        $grab($row2, [
            'FEST. A.P.', 'FEST. MAT.', 'FEST. GOD.', 'FEST. RES.',
            'FLESS. A.P.', 'FLESS. MAT.', 'FLESS.GOD.', 'FLESS. RES.',
            'B. ORE A.P.', 'B. ORE MAT.', 'B. ORE GOD.', 'B. ORE RES.',
        ], ['FEST. RES.' => 'exfest']);

        if (empty($res)) return null;

        $mk = fn(float $v) => [
            'anno_prec' => null, 'maturato' => null, 'goduto' => null,
            'residuo' => round($v, 2), 'proiezione' => null,
        ];

        $ferie = isset($res['ferie']) ? $mk($res['ferie']) : null;

        // permesso = somma residui valorizzati di PERM + ROL + EX FEST
        $components = [];
        foreach (['perm', 'rol', 'exfest'] as $k) {
            if (isset($res[$k])) $components[$k] = $res[$k];
        }
        $permesso = null;
        if (!empty($components)) {
            $permesso = $mk(array_sum($components));
            $permesso['components'] = $components; // dettaglio per debug/UI futura
        }

        return ['ferie' => $ferie, 'permesso' => $permesso];
    }

    /** Prima riga con cifre dopo l'header griglia (salta righe vuote). */
    private static function gridValueLine(array $lines, int $hdrIdx): ?string
    {
        for ($i = $hdrIdx + 1; $i < min($hdrIdx + 5, count($lines)); $i++) {
            if (isset($lines[$i]) && preg_match('/\d/', $lines[$i])) return $lines[$i];
        }
        return null;
    }

    /** Centro X (offset carattere) di ogni label presente nell'header, ordinato per posizione. */
    private static function gridColumns(string $header, array $labels): array
    {
        $cols = [];
        foreach ($labels as $lab) {
            $p = stripos($header, $lab);
            if ($p !== false) {
                $cols[] = ['label' => $lab, 'pos' => $p, 'center' => $p + (strlen($lab) - 1) / 2.0];
            }
        }
        usort($cols, fn($a, $b) => $a['pos'] <=> $b['pos']);
        return $cols;
    }

    /**
     * Mappa i numeri della riga alle colonne con assegnazione MONOTONA
     * sinistra->destra: ogni numero va alla colonna piu' vicina (per centro X)
     * con indice > dell'ultima usata. Cosi' le celle a 0 (omesse dalla stampa)
     * vengono saltate senza disallineare i numeri successivi.
     * @return array<string,float> label => valore
     */
    private static function assignByColumn(string $line, array $cols): array
    {
        if (!preg_match_all('/-?\d+(?:[\.,]\d+)?/u', $line, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        $lastIdx = -1;
        foreach ($m[0] as $match) {
            [$val, $pos] = $match;
            $center = $pos + (strlen($val) - 1) / 2.0;
            $bestIdx = -1; $bestDist = PHP_FLOAT_MAX;
            for ($c = $lastIdx + 1; $c < count($cols); $c++) {
                $d = abs($cols[$c]['center'] - $center);
                if ($d < $bestDist) { $bestDist = $d; $bestIdx = $c; }
            }
            if ($bestIdx < 0) break; // colonne esaurite
            $out[$cols[$bestIdx]['label']] = (float) str_replace(',', '.', $val);
            $lastIdx = $bestIdx;
        }
        return $out;
    }

    private static function pickBestMatchByKeyword(string $haystack, array $matches, array $keywords): int
    {
        $kPositions = [];
        foreach ($keywords as $kw) {
            $p = mb_strpos($haystack, $kw);
            if ($p !== false) $kPositions[] = $p;
        }
        if (empty($kPositions)) return 0;
        $bestIdx = 0; $bestDist = PHP_INT_MAX;
        foreach ($matches['m'] as $i => $pair) {
            $pos = $pair[1];
            foreach ($kPositions as $kp) {
                $d = abs($pos - $kp);
                if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
            }
        }
        return $bestIdx;
    }

    /** Validazione strutturale (no checksum): CF persona ha pattern fisso 16 char. */
    private static function isValidCfStructure(string $cf): bool
    {
        return (bool) preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z][0-9A-Z]{3}[A-Z]$/', $cf);
    }
}
