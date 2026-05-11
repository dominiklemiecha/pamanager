<?php
/**
 * API VAPID Public Key - Restituisce la chiave pubblica VAPID
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $publicKey = PushNotification::getPublicKey();
    echo json_encode(['success' => true, 'publicKey' => $publicKey]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
