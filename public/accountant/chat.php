<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

// Sessione scaduta su AJAX -> JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isUserLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta. Ricarica la pagina.', 'session_expired' => true]);
    exit;
}

Auth::requireUser('accountant');

$user     = Auth::getUser();
$userType = 'accountant';
$userId   = (int) $user['id'];

require __DIR__ . '/../includes/_chat-page.inc.php';
