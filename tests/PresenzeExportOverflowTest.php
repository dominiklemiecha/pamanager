<?php
/**
 * Test PresenzeExport: tutti i dipendenti devono comparire nell'export,
 * anche quando superano le righe dipendente disponibili nel template (18).
 * Esecuzione: php tests/PresenzeExportOverflowTest.php
 *
 * Non usa il DB: inietta i dipendenti via reflection e invoca buildXlsxBinary.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
require_once __DIR__ . '/../src/classes/ItalianHolidays.php';
require_once __DIR__ . '/../src/classes/PresenzeExport.php';

$failures = 0;
$tests = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $tests;
    $tests++;
    if ($expected === $actual) {
        echo "  OK   $name\n";
    } else {
        $failures++;
        echo "  FAIL $name — atteso " . var_export($expected, true) . ", ottenuto " . var_export($actual, true) . "\n";
    }
}

function fakeEmployees(int $n): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = [
            'id'                              => $i,
            'first_name'                      => 'Mario',
            'last_name'                       => sprintf('Rossi%02d', $i),
            'smart_working_days'              => null,
            'buoni_pasto_min_hours_override'  => null,
            'buoni_pasto_sw_eligible_override'=> null,
            'buoni_pasto_excluded'            => 0,
        ];
    }
    return $out;
}

/**
 * Genera l'export con dipendenti iniettati (bypass DB) e ritorna
 * [PresenzeExport, righe del foglio] dove ogni riga = lista di testi inlineStr.
 */
function buildWithEmployees(array $employees, array $cells = []): array
{
    $exp = new PresenzeExport(6, 2026);
    $rp = new ReflectionProperty(PresenzeExport::class, 'employees');
    $rp->setAccessible(true);
    $rp->setValue($exp, $employees);
    if ($cells) {
        $rc = new ReflectionProperty(PresenzeExport::class, 'cells');
        $rc->setAccessible(true);
        $rc->setValue($exp, $cells);
    }
    $rm = new ReflectionMethod(PresenzeExport::class, 'buildXlsxBinary');
    $rm->setAccessible(true);
    $bin = $rm->invoke($exp);

    $tmp = tempnam(sys_get_temp_dir(), 'pamtest');
    file_put_contents($tmp, $bin);
    $zip = new ZipArchive();
    $zip->open($tmp);
    // Shared strings (la legenda del template resta come t="s")
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
    if ($ssXml !== '' && preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $ssXml, $items)) {
        foreach ($items[1] as $item) {
            preg_match_all('#<t[^>]*>(.*?)</t>#s', $item, $tt);
            $shared[] = html_entity_decode(implode('', $tt[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $rows = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'xl/worksheets/sheet') !== 0) continue;
        $xml = $zip->getFromName($name);
        preg_match_all('#<row r="(\d+)"[^>]*>(.*?)</row>#s', $xml, $rm2, PREG_SET_ORDER);
        foreach ($rm2 as $r) {
            $texts = [];
            preg_match_all('#<c\s+[^>]*?>.*?(?:</c>)#s', $r[2], $cm);
            foreach ($cm[0] as $cellXml) {
                if (preg_match('#t="s"[^>]*>\s*<v>(\d+)</v>#s', $cellXml, $vm)) {
                    $texts[] = $shared[(int)$vm[1]] ?? '';
                } elseif (preg_match('#<is>.*?<t[^>]*>([^<]*)</t>#s', $cellXml, $tm)) {
                    $texts[] = $tm[1];
                }
            }
            $rows[(int)$r[1]] = $texts;
        }
    }
    $zip->close();
    @unlink($tmp);
    ksort($rows);
    return [$exp, $rows];
}

/** Nomi dipendente presenti in tutto il foglio (celle "M. RossiNN"). */
function namesInSheet(array $rows): array
{
    $names = [];
    foreach ($rows as $texts) {
        foreach ($texts as $t) {
            if (preg_match('/^M\. Rossi\d+$/', $t)) $names[] = $t;
        }
    }
    return $names;
}

echo "PresenzeExport — dipendenti oltre le righe del template\n";

// --- Caso 1: 25 dipendenti (template ne ha 18) -> tutti presenti ---
[$exp, $rows] = buildWithEmployees(fakeEmployees(25));
$names = namesInSheet($rows);
check('25 dipendenti: tutti scritti', 25, count($names));
check('25 dipendenti: writtenEmployees', 25, $exp->writtenEmployees);
check('25 dipendenti: nessun overflow', false, $exp->overflow);
check('25 dipendenti: ordine preservato (ultimo)', 'M. Rossi25', $names ? end($names) : null);

// La legenda deve restare presente dopo le righe dipendente
$flat = [];
foreach ($rows as $texts) foreach ($texts as $t) $flat[] = $t;
check('25 dipendenti: legenda presente', true, in_array('Smart Working', $flat, true));

// I codici del 25° dipendente devono comparire nella sua riga
[, $rows2] = buildWithEmployees(fakeEmployees(25), [25 => ['2026-06-10' => 'F']]);
$rowOfLast = null;
foreach ($rows2 as $texts) {
    if (in_array('M. Rossi25', $texts, true)) { $rowOfLast = $texts; break; }
}
check('25 dipendenti: codice F scritto nella riga del 25°', true, $rowOfLast !== null && in_array('F', $rowOfLast, true));

// --- Caso 2: 10 dipendenti -> nessuna regressione ---
[$exp3, $rows3] = buildWithEmployees(fakeEmployees(10));
$names3 = namesInSheet($rows3);
check('10 dipendenti: tutti scritti', 10, count($names3));
check('10 dipendenti: writtenEmployees', 10, $exp3->writtenEmployees);
$flat3 = [];
foreach ($rows3 as $texts) foreach ($texts as $t) $flat3[] = $t;
check('10 dipendenti: nomi template rimossi', false, in_array('S. De Vincenti', $flat3, true));
check('10 dipendenti: legenda presente', true, in_array('Smart Working', $flat3, true));

echo "\n$tests test, $failures falliti\n";
exit($failures > 0 ? 1 : 0);
