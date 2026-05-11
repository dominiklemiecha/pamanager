<?php
/**
 * API Push Delayed - Invia push dopo un ritardo
 * Per testare le notifiche quando l'app è in background
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');

$delay = isset($_GET['delay']) ? (int)$_GET['delay'] : 10;
$delay = min(max($delay, 5), 60); // Min 5s, max 60s

error_log('[Push-Delayed] Attesa di ' . $delay . ' secondi prima di inviare...');

// Invia header per chiudere la connessione
if (function_exists('fastcgi_finish_request')) {
    echo json_encode(['success' => true, 'message' => 'Push verrà inviato tra ' . $delay . ' secondi']);
    fastcgi_finish_request();
} else {
    // Fallback per non-FPM
    ignore_user_abort(true);
    ob_start();
    echo json_encode(['success' => true, 'message' => 'Push verrà inviato tra ' . $delay . ' secondi']);
    $size = ob_get_length();
    header('Content-Length: ' . $size);
    header('Connection: close');
    ob_end_flush();
    flush();
}

// Attendi
sleep($delay);

// Trova l'ultima subscription
$subscription = Database::fetchOne(
    "SELECT * FROM push_subscriptions ORDER BY created_at DESC LIMIT 1"
);

if (!$subscription) {
    error_log('[Push-Delayed] Nessuna subscription trovata');
    exit;
}

error_log('[Push-Delayed] Invio push a subscription ID: ' . $subscription['id']);

$payload = [
    'title' => 'Test Background Push',
    'body' => 'Questo push è stato inviato ' . $delay . ' secondi fa - ' . date('H:i:s'),
    'url' => '/push-client-debug.php',
    'tag' => 'test-delayed-' . time(),
    'icon' => '/assets/images/icon.php?size=192'
];

// Invia il push
$reflection = new ReflectionClass('PushNotification');
$method = $reflection->getMethod('sendPushNotification');
$method->setAccessible(true);

$result = $method->invoke(null, $subscription, $payload);

error_log('[Push-Delayed] Risultato: ' . json_encode($result));
