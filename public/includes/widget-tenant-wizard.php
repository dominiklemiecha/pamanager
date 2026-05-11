<?php
/**
 * Wizard primo setup: chiede il nome della prima azienda all'admin.
 * Da includere nelle dashboard admin.
 */

if (!class_exists('Tenant') || !Tenant::needsSetupWizard()) return;

$company = Tenant::currentCompany();
if (!$company) return;

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tenant_setup') {
    CSRF::verifyOrDie();
    $name = trim($_POST['company_name'] ?? '');
    if ($name !== '' && Tenant::completeSetup((int)$company['id'], $name)) {
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>
<div class="tenant-wizard">
    <div style="flex:1; min-width:240px;">
        <h3>&#127970; Benvenuto! Dai un nome alla tua azienda</h3>
        <p>Il sistema e' in modalita multi-azienda. Inserisci il nome della tua azienda per iniziare. Potrai crearne altre in seguito.</p>
    </div>
    <form method="POST">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="tenant_setup">
        <input type="text" name="company_name" required maxlength="120" placeholder="Es. Connecteed S.r.l." autofocus>
        <button type="submit">Salva &amp; Inizia</button>
    </form>
</div>
