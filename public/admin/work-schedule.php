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
$pageTitle = 'Orario lavorativo';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Orario lavorativo</h1>
        <p style="color:#64748b; margin:0;">Default aziendale usato per calcolare il consumo di ferie e permessi. Ogni dipendente può avere un override.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="form-card" style="max-width:640px;">
        <?= CSRF::field() ?>

        <h3>Giorni lavorativi</h3>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
            <?php foreach (LeaveBalance::allDayKeys() as $dk): ?>
                <label class="checkbox-label" style="font-weight:500;">
                    <input type="checkbox" name="working_days[]" value="<?= $dk ?>"
                           <?= in_array($dk, $defaults['days'], true) ? 'checked' : '' ?>>
                    <?= htmlspecialchars(LeaveBalance::dayLabel($dk)) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <h3>Ore lavorate al giorno</h3>
        <div class="form-group" style="max-width:200px;">
            <input type="number" step="0.25" min="0" max="24" name="hours_per_day"
                   value="<?= htmlspecialchars((string) $defaults['hours']) ?>" required>
            <small>Usato per convertire permessi a giornata intera in ore.</small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
