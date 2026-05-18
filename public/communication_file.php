<?php
/**
 * Stream allegato comunicazione (admin o dipendente autorizzato).
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::init();

$user = Auth::getUser();
$employee = method_exists('Auth', 'getEmployee') ? Auth::getEmployee() : null;
if (!$user && !$employee) {
    http_response_code(401);
    exit('Non autorizzato');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) { http_response_code(400); exit('id mancante'); }

$att = Communication::getAttachment($id);
if (!$att) { http_response_code(404); exit('Non trovato'); }

$comm = Communication::getById((int)$att['communication_id']);
if (!$comm) { http_response_code(404); exit('Non trovato'); }

// Dipendente: solo se la comunicazione è pubblicata e in finestra
if ($employee && !$user) {
    $today = date('Y-m-d');
    if (!$comm['is_published']
        || $comm['publish_date'] > $today
        || (!empty($comm['expire_date']) && $comm['expire_date'] < $today)) {
        http_response_code(403);
        exit('Non accessibile');
    }
}

$path = STORAGE_PATH . '/communications/' . $comm['id'] . '/' . $att['stored_name'];
if (!file_exists($path)) { http_response_code(404); exit('File mancante'); }

$mime = $att['mime_type'] ?: 'application/octet-stream';
$inline = !empty($_GET['inline']) || strpos($mime, 'image/') === 0;

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^a-zA-Z0-9._-]/','_', $att['original_name']) . '"');
header('Cache-Control: private, max-age=60');
readfile($path);
