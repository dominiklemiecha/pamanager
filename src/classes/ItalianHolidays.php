<?php
/**
 * Festivita nazionali italiane.
 * Calcolo a runtime, nessun dato in DB. Pasqua/Pasquetta via algoritmo di Gauss.
 * Santo patrono locale non incluso (configurabile in step successivo).
 */
class ItalianHolidays
{
    /** @var array<int, array<string,string>> cache per anno: 'MM-DD' o 'YYYY-MM-DD' => nome */
    private static array $cache = [];

    /**
     * Mappa YYYY-MM-DD => nome festivita per l'anno indicato.
     */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) return self::$cache[$year];

        $fixed = [
            '01-01' => 'Capodanno',
            '01-06' => 'Epifania',
            '04-25' => 'Festa della Liberazione',
            '05-01' => 'Festa del Lavoro',
            '06-02' => 'Festa della Repubblica',
            '08-15' => 'Ferragosto',
            '11-01' => 'Ognissanti',
            '12-08' => 'Immacolata Concezione',
            '12-25' => 'Natale',
            '12-26' => 'Santo Stefano',
        ];

        $out = [];
        foreach ($fixed as $md => $name) {
            $out[$year . '-' . $md] = $name;
        }

        // Pasqua + Pasquetta (algoritmo di Gauss / easter_days non garantito senza ext-calendar)
        [$em, $ed] = self::easterDate($year);
        $easter = sprintf('%04d-%02d-%02d', $year, $em, $ed);
        $monday = date('Y-m-d', strtotime($easter . ' +1 day'));
        $out[$easter] = 'Pasqua';
        $out[$monday] = 'Pasquetta';

        ksort($out);
        self::$cache[$year] = $out;
        return $out;
    }

    /**
     * Ritorna il nome della festivita per la data YYYY-MM-DD, o null.
     */
    public static function nameFor(string $ymd): ?string
    {
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $ymd, $m)) return null;
        $year = (int) $m[1];
        $map  = self::forYear($year);
        return $map[$ymd] ?? null;
    }

    public static function isHoliday(string $ymd): bool
    {
        return self::nameFor($ymd) !== null;
    }

    /**
     * Algoritmo di Gauss per la Pasqua (rito gregoriano, valido > 1583).
     * @return array{0:int,1:int} [mese, giorno]
     */
    private static function easterDate(int $year): array
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return [$month, $day];
    }
}
