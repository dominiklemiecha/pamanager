<?php
/**
 * Endpoint azioni Documenti Dipendente - Admin.
 * Gestisce upload, rename, toggle visibilita, scadenza, delete, download.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

function ed_redirect_back(int $employeeId, string $status = ''): void
{
    $url = 'employees.php?action=view&id=' . $employeeId . '#docs';
    if ($status !== '') {
        $url .= '&ed_status=' . urlencode($status);
    }
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = EmployeeDocument::download($docId);
    if (!$result['success']) {
        http_response_code(403);
        echo htmlspecialchars($result['error']);
        exit;
    }
    $doc = $result['document'];
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
    setDownloadHeaders($downloadName, $doc['mime_type'], filesize($result['file_path']));
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

CSRF::verifyOrDie();

$action = $_POST['action'] ?? '';
$employeeId = (int) ($_POST['employee_id'] ?? 0);

if (!$employeeId) {
    http_response_code(400);
    exit('employee_id mancante');
}

if ($action === 'upload') {
    if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
        ed_redirect_back($employeeId, 'no_file');
    }
    $result = EmployeeDocument::upload($_FILES['document'], [
        'employee_id' => $employeeId,
        'name' => $_POST['name'] ?? '',
        'visible_to_employee' => !empty($_POST['visible_to_employee']) ? 1 : 0,
        'expires_on' => $_POST['expires_on'] ?? null
    ]);
    ed_redirect_back($employeeId, $result['success'] ? 'uploaded' : 'error_' . $result['error']);
}

if ($action === 'rename' || $action === 'toggle_visibility' || $action === 'update_expiry') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (!$docId) { ed_redirect_back($employeeId, 'invalid'); }
    $payload = [];
    if ($action === 'rename') {
        $payload['name'] = $_POST['name'] ?? '';
    } elseif ($action === 'toggle_visibility') {
        $current = EmployeeDocument::getById($docId);
        if (!$current) { ed_redirect_back($employeeId, 'notfound'); }
        $payload['visible_to_employee'] = (int) $current['visible_to_employee'] === 1 ? 0 : 1;
    } elseif ($action === 'update_expiry') {
        $payload['expires_on'] = $_POST['expires_on'] ?? '';
    }
    $result = EmployeeDocument::update($docId, $payload);
    ed_redirect_back($employeeId, $result['success'] ? 'updated' : 'error_' . ($result['error'] ?? 'unknown'));
}

if ($action === 'delete') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (!$docId) { ed_redirect_back($employeeId, 'invalid'); }
    $result = EmployeeDocument::delete($docId);
    ed_redirect_back($employeeId, $result['success'] ? 'deleted' : 'error_' . ($result['error'] ?? 'unknown'));
}

ed_redirect_back($employeeId, 'unknown_action');
