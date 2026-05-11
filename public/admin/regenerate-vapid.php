<?php
/**
 * Rigenera Chiavi VAPID
 * PAManager - Comune
 *
 * Esegui questo script per rigenerare le chiavi VAPID
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Rigenera VAPID - PAManager</title>
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        .success { color: green; background: #e8f5e9; padding: 15px; border-radius: 4px; }
        .error { color: red; background: #ffebee; padding: 15px; border-radius: 4px; }
        .info { color: #1565c0; background: #e3f2fd; padding: 15px; border-radius: 4px; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
        .btn { display: inline-block; padding: 10px 20px; background: #1976d2; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
        .btn:hover { background: #1565c0; }
        .btn-danger { background: #d32f2f; }
    </style>
</head>
<body>
    <h1>Rigenera Chiavi VAPID</h1>

    <?php
    $keyFile = STORAGE_PATH . '/vapid_keys.json';

    if (isset($_POST['regenerate']) && $_POST['regenerate'] === 'yes') {
        echo '<h2>Rigenerazione in corso...</h2>';

        // 1. Elimina file esistente
        if (file_exists($keyFile)) {
            if (unlink($keyFile)) {
                echo '<p class="success">✓ File chiavi esistente eliminato</p>';
            } else {
                echo '<p class="error">✗ Impossibile eliminare il file: ' . $keyFile . '</p>';
                echo '<p>Verifica i permessi della cartella.</p>';
                exit;
            }
        } else {
            echo '<p class="info">ℹ File chiavi non esisteva</p>';
        }

        // 2. Resetta la cache statica
        $reflection = new ReflectionClass('PushNotification');
        $property = $reflection->getProperty('vapidKeys');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // 3. Genera nuove chiavi
        try {
            $newKeys = PushNotification::getVapidKeys();

            echo '<p class="success">✓ Nuove chiavi generate con successo!</p>';
            echo '<h3>Nuova chiave pubblica:</h3>';
            echo '<pre>' . htmlspecialchars($newKeys['public']) . '</pre>';

            echo '<h3>Verifica file salvato:</h3>';
            if (file_exists($keyFile)) {
                $saved = json_decode(file_get_contents($keyFile), true);
                echo '<pre>';
                echo 'public: ' . (isset($saved['public']) ? 'OK (' . strlen($saved['public']) . ' chars)' : 'MANCANTE') . "\n";
                echo 'private: ' . (isset($saved['private']) ? 'OK (' . strlen($saved['private']) . ' chars)' : 'MANCANTE') . "\n";
                echo 'private_pem: ' . (isset($saved['private_pem']) ? 'OK (' . strlen($saved['private_pem']) . ' chars)' : 'MANCANTE - IMPORTANTE!') . "\n";
                echo '</pre>';

                if (!isset($saved['private_pem'])) {
                    echo '<p class="error">⚠️ Il campo private_pem non è stato salvato. Potrebbe esserci un problema con la generazione.</p>';
                }
            } else {
                echo '<p class="error">✗ File non trovato dopo la generazione!</p>';
            }

            echo '<div class="info" style="margin-top: 20px;">';
            echo '<strong>⚠️ Importante:</strong><br>';
            echo 'Tutti i dispositivi dovranno ri-attivare le notifiche.<br>';
            echo 'Le vecchie sottoscrizioni nel database non funzioneranno più.';
            echo '</div>';

            echo '<p style="margin-top: 20px;"><a href="push-test.php" class="btn">Vai al Test Push</a></p>';

        } catch (Exception $e) {
            echo '<p class="error">✗ Errore generazione chiavi: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

    } else {
        // Mostra stato attuale
        echo '<h2>Stato Attuale</h2>';

        echo '<p><strong>File chiavi:</strong> ' . $keyFile . '</p>';

        if (file_exists($keyFile)) {
            echo '<p class="success">✓ File esistente</p>';

            $current = json_decode(file_get_contents($keyFile), true);
            echo '<pre>';
            echo 'public: ' . (isset($current['public']) ? substr($current['public'], 0, 30) . '...' : 'MANCANTE') . "\n";
            echo 'private: ' . (isset($current['private']) ? substr($current['private'], 0, 20) . '...' : 'MANCANTE') . "\n";
            echo 'private_pem: ' . (isset($current['private_pem']) ? 'PRESENTE (' . strlen($current['private_pem']) . ' chars)' : 'MANCANTE') . "\n";
            echo '</pre>';

            if (!isset($current['private_pem'])) {
                echo '<p class="error">⚠️ Il campo private_pem manca! Devi rigenerare le chiavi.</p>';
            }
        } else {
            echo '<p class="info">ℹ File non esiste ancora (verrà creato alla rigenerazione)</p>';
        }

        echo '<h2>Rigenera Chiavi</h2>';
        echo '<p class="error"><strong>Attenzione:</strong> Rigenerando le chiavi, tutti i dispositivi dovranno ri-sottoscriversi alle notifiche!</p>';

        echo '<form method="POST">';
        echo '<input type="hidden" name="regenerate" value="yes">';
        echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'Sei sicuro? Tutti i dispositivi dovranno ri-attivare le notifiche.\')">Rigenera Chiavi VAPID</button>';
        echo '</form>';
    }
    ?>

    <p style="margin-top: 30px;"><a href="push-debug.php">← Torna a Push Debug</a></p>
</body>
</html>
