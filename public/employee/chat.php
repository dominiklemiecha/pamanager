<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isEmployeeLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta. Ricarica la pagina.', 'session_expired' => true]);
    exit;
}

Auth::requireEmployee();

$employee     = Auth::getEmployee();
$user         = $employee;
$userType     = 'employee';
$userId       = (int) $employee['id'];
$departmentId = $employee['department_id'] ?? null;

require __DIR__ . '/../includes/_chat-page.inc.php';
