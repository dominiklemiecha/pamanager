<?php
/**
 * Push Log - endpoint diagnostico chiamato dal Service Worker.
 *
 * Requisiti di sicurezza:
 * - solo sessione autenticata (utente o dipendente): niente log da terzi
 * - nessun CORS wildcard: e' un endpoint same-origin
 * - input troncato e log a rotazione, per non riempire il disco
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

Auth::init();
$isAuthenticated = Auth::getUser() !== null || Auth::getEmployee() !== null;

if (!$isAuthenticated) {
    // Nessun errore rumoroso verso il Service Worker: semplicemente non si logga.
    http_response_code(204);
    exit;
}

$clean = static function (string $value, int $max = 200): string {
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return substr(trim($value), 0, $max);
};

$event     = $clean((string) ($_GET['event'] ?? 'unknown'), 60);
$payload   = $clean((string) ($_GET['payload'] ?? ''));
$error     = $clean((string) ($_GET['error'] ?? ''));
$raw       = $clean((string) ($_GET['raw'] ?? ''));
$timestamp = $clean((string) ($_GET['timestamp'] ?? ''), 40);

$logMessage = '[SW-Event] ' . $event;
if ($payload)   $logMessage .= ' | payload: ' . $payload;
if ($raw)       $logMessage .= ' | raw: ' . $raw;
if ($error)     $logMessage .= ' | error: ' . $error;
if ($timestamp) $logMessage .= ' | sw_time: ' . $timestamp;

error_log($logMessage);

// Salva anche in un file dedicato per facile accesso (letto da /api/sw-logs.php, admin-only)
$logFile = STORAGE_PATH . '/logs/sw-push.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Rotazione semplice: oltre 256KB si riparte da zero
if (is_file($logFile) && filesize($logFile) > 256 * 1024) {
    @unlink($logFile);
}

$logLine = date('Y-m-d H:i:s') . ' | ' . $event;
if ($payload)   $logLine .= ' | payload: ' . $payload;
if ($raw)       $logLine .= ' | raw: ' . $raw;
if ($error)     $logLine .= ' | ERROR: ' . $error;
if ($timestamp) $logLine .= ' | sw_time: ' . $timestamp;
$logLine .= "\n";

@file_put_contents($logFile, $logLine, FILE_APPEND);

echo json_encode(['logged' => true, 'event' => $event]);
