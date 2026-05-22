<?php
/**
 * Configurazione modulo Apertura Porta (ESP32 + RC522).
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

    $action = $_POST['action'] ?? 'save';

    if ($action === 'regenerate_key') {
        $newKey = bin2hex(random_bytes(24));
        Database::update('companies', ['door_api_key' => $newKey], 'id = ?', [$companyId]);
        $message = 'Nuova API key generata. Aggiorna la configurazione dell\'ESP32.';
    } else {
        $enabled  = !empty($_POST['door_enabled']) ? 1 : 0;
        $duration = max(500, min(10000, (int) ($_POST['door_open_duration_ms'] ?? 3000)));

        $update = [
            'door_enabled'          => $enabled,
            'door_open_duration_ms' => $duration,
        ];

        // Genera la key automaticamente alla prima abilitazione
        if ($enabled) {
            $currentKey = Database::fetchColumn("SELECT door_api_key FROM companies WHERE id = ?", [$companyId]);
            if (empty($currentKey)) {
                $update['door_api_key'] = bin2hex(random_bytes(24));
            }
        }

        Database::update('companies', $update, 'id = ?', [$companyId]);
        $message = 'Impostazioni salvate.';
    }
}

$compRow = Database::fetchOne(
    "SELECT slug, name, door_enabled, door_api_key, door_open_duration_ms FROM companies WHERE id = ?",
    [$companyId]
);
$companySlug = $compRow['slug'] ?? '';
if (empty($companySlug) && !empty($compRow['name'])) {
    $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($compRow['name'], 'UTF-8'));
    $companySlug = trim($base, '-') ?: ('az' . $companyId);
}
$isEnabled    = (int) ($compRow['door_enabled'] ?? 0);
$apiKey       = (string) ($compRow['door_api_key'] ?? '');
$durationMs   = (int) ($compRow['door_open_duration_ms'] ?? 3000);

$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__pubPath = (defined('PUBLIC_URL') && strpos(PUBLIC_URL, '://') !== false)
    ? PUBLIC_URL
    : ($__scheme . '://' . $__host . PUBLIC_URL);
$apiUrl = $__pubPath . '/api/door.php?c=' . urlencode($companySlug);

$nfcAssignedCount = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM employees WHERE company_id = ? AND nfc_uid IS NOT NULL AND nfc_uid != ''",
    [$companyId]
);
$nfcTotalEmployees = (int) Database::fetchColumn(
    "SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = 1",
    [$companyId]
);

$pageTitle = 'Configurazione · Apertura porta';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="cfg-card" style="max-width: 760px;">
        <?= CSRF::field() ?>

        <h3>Modulo Apertura Porta</h3>
        <p class="desc">Abilita l'apertura della porta dell'ufficio tramite badge NFC NTAG215, letto da un ESP32 con modulo RC522 collegato a un relè 5V che pilota la serratura elettrica.</p>

        <label class="cfg-toggle" style="margin-bottom: 22px;">
            <input type="checkbox" name="door_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
            <span>Abilita apertura porta per la mia azienda</span>
        </label>

        <h3 style="margin-top: 16px;">Durata apertura</h3>
        <p class="desc">Quanti millisecondi tenere il relè chiuso per sbloccare la serratura. Tipico: 3000ms (3 secondi).</p>
        <div class="cfg-fg" style="max-width: 240px;">
            <input type="number" name="door_open_duration_ms" min="500" max="10000" step="100"
                   value="<?= $durationMs ?>" style="font-family: inherit; font-size: 14px;">
            <small>Valore tra 500 e 10000 ms.</small>
        </div>

        <div class="cfg-actions">
            <button type="submit" class="cfg-btn cfg-btn-primary">Salva impostazioni</button>
        </div>
    </form>

    <?php if ($isEnabled && $apiKey): ?>
    <div class="cfg-card" style="max-width: 760px;">
        <h3>Configurazione ESP32</h3>
        <p class="desc">Copia questi valori nel firmware Arduino del tuo ESP32. Tieni segreta la <strong>API key</strong>: chi la conosce può aprire la porta.</p>

        <div class="cfg-fg">
            <label>URL endpoint</label>
            <div style="display:flex; gap:8px;">
                <input type="text" id="doorApiUrl" value="<?= htmlspecialchars($apiUrl) ?>" readonly
                       style="flex:1; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; background: #f8fafc;">
                <button type="button" class="cfg-btn cfg-btn-ghost" onclick="copyVal('doorApiUrl')">Copia</button>
            </div>
        </div>

        <div class="cfg-fg">
            <label>API key (header X-Door-Key)</label>
            <div style="display:flex; gap:8px;">
                <input type="text" id="doorApiKey" value="<?= htmlspecialchars($apiKey) ?>" readonly
                       style="flex:1; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; background: #f8fafc;">
                <button type="button" class="cfg-btn cfg-btn-ghost" onclick="copyVal('doorApiKey')">Copia</button>
            </div>
        </div>

        <div class="cfg-fg">
            <label>Snippet Arduino (incollare in cima allo sketch)</label>
            <textarea id="doorSnippet" readonly rows="5"
                style="width:100%; font-family: 'JetBrains Mono', monospace; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 12px; border: none; resize: vertical;">const char* WIFI_SSID  = "TUO_WIFI";
const char* WIFI_PASS  = "TUA_PASSWORD";
const char* DOOR_URL   = "<?= htmlspecialchars($apiUrl) ?>";
const char* DOOR_KEY   = "<?= htmlspecialchars($apiKey) ?>";
const int   RELAY_PIN  = 26;</textarea>
            <div style="margin-top:8px;">
                <button type="button" class="cfg-btn cfg-btn-ghost" onclick="copyVal('doorSnippet')">Copia snippet</button>
            </div>
        </div>

        <form method="POST" style="margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 16px;"
              onsubmit="return confirm('Sicuro di voler rigenerare la API key? L\'ESP32 smetterà di funzionare finché non aggiorni il firmware con la nuova key.');">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="regenerate_key">
            <button type="submit" class="cfg-btn cfg-btn-ghost" style="color: #b91c1c; border-color: #fecaca;">
                Rigenera API key
            </button>
            <small style="display:block; color:#94a3b8; margin-top:6px;">Usa se sospetti che la key sia compromessa.</small>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($isEnabled): ?>
    <div class="cfg-card" style="max-width: 760px;">
        <h3>Badge assegnati</h3>
        <p class="desc"><strong><?= $nfcAssignedCount ?></strong> di <?= $nfcTotalEmployees ?> dipendenti attivi hanno un UID badge configurato.</p>
        <p style="font-size: 13px; color: #475569;">Per assegnare l'UID a un dipendente, vai su <a href="<?= PUBLIC_URL ?>/admin/employees.php" style="color: #0b3aa4;">Dipendenti</a> → apri la scheda → campo "UID badge porta".</p>
        <div style="margin-top: 12px;">
            <a href="<?= PUBLIC_URL ?>/admin/door-log.php" class="cfg-btn cfg-btn-ghost">Vedi log accessi →</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function copyVal(id) {
    const el = document.getElementById(id);
    el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard?.writeText(el.value).then(() => {
        const orig = el.style.background;
        el.style.background = '#dcfce7';
        setTimeout(() => { el.style.background = orig; }, 800);
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
