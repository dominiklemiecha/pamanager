<?php
/**
 * API Guida interattiva dipendente
 * POST { status: 'completed'|'skipped' }
 * Header: X-CSRF-Token
 * Scrive solo sulla riga del dipendente loggato e solo se la guida non era gia' conclusa.
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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!CSRF::validateToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') && !CSRF::validateToken($input['csrf_token'] ?? '')) {
    apiError('CSRF non valido', 403);
}

$status = $input['status'] ?? '';
if (!in_array($status, EmployeeTour::STATUSES, true)) {
    apiError('Stato non valido');
}

try {
    EmployeeTour::setStatus((int) $employee['id'], $status);
    apiResponse(['ok' => true]);
} catch (Throwable $e) {
    error_log('employee-tour.php: ' . $e->getMessage());
    apiError('Errore salvataggio stato guida', 500);
}
