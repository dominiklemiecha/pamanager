<?php
/**
 * Test unitari LeaveBalance::effectiveHours — durata permesso a ore
 * al netto della sovrapposizione con la pausa pranzo aziendale.
 * Esecuzione: php tests/EffectiveHoursTest.php
 */

require_once __DIR__ . '/../src/classes/LeaveBalance.php';

$failures = 0;
$tests = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $tests;
    $tests++;
    if (abs($expected - $actual) < 0.001) {
        echo "  OK   $name\n";
    } else {
        $failures++;
        echo "  FAIL $name — atteso " . var_export($expected, true) . ", ottenuto " . var_export($actual, true) . "\n";
    }
}

echo "LeaveBalance::effectiveHours\n";

// Caso segnalato: ROL 12-18 con pausa 13-14 -> 5 ore
check('12-18 pausa 13-14', 5.0, LeaveBalance::effectiveHours('12:00:00', '18:00:00', '13:00:00', '14:00:00'));

// Nessuna pausa configurata -> durata piena
check('12-18 senza pausa', 6.0, LeaveBalance::effectiveHours('12:00:00', '18:00:00', null, null));

// Pausa configurata solo a metà (start senza end) -> ignorata
check('pausa incompleta ignorata', 6.0, LeaveBalance::effectiveHours('12:00:00', '18:00:00', '13:00:00', null));

// Permesso che non tocca la pausa
check('9-13 pausa 13-14', 4.0, LeaveBalance::effectiveHours('09:00:00', '13:00:00', '13:00:00', '14:00:00'));

// Sovrapposizione parziale: 12:30-13:30 con pausa 13-14 -> 1h - 0.5h = 0.5
check('sovrapposizione parziale', 0.5, LeaveBalance::effectiveHours('12:30:00', '13:30:00', '13:00:00', '14:00:00'));

// Permesso interamente dentro la pausa -> 0
check('permesso dentro la pausa', 0.0, LeaveBalance::effectiveHours('13:15:00', '13:45:00', '13:00:00', '14:00:00'));

// Permesso che copre tutta la giornata con pausa 13-14
check('8-20 pausa 13-14', 11.0, LeaveBalance::effectiveHours('08:00:00', '20:00:00', '13:00:00', '14:00:00'));

// Orari invalidi -> 0
check('end prima di start', 0.0, LeaveBalance::effectiveHours('18:00:00', '12:00:00', '13:00:00', '14:00:00'));

// Pausa da 30 minuti: 12-18 con pausa 13:00-13:30 -> 5.5
check('pausa 30 minuti', 5.5, LeaveBalance::effectiveHours('12:00:00', '18:00:00', '13:00:00', '13:30:00'));

echo "\n$tests test, $failures falliti\n";
exit($failures > 0 ? 1 : 0);
