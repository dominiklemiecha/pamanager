<?php
/**
 * Serve il PDF del contratto inline, accessibile solo al dipendente proprietario.
 * File separato per consentire l'embedding via iframe same-origin senza interferire
 * con le security headers globali (X-Frame-Options DENY / CSP frame-ancestors 'none').
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
// NON chiamare setSecurityHeaders(): il file deve essere framabile same-origin.
Auth::requireEmployee();

$emp = Auth::getEmployee();
$id = (int)($_GET['id'] ?? 0);

$hr = $id > 0 ? Database::fetchOne("SELECT * FROM hire_requests WHERE id = ?", [$id]) : null;
if (!$hr || (int)$hr['employee_id'] !== (int)$emp['id']) {
    http_response_code(404);
    exit('Non autorizzato');
}

$contract = Database::fetchOne(
    "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1",
    [$id]
);
if (!$contract) { http_response_code(404); exit('Contratto non disponibile'); }

$path = HireRequest::fileFsPath($contract);
if (!is_file($path)) { http_response_code(404); exit('File non trovato'); }

header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'");
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="contratto.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
