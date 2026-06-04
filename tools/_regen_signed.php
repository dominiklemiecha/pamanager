<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../vendor/autoload.php';

$hrId = (int)($argv[1] ?? 3);
$hr = Database::fetchOne("SELECT * FROM hire_requests WHERE id = ?", [$hrId]);
if (!$hr) die("HR $hrId non trovata\n");

// Dati reali dipendente: prima employee_id collegato, poi fallback ai campi della richiesta
$emp = !empty($hr['employee_id']) ? Database::fetchOne("SELECT first_name, last_name, fiscal_code FROM employees WHERE id = ?", [(int)$hr['employee_id']]) : null;
$signerName = $emp
    ? trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''))
    : trim(($hr['employee_first_name'] ?? '') . ' ' . ($hr['employee_last_name'] ?? ''));
$signerFc = $emp['fiscal_code'] ?? $hr['fiscal_code'];
echo "Firmatario: $signerName ($signerFc)\n";

$contract = Database::fetchOne("SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1", [$hrId]);
$sigRow = Database::fetchOne("SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'signature_image' ORDER BY id DESC LIMIT 1", [$hrId]);
if (!$contract || !$sigRow) die("File mancanti\n");

$base = defined('UPLOAD_PATH') ? UPLOAD_PATH : (dirname(__DIR__) . '/public/uploads');
$contractFs = $base . '/' . $contract['file_path'];
$sigFs = $base . '/' . $sigRow['file_path'];

echo "Contratto: $contractFs - " . (is_file($contractFs) ? 'OK' : 'MISSING') . PHP_EOL;
echo "Firma:     $sigFs - " . (is_file($sigFs) ? 'OK' : 'MISSING') . PHP_EOL;

try {
    $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->SetMargins(0, 0, 0);

    $pages = $pdf->setSourceFile($contractFs);
    echo "Pagine: $pages\n";
    for ($p = 1; $p <= $pages; $p++) {
        $tpl = $pdf->importPage($p);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
        if ($p === $pages) {
            $pageW = $size['width']; $pageH = $size['height'];
            $sigW = 65; $sigH = 22; $marginR = 12; $marginB = 14;
            $x = $pageW - $sigW - $marginR; $y = $pageH - $sigH - $marginB;
            $pdf->Image($sigFs, $x, $y, $sigW, $sigH, 'PNG');
            $pdf->SetTextColor(60,60,60);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetXY($x, $y + $sigH + 0.5);
            $pdf->Cell($sigW, 4, 'Firmato digitalmente da:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetX($x);
            $pdf->Cell($sigW, 4, $signerName, 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 6.5);
            $pdf->SetX($x);
            $pdf->Cell($sigW, 3, 'CF: ' . $signerFc, 0, 1, 'L');
        }
    }
    $outDir = $base . '/hire-requests/' . $hrId . '/signed_contract';
    if (!is_dir($outDir)) mkdir($outDir, 0775, true);
    $outPath = $outDir . '/contratto-firmato.pdf';
    $pdf->Output($outPath, 'F');
    echo "Scritto: $outPath - size=" . (file_exists($outPath) ? filesize($outPath) : 'NO FILE') . PHP_EOL;
} catch (Throwable $e) {
    echo "ERRORE: " . $e->getMessage() . "\n" . $e->getTraceAsString() . PHP_EOL;
}
