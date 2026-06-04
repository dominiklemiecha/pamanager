<?php
/**
 * FiscalCodeDecoder — Decodifica del Codice Fiscale italiano.
 *
 * Estrae offline: data di nascita, sesso, comune/provincia/stato di nascita.
 * I primi 6 caratteri (cognome+nome) NON sono decodificabili (sono solo
 * tre consonanti per ciascuno; per risalire al nome serve l'anagrafe).
 *
 * Tabella codici catastali: file data/codici_catastali.php (array indicizzato
 * per codice di 4 caratteri). Per la lista completa lanciare
 * `php tools/import-codici-catastali.php`.
 */
class FiscalCodeDecoder
{
    private const MONTHS = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'H' => 6,
        'L' => 7, 'M' => 8, 'P' => 9, 'R' => 10, 'S' => 11, 'T' => 12,
    ];

    /**
     * Decodifica un codice fiscale.
     * @param string $cf 16 caratteri
     * @return array|null ['valid', 'birth_date', 'sex', 'birth_city', 'birth_province', 'birth_state'] o null se non valido
     */
    public static function decode(string $cf): ?array
    {
        $cf = strtoupper(preg_replace('/\s+/', '', $cf));
        if (!preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $cf)) {
            return null;
        }

        // Anno (2 cifre): senza calcolo del secolo univoco, prendiamo finestra 1900-2099
        $yearTwo = (int) substr($cf, 6, 2);
        $now = (int) date('y');
        // Se anno_due_cifre > anno_attuale + 5 -> 1900s, altrimenti 2000s
        $century = ($yearTwo > ($now + 5)) ? 1900 : 2000;
        $year = $century + $yearTwo;

        // Mese
        $monthLetter = substr($cf, 8, 1);
        if (!isset(self::MONTHS[$monthLetter])) return null;
        $month = self::MONTHS[$monthLetter];

        // Giorno (se > 40 = sesso femminile)
        $day = (int) substr($cf, 9, 2);
        $sex = ($day > 40) ? 'F' : 'M';
        if ($day > 40) $day -= 40;

        if (!checkdate($month, $day, $year)) return null;
        $birthDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

        // Codice catastale: 4 caratteri (lettera + 3 cifre)
        $codCat = substr($cf, 11, 4);

        $birthCity = '';
        $birthProvince = '';
        $birthState = '';

        if ($codCat[0] === 'Z') {
            // Stato estero
            $birthState = self::lookupForeignCountry($codCat);
            $birthCity = $birthState ?: 'Estero';
            $birthProvince = 'EE';
        } else {
            // Comune italiano
            $hit = self::lookupItalianComune($codCat);
            if ($hit) {
                $birthCity = $hit['comune'];
                $birthProvince = $hit['provincia'];
                $birthState = 'Italia';
            }
        }

        return [
            'valid'           => true,
            'birth_date'      => $birthDate,
            'sex'             => $sex,
            'birth_city'      => $birthCity,
            'birth_province'  => $birthProvince,
            'birth_state'     => $birthState,
            'cadastral_code'  => $codCat,
        ];
    }

    private static function lookupItalianComune(string $code): ?array
    {
        static $table = null;
        if ($table === null) {
            $file = dirname(__DIR__) . '/data/codici_catastali.php';
            $table = is_file($file) ? (include $file) : [];
        }
        if (!is_array($table)) return null;
        $row = $table[$code] ?? null;
        if (!$row) return null;
        return ['comune' => $row[0], 'provincia' => $row[1]];
    }

    private static function lookupForeignCountry(string $code): ?string
    {
        static $table = null;
        if ($table === null) {
            $file = dirname(__DIR__) . '/data/codici_stati_esteri.php';
            $table = is_file($file) ? (include $file) : [];
        }
        return is_array($table) ? ($table[$code] ?? null) : null;
    }
}
