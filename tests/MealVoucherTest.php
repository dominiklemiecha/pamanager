<?php
/**
 * Test unitari MealVoucher (logica pura, nessun DB).
 * Esecuzione: php tests/MealVoucherTest.php
 *
 * Mese di riferimento: GIUGNO 2026 (30 giorni, 1° = lunedì).
 *   Lun: 1,8,15,22,29 · Mar: 2,9,16,23,30 · Mer: 3,10,17,24 · Gio: 4,11,18,25 · Ven: 5,12,19,26
 *   Festività: 2 giugno (Festa della Repubblica, martedì).
 *   Giorni lavorativi lun-ven non festivi = 22 - 1 = 21.
 */

require_once __DIR__ . '/../src/classes/ItalianHolidays.php';
require_once __DIR__ . '/../src/classes/MealVoucher.php';

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

function baseCfg(array $override = []): array
{
    return array_merge([
        'enabled'            => true,
        'excluded'           => false,
        'working_days'       => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'hours_per_day'      => 8.0,
        'smart_working_days' => [],
        'min_hours_enabled'  => true,
        'min_hours'          => 6.0,
        'sw_eligible'        => false,
    ], $override);
}

echo "MealVoucher::monthlyCount — giugno 2026\n";

// Mese pieno, nessuna assenza: 21 giorni lavorativi non festivi
check('mese pieno full-time', 21, MealVoucher::monthlyCount(baseCfg(), 2026, 6, []));

// Feature disattivata a livello azienda
check('feature disabilitata', 0, MealVoucher::monthlyCount(baseCfg(['enabled' => false]), 2026, 6, []));

// Dipendente escluso
check('dipendente escluso', 0, MealVoucher::monthlyCount(baseCfg(['excluded' => true]), 2026, 6, []));

// Part-time sotto soglia: 5h/giorno < 6h -> mai ticket
check('part-time sotto soglia', 0, MealVoucher::monthlyCount(baseCfg(['hours_per_day' => 5.0]), 2026, 6, []));

// Part-time con soglia personalizzata più bassa
check('part-time con soglia override 4h', 21, MealVoucher::monthlyCount(baseCfg(['hours_per_day' => 5.0, 'min_hours' => 4.0]), 2026, 6, []));

// Assenza a giornata intera (ferie) il 3 giugno
check('assenza giornata intera', 20, MealVoucher::monthlyCount(baseCfg(), 2026, 6, [
    '2026-06-03' => ['full' => true, 'hours' => 0.0],
]));

// Permesso a ore che porta sotto soglia: 3h il 4 giugno (8-3=5 < 6)
check('permesso a ore sotto soglia', 20, MealVoucher::monthlyCount(baseCfg(), 2026, 6, [
    '2026-06-04' => ['full' => false, 'hours' => 3.0],
]));

// Permesso a ore che resta sopra soglia: 1h il 5 giugno (8-1=7 >= 6)
check('permesso a ore sopra soglia', 21, MealVoucher::monthlyCount(baseCfg(), 2026, 6, [
    '2026-06-05' => ['full' => false, 'hours' => 1.0],
]));

// Smart working mar+gio, regola default (SW non dà ticket):
// mar non festivi 9,16,23,30 (4) + gio 4,11,18,25 (4) -> 21-8 = 13
check('SW non eleggibile', 13, MealVoucher::monthlyCount(baseCfg(['smart_working_days' => ['tue', 'thu']]), 2026, 6, []));

// Smart working mar+gio con regola "SW dà ticket"
check('SW eleggibile', 21, MealVoucher::monthlyCount(baseCfg(['smart_working_days' => ['tue', 'thu'], 'sw_eligible' => true]), 2026, 6, []));

// SW eleggibile ma permesso a ore sotto soglia in un giorno SW (mar 9: 8-4=4 < 6)
check('SW eleggibile con permesso sotto soglia', 20, MealVoucher::monthlyCount(baseCfg(['smart_working_days' => ['tue', 'thu'], 'sw_eligible' => true]), 2026, 6, [
    '2026-06-09' => ['full' => false, 'hours' => 4.0],
]));

// Sabato incluso nei giorni lavorativi: +4 sabati (6,13,20,27)
check('sabato lavorativo', 25, MealVoucher::monthlyCount(baseCfg(['working_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']]), 2026, 6, []));

// Festività su giorno lavorativo esclusa anche con assenze vuote (già coperto dal 21)
// Dicembre 2026: 25 (ven) e 26 (sab) festivi, 8 (mar) festivo -> lun-ven = 23 - 2 = 21
check('dicembre con festività', 21, MealVoucher::monthlyCount(baseCfg(), 2026, 12, []));

// Soglia disattivata (min_hours_enabled=false): part-time 3h prende comunque il ticket
check('soglia disattivata part-time', 21, MealVoucher::monthlyCount(baseCfg(['min_hours_enabled' => false, 'hours_per_day' => 3.0]), 2026, 6, []));

// Soglia disattivata: permesso a ore che copre TUTTA la giornata -> niente ticket (ore_eff = 0)
check('soglia disattivata giornata coperta da permesso', 20, MealVoucher::monthlyCount(baseCfg(['min_hours_enabled' => false]), 2026, 6, [
    '2026-06-10' => ['full' => false, 'hours' => 8.0],
]));

// Soglia disattivata: permesso a ore parziale -> ticket (ore_eff > 0)
check('soglia disattivata permesso parziale', 21, MealVoucher::monthlyCount(baseCfg(['min_hours_enabled' => false]), 2026, 6, [
    '2026-06-11' => ['full' => false, 'hours' => 7.5],
]));

// Soglia disattivata: assenza a giornata intera resta esclusa
check('soglia disattivata assenza intera', 20, MealVoucher::monthlyCount(baseCfg(['min_hours_enabled' => false]), 2026, 6, [
    '2026-06-12' => ['full' => true, 'hours' => 0.0],
]));

// Smart working OCCASIONALE (richiesta approvata, flag sw nel giorno):
// con regola "SW non dà ticket" il giorno è escluso
check('SW da richiesta, non eleggibile', 20, MealVoucher::monthlyCount(baseCfg(), 2026, 6, [
    '2026-06-15' => ['full' => false, 'hours' => 0.0, 'sw' => true],
]));

// Con regola "SW dà ticket" il giorno conta normalmente (giornata piena)
check('SW da richiesta, eleggibile', 21, MealVoucher::monthlyCount(baseCfg(['sw_eligible' => true]), 2026, 6, [
    '2026-06-15' => ['full' => false, 'hours' => 0.0, 'sw' => true],
]));

// SW da richiesta eleggibile + permesso a ore sotto soglia lo stesso giorno -> escluso dalla soglia
check('SW da richiesta eleggibile ma sotto soglia', 20, MealVoucher::monthlyCount(baseCfg(['sw_eligible' => true]), 2026, 6, [
    '2026-06-16' => ['full' => false, 'hours' => 4.0, 'sw' => true],
]));

echo "\n$tests test, $failures falliti\n";
exit($failures > 0 ? 1 : 0);
