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
            Database::update('companies', [
                'working_days'  => implode(',', $clean),
                'hours_per_day' => $hours,
            ], 'id = ?', [$companyId]);
            $message = 'Impostazioni salvate.';
        }
    }
}

$defaults = LeaveBalance::companyDefaults($companyId);
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

        <div class="cfg-actions">
            <button type="submit" class="cfg-btn cfg-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Salva impostazioni
            </button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
