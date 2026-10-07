<?php
/**
 * Academy - consegna file con controllo accessi.
 *
 *   ?m=<id>      materiale del corso. Dipendente: deve avere il corso assegnato,
 *                l'apertura viene registrata nel registro. Link esterni: registra
 *                il clic e fa redirect. Staff: anteprima senza registrazione.
 *   ?img=<nome>  immagine dei modelli attestato (solo staff, azienda corrente)
 *   ?cert=<id>   PDF dell'attestato emesso
 *
 * &dl=1 forza il download invece della visualizzazione nel browser.
 */

require_once dirname(__DIR__) . '/config/config.php';

Auth::init();

$user = Auth::getUser();
$employee = Auth::getEmployee();
if (!$user && !$employee) {
    http_response_code(401);
    exit('Autenticazione richiesta');
}

$staffRoles = ['admin', 'consulente_lavoro', 'admin_reparto', 'formatore'];
$isStaff = $user && in_array($user['role'], $staffRoles, true);
$forceDownload = !empty($_GET['dl']);

/**
 * Invia un file con supporto Range (necessario per avanzare nei video).
 * Ammette l'incorniciamento solo dalle pagine dello stesso dominio.
 */
function academy_send_file(string $path, string $mime, string $downloadName, bool $inline): void
{
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;

    while (ob_get_level()) { ob_end_clean(); }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // Niente sandbox/default-src: il visualizzatore PDF di Chrome si rifiuta di partire
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int) $m[1];
        if ($m[2] !== '') $end = min((int) $m[2], $size - 1);
        if ($m[1] === '' && $m[2] !== '') { // suffisso: ultimi N byte
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Length: ' . ($end - $start + 1));

    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int) min(1048576, $left));
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

// ---------------------------------------------------------------------
// Immagini dei modelli attestato
// ---------------------------------------------------------------------
if (isset($_GET['img'])) {
    if (!$user || !in_array($user['role'], ['admin', 'consulente_lavoro', 'formatore'], true)) {
        http_response_code(403);
        exit('Accesso non autorizzato');
    }
    $path = AcademyCertificate::imagePath((string) $_GET['img'], (int) Tenant::currentCompanyId());
    if (!$path) {
        http_response_code(404);
        exit('Immagine non trovata');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/png';
    academy_send_file($path, $mime, basename($path), true);
}

// ---------------------------------------------------------------------
// Attestati
// ---------------------------------------------------------------------
if (isset($_GET['cert'])) {
    $res = AcademyCertificate::authorizeDownload((int) $_GET['cert']);
    if (!$res['success']) {
        http_response_code(403);
        exit(htmlspecialchars($res['error']));
    }
    $c = $res['certificate'];
    academy_send_file($c['file_path'], 'application/pdf', AcademyCertificate::downloadName($c), !$forceDownload);
}

// ---------------------------------------------------------------------
// Materiali
// ---------------------------------------------------------------------
if (isset($_GET['m'])) {
    $material = Academy::getMaterial((int) $_GET['m']);
    if (!$material) {
        http_response_code(404);
        exit('Materiale non trovato');
    }

    if ($employee && !$user) {
        $assignment = Academy::getEmployeeAssignment((int) $employee['id'], (int) $material['course_id']);
        if (!$assignment) {
            AuditLog::logUnauthorizedAccess('academy_material', ['material_id' => (int) $material['id'], 'employee_id' => (int) $employee['id']]);
            http_response_code(403);
            exit('Accesso non autorizzato');
        }
        // Le richieste Range successive di un video non sono nuove aperture
        $isRangeFollowUp = isset($_SERVER['HTTP_RANGE']) && !preg_match('/bytes=0-/', $_SERVER['HTTP_RANGE']);
        if (!$isRangeFollowUp) {
            Academy::recordMaterialOpen($assignment, $material);
        }
    } elseif (!$isStaff) {
        http_response_code(403);
        exit('Accesso non autorizzato');
    }

    if ($material['kind'] === 'link') {
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $material['url'], true, 302);
        exit;
    }

    if (empty($material['file_path']) || !is_file($material['file_path'])) {
        http_response_code(404);
        exit('File non trovato sul server');
    }
    $mime = $material['mime_type'] ?: 'application/octet-stream';
    $inline = !$forceDownload && Academy::isInlineMime($mime);
    academy_send_file($material['file_path'], $mime, $material['original_name'] ?: basename($material['file_path']), $inline);
}

http_response_code(400);
echo 'Richiesta non valida';
