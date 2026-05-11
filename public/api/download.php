<?php
/**
 * API Download Documenti
 * PAManager - Comune
 *
 * Gestisce download sicuro con:
 * - Verifica autenticazione
 * - Controllo accesso (admin/commercialista: tutti, dipendente: solo propri)
 * - Logging download
 * - Watermark PDF (opzionale)
 * - Rate limiting
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();

// Verifica autenticazione
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Autenticazione richiesta']);
    exit;
}

// Verifica ID documento
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ID documento non specificato']);
    exit;
}

// Opzione watermark (default: true per PDF)
$applyWatermark = !isset($_GET['nowatermark']);

// Richiedi download con controllo accesso
$result = Document::download($id, $applyWatermark);

if (!$result['success']) {
    $statusCode = 404;
    if ($result['error'] === 'Accesso non autorizzato') {
        $statusCode = 403;
    } elseif (strpos($result['error'], 'Rate limit') !== false || strpos($result['error'], 'Limite') !== false) {
        $statusCode = 429; // Too Many Requests
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['error' => $result['error']]);
    exit;
}

$document = $result['document'];

// Usa il file path corretto (potrebbe essere watermarked)
$filePath = $result['file_path'] ?? $document['file_path'];
$tempFile = $result['temp_file'] ?? null;

// Verifica esistenza file
if (!file_exists($filePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File non trovato sul server']);
    exit;
}

// Genera nome file per download
$downloadName = $document['original_name'] ?? $document['file_name'];

// Sanifica nome file
$downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);

// Imposta headers per download sicuro
setDownloadHeaders($downloadName, $document['mime_type'], filesize($filePath));

// Disabilita output buffering per file grandi
if (ob_get_level()) {
    ob_end_clean();
}

// Invia file
readfile($filePath);

// Pulisci file temporaneo watermark
if ($tempFile) {
    Document::cleanupTempFile($tempFile);
}

exit;
