<?php
/**
 * ProbationParser - estrae il periodo di prova da un PDF di contratto e ne calcola
 * la data di fine a partire dalla data di inizio rapporto.
 *
 * E' un ASSIST: il risultato e' un SUGGERIMENTO che l'admin conferma o corregge
 * nella scheda del dipendente. I contratti sono testo libero e la dicitura del
 * periodo di prova varia molto ("3 mesi", "mesi 3", "sei mesi", "90 giorni",
 * "in giorni novanta"...), quindi l'estrazione e' best-effort.
 *
 * Riusa PayslipParser::extractText (pdftotext + fallback smalot) per il testo PDF.
 */
class ProbationParser
{
    /** Numeri scritti a parole comunemente usati nei contratti. */
    private const WORD_NUMBERS = [
        'uno' => 1, 'una' => 1, 'due' => 2, 'tre' => 3, 'quattro' => 4, 'cinque' => 5,
        'sei' => 6, 'sette' => 7, 'otto' => 8, 'nove' => 9, 'dieci' => 10, 'undici' => 11,
        'dodici' => 12, 'tredici' => 13, 'quattordici' => 14, 'quindici' => 15,
        'venti' => 20, 'trenta' => 30, 'quaranta' => 40, 'quarantacinque' => 45,
        'sessanta' => 60, 'novanta' => 90, 'centoventi' => 120, 'centottanta' => 180,
    ];

    /**
     * @return array{end_date: ?string, months: ?int, days: ?int, weeks: ?int, raw: ?string}
     */
    public static function parse(string $pdfPath, ?string $startDate): array
    {
        $text = class_exists('PayslipParser') ? PayslipParser::extractText($pdfPath) : '';
        return self::parseText($text, $startDate);
    }

    /**
     * Analizza il testo gia' estratto. Separato da parse() per testabilita'.
     * @return array{end_date: ?string, months: ?int, days: ?int, weeks: ?int, raw: ?string}
     */
    public static function parseText(string $text, ?string $startDate): array
    {
        $empty = ['end_date' => null, 'months' => null, 'days' => null, 'weeks' => null, 'raw' => null];
        if (trim($text) === '') return $empty;

        $lower = mb_strtolower($text, 'UTF-8');
        // Normalizza spazi/newline per finestre di ricerca piu' stabili.
        $flat = preg_replace('/\s+/u', ' ', $lower);

        $found = null;
        $offset = 0;
        while (($pos = mb_strpos($flat, 'prova', $offset, 'UTF-8')) !== false) {
            $offset = $pos + 5;
            // Finestra dopo l'occorrenza di "prova" (la durata segue quasi sempre).
            $window = mb_substr($flat, $pos, 160, 'UTF-8');
            $hit = self::extractDuration($window);
            if ($hit !== null) { $found = $hit; break; }
        }
        if ($found === null) return $empty;

        $result = [
            'end_date' => null,
            'months'   => $found['unit'] === 'month' ? $found['n'] : null,
            'days'     => $found['unit'] === 'day' ? $found['n'] : null,
            'weeks'    => $found['unit'] === 'week' ? $found['n'] : null,
            'raw'      => trim($found['raw']),
        ];
        $result['end_date'] = self::computeEndDate($startDate, $found['unit'], $found['n']);
        return $result;
    }

    /**
     * Cerca "numero + unita'" o "unita' + numero" in una finestra di testo.
     * @return array{n:int, unit:string, raw:string}|null  unit: month|day|week
     */
    private static function extractDuration(string $window): ?array
    {
        $units = 'mes[ei]|giorn[oi]|settiman[ae]';
        $numWord = implode('|', array_keys(self::WORD_NUMBERS));

        // Ordine A: numero (cifre o parola) poi unita' — "3 mesi", "sei mesi", "90 giorni"
        if (preg_match('/\b(\d{1,3}|' . $numWord . ')\b[\s\(\)a-z]{0,20}?\b(' . $units . ')\b/u', $window, $m)) {
            $n = self::toInt($m[1]);
            if ($n > 0 && $n <= 730) {
                return ['n' => $n, 'unit' => self::unitKey($m[2]), 'raw' => $m[0]];
            }
        }
        // Ordine B: unita' poi numero — "mesi 3", "giorni novanta"
        if (preg_match('/\b(' . $units . ')\b[\s\(\)a-z]{0,10}?\b(\d{1,3}|' . $numWord . ')\b/u', $window, $m)) {
            $n = self::toInt($m[2]);
            if ($n > 0 && $n <= 730) {
                return ['n' => $n, 'unit' => self::unitKey($m[1]), 'raw' => $m[0]];
            }
        }
        return null;
    }

    private static function toInt(string $token): int
    {
        $token = trim(mb_strtolower($token, 'UTF-8'));
        if (ctype_digit($token)) return (int)$token;
        return self::WORD_NUMBERS[$token] ?? 0;
    }

    private static function unitKey(string $u): string
    {
        $u = mb_strtolower($u, 'UTF-8');
        if (strpos($u, 'mes') === 0) return 'month';
        if (strpos($u, 'settiman') === 0) return 'week';
        return 'day';
    }

    /** start_date + durata → data di fine prova (Y-m-d), o null se manca lo start. */
    private static function computeEndDate(?string $startDate, string $unit, int $n): ?string
    {
        if (empty($startDate)) return null;
        $ts = strtotime($startDate);
        if ($ts === false) return null;
        try {
            $d = new DateTime(date('Y-m-d', $ts));
            $spec = match ($unit) {
                'month' => 'P' . $n . 'M',
                'week'  => 'P' . ($n * 7) . 'D',
                default => 'P' . $n . 'D',
            };
            $d->add(new DateInterval($spec));
            return $d->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
}
