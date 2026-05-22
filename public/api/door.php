<?php
/**
 * Endpoint apertura porta NFC chiamato dall'ESP32.
 *
 * Request:
 *   POST /api/door.php?c=<SLUG>
 *   Header: X-Door-Key: <chiave_porta>
 *   Body JSON: {"uid": "04AABBCCDDEE80"}
 *
 * Response:
 *   200 {"open": true, "employee": "Mario Rossi", "duration_ms": 3000}
 *   200 {"open": false, "reason": "unknown_uid"}
 *   401 {"open": false, "reason": "invalid_key"}
 *   404 {"open": false, "reason": "company_not_found"}
 *   503 {"open": false, "reason": "module_disabled"}
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function door_reply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function door_log(int $companyId, ?int $employeeId, string $uid, bool $granted, string $reason): void {
    Database::insert('door_access_log', [
        'company_id'  => $companyId,
        'employee_id' => $employeeId,
        'nfc_uid'     => $uid,
        'granted'     => $granted ? 1 : 0,
        'reason'      => $reason,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    door_reply(405, ['open' => false, 'reason' => 'method_not_allowed']);
}

$slug = trim($_GET['c'] ?? '');
if ($slug === '') {
    door_reply(400, ['open' => false, 'reason' => 'missing_company']);
}

$company = Database::fetchOne(
    "SELECT id, name, door_enabled, door_api_key, door_open_duration_ms FROM companies WHERE LOWER(slug) = ?",
    [mb_strtolower($slug)]
);
if (!$company) {
    door_reply(404, ['open' => false, 'reason' => 'company_not_found']);
}

$companyId = (int) $company['id'];

if ((int) $company['door_enabled'] !== 1) {
    door_reply(503, ['open' => false, 'reason' => 'module_disabled']);
}

$providedKey = $_SERVER['HTTP_X_DOOR_KEY'] ?? '';
if (empty($company['door_api_key']) || !hash_equals((string) $company['door_api_key'], $providedKey)) {
    door_reply(401, ['open' => false, 'reason' => 'invalid_key']);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$uid = is_array($body) ? strtoupper(trim((string) ($body['uid'] ?? ''))) : '';
$uid = preg_replace('/[^0-9A-F]/', '', $uid);

if ($uid === '' || strlen($uid) < 8 || strlen($uid) > 20) {
    door_reply(400, ['open' => false, 'reason' => 'invalid_uid']);
}

$employee = Database::fetchOne(
    "SELECT id, first_name, last_name, is_active FROM employees WHERE company_id = ? AND nfc_uid = ? LIMIT 1",
    [$companyId, $uid]
);

if (!$employee) {
    door_log($companyId, null, $uid, false, 'unknown_uid');
    door_reply(200, ['open' => false, 'reason' => 'unknown_uid']);
}

if ((int) $employee['is_active'] !== 1) {
    door_log($companyId, (int) $employee['id'], $uid, false, 'employee_inactive');
    door_reply(200, ['open' => false, 'reason' => 'employee_inactive']);
}

door_log($companyId, (int) $employee['id'], $uid, true, 'ok');

$duration = max(500, min(10000, (int) ($company['door_open_duration_ms'] ?: 3000)));

door_reply(200, [
    'open'        => true,
    'employee'    => trim($employee['first_name'] . ' ' . $employee['last_name']),
    'duration_ms' => $duration,
]);
