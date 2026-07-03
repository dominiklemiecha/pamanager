<?php
/**
 * Impostazioni buoni pasto (ticket restaurant) per la company corrente.
 * Il conteggio mensile appare come colonna nell'export presenze (MealVoucher).
 * I giorni di smart working ricorrenti si definiscono in Orario lavorativo
 * (default azienda) e nella scheda dipendente (override).
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$companyId = Tenant::currentCompanyId();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $minHours = (float) str_replace(',', '.', trim((string) ($_POST['buoni_pasto_min_hours'] ?? '6')));
    if ($minHours <= 0 || $minHours > 24) {
        $error = 'La soglia ore deve essere tra 0 e 24.';
    } else {
        Database::update('companies', [
            'buoni_pasto_enabled'           => isset($_POST['buoni_pasto_enabled']) ? 1 : 0,
            'buoni_pasto_min_hours_enabled' => isset($_POST['buoni_pasto_min_hours_enabled']) ? 1 : 0,
            'buoni_pasto_min_hours'         => $minHours,
            'buoni_pasto_sw_eligible'       => isset($_POST['buoni_pasto_sw_eligible']) ? 1 : 0,
        ], 'id = ?', [$companyId]);
        $message = 'Impostazioni salvate.';
    }
}

$cfg = Database::fetchOne(
    "SELECT buoni_pasto_enabled, buoni_pasto_min_hours_enabled, buoni_pasto_min_hours, buoni_pasto_sw_eligible, smart_working_days
     FROM companies WHERE id = ?",
    [$companyId]
) ?: [];
$bpEnabled   = !empty($cfg['buoni_pasto_enabled']);
$bpMinOn     = !empty($cfg['buoni_pasto_min_hours_enabled']);
$bpMinHours  = (float) ($cfg['buoni_pasto_min_hours'] ?? 6.0);
$bpSw        = !empty($cfg['buoni_pasto_sw_eligible']);
$swDaysLabel = !empty($cfg['smart_working_days'])
    ? implode(', ', array_map(['LeaveBalance', 'dayLabel'], array_filter(array_map('trim', explode(',', $cfg['smart_working_days'])))))
    : 'nessuno';

$pageTitle = 'Configurazione · Buoni pasto';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<style>
/* Switch on/off stile iOS per le impostazioni buoni pasto */
.bp-switch-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #f1f5f9;
}
.bp-switch-row:last-of-type { border-bottom: none; }
.bp-switch-row .bp-label { font-size: 14px; font-weight: 600; color: #0f172a; }
.bp-switch-row .bp-sub { font-size: 12px; color: #64748b; margin-top: 2px; }
.bp-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
.bp-switch input { opacity: 0; width: 0; height: 0; }
.bp-switch .bp-slider {
    position: absolute; inset: 0; cursor: pointer;
    background: #cbd5e1; border-radius: 999px;
    transition: background .15s ease;
}
.bp-switch .bp-slider::before {
    content: ''; position: absolute;
    width: 18px; height: 18px; left: 3px; top: 3px;
    background: white; border-radius: 50%;
    box-shadow: 0 1px 3px rgba(15,23,42,0.25);
    transition: transform .15s ease;
}
.bp-switch input:checked + .bp-slider { background: var(--accent); }
.bp-switch input:checked + .bp-slider::before { transform: translateX(20px); }
.bp-switch input:focus-visible + .bp-slider { box-shadow: 0 0 0 3px rgba(11,58,164,0.20); }
.bp-inline-input {
    display: flex; align-items: center; gap: 8px;
    margin: 10px 0 4px;
}
.bp-inline-input input {
    width: 110px; padding: 9px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    font-family: inherit; font-size: 14px;
}
.bp-inline-input input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(11,58,164,0.10); }
.bp-inline-input span { font-size: 13px; color: #64748b; }
.bp-disabled-note { opacity: 0.45; pointer-events: none; }
</style>

<div class="admin-page">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="cfg-card" style="max-width:720px;">
        <?= CSRF::field() ?>
        <h3>Buoni pasto</h3>
        <p class="desc">1 ticket per ogni giorno effettivamente lavorato. Il totale mensile per dipendente compare nell'export presenze come colonna "Buoni pasto (nr)". Esclusioni e regole personalizzate si impostano nella scheda del singolo dipendente.</p>

        <div class="bp-switch-row">
            <div>
                <div class="bp-label">Abilita buoni pasto</div>
                <div class="bp-sub">Attiva il conteggio dei ticket per questa azienda.</div>
            </div>
            <label class="bp-switch">
                <input type="checkbox" name="buoni_pasto_enabled" value="1" <?= $bpEnabled ? 'checked' : '' ?>>
                <span class="bp-slider"></span>
            </label>
        </div>

        <div class="bp-switch-row">
            <div style="flex:1;">
                <div class="bp-label">Impostare una soglia minima di ore?</div>
                <div class="bp-sub">Se attiva, il ticket matura solo nei giorni con ore effettive (ore/giorno meno permessi) pari o sopra la soglia. Se disattivata, basta aver lavorato.</div>
                <div class="bp-inline-input" id="bp_min_wrap" <?= $bpMinOn ? '' : 'style="display:none;"' ?>>
                    <input type="number" step="0.25" min="0.25" max="24" name="buoni_pasto_min_hours"
                           value="<?= htmlspecialchars(rtrim(rtrim(number_format($bpMinHours, 2, '.', ''), '0'), '.')) ?>">
                    <span>ore al giorno</span>
                </div>
            </div>
            <label class="bp-switch">
                <input type="checkbox" name="buoni_pasto_min_hours_enabled" value="1" <?= $bpMinOn ? 'checked' : '' ?>
                       onchange="document.getElementById('bp_min_wrap').style.display = this.checked ? '' : 'none';">
                <span class="bp-slider"></span>
            </label>
        </div>

        <div class="bp-switch-row">
            <div>
                <div class="bp-label">Lo smart working d&agrave; diritto al ticket?</div>
                <div class="bp-sub">Giorni SW ricorrenti dell'azienda: <strong><?= htmlspecialchars($swDaysLabel) ?></strong> (si impostano in <a href="work-schedule.php" style="color: var(--accent);">Orario lavorativo</a>, override per dipendente nella sua scheda).</div>
            </div>
            <label class="bp-switch">
                <input type="checkbox" name="buoni_pasto_sw_eligible" value="1" <?= $bpSw ? 'checked' : '' ?>>
                <span class="bp-slider"></span>
            </label>
        </div>

        <div class="cfg-actions">
            <button type="submit" class="cfg-btn cfg-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Salva impostazioni
            </button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
