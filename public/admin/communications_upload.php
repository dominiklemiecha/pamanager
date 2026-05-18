<?php
/**
 * Upload allegato / immagine inline per una comunicazione.
 * - Inline image: chiamato dall'editor (?type=image), risponde JSON {url}
 * - Allegato: chiamato dal form (?type=attachment), risponde JSON {success, id, url, name, size}
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
Auth::requireUser('admin');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

CSRF::verifyOrDie();

$commId = isset($_POST['communication_id']) ? (int) $_POST['communication_id'] : 0;
$type = $_POST['type'] ?? 'attachment';

if (!$commId) {
    echo json_encode(['success' => false, 'error' => 'Comunicazione non specificata']);
    exit;
}

$comm = Communication::getById($commId);
if (!$comm) {
    echo json_encode(['success' => false, 'error' => 'Comunicazione non trovata']);
    exit;
}

if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'File mancante']);
    exit;
}

$isInline = ($type === 'image');
$result = Communication::addUploadedFile($_FILES['file'], $commId, $isInline);

if (!$result['success']) {
    echo json_encode($result);
    exit;
}

$att = Communication::getAttachment((int)$result['id']);
echo json_encode([
    'success' => true,
    'id' => (int)$att['id'],
    'url' => Communication::attachmentUrl((int)$att['id']) . ($isInline ? '&inline=1' : ''),
    'name' => $att['original_name'],
    'size' => (int)$att['size_bytes'],
    'mime' => $att['mime_type'],
]);
