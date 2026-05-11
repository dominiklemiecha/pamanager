<?php
/**
 * Test Push Notifications - Diagnostica dettagliata
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

header('Content-Type: text/html; charset=utf-8');

$baseUrl = PUBLIC_URL;

// Abilita error reporting per questa pagina
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Cattura i log in un buffer
$logBuffer = [];
$originalHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$logBuffer) {
    $logBuffer[] = "[PHP] $errstr";
    return false;
});

// Override error_log temporaneamente
function captureLog($message) {
    global $logBuffer;
    $logBuffer[] = $message;
    error_log($message); // Log anche su file
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Push - PAManager</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; background: #f5f5f5; }
        h1 { color: #1a365d; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; color: #2c5282; font-size: 1.2rem; }
        pre { background: #1a1a2e; color: #00ff00; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
        .success { color: #22c55e; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; }
        .btn { display: inline-block; padding: 10px 20px; background: #3182ce; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #2c5282; }
        .btn-danger { background: #e53e3e; }
        .endpoint { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; }
    </style>
</head>
<body>
    <h1>🔔 Test Push Notifications - Diagnostica</h1>

    <div class="card">
        <h2>1. Verifica Configurazione Sistema</h2>
        <?php
        $checks = [
            'OpenSSL disponibile' => extension_loaded('openssl'),
            'cURL disponibile' => extension_loaded('curl'),
            'PushNotification::isAvailable()' => PushNotification::isAvailable(),
        ];

        echo '<table>';
        foreach ($checks as $name => $ok) {
            $status = $ok ? '<span class="success">✓ OK</span>' : '<span class="error">✗ ERRORE</span>';
            echo "<tr><td>$name</td><td>$status</td></tr>";
        }
        echo '</table>';
        ?>
    </div>

    <div class="card">
        <h2>2. Chiavi VAPID</h2>
        <?php
        try {
            $vapidKeys = PushNotification::getVapidKeys();
            echo '<table>';
            echo '<tr><td><strong>Chiave Pubblica</strong></td><td style="word-break: break-all; font-size: 11px;">' . htmlspecialchars($vapidKeys['public']) . '</td></tr>';
            echo '<tr><td><strong>Lunghezza pubblica</strong></td><td>' . strlen(PushNotification::base64UrlDecode($vapidKeys['public'])) . ' bytes (deve essere 65)</td></tr>';
            echo '<tr><td><strong>Lunghezza privata</strong></td><td>' . strlen(PushNotification::base64UrlDecode($vapidKeys['private'])) . ' bytes (deve essere 32)</td></tr>';
            echo '</table>';
        } catch (Exception $e) {
            echo '<p class="error">Errore: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
    </div>

    <div class="card">
        <h2>3. Sottoscrizioni nel Database</h2>
        <?php
        $subscriptions = Database::fetchAll("SELECT * FROM push_subscriptions ORDER BY created_at DESC");

        if (empty($subscriptions)) {
            echo '<p class="warning">⚠️ Nessuna sottoscrizione trovata nel database.</p>';
            echo '<p>Per registrare una sottoscrizione:</p>';
            echo '<ol>';
            echo '<li>Vai su qualsiasi pagina dell\'app</li>';
            echo '<li>Clicca il pulsante "Attiva Notifiche"</li>';
            echo '<li>Accetta il permesso del browser</li>';
            echo '</ol>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Tipo</th><th>User ID</th><th>Endpoint</th><th>Created</th><th>Test</th></tr>';
            foreach ($subscriptions as $sub) {
                $endpointType = 'Altro';
                if (strpos($sub['endpoint'], 'apple') !== false) $endpointType = '🍎 Apple';
                elseif (strpos($sub['endpoint'], 'fcm') !== false) $endpointType = '🔥 FCM';
                elseif (strpos($sub['endpoint'], 'mozilla') !== false) $endpointType = '🦊 Mozilla';

                echo '<tr>';
                echo '<td>' . $sub['id'] . '</td>';
                echo '<td>' . htmlspecialchars($sub['user_type']) . '</td>';
                echo '<td>' . $sub['user_id'] . '</td>';
                echo '<td class="endpoint" title="' . htmlspecialchars($sub['endpoint']) . '">' . $endpointType . '</td>';
                echo '<td>' . date('d/m H:i', strtotime($sub['created_at'])) . '</td>';
                echo '<td><a href="?test_sub=' . $sub['id'] . '" class="btn">Test</a></td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        ?>
    </div>

    <?php
    // Esegui test se richiesto
    if (isset($_GET['test_sub'])) {
        $subId = (int) $_GET['test_sub'];
        $sub = Database::fetchOne("SELECT * FROM push_subscriptions WHERE id = ?", [$subId]);

        if ($sub) {
            echo '<div class="card">';
            echo '<h2>4. Risultato Test per Sottoscrizione #' . $subId . '</h2>';

            // Cattura output di error_log
            ob_start();

            $payload = [
                'title' => 'Test PAManager',
                'body' => 'Notifica di test inviata il ' . date('d/m/Y H:i:s'),
                'url' => '/',
                'tag' => 'test-' . time(),
                'icon' => '/assets/images/icon.php?size=192'
            ];

            echo '<h3>Payload inviato:</h3>';
            echo '<pre>' . json_encode($payload, JSON_PRETTY_PRINT) . '</pre>';

            echo '<h3>Dettagli sottoscrizione:</h3>';
            echo '<pre>';
            echo "Endpoint: " . $sub['endpoint'] . "\n";
            echo "p256dh length: " . strlen($sub['p256dh']) . " chars\n";
            echo "auth length: " . strlen($sub['auth']) . " chars\n";
            echo '</pre>';

            // Invia la notifica
            echo '<h3>Invio in corso...</h3>';

            $startTime = microtime(true);

            // Usa reflection per chiamare il metodo privato con logging
            $result = PushNotification::sendToUser($sub['user_type'], $sub['user_id'], $payload);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            echo '<h3>Risultato (tempo: ' . $elapsed . 'ms):</h3>';
            echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT) . '</pre>';

            if ($result['sent'] > 0) {
                echo '<p class="success">✓ Notifica inviata con successo al push service!</p>';
                echo '<p><strong>Se non arriva sul dispositivo:</strong></p>';
                echo '<ul>';
                echo '<li>Verifica che il dispositivo sia connesso a internet</li>';
                echo '<li>Su iOS: verifica nelle Impostazioni > Notifiche che l\'app abbia i permessi</li>';
                echo '<li>Prova a chiudere e riaprire l\'app</li>';
                echo '</ul>';
            } else {
                echo '<p class="error">✗ Notifica non inviata</p>';
                if (!empty($result['errors'])) {
                    echo '<h3>Errori:</h3>';
                    echo '<pre class="error">' . implode("\n", $result['errors']) . '</pre>';
                }
            }

            // Mostra log dal database
            $logs = Database::fetchAll(
                "SELECT * FROM push_logs WHERE subscription_id = ? ORDER BY created_at DESC LIMIT 10",
                [$subId]
            );

            if (!empty($logs)) {
                echo '<h3>Log recenti per questa sottoscrizione:</h3>';
                echo '<table>';
                echo '<tr><th>Data</th><th>Stato</th><th>Errore</th></tr>';
                foreach ($logs as $log) {
                    $statusClass = $log['status'] === 'sent' ? 'success' : 'error';
                    echo '<tr>';
                    echo '<td>' . date('d/m H:i:s', strtotime($log['created_at'])) . '</td>';
                    echo '<td class="' . $statusClass . '">' . $log['status'] . '</td>';
                    echo '<td>' . htmlspecialchars($log['error_message'] ?? '-') . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }

            echo '</div>';
        }
    }
    ?>

    <div class="card">
        <h2>5. Log di Sistema (ultimi)</h2>
        <p>Controlla il file di log PHP del server per vedere i messaggi dettagliati [Push]</p>
        <?php
        // Prova a leggere gli ultimi log
        $logFile = ini_get('error_log');
        if ($logFile && file_exists($logFile) && is_readable($logFile)) {
            $lines = file($logFile);
            $pushLogs = array_filter($lines, fn($l) => strpos($l, '[Push]') !== false);
            $pushLogs = array_slice($pushLogs, -30);

            if (!empty($pushLogs)) {
                echo '<pre>' . htmlspecialchars(implode('', $pushLogs)) . '</pre>';
            } else {
                echo '<p class="warning">Nessun log [Push] trovato nel file di log</p>';
            }
        } else {
            echo '<p>File log: ' . ($logFile ?: 'non configurato') . '</p>';
            echo '<p class="warning">Non è possibile leggere il file di log. Controlla manualmente.</p>';
        }
        ?>
    </div>

    <div class="card">
        <h2>6. Troubleshooting</h2>
        <h3>Errori comuni:</h3>
        <ul>
            <li><strong>HTTP 400</strong> - Payload malformato o headers non validi</li>
            <li><strong>HTTP 401</strong> - Errore autenticazione VAPID (chiavi non valide o JWT malformato)</li>
            <li><strong>HTTP 403</strong> - Chiave VAPID non corrisponde alla sottoscrizione</li>
            <li><strong>HTTP 404</strong> - Endpoint non trovato (sottoscrizione non valida)</li>
            <li><strong>HTTP 410</strong> - Sottoscrizione scaduta o revocata dall'utente</li>
            <li><strong>HTTP 413</strong> - Payload troppo grande</li>
            <li><strong>HTTP 429</strong> - Rate limit superato</li>
        </ul>

        <h3>Per iOS (Safari/PWA):</h3>
        <ul>
            <li>Richiede iOS 16.4 o superiore</li>
            <li>L'app DEVE essere installata sulla Home Screen</li>
            <li>L'app DEVE essere aperta dalla Home (non da Safari)</li>
            <li>L'utente deve aver accettato le notifiche</li>
            <li>Verificare: Impostazioni > Notifiche > [Nome App]</li>
        </ul>
    </div>

    <p><a href="<?= $baseUrl ?>/admin/push-debug.php">← Torna a Push Debug</a></p>
</body>
</html>
