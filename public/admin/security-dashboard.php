<?php
/**
 * Dashboard Sicurezza - Pannello Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();

// Ottieni dati dashboard sicurezza con gestione errori
try {
    $dashboard = SecurityMonitor::getDashboard();
} catch (Throwable $e) {
    $dashboard = [
        'summary' => [
            'unresolved_alerts' => 0,
            'failed_logins_24h' => 0,
            'successful_logins_24h' => 0,
            'downloads_today' => 0,
            'active_users' => 0,
            'critical_events_24h' => 0
        ],
        'recent_alerts' => [],
        'login_stats' => [],
        'threat_level' => ['level' => 'unknown', 'score' => 0, 'color' => '#6c757d', 'factors' => ['Errore database']],
        'active_sessions' => 0,
        'recent_critical_events' => []
    ];
}

try {
    $gdprCompliance = GDPR::checkCompliance();
} catch (Throwable $e) {
    $gdprCompliance = [
        'status' => ['status' => 'error', 'message' => 'Verifica non disponibile']
    ];
}

// Azioni POST
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'resolve_alert':
                $alertId = (int) ($_POST['alert_id'] ?? 0);
                $notes = $_POST['notes'] ?? '';
                if (SecurityMonitor::resolveAlert($alertId, $user['id'], $notes)) {
                    $message = 'Alert risolto con successo';
                    $messageType = 'success';
                } else {
                    $message = 'Errore nella risoluzione dell\'alert';
                    $messageType = 'error';
                }
                break;

            case 'run_security_scan':
                $scanResult = SecurityMonitor::runSecurityScan();
                $message = "Scansione completata. Anomalie: " . count($scanResult['anomalies'] ?? []);
                $messageType = 'success';
                break;

            case 'test_password':
                $testPassword = $_POST['test_password'] ?? '';
                $validation = Auth::validatePassword($testPassword);
                if ($validation['valid']) {
                    $message = "Password valida!";
                    $messageType = 'success';
                } else {
                    $message = "Password non valida: " . implode(', ', $validation['errors']);
                    $messageType = 'error';
                }
                break;
        }
    } catch (Throwable $e) {
        $message = "Errore: " . $e->getMessage();
        $messageType = 'error';
    }

    // Ricarica dati
    try {
        $dashboard = SecurityMonitor::getDashboard();
    } catch (Throwable $e) {}
}

// IP sospetti
try {
    $suspiciousIPs = SecurityMonitor::getSuspiciousIPs(24, 10);
} catch (Throwable $e) {
    $suspiciousIPs = [];
}

$pageTitle = 'Dashboard Sicurezza';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
    .security-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .sec-card { background: white; border-radius: 10px; padding: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .sec-card.danger { border-left: 4px solid #ef4444; }
    .sec-card.warning { border-left: 4px solid #f59e0b; }
    .sec-card.success { border-left: 4px solid #10b981; }
    .sec-card.info { border-left: 4px solid #3b82f6; }
    .sec-value { font-size: 1.75rem; font-weight: 700; color: #1a365d; }
    .sec-label { font-size: 0.8rem; color: #64748b; margin-top: 0.25rem; }

    .threat-banner { padding: 1rem 1.5rem; border-radius: 10px; color: white; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
    .threat-banner h3 { margin: 0; font-size: 1.1rem; }
    .threat-banner .factors { font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem; }

    .section-card { background: white; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .section-card h2 { margin: 0 0 1rem 0; font-size: 1.1rem; color: #1a365d; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }

    .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 900px) { .two-cols { grid-template-columns: 1fr; } }

    .alert-item { padding: 1rem; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.75rem; }
    .alert-item.unresolved { border-left: 4px solid #ef4444; }
    .alert-item.resolved { border-left: 4px solid #10b981; opacity: 0.7; }
    .alert-header { display: flex; justify-content: space-between; font-size: 0.9rem; }
    .alert-type { font-weight: 600; color: #1a365d; }
    .alert-time { color: #64748b; font-size: 0.8rem; }

    .compliance-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 8px; margin-bottom: 0.5rem; }
    .compliance-item.ok { background: #ecfdf5; }
    .compliance-item.warning { background: #fffbeb; }
    .compliance-item.error { background: #fef2f2; }
    .compliance-icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
    .compliance-item.ok .compliance-icon { background: #10b981; color: white; }
    .compliance-item.warning .compliance-icon { background: #f59e0b; color: white; }
    .compliance-item.error .compliance-icon { background: #ef4444; color: white; }

    .test-box { background: #f8fafc; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
    .test-box h4 { margin: 0 0 0.5rem 0; font-size: 0.95rem; color: #475569; }
    .test-box p { font-size: 0.85rem; color: #64748b; margin: 0 0 0.75rem 0; }
    .test-form { display: flex; gap: 0.5rem; }
    .test-form input { flex: 1; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; }

    .msg { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .msg.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .msg.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    .ip-table { width: 100%; font-size: 0.9rem; }
    .ip-table th, .ip-table td { padding: 0.6rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .ip-table th { background: #f8fafc; font-weight: 600; color: #475569; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 500; }
    .badge-danger { background: #fef2f2; color: #991b1b; }
    .badge-success { background: #ecfdf5; color: #065f46; }
</style>

<?php if ($message): ?>
    <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Threat Level Banner -->
<div class="threat-banner" style="background: <?= $dashboard['threat_level']['color'] ?>">
    <div>
        <h3>Livello Minaccia: <?= strtoupper($dashboard['threat_level']['level']) ?> (Score: <?= $dashboard['threat_level']['score'] ?>)</h3>
        <?php if (!empty($dashboard['threat_level']['factors'])): ?>
            <div class="factors"><?= implode(' | ', $dashboard['threat_level']['factors']) ?></div>
        <?php else: ?>
            <div class="factors">Sistema sicuro - nessuna anomalia</div>
        <?php endif; ?>
    </div>
    <form method="POST" style="margin: 0;">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="run_security_scan">
        <button type="submit" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">Scansione</button>
    </form>
</div>

<!-- Stats Grid -->
<div class="security-grid">
    <div class="sec-card <?= $dashboard['summary']['unresolved_alerts'] > 0 ? 'danger' : 'success' ?>">
        <div class="sec-value"><?= $dashboard['summary']['unresolved_alerts'] ?></div>
        <div class="sec-label">Alert Non Risolti</div>
    </div>
    <div class="sec-card <?= $dashboard['summary']['failed_logins_24h'] > 10 ? 'warning' : 'info' ?>">
        <div class="sec-value"><?= $dashboard['summary']['failed_logins_24h'] ?></div>
        <div class="sec-label">Login Falliti (24h)</div>
    </div>
    <div class="sec-card success">
        <div class="sec-value"><?= $dashboard['summary']['successful_logins_24h'] ?></div>
        <div class="sec-label">Login OK (24h)</div>
    </div>
    <div class="sec-card info">
        <div class="sec-value"><?= $dashboard['summary']['downloads_today'] ?></div>
        <div class="sec-label">Download Oggi</div>
    </div>
    <div class="sec-card info">
        <div class="sec-value"><?= $dashboard['active_sessions'] ?></div>
        <div class="sec-label">Sessioni Attive</div>
    </div>
    <div class="sec-card <?= $dashboard['summary']['critical_events_24h'] > 0 ? 'danger' : 'success' ?>">
        <div class="sec-value"><?= $dashboard['summary']['critical_events_24h'] ?></div>
        <div class="sec-label">Eventi Critici</div>
    </div>
</div>

<div class="two-cols">
    <!-- Security Alerts -->
    <div class="section-card">
        <h2>Alert di Sicurezza</h2>
        <?php if (empty($dashboard['recent_alerts'])): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem 0;">Nessun alert recente</p>
        <?php else: ?>
            <div style="max-height: 300px; overflow-y: auto;">
            <?php foreach (array_slice($dashboard['recent_alerts'], 0, 5) as $alert): ?>
                <div class="alert-item <?= $alert['is_resolved'] ? 'resolved' : 'unresolved' ?>">
                    <div class="alert-header">
                        <span class="alert-type"><?= htmlspecialchars($alert['alert_type']) ?></span>
                        <span class="alert-time"><?= date('d/m H:i', strtotime($alert['created_at'])) ?></span>
                    </div>
                    <div style="font-size: 0.85rem; color: #475569; margin-top: 0.5rem;">
                        IP: <?= htmlspecialchars($alert['ip_address'] ?? 'N/A') ?>
                        <?php if (!$alert['is_resolved']): ?>
                            <form method="POST" style="display: inline; float: right;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="resolve_alert">
                                <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success">Risolvi</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-success" style="float: right;">Risolto</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- GDPR Compliance -->
    <div class="section-card">
        <h2>Compliance GDPR</h2>
        <?php foreach ($gdprCompliance as $key => $check): ?>
            <div class="compliance-item <?= $check['status'] ?? 'warning' ?>">
                <div class="compliance-icon">
                    <?php if (($check['status'] ?? '') === 'ok'): ?>✓
                    <?php elseif (($check['status'] ?? '') === 'warning'): ?>!
                    <?php else: ?>✗<?php endif; ?>
                </div>
                <div>
                    <strong style="font-size: 0.9rem;"><?= ucfirst(str_replace('_', ' ', $key)) ?></strong><br>
                    <small style="color: #64748b;"><?= htmlspecialchars($check['message'] ?? '') ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="two-cols">
    <!-- IP Sospetti -->
    <div class="section-card">
        <h2>IP Sospetti (24h)</h2>
        <?php if (empty($suspiciousIPs)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem 0;">Nessun IP sospetto</p>
        <?php else: ?>
            <table class="ip-table">
                <thead>
                    <tr><th>IP</th><th>Tentativi</th><th>Ultimo</th></tr>
                </thead>
                <tbody>
                <?php foreach ($suspiciousIPs as $ip): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($ip['ip_address']) ?></code></td>
                        <td><span class="badge badge-danger"><?= $ip['attempt_count'] ?></span></td>
                        <td><?= date('d/m H:i', strtotime($ip['last_attempt'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Test Sicurezza -->
    <div class="section-card">
        <h2>Test Sicurezza</h2>

        <div class="test-box">
            <h4>Test Password Policy</h4>
            <p>Min <?= PASSWORD_MIN_LENGTH ?> caratteri, maiuscola, minuscola, numero, speciale</p>
            <form method="POST" class="test-form">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="test_password">
                <input type="text" name="test_password" placeholder="Password da testare...">
                <button type="submit" class="btn btn-primary btn-sm">Test</button>
            </form>
        </div>

        <div class="test-box">
            <h4>Configurazione Sessioni</h4>
            <p>
                Admin: <?= SESSION_TIMEOUT_ADMIN/60 ?> min |
                Commercialista: <?= SESSION_TIMEOUT_ACCOUNTANT/60 ?> min |
                Dipendente: <?= SESSION_TIMEOUT_EMPLOYEE/60 ?> min
            </p>
            <p style="margin-top: 0.5rem;">
                <strong>Tempo rimanente:</strong>
                <?php try { echo Auth::getSessionTimeRemaining(); } catch (Throwable $e) { echo 'N/A'; } ?> sec
            </p>
        </div>

        <div class="test-box">
            <h4>MFA</h4>
            <p>
                Stato: <?= MFA_ENABLED ? '<span style="color:#10b981">Abilitato</span>' : '<span style="color:#ef4444">Disabilitato</span>' ?> |
                Ruoli: <?= implode(', ', MFA_REQUIRED_ROLES) ?>
            </p>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
