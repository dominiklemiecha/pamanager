<?php
/**
 * API Push Test Send - Invia push di test all'utente corrente
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json');

try {
    // Ottieni l'utente corrente (se loggato)
    $user = Auth::getUser();
    $employee = Auth::getEmployee();

    $userType = null;
    $userId = null;

    if ($user) {
        $userType = $user['role'];
        $userId = $user['id'];
    } elseif ($employee) {
        $userType = 'employee';
        $userId = $employee['id'];
    }

    // Se non loggato, cerca subscription basandosi su IP/User-Agent recente
    $subscription = null;

    if ($userType && $userId) {
        // Utente loggato: cerca le sue subscription
        $subscriptions = PushNotification::getUserSubscriptions($userType, $userId);
        if (!empty($subscriptions)) {
            $subscription = $subscriptions[0];
        }
    }

    // Se ancora nessuna subscription, cerca l'ultima registrata
    if (!$subscription) {
        $subscription = Database::fetchOne(
            "SELECT * FROM push_subscriptions ORDER BY created_at DESC LIMIT 1"
        );
    }

    if (!$subscription) {
        echo json_encode([
            'success' => false,
            'error' => 'Nessuna subscription trovata. Assicurati di essere sottoscritto alle notifiche push.'
        ]);
        exit;
    }

    error_log('[Push-Test-API] Invio push di test a subscription ID: ' . $subscription['id']);
    error_log('[Push-Test-API] Endpoint: ' . substr($subscription['endpoint'], 0, 80) . '...');

    // Prepara il payload
    $payload = [
        'title' => 'Test Push PAManager',
        'body' => 'Push inviato alle ' . date('H:i:s') . ' - Se vedi questo, funziona!',
        'url' => '/push-client-debug.php',
        'tag' => 'test-real-' . time(),
        'icon' => '/assets/images/icon.php?size=192'
    ];

    // Invia il push direttamente usando la classe
    $reflection = new ReflectionClass('PushNotification');
    $method = $reflection->getMethod('sendPushNotification');
    $method->setAccessible(true);

    $result = $method->invoke(null, $subscription, $payload);

    error_log('[Push-Test-API] Risultato: ' . json_encode($result));

    echo json_encode([
        'success' => $result['success'],
        'sent' => $result['success'] ? 1 : 0,
        'failed' => $result['success'] ? 0 : 1,
        'error' => $result['error'] ?? null,
        'details' => [
            'subscription_id' => $subscription['id'],
            'user_type' => $subscription['user_type'],
            'endpoint_type' => strpos($subscription['endpoint'], 'apple') !== false ? 'Apple' :
                              (strpos($subscription['endpoint'], 'fcm') !== false ? 'FCM' : 'Other')
        ]
    ]);

} catch (Exception $e) {
    error_log('[Push-Test-API] Errore: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
