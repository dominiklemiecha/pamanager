<?php
/**
 * API notifiche dipendente: segna tutte come lette (apertura della campanella).
 * POST, header X-CSRF-Token
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

if (!CSRF::validateToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    apiError('CSRF non valido', 403);
}

Notification::markAllAsRead('employee', (int) $employee['id']);
apiResponse(['success' => true]);
