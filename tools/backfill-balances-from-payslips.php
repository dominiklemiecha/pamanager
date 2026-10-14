<?php
/**
 * Allinea i saldi ferie/permessi all'ULTIMA busta paga caricata nel sistema
 * per ogni dipendente.
 *
 * USO (dal container app):
 *   php tools/backfill-balances-from-payslips.php                # dry-run (default)
 *   php tools/backfill-balances-from-payslips.php --apply        # scrive davvero
 *   php tools/backfill-balances-from-payslips.php --company-id=1
 *   php tools/backfill-balances-from-payslips.php --employee-id=8
 *
 * Per ogni dipendente:
 *   - prende il documento type='payslip' piu' recente (year DESC, month DESC)
 *   - legge maturato / goduto / residuo di ferie e permessi con PayslipParser
 *   - con --apply scrive via LeaveBalance::applyFromPayslip: utilizzati = goduto,
 *     totale = residuo + goduto, saldo dal 1 del mese successivo alla busta
 *     (le richieste approvate da quella data si sommano agli utilizzati).
 *
 * Sicurezza: di default e' DRY-RUN. Stampa la tabella di cio' che farebbe.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Esegui da CLI.\n"); exit(1); }
require_once __DIR__ . '/../config/config.php';

$argsList = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $argsList, true);
$companyId = null;
$employeeId = null;
foreach ($argsList as $a) {
    if (preg_match('/^--company-id=(\d+)$/', $a, $m)) $companyId = (int) $m[1];
    if (preg_match('/^--employee-id=(\d+)$/', $a, $m)) $employeeId = (int) $m[1];
}

echo "=== Allineamento saldi alle ultime buste paga ===\n";
echo $apply ? "Modalita': APPLY (scrittura DB)\n" : "Modalita': DRY-RUN (nessuna scrittura, usa --apply per scrivere)\n";
if ($companyId)  echo "Filtro company_id = $companyId\n";
if ($employeeId) echo "Filtro employee_id = $employeeId\n";
echo "\n";

// 1) Trova candidati: l'ultima busta payslip per dipendente
$sql = "SELECT d.employee_id, d.file_path, d.year, d.month, d.original_name,
               e.first_name, e.last_name, e.fiscal_code, e.company_id
        FROM documents d
        JOIN employees e ON e.id = d.employee_id
        WHERE d.type = 'payslip' AND e.is_active = TRUE";
$params = [];
if ($companyId)  { $sql .= " AND e.company_id = ?";  $params[] = $companyId; }
if ($employeeId) { $sql .= " AND d.employee_id = ?"; $params[] = $employeeId; }
$sql .= " ORDER BY d.employee_id, d.year DESC, d.month DESC";

$rows = Database::fetchAll($sql, $params);

// Tieni solo la riga piu' recente per employee
$latestPerEmployee = [];
foreach ($rows as $r) {
    $eid = (int) $r['employee_id'];
    if (!isset($latestPerEmployee[$eid])) $latestPerEmployee[$eid] = $r;
}

if (empty($latestPerEmployee)) {
    echo "Nessuna busta paga trovata nel filtro indicato.\n";
    exit;
}

$fmt = fn($v) => $v === null ? '-' : number_format((float) $v, 2, ',', '');
$cell = fn($b) => $b === null ? '-' : ($fmt($b['maturato_tot'] ?? null) . '/' . $fmt($b['goduto'] ?? null) . '/' . $fmt($b['residuo'] ?? null));
$updates = 0; $skipped = 0; $missing = 0;

printf("%-4s %-28s %-7s %-24s %-24s %s\n", 'EID', 'Dipendente', 'Busta', 'Ferie tot/god/res (gg)', 'Perm. tot/god/res (h)', 'Esito');
echo str_repeat('-', 110) . "\n";

foreach ($latestPerEmployee as $eid => $r) {
    $name = mb_strimwidth(trim($r['last_name'] . ' ' . $r['first_name']), 0, 27, '');
    $period = sprintf('%02d/%d', (int)$r['month'], (int)$r['year']);
    $file = $r['file_path'];

    if (!is_file($file)) {
        printf("%-4d %-28s %-7s %-24s %-24s %s\n", $eid, $name, $period, '-', '-', 'NO FILE ' . basename($file));
        $missing++;
        continue;
    }

    try {
        $parsed = PayslipParser::parse($file);
    } catch (Throwable $e) {
        printf("%-4d %-28s %-7s %-24s %-24s %s\n", $eid, $name, $period, '-', '-', 'PARSE FAIL: ' . $e->getMessage());
        $skipped++;
        continue;
    }

    $bal = $parsed['balances'] ?? ['ferie' => null, 'permesso' => null];
    foreach (['ferie', 'permesso'] as $t) {
        if (!empty($bal[$t]) && isset($bal[$t]['residuo'])) {
            $bal[$t]['maturato_tot'] = round((float)$bal[$t]['residuo'] + (float)($bal[$t]['goduto'] ?? 0), 2);
        }
    }
    if (!isset($bal['ferie']['residuo']) && !isset($bal['permesso']['residuo'])) {
        printf("%-4d %-28s %-7s %-24s %-24s %s\n", $eid, $name, $period, '-', '-', 'NESSUN SALDO NELLA BUSTA');
        $skipped++;
        continue;
    }

    // Il periodo della busta: quello letto dal PDF, altrimenti quello del documento
    $pYear  = (int)($parsed['period']['year']  ?? $r['year']);
    $pMonth = (int)($parsed['period']['month'] ?? $r['month']);

    $esito = $apply ? 'AGGIORNATO' : 'DA AGGIORNARE';
    if ($apply) {
        try {
            LeaveBalance::applyFromPayslip($eid, (int)$r['company_id'], $bal, $pYear, $pMonth, null);
        } catch (Throwable $e) {
            $esito = 'ERRORE: ' . $e->getMessage();
            $skipped++;
            printf("%-4d %-28s %-7s %-24s %-24s %s\n", $eid, $name, $period, $cell($bal['ferie']), $cell($bal['permesso']), $esito);
            continue;
        }
    }
    $updates++;
    printf("%-4d %-28s %-7s %-24s %-24s %s\n", $eid, $name, $period, $cell($bal['ferie']), $cell($bal['permesso']), $esito);
}

echo str_repeat('-', 110) . "\n";
echo sprintf("Riepilogo: %d %s, %d saltati, %d file mancanti.\n", $updates, $apply ? 'aggiornati' : 'da aggiornare', $skipped, $missing);
if (!$apply) echo "\nDRY-RUN: nessuna scrittura. Rilancia con --apply per scrivere davvero.\n";
