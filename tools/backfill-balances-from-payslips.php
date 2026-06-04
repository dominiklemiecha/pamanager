<?php
/**
 * Ricostruisce i saldi ferie/permessi leggendoli dall'ULTIMA busta paga
 * caricata nel sistema per ogni dipendente.
 *
 * USO (dal container app):
 *   php tools/backfill-balances-from-payslips.php                # dry-run (default)
 *   php tools/backfill-balances-from-payslips.php --apply        # scrive davvero
 *   php tools/backfill-balances-from-payslips.php --company-id=1
 *   php tools/backfill-balances-from-payslips.php --employee-id=8
 *
 * Per ogni dipendente:
 *   - prende il documento type='payslip' piu' recente (year DESC, month DESC)
 *   - estrae i residui ferie/permessi con PayslipParser
 *   - se OK e --apply, scrive snapshot via LeaveBalance::setSnapshotResidual
 *     con balance_set_at = OGGI (vedi feedback_leave_balance: snapshot=oggi
 *     evita rateo retroattivo).
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

echo "=== Backfill saldi da ultime buste paga ===\n";
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

$today = date('Y-m-d');
$year  = (int) date('Y');
$updates = 0; $skipped = 0; $missing = 0; $partial = 0;
$systemUserId = 0; // CLI: nessun utente loggato

printf("%-4s %-30s %-7s %-12s %-12s %-12s %s\n",
    'EID', 'Dipendente', 'Periodo', 'Ferie res.', 'Perm. res.', 'Esito', 'File');
echo str_repeat('-', 110) . "\n";

foreach ($latestPerEmployee as $eid => $r) {
    $name = trim($r['last_name'] . ' ' . $r['first_name']);
    $period = sprintf('%02d/%d', (int)$r['month'], (int)$r['year']);
    $file = $r['file_path'];

    if (!is_file($file)) {
        printf("%-4d %-30s %-7s %-12s %-12s %-12s %s\n",
            $eid, mb_strimwidth($name, 0, 29, ''), $period, '-', '-', 'NO FILE', basename($file));
        $missing++;
        continue;
    }

    try {
        $parsed = PayslipParser::parse($file);
    } catch (Throwable $e) {
        printf("%-4d %-30s %-7s %-12s %-12s %-12s %s\n",
            $eid, mb_strimwidth($name, 0, 29, ''), $period, '-', '-', 'PARSE FAIL', $e->getMessage());
        $skipped++;
        continue;
    }

    $fr = $parsed['balances']['ferie']['residuo']    ?? null;
    $pr = $parsed['balances']['permesso']['residuo'] ?? null;
    $frStr = $fr !== null ? number_format($fr, 2, ',', '') . ' gg' : '-';
    $prStr = $pr !== null ? number_format($pr, 2, ',', '') . ' h'  : '-';

    if ($fr === null && $pr === null) {
        printf("%-4d %-30s %-7s %-12s %-12s %-12s\n",
            $eid, mb_strimwidth($name, 0, 29, ''), $period, $frStr, $prStr, 'NO BALANCES');
        $skipped++;
        continue;
    }

    $esito = $apply ? 'APPLY' : 'WOULD APPLY';
    if ($fr === null || $pr === null) $esito = ($apply ? 'APPLY P' : 'WOULD APPLY P'); // partial
    if ($apply) {
        try {
            if ($fr !== null) {
                LeaveBalance::setSnapshotResidual($eid, (int)$r['company_id'], $year, 'ferie', (float)$fr, $today, $systemUserId);
            }
            if ($pr !== null) {
                LeaveBalance::setSnapshotResidual($eid, (int)$r['company_id'], $year, 'permesso', (float)$pr, $today, $systemUserId);
            }
            $updates++;
            if ($fr === null || $pr === null) $partial++;
        } catch (Throwable $e) {
            $esito = 'ERR: ' . $e->getMessage();
            $skipped++;
        }
    } else {
        $updates++;
        if ($fr === null || $pr === null) $partial++;
    }

    printf("%-4d %-30s %-7s %-12s %-12s %-12s %s\n",
        $eid, mb_strimwidth($name, 0, 29, ''), $period, $frStr, $prStr, $esito, basename($file));
}

echo str_repeat('-', 110) . "\n";
echo sprintf("Riepilogo: %d aggiornati (%d parziali), %d saltati, %d file mancanti.\n",
    $updates, $partial, $skipped, $missing);
if (!$apply) echo "\nDRY-RUN: nessuna scrittura. Rilancia con --apply per scrivere davvero.\n";
