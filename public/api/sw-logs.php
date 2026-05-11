<?php
/**
 * API SW Logs - Legge i log degli eventi del Service Worker
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');

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
    'file_path' => $logFile
]);
