<?php
/**
 * API SW Logs - Legge i log degli eventi del Service Worker
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');

// --- Guard: solo admin autenticato (endpoint di debug) ---
Auth::init();
$__u = Auth::getUser();
if (!$__u || ($__u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso riservato agli amministratori']);
    exit;
}

$logFile = STORAGE_PATH . '/logs/sw-push.log';

$logs = [];

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    if (!empty($content)) {
        $lines = explode("\n", trim($content));
        // Prendi le ultime 50 righe
        $logs = array_slice($lines, -50);
    }
}

echo json_encode([
    'success' => true,
    'count' => count($logs),
    'logs' => $logs,
    'file_exists' => file_exists($logFile),
]);
