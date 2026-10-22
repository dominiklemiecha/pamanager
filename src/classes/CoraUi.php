<?php
/**
 * Interfaccia "Cora" della vista dipendente (design Figma "Modulo Cora HR",
 * sezione 913:5639): shell con dock in basso e pagine ridisegnate.
 *
 * ENV EMPLOYEE_CORA_UI:
 *   vuota        interfaccia classica per tutti
 *   all          interfaccia Cora per tutti i dipendenti
 *   a@x,b@y      interfaccia Cora solo per queste email (employees.email)
 */
class CoraUi
{
    private static ?bool $enabled = null;

    /** Dati della shell (foto, notifiche, contatori) calcolati da header-employee.php. */
    public static array $shell = [];

    public static function enabled(): bool
    {
        if (self::$enabled !== null) return self::$enabled;
        self::$enabled = false;

        $cfg = strtolower(trim((string) (getenv('EMPLOYEE_CORA_UI') ?: ($_ENV['EMPLOYEE_CORA_UI'] ?? ''))));
        if ($cfg === '' || !Auth::isEmployeeLoggedIn()) return false;
        if ($cfg === 'all' || $cfg === '1') return self::$enabled = true;

        $emails = array_filter(array_map('trim', explode(',', $cfg)));
        $emp = Auth::getEmployee() ?? [];
        try {
            $email = strtolower(trim((string) Database::fetchColumn("SELECT email FROM employees WHERE id = ?", [(int) ($emp['id'] ?? 0)])));
        } catch (Throwable $e) {
            return false;
        }
        return self::$enabled = ($email !== '' && in_array($email, $emails, true));
    }

    public static function asset(string $file): string
    {
        return PUBLIC_URL . '/assets/images/cora/' . $file;
    }

    /**
     * SVG del design inline. Con $tint i colori di tratto e riempimento
     * diventano currentColor, cosi' l'icona segue il colore del testo
     * (voce attiva del dock, hover).
     */
    public static function svg(string $file, bool $tint = false): string
    {
        static $cache = [];
        $key = $file . ($tint ? ':t' : '');
        if (isset($cache[$key])) return $cache[$key];
        $path = ROOT_PATH . '/public/assets/images/cora/' . basename($file);
        $svg = is_file($path) ? (string) file_get_contents($path) : '';
        $svg = str_replace(['style="display: block;" ', 'preserveAspectRatio="none" '], '', $svg);
        if ($tint) {
            $svg = preg_replace('/(stroke|fill)="#[0-9A-Fa-f]{3,8}"/', '$1="currentColor"', $svg);
        }
        $svg = preg_replace('/<svg /', '<svg aria-hidden="true" focusable="false" ', $svg, 1);
        return $cache[$key] = $svg;
    }

    /** Nomi salvati in maiuscolo ("LUCA") mostrati come "Luca". */
    public static function name(?string $s): string
    {
        $s = trim((string) $s);
        if ($s === '' || $s !== mb_strtoupper($s)) return $s;
        return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
    }

    /** Numero all'italiana senza decimali inutili: 13.5 -> "13,5", 15.0 -> "15". */
    public static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
    }

    /** "23 ott 2026" (con anno) o "23 ott". */
    public static function shortDate(?string $d, bool $withYear = true): string
    {
        if (!$d) return '';
        $m = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $ts = strtotime($d);
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)] . ($withYear ? ' ' . date('Y', $ts) : '');
    }

    /** "12 ottobre 2026". */
    public static function longDate(?string $d): string
    {
        if (!$d) return '';
        $m = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto',
              'settembre', 'ottobre', 'novembre', 'dicembre'];
        $ts = strtotime($d);
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    /** "6 giorni fa", "2 settimane fa", "1 mese fa". */
    public static function ago(?string $dt): string
    {
        if (!$dt) return '';
        $diff = max(0, time() - strtotime($dt));
        if ($diff < 3600) return 'pochi minuti fa';
        if ($diff < 86400) { $h = (int) floor($diff / 3600); return $h . ($h === 1 ? ' ora fa' : ' ore fa'); }
        $d = (int) floor($diff / 86400);
        if ($d < 7) return $d === 1 ? '1 giorno fa' : $d . ' giorni fa';
        if ($d < 30) { $w = (int) floor($d / 7); return $w === 1 ? '1 settimana fa' : $w . ' settimane fa'; }
        if ($d < 365) { $mo = (int) floor($d / 30); return $mo === 1 ? '1 mese fa' : $mo . ' mesi fa'; }
        $y = (int) floor($d / 365);
        return $y === 1 ? '1 anno fa' : $y . ' anni fa';
    }

    /** Dimensione file: "846 KB", "1,2 MB". */
    public static function size(?int $bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) return self::num(round($bytes / 1048576, 1)) . ' MB';
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Estensione in maiuscolo dal nome file o dal mime. */
    public static function ext(?string $name, ?string $mime = null): string
    {
        $e = strtoupper((string) pathinfo((string) $name, PATHINFO_EXTENSION));
        if ($e !== '') return $e;
        return $mime === 'application/pdf' ? 'PDF' : 'FILE';
    }

    /**
     * Barra in alto delle pagine: titolo + sottotitolo a sinistra, azioni
     * della pagina, poi campanella e avatar (sempre uguali).
     */
    public static function topbar(string $title, string $subtitle = '', string $actionsHtml = '', string $centerHtml = ''): string
    {
        ob_start();
        include ROOT_PATH . '/public/includes/cora/_topbar.inc.php';
        return (string) ob_get_clean();
    }
}
