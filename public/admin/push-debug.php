<?php
/**
 * Debug Push Notifications
 * PAManager - Comune
 *
 * Pagina per diagnosticare e testare le notifiche push
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$baseUrl = PUBLIC_URL;
$message = '';
$messageType = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && CSRF::validate($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_push') {
        $userType = $_POST['user_type'] ?? '';
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userType && $userId) {
            $result = PushNotification::sendToUser($userType, $userId, [
                'title' => 'Test Notifica PAManager',
                'body' => 'Questa è una notifica di test inviata il ' . date('d/m/Y H:i:s'),
                'url' => '/',
                'tag' => 'test-' . time(),
                'icon' => '/assets/images/icon.php?size=192'
            ]);

            if ($result['sent'] > 0) {
                $message = 'Notifica inviata con successo a ' . $result['sent'] . ' dispositivo/i';
                $messageType = 'success';
            } else {
                $message = 'Nessuna notifica inviata. ' . ($result['message'] ?? '') .
                           (!empty($result['errors']) ? ' Errori: ' . implode(', ', $result['errors']) : '');
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete_subscription') {
        $subId = (int) ($_POST['sub_id'] ?? 0);
        if ($subId) {
            Database::delete('push_subscriptions', 'id = ?', [$subId]);
            $message = 'Sottoscrizione eliminata';
            $messageType = 'success';
        }
    } elseif ($action === 'clear_logs') {
        Database::execute("DELETE FROM push_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $message = 'Log vecchi eliminati';
        $messageType = 'success';
    } elseif ($action === 'regenerate_vapid') {
        $keyFile = STORAGE_PATH . '/vapid_keys.json';
        if (file_exists($keyFile)) {
            unlink($keyFile);
        }
        // Force regeneration
        PushNotification::getVapidKeys();
        $message = 'Chiavi VAPID rigenerate. I dispositivi dovranno ri-sottoscriversi.';
        $messageType = 'warning';
    }
}

// Carica sottoscrizioni
$subscriptions = Database::fetchAll(
    "SELECT ps.*,
            CASE
                WHEN ps.user_type = 'employee' THEN CONCAT(e.first_name, ' ', e.last_name)
                ELSE u.name
            END as user_name
     FROM push_subscriptions ps
     LEFT JOIN employees e ON ps.user_type = 'employee' AND ps.user_id = e.id
     LEFT JOIN users u ON ps.user_type IN ('admin', 'accountant', 'admin_reparto') AND ps.user_id = u.id
     ORDER BY ps.created_at DESC"
);

// Carica log recenti
$recentLogs = Database::fetchAll(
    "SELECT * FROM push_logs ORDER BY created_at DESC LIMIT 50"
);

// Statistiche
$stats = [
    'total' => count($subscriptions),
    'by_type' => []
];
foreach ($subscriptions as $sub) {
    $type = $sub['user_type'];
    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
}

// Verifica configurazione
$vapidKeys = PushNotification::getVapidKeys();
$isOpenSSLAvailable = PushNotification::isAvailable();
$isCurlAvailable = function_exists('curl_init');

// Carica utenti per test
$users = Database::fetchAll("SELECT id, name, role FROM users ORDER BY name");
$employees = Database::fetchAll("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM employees ORDER BY first_name");

$pageTitle = 'Debug Push Notifications';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="dashboard">
    <div class="alert alert-info" style="margin-bottom: 1rem;">
        <strong>Test Dettagliato:</strong>
        <a href="<?= $baseUrl ?>/admin/push-test.php" style="color: white; text-decoration: underline;">Vai alla pagina di test avanzato</a>
        per vedere log dettagliati e diagnosticare problemi.
    </div>
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 1rem;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Stato Sistema -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Stato Sistema Push Notifications</h3>
        </div>
        <div class="card-body">
            <table class="table" style="margin-bottom: 1rem;">
                <tr>
                    <td><strong>OpenSSL disponibile</strong></td>
                    <td>
                        <?php if ($isOpenSSLAvailable): ?>
                            <span style="color: green;">✓ Disponibile</span>
                        <?php else: ?>
                            <span style="color: red;">✗ Non disponibile</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>cURL disponibile</strong></td>
                    <td>
                        <?php if ($isCurlAvailable): ?>
                            <span style="color: green;">✓ Disponibile</span>
                        <?php else: ?>
                            <span style="color: red;">✗ Non disponibile</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Chiave VAPID pubblica</strong></td>
                    <td>
                        <code style="font-size: 0.7rem; word-break: break-all;"><?= htmlspecialchars($vapidKeys['public']) ?></code>
                    </td>
                </tr>
                <tr>
                    <td><strong>Sottoscrizioni totali</strong></td>
                    <td><?= $stats['total'] ?></td>
                </tr>
                <tr>
                    <td><strong>Per tipo utente</strong></td>
                    <td>
                        <?php foreach ($stats['by_type'] as $type => $count): ?>
                            <span style="margin-right: 1rem;"><?= htmlspecialchars($type) ?>: <?= $count ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($stats['by_type'])): ?>
                            <span style="color: #718096;">Nessuna sottoscrizione</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="alert alert-info">
                <strong>Requisiti iOS per Push Notifications:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem;">
                    <li>iOS 16.4 o superiore</li>
                    <li>L'app DEVE essere installata sulla Home (Add to Home Screen)</li>
                    <li>L'utente deve acconsentire alle notifiche quando richiesto</li>
                    <li>La PWA deve essere aperta almeno una volta dopo l'installazione</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Test Notifica -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Test Invio Notifica</h3>
        </div>
        <div class="card-body">
            <form method="POST" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="csrf_token" value="<?= CSRF::generate() ?>">
                <input type="hidden" name="action" value="test_push">

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="user_type">Tipo Utente</label>
                    <select name="user_type" id="user_type" class="form-control" required onchange="updateUserSelect()">
                        <option value="">-- Seleziona --</option>
                        <option value="admin">Admin</option>
                        <option value="accountant">Commercialista</option>
                        <option value="admin_reparto">Admin Reparto</option>
                        <option value="employee">Dipendente</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="user_id">Utente</label>
                    <select name="user_id" id="user_id" class="form-control" required>
                        <option value="">-- Seleziona tipo prima --</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Invia Test</button>
            </form>

            <script>
                const users = <?= json_encode($users) ?>;
                const employees = <?= json_encode($employees) ?>;

                function updateUserSelect() {
                    const type = document.getElementById('user_type').value;
                    const select = document.getElementById('user_id');
                    select.innerHTML = '<option value="">-- Seleziona --</option>';

                    if (type === 'employee') {
                        employees.forEach(e => {
                            const opt = document.createElement('option');
                            opt.value = e.id;
                            opt.textContent = e.name;
                            select.appendChild(opt);
                        });
                    } else if (type) {
                        users.filter(u => u.role === type).forEach(u => {
                            const opt = document.createElement('option');
                            opt.value = u.id;
                            opt.textContent = u.name;
                            select.appendChild(opt);
                        });
                    }
                }
            </script>
        </div>
    </div>

    <!-- Sottoscrizioni Attive -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Sottoscrizioni Attive (<?= count($subscriptions) ?>)</h3>
        </div>
        <div class="card-body">
            <?php if (empty($subscriptions)): ?>
                <p style="color: #718096;">Nessuna sottoscrizione push attiva.</p>
                <p><strong>Per attivare le notifiche:</strong></p>
                <ol>
                    <li>Accedi all'app da un dispositivo</li>
                    <li>Clicca su "Attiva Notifiche" nell'header</li>
                    <li>Accetta la richiesta del browser</li>
                </ol>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tipo</th>
                                <th>Utente</th>
                                <th>Endpoint</th>
                                <th>User Agent</th>
                                <th>Data</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptions as $sub): ?>
                                <tr>
                                    <td><?= $sub['id'] ?></td>
                                    <td>
                                        <span class="badge" style="background: var(--primary-color); color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                            <?= htmlspecialchars($sub['user_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($sub['user_name'] ?? 'N/A') ?></td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($sub['endpoint']) ?>">
                                        <?php
                                            $endpoint = $sub['endpoint'];
                                            if (strpos($endpoint, 'fcm.googleapis.com') !== false) {
                                                echo '<span title="Google Firebase">🔥 FCM (Android/Chrome)</span>';
                                            } elseif (strpos($endpoint, 'push.apple.com') !== false) {
                                                echo '<span title="Apple Push">🍎 APNs (iOS/Safari)</span>';
                                            } elseif (strpos($endpoint, 'mozilla.com') !== false) {
                                                echo '<span title="Mozilla">🦊 Mozilla</span>';
                                            } elseif (strpos($endpoint, 'windows.com') !== false) {
                                                echo '<span title="Windows">🪟 Windows</span>';
                                            } else {
                                                echo '<span title="' . htmlspecialchars($endpoint) . '">📱 Altro</span>';
                                            }
                                        ?>
                                    </td>
                                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.75rem;" title="<?= htmlspecialchars($sub['user_agent'] ?? '') ?>">
                                        <?php
                                            $ua = $sub['user_agent'] ?? '';
                                            if (strpos($ua, 'iPhone') !== false) echo '📱 iPhone';
                                            elseif (strpos($ua, 'iPad') !== false) echo '📱 iPad';
                                            elseif (strpos($ua, 'Android') !== false) echo '🤖 Android';
                                            elseif (strpos($ua, 'Windows') !== false) echo '🖥️ Windows';
                                            elseif (strpos($ua, 'Mac') !== false) echo '🖥️ Mac';
                                            else echo '📱 Altro';
                                        ?>
                                    </td>
                                    <td style="font-size: 0.8rem;"><?= date('d/m/Y H:i', strtotime($sub['created_at'])) ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= CSRF::generate() ?>">
                                            <input type="hidden" name="action" value="delete_subscription">
                                            <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Eliminare questa sottoscrizione?')">
                                                Elimina
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Log Recenti -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Log Invii Recenti</h3>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= CSRF::generate() ?>">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="btn btn-sm btn-secondary">Pulisci Log Vecchi</button>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($recentLogs)): ?>
                <p style="color: #718096;">Nessun log di invio registrato.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Stato</th>
                                <th>Subscription ID</th>
                                <th>Errore</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="font-size: 0.8rem;"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'sent'): ?>
                                            <span style="color: green;">✓ Inviato</span>
                                        <?php elseif ($log['status'] === 'failed'): ?>
                                            <span style="color: red;">✗ Fallito</span>
                                        <?php else: ?>
                                            <span style="color: orange;">⚠ Scaduto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $log['subscription_id'] ?? 'N/A' ?></td>
                                    <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8rem;" title="<?= htmlspecialchars($log['error_message'] ?? '') ?>">
                                        <?= htmlspecialchars($log['error_message'] ?? '-') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Azioni Avanzate -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Azioni Avanzate</h3>
        </div>
        <div class="card-body">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= CSRF::generate() ?>">
                <input type="hidden" name="action" value="regenerate_vapid">
                <button type="submit" class="btn btn-warning" onclick="return confirm('ATTENZIONE: Rigenerando le chiavi VAPID, tutti i dispositivi dovranno ri-sottoscriversi. Continuare?')">
                    Rigenera Chiavi VAPID
                </button>
            </form>
            <p style="margin-top: 0.5rem; font-size: 0.85rem; color: #718096;">
                <strong>Nota:</strong> Usa questa opzione solo se hai problemi con le chiavi VAPID.
                Tutti gli utenti dovranno ri-attivare le notifiche.
            </p>
        </div>
    </div>

    <!-- Troubleshooting -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>Troubleshooting</h3>
        </div>
        <div class="card-body">
            <h4>Le notifiche non arrivano su iPhone?</h4>
            <ol>
                <li><strong>Verifica iOS 16.4+:</strong> Le notifiche push per PWA sono disponibili solo da iOS 16.4</li>
                <li><strong>App installata:</strong> L'app DEVE essere aggiunta alla Home Screen (non basta visitare il sito)</li>
                <li><strong>Permessi:</strong> Vai in Impostazioni > Notifiche e verifica che PAManager abbia i permessi</li>
                <li><strong>Sottoscrizione attiva:</strong> Verifica nella tabella sopra che l'endpoint contenga "push.apple.com"</li>
                <li><strong>Riprova:</strong> Rimuovi l'app dalla Home, cancella la cache di Safari, reinstalla e riattiva le notifiche</li>
            </ol>

            <h4 style="margin-top: 1.5rem;">Le notifiche non arrivano su Android/Chrome?</h4>
            <ol>
                <li><strong>Permessi browser:</strong> Verifica che il sito abbia i permessi per le notifiche</li>
                <li><strong>Service Worker:</strong> Apri DevTools > Application > Service Workers e verifica che sia attivo</li>
                <li><strong>Sottoscrizione:</strong> Verifica che l'endpoint contenga "fcm.googleapis.com"</li>
            </ol>

            <h4 style="margin-top: 1.5rem;">Errori comuni nei log</h4>
            <ul>
                <li><strong>HTTP 401:</strong> Problema con le chiavi VAPID - prova a rigenerarle</li>
                <li><strong>HTTP 410:</strong> Sottoscrizione scaduta - viene rimossa automaticamente</li>
                <li><strong>HTTP 429:</strong> Troppi invii - attendi prima di riprovare</li>
                <li><strong>Errore crittografia:</strong> Problema con OpenSSL - verifica estensione PHP</li>
            </ul>
        </div>
    </div>
</div>

<style>
.dashboard-card .card-header h3 {
    margin: 0;
}
.dashboard-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}
</style>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
