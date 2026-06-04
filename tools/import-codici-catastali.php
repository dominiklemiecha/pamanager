<?php
/**
 * Import codici catastali Comuni Italiani + Stati Esteri.
 * Lancia: docker exec gestionalepa-app-1 php /var/www/html/tools/import-codici-catastali.php
 *
 * Sorgenti (mirror pubblici stabili dell'elenco ANPR / Agenzia Entrate):
 *  - Comuni: https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json
 *  - Stati:  https://raw.githubusercontent.com/matteocontrini/comuni-json/master/stati.json (se disponibile)
 */

$outDir = dirname(__DIR__) . '/src/data';
if (!is_dir($outDir)) mkdir($outDir, 0775, true);

function fetch(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: PAManager-Import/1.0\r\n"]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) throw new RuntimeException("Download fallito: $url");
    return $data;
}

echo "Scarico comuni.json...\n";
$json = fetch('https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json');
$comuni = json_decode($json, true);
if (!is_array($comuni)) throw new RuntimeException('JSON comuni non valido');

echo "Trovati " . count($comuni) . " comuni\n";

$out = [];
foreach ($comuni as $c) {
    $cod = $c['codiceCatastale'] ?? null;
    $nome = $c['nome'] ?? null;
    $prov = $c['sigla'] ?? ($c['provincia']['sigla'] ?? null);
    if (!$cod || !$nome || !$prov) continue;
    $out[$cod] = [$nome, $prov];
}

ksort($out);
$php = "<?php\n// Auto-generato da tools/import-codici-catastali.php\n// Comuni: " . count($out) . "\nreturn " . var_export($out, true) . ";\n";
file_put_contents($outDir . '/codici_catastali.php', $php);
echo "Scritto src/data/codici_catastali.php (" . count($out) . " comuni)\n";

// Stati esteri: lista AT-Z statica (codici fissi ANPR). Subset comune.
$stati = [
    'Z100' => 'Albania', 'Z101' => 'Andorra', 'Z102' => 'Austria', 'Z103' => 'Belgio',
    'Z104' => 'Bulgaria', 'Z106' => 'Danimarca', 'Z107' => 'Estonia', 'Z109' => 'Finlandia',
    'Z110' => 'Francia', 'Z111' => 'Germania', 'Z112' => 'Regno Unito', 'Z115' => 'Grecia',
    'Z118' => 'Irlanda', 'Z120' => 'Islanda', 'Z124' => 'Liechtenstein', 'Z126' => 'Lussemburgo',
    'Z127' => 'Malta', 'Z131' => 'Norvegia', 'Z133' => 'Paesi Bassi', 'Z134' => 'Polonia',
    'Z135' => 'Portogallo', 'Z140' => 'Romania', 'Z141' => 'San Marino', 'Z144' => 'Spagna',
    'Z146' => 'Svezia', 'Z147' => 'Svizzera', 'Z148' => 'Cecoslovacchia', 'Z149' => 'Turchia',
    'Z151' => 'Ungheria', 'Z153' => 'URSS', 'Z154' => 'Russia', 'Z155' => 'Ucraina',
    'Z156' => 'Bielorussia', 'Z157' => 'Moldova', 'Z160' => 'Croazia', 'Z161' => 'Slovenia',
    'Z162' => 'Bosnia-Erzegovina', 'Z163' => 'Serbia', 'Z164' => 'Macedonia del Nord',
    'Z200' => 'Algeria', 'Z204' => 'Burundi', 'Z206' => 'Capo Verde', 'Z209' => 'Costa d\'Avorio',
    'Z210' => 'Egitto', 'Z212' => 'Etiopia', 'Z216' => 'Ghana', 'Z217' => 'Gibuti',
    'Z221' => 'Kenya', 'Z222' => 'Lesotho', 'Z223' => 'Liberia', 'Z224' => 'Libia',
    'Z225' => 'Madagascar', 'Z226' => 'Malawi', 'Z228' => 'Marocco', 'Z229' => 'Mauritania',
    'Z230' => 'Mauritius', 'Z232' => 'Mozambico', 'Z233' => 'Niger', 'Z234' => 'Nigeria',
    'Z236' => 'Uganda', 'Z238' => 'Rwanda', 'Z240' => 'Senegal', 'Z242' => 'Sierra Leone',
    'Z244' => 'Somalia', 'Z245' => 'Sudan', 'Z248' => 'Tanzania', 'Z250' => 'Tunisia',
    'Z254' => 'Zambia', 'Z255' => 'Zimbabwe', 'Z300' => 'Afghanistan', 'Z301' => 'Arabia Saudita',
    'Z302' => 'Bahrein', 'Z303' => 'Bangladesh', 'Z304' => 'Bhutan', 'Z305' => 'Birmania',
    'Z306' => 'Cambogia', 'Z210' => 'Sri Lanka', 'Z210' => 'Cina', 'Z210' => 'Cipro',
    'Z214' => 'Filippine', 'Z215' => 'Giappone', 'Z216' => 'Giordania', 'Z218' => 'India',
    'Z219' => 'Indonesia', 'Z220' => 'Iran', 'Z221' => 'Iraq', 'Z222' => 'Israele',
    'Z223' => 'Kazakistan', 'Z224' => 'Kuwait', 'Z226' => 'Libano', 'Z227' => 'Malesia',
    'Z229' => 'Mongolia', 'Z230' => 'Nepal', 'Z232' => 'Oman', 'Z233' => 'Pakistan',
    'Z236' => 'Corea del Sud', 'Z237' => 'Singapore', 'Z238' => 'Siria', 'Z240' => 'Thailandia',
    'Z242' => 'Vietnam', 'Z243' => 'Yemen', 'Z400' => 'Bahamas', 'Z401' => 'Barbados',
    'Z402' => 'Canada', 'Z403' => 'Costa Rica', 'Z404' => 'Cuba', 'Z405' => 'Repubblica Dominicana',
    'Z408' => 'El Salvador', 'Z410' => 'Guatemala', 'Z411' => 'Haiti', 'Z412' => 'Honduras',
    'Z413' => 'Giamaica', 'Z414' => 'Messico', 'Z415' => 'Nicaragua', 'Z416' => 'Panama',
    'Z418' => 'Trinidad e Tobago', 'Z420' => 'Stati Uniti d\'America', 'Z600' => 'Argentina',
    'Z602' => 'Bolivia', 'Z603' => 'Brasile', 'Z604' => 'Cile', 'Z605' => 'Colombia',
    'Z606' => 'Ecuador', 'Z608' => 'Guyana', 'Z609' => 'Paraguay', 'Z611' => 'Perù',
    'Z612' => 'Suriname', 'Z613' => 'Uruguay', 'Z614' => 'Venezuela', 'Z700' => 'Australia',
    'Z702' => 'Figi', 'Z703' => 'Kiribati', 'Z707' => 'Nauru', 'Z708' => 'Nuova Zelanda',
    'Z710' => 'Papua Nuova Guinea', 'Z715' => 'Tonga', 'Z718' => 'Vanuatu',
];
$php = "<?php\nreturn " . var_export($stati, true) . ";\n";
file_put_contents($outDir . '/codici_stati_esteri.php', $php);
echo "Scritto src/data/codici_stati_esteri.php (" . count($stati) . " stati)\n";
echo "DONE\n";
