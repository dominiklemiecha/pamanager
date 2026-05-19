<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::isEmployeeLoggedIn()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta']);
    exit;
}

Auth::requireEmployee();
$employee = Auth::getEmployee();
$callerType = 'employee';
$callerId   = (int) $employee['id'];
$callerName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
$departmentId = (int) ($employee['department_id'] ?? 0) ?: null;

$pageTitle = 'Calendario';
include dirname(__DIR__) . '/includes/header-employee.php';
include dirname(__DIR__) . '/includes/_calendar.inc.php';
include dirname(__DIR__) . '/includes/footer-employee.php';
