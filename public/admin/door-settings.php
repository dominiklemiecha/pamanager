<?php
/**
 * Configurazione modulo Apertura Porta (ESP32 + RC522).
 * Modulo riservato: visibile solo all'admin globale del SaaS.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

// Modulo riservato: solo l'admin globale (nessun tenant) può gestire la porta.
if (!Tenant::isCurrentUserTrueGlobalAdmin()) {
    header('Location: ' . PUBLIC_URL . '/admin/'); exit;
}

$pageTitle = 'Configurazione · Apertura porta';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <div class="cfg-card" style="max-width: 760px;">
        <h3>Modulo Apertura Porta</h3>
        <p class="desc">Configura il dispositivo che gestisce l'apertura della porta dell'ufficio. Il pulsante apre l'interfaccia del dispositivo in una nuova scheda.</p>

        <div class="cfg-actions" style="justify-content: flex-start; border-top: none; padding-top: 0; margin-top: 4px;">
            <a href="http://door.local" target="_blank" rel="noopener" class="cfg-btn cfg-btn-primary">Configura</a>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
