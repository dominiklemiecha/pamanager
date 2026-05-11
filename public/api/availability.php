<?php
/**
 * API Stato disponibilita dipendente
 * POST { status: 'operative'|'in_call'|'in_meeting' }
 * Header: X-CSRF-Token
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metodo non consentito', 405);
}

Auth::init();

$employee = Auth::getEmployee();
if (!$employee) {
    apiError('Autenticazione richiesta', 401);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!CSRF::validateToken($csrfToken)) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
        apiError('CSRF non valido', 403);
    }
}

$input = $input ?? json_decode(file_get_contents('php://input'), true) ?: [];
$status = $input['status'] ?? '';

$allowed = ['operative', 'in_call', 'in_meeting'];
if (!in_array($status, $allowed, true)) {
    apiError('Stato non valido');
}

try {
    Database::update(
        'employees',
        ['availability_status' => $status, 'availability_set_at' => date('Y-m-d H:i:s')],
        'id = ?',
        [$employee['id']]
    );
    apiResponse(['ok' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('availability.php: ' . $e->getMessage());
    apiError('Errore aggiornamento stato', 500);
}
