<?php
/**
 * Push Log - Debug endpoint
 * Logga eventi dal Service Worker per debug push notifications
 */

// Permetti CORS per chiamate dal SW
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$event = $_GET['event'] ?? 'unknown';
$payload = $_GET['payload'] ?? '';
$error = $_GET['error'] ?? '';
$raw = $_GET['raw'] ?? '';
$time = $_GET['time'] ?? time();
$timestamp = $_GET['timestamp'] ?? '';

$logMessage = '[SW-Event] ' . $event;
if ($payload) {
    $logMessage .= ' | payload: ' . substr($payload, 0, 200);
}
if ($raw) {
    $logMessage .= ' | raw: ' . substr($raw, 0, 200);
}
if ($error) {
    $logMessage .= ' | error: ' . $error;
}
if ($timestamp) {
    $logMessage .= ' | sw_time: ' . $timestamp;
}

error_log($logMessage);

// Salva anche in un file dedicato per facile accesso
$logFile = dirname(__DIR__, 2) . '/storage/logs/sw-push.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$logLine = date('Y-m-d H:i:s') . ' | ' . $event;
if ($payload) $logLine .= ' | payload: ' . $payload;
if ($raw) $logLine .= ' | raw: ' . $raw;
if ($error) $logLine .= ' | ERROR: ' . $error;
if ($timestamp) $logLine .= ' | sw_time: ' . $timestamp;
$logLine .= "\n";

@file_put_contents($logFile, $logLine, FILE_APPEND);

echo json_encode(['logged' => true, 'event' => $event]);
