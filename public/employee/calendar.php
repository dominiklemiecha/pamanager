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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require dirname(__DIR__) . '/includes/_calendar.inc.php';
    exit;
}

$pageTitle = 'Calendario';
if (CoraUi::enabled()) {
    // Vista Cora: stessa logica del calendario, aspetto della nuova shell
    $coraPage = true;
    $coraExtraCss = ['cora-calendar.css'];
}
include dirname(__DIR__) . '/includes/header-employee.php';
if (!empty($coraPage)) {
    echo CoraUi::topbar('Calendario', 'I tuoi impegni e gli inviti dei colleghi');
}
include dirname(__DIR__) . '/includes/_calendar.inc.php';
include dirname(__DIR__) . '/includes/footer-employee.php';
