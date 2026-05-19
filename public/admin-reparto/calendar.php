<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isUserLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta']);
    exit;
}

Auth::requireUser('admin_reparto');
$user = Auth::getUser();
$callerType = 'admin_reparto';
$callerId   = (int) $user['id'];
$callerName = $user['name'] ?? $user['username'];
$departmentId = (int) ($user['department_id'] ?? 0) ?: null;

$pageTitle = 'Calendario';
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
include dirname(__DIR__) . '/includes/_calendar.inc.php';
include dirname(__DIR__) . '/includes/footer-admin.php';
