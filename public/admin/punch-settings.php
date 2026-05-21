<?php
/**
 * Impostazioni Timbrature: abilita/disabilita feature + slug NFC + URL da scrivere sulla carta.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$companyId = Tenant::currentCompanyId();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $enabled  = !empty($_POST['timbratura_enabled']) ? 1 : 0;
    $postSlug = trim((string) ($_POST['nfc_slug'] ?? ''));
    $cleanSlug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($postSlug));
    $cleanSlug = trim($cleanSlug, '-');

    $update = ['timbratura_enabled' => $enabled];
    $errSlug = '';

    if ($cleanSlug !== '') {
        if (Database::exists('companies', 'slug = ? AND id != ?', [$cleanSlug, $companyId])) {
            $errSlug = "Identificativo '$cleanSlug' già usato da un'altra azienda.";
        } else {
            $update['slug'] = $cleanSlug;
        }
    }

    if ($errSlug) {
        $error = $errSlug;
    } else {
        Database::update('companies', $update, 'id = ?', [$companyId]);
        $message = 'Impostazioni salvate.';
    }
}

$compRow = Database::fetchOne(
    "SELECT slug, name, timbratura_enabled FROM companies WHERE id = ?",
    [$companyId]
);
$companySlug = $compRow['slug'] ?? '';
if (empty($companySlug) && !empty($compRow['name'])) {
    $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($compRow['name'], 'UTF-8'));
    $companySlug = trim($base, '-') ?: ('az' . $companyId);
}
$isEnabled = (int) ($compRow['timbratura_enabled'] ?? 1);

// URL assoluta
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__pubPath = (defined('PUBLIC_URL') && strpos(PUBLIC_URL, '://') !== false)
    ? PUBLIC_URL
    : ($__scheme . '://' . $__host . PUBLIC_URL);
$punchUrl = $__pubPath . '/punch.php?c=' . urlencode($companySlug);

$pageTitle = 'Configurazione · Timbrature NFC';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="cfg-card" style="max-width: 720px;">
        <?= CSRF::field() ?>

        <h3>Timbratura abilitata</h3>
        <p class="desc">Se disabilitata, i dipendenti non vedono il bottone "Timbra entrata/uscita" e la URL NFC restituisce errore.</p>
        <label class="cfg-toggle" style="margin-bottom: 22px;">
            <input type="checkbox" name="timbratura_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
            <span>Abilita timbrature NFC per la mia azienda</span>
        </label>

        <h3 style="margin-top: 16px;">Identificativo azienda</h3>
        <p class="desc">Solo lettere/numeri/trattini. È la parte finale dell'URL NFC.</p>
        <div class="cfg-fg" style="max-width: 360px;">
            <input type="text" id="nfc_slug" name="nfc_slug" value="<?= htmlspecialchars($companySlug) ?>"
                   pattern="[a-z0-9-]+" maxlength="40"
                   placeholder="es. connecteed"
                   oninput="updatePunchUrl()"
                   style="font-family: inherit; font-size: 14px;">
        </div>

        <h3 style="margin-top: 16px;">URL da scrivere sulla carta NTAG215</h3>
        <p class="desc">Apri <strong>NFC Tools</strong> sul telefono → Scrivi → Aggiungi record → URL/URI → incolla l'URL qui sotto → Scrivi.</p>
        <div class="cfg-fg" style="max-width: 100%;">
            <div style="display:flex; gap:8px; align-items:center;">
                <input type="text" id="punchUrlField" value="<?= htmlspecialchars($punchUrl) ?>" readonly
                       style="flex:1; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; background: #f8fafc;">
                <button type="button" class="cfg-btn cfg-btn-ghost" onclick="copyPunchUrl()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Copia
                </button>
            </div>
            <small style="color:#94a3b8; margin-top:6px; display:block;">L'URL si aggiorna mentre digiti. <strong>Salva prima di scriverla sulla carta.</strong></small>
        </div>

        <div class="cfg-actions">
            <button type="submit" class="cfg-btn cfg-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Salva impostazioni
            </button>
        </div>
    </form>
</div>

<script>
const PUNCH_BASE = <?= json_encode($__pubPath . '/punch.php') ?>;
function updatePunchUrl() {
    const slug = (document.getElementById('nfc_slug').value || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
    document.getElementById('punchUrlField').value = PUNCH_BASE + (slug ? '?c=' + slug : '');
}
function copyPunchUrl() {
    const el = document.getElementById('punchUrlField');
    el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard?.writeText(el.value).then(() => {
        el.style.background = '#dcfce7';
        setTimeout(() => { el.style.background = '#f8fafc'; }, 800);
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
