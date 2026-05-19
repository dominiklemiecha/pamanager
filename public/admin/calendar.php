<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isUserLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta. Ricarica la pagina.']);
    exit;
}

Auth::requireUser('admin');
$user = Auth::getUser();
$callerType = 'admin';
$callerId   = (int) $user['id'];
$callerName = $user['name'] ?? $user['username'];
$departmentId = null;

// POST: il partial gestisce e fa exit() — nessun output HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require dirname(__DIR__) . '/includes/_calendar.inc.php';
    exit;
}

$pageTitle = 'Calendario';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_calendar.inc.php';
include dirname(__DIR__) . '/includes/footer-admin.php';
