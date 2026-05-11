<?php
/**
 * API Push Notifications
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/index.php';

// GET - ottieni chiave pubblica VAPID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'public_key';

    switch ($action) {
        case 'public_key':
            try {
                $publicKey = PushNotification::getPublicKey();
                apiResponse(['publicKey' => $publicKey]);
            } catch (Exception $e) {
                error_log('Push API error: ' . $e->getMessage());
                apiError('Errore nel recupero della chiave pubblica');
            }
            break;

        default:
            apiError('Azione non valida');
    }
    exit;
}

// POST - gestione sottoscrizioni
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metodo non consentito', 405);
}

// Ottieni dati JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    apiError('Dati JSON non validi');
}

$action = $input['action'] ?? 'subscribe';

switch ($action) {
    case 'subscribe':
        // Registra una nuova sottoscrizione push
        Auth::init();
        $user = Auth::getUser();

        if (!$user) {
            apiError('Autenticazione richiesta', 401);
        }

        $subscription = $input['subscription'] ?? null;

        if (!$subscription) {
            apiError('Dati sottoscrizione mancanti');
        }

        $result = PushNotification::saveSubscription(
            $user['role'],
            $user['id'],
            $subscription
        );

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Sottoscrizione salvata']);
        } else {
            apiError($result['error']);
        }
        break;

    case 'subscribe_employee':
        // Registra sottoscrizione per dipendente
        Auth::init();
        $employee = Auth::getEmployee();

        if (!$employee) {
            apiError('Autenticazione richiesta', 401);
        }

        $subscription = $input['subscription'] ?? null;

        if (!$subscription) {
            apiError('Dati sottoscrizione mancanti');
        }

        $result = PushNotification::saveSubscription(
            'employee',
            $employee['id'],
            $subscription
        );

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Sottoscrizione salvata']);
        } else {
            apiError($result['error']);
        }
        break;

    case 'unsubscribe':
        // Rimuovi sottoscrizione
        $endpoint = $input['endpoint'] ?? null;

        if (!$endpoint) {
            apiError('Endpoint mancante');
        }

        $result = PushNotification::removeSubscription($endpoint);

        if ($result['success']) {
            apiResponse(['success' => true, 'message' => 'Sottoscrizione rimossa']);
        } else {
            apiError($result['error']);
        }
        break;

    case 'test':
        // Invia notifica di test (solo per utenti autenticati)
        Auth::init();
        $user = Auth::getUser();
        $employee = Auth::getEmployee();

        if (!$user && !$employee) {
            apiError('Autenticazione richiesta', 401);
        }

        $userType = $user ? $user['role'] : 'employee';
        $userId = $user ? $user['id'] : $employee['id'];

        $result = PushNotification::sendToUser($userType, $userId, [
            'title' => 'Test Notifica',
            'body' => 'Le notifiche push funzionano correttamente!',
            'url' => '/',
            'tag' => 'test-' . time()
        ]);

        apiResponse($result);
        break;

    default:
        apiError('Azione non valida');
}
