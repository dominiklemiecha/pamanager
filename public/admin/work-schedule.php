<?php
/**
 * Impostazioni orario lavorativo aziendale.
 * Configura giorni lavorativi e ore/giorno default per la company corrente.
 * Usato dal calcolo saldo ferie/permessi (LeaveBalance).
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
    $allowed = LeaveBalance::allDayKeys();
    $days = $_POST['working_days'] ?? [];
    $clean = is_array($days) ? array_values(array_intersect($allowed, $days)) : [];
    if (empty($clean)) {
        $error = 'Seleziona almeno un giorno lavorativo.';
    } else {
        $hours = (float) str_replace(',', '.', trim((string) ($_POST['hours_per_day'] ?? '8')));
        if ($hours <= 0 || $hours > 24) {
            $error = 'Ore/giorno deve essere tra 0 e 24.';
        } else {
            $defaultCcnl = !empty($_POST['default_ccnl_id']) ? (int) $_POST['default_ccnl_id'] : null;
            // Slug NFC: solo lettere/numeri/trattino, lowercased
            $postedSlug = trim((string) ($_POST['nfc_slug'] ?? ''));
            $cleanSlug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($postedSlug));
            $cleanSlug = trim($cleanSlug, '-');
            $update = [
                'working_days'    => implode(',', $clean),
                'hours_per_day'   => $hours,
                'default_ccnl_id' => $defaultCcnl,
            ];
            if ($cleanSlug !== '') {
                // Verifica unicità globale
                $exists = Database::exists('companies', 'slug = ? AND id != ?', [$cleanSlug, $companyId]);
                if ($exists) {
                    $error = "Slug '$cleanSlug' già usato da un'altra azienda. Scegli un altro nome.";
                } else {
                    $update['slug'] = $cleanSlug;
                }
            }
            if (!$error) {
                Database::update('companies', $update, 'id = ?', [$companyId]);
                $message = 'Impostazioni salvate.';
            }
        }
    }
}

$defaults = LeaveBalance::companyDefaults($companyId);
$ccnls = LeaveBalance::availableCcnls($companyId);
$compRow = Database::fetchOne("SELECT default_ccnl_id, slug, name FROM companies WHERE id = ?", [$companyId]);
$currentDefaultCcnl = $compRow['default_ccnl_id'] ?? null;
$companySlug = $compRow['slug'] ?? '';
// Auto-genera slug se mancante (azienda creata prima dell'introduzione delle URL tenant)
if (empty($companySlug) && !empty($compRow['name'])) {
    $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($compRow['name'], 'UTF-8'));
    $base = trim($base, '-');
    $candidate = $base ?: ('az' . $companyId);
    $i = 1;
    while (Database::exists('companies', 'slug = ? AND id != ?', [$candidate, $companyId])) {
        $candidate = $base . '-' . (++$i);
    }
    Database::update('companies', ['slug' => $candidate], 'id = ?', [$companyId]);
    $companySlug = $candidate;
}
// Costruisce URL ASSOLUTA per il tag NFC (scheme + host obbligatori).
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__pubPath = (defined('PUBLIC_URL') && strpos(PUBLIC_URL, '://') !== false)
    ? PUBLIC_URL
    : ($__scheme . '://' . $__host . PUBLIC_URL);
$punchUrl = $__pubPath . '/punch.php?c=' . urlencode($companySlug);
$pageTitle = 'Configurazione · Orario lavorativo';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="cfg-card" style="max-width:720px;">
        <?= CSRF::field() ?>
        <h3>Giorni lavorativi</h3>
        <p class="desc">Default aziendale usato per calcolare il consumo di ferie e permessi. Ogni dipendente può avere un override.</p>

        <div class="cfg-day-chips" style="margin-bottom: 24px;">
            <?php foreach (LeaveBalance::allDayKeys() as $dk): ?>
                <label class="cfg-day-chip">
                    <input type="checkbox" name="working_days[]" value="<?= $dk ?>"
                           <?= in_array($dk, $defaults['days'], true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars(LeaveBalance::dayLabel($dk)) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <h3>Ore lavorate al giorno</h3>
        <p class="desc">Usato per convertire permessi a giornata intera in ore.</p>
        <div class="cfg-fg" style="max-width:220px;">
            <input type="number" step="0.25" min="0" max="24" name="hours_per_day"
                   value="<?= htmlspecialchars((string) $defaults['hours']) ?>" required>
        </div>

        <h3 style="margin-top: 24px;">CCNL applicato di default</h3>
        <p class="desc">Determina la maturazione annua di ferie e permessi. Si applica a tutti i dipendenti senza CCNL specifico.</p>
        <div class="cfg-fg" style="max-width: 100%;">
            <select name="default_ccnl_id">
                <option value="">— Non impostato (configurare per dipendente) —</option>
                <?php foreach ($ccnls as $cc): ?>
                    <option value="<?= (int)$cc['id'] ?>" <?= (int)$currentDefaultCcnl === (int)$cc['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cc['name']) ?> · <?= rtrim(rtrim(number_format($cc['ferie_days_year'], 1, ',', '.'), '0'), ',') ?>gg ferie / <?= rtrim(rtrim(number_format($cc['permessi_hours_year'], 1, ',', '.'), '0'), ',') ?>h permessi
                    </option>
                <?php endforeach; ?>
            </select>
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
