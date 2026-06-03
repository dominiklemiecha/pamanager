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
     * Cerca saldi ferie/permessi nel testo (formato Teamsystem):
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
    public static function findBalances(string $text): array
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
