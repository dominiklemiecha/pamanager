<?php
/**
 * Configurazione visibilita campi anagrafici dipendente per ruolo.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) {
        $error = 'Token CSRF non valido.';
    } else {
        $cfg = [];
        foreach (array_keys(FieldVisibility::FIELDS) as $field) {
            $cfg[$field] = $_POST['visibility'][$field] ?? [];
        }
        try {
            FieldVisibility::saveConfig($cfg, (int)$user['id']);
            $saved = true;
        } catch (Throwable $e) {
            $error = 'Errore salvataggio: ' . $e->getMessage();
        }
    }
}

$config = FieldVisibility::getConfig();
$pageTitle = 'Visibilita campi anagrafici';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="hero-inbox">
    <div>
        <h2>Visibilita campi anagrafici</h2>
        <p>Per ogni campo della scheda dipendente, scegli quali ruoli possono vederlo. L'amministratore vede sempre tutto. Il dipendente vede sempre i propri dati.</p>
    </div>
</div>

<?php if ($saved): ?>
    <div class="hero-inbox is-clear" style="background:#dcfce7; border-color:#86efac;">
        <div><h2 style="color:#15803d;">Configurazione salvata</h2></div>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="hero-inbox" style="background:#fee2e2; border-color:#fca5a5;">
        <div><h2 style="color:#991b1b;">Errore</h2><p style="color:#991b1b;"><?= htmlspecialchars($error) ?></p></div>
    </div>
<?php endif; ?>

<form method="post" class="card">
    <?= CSRF::field() ?>
    <div class="card-body" style="padding: 0;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 0.8rem 1rem; text-align: left;">Campo</th>
                    <?php foreach (FieldVisibility::ROLES as $roleKey => $roleLabel): ?>
                        <th style="padding: 0.8rem 0.5rem; text-align: center; min-width: 110px;"><?= htmlspecialchars($roleLabel) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (FieldVisibility::FIELDS as $field => $label): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 0.7rem 1rem; font-weight: 500;"><?= htmlspecialchars($label) ?></td>
                        <?php foreach (array_keys(FieldVisibility::ROLES) as $roleKey): ?>
                            <td style="padding: 0.7rem 0.5rem; text-align: center;">
                                <input type="checkbox" name="visibility[<?= $field ?>][]" value="<?= $roleKey ?>"
                                       <?= in_array($roleKey, $config[$field] ?? [], true) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding: 1rem; border-top: 1px solid #e2e8f0; display: flex; gap: 0.5rem; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">Salva configurazione</button>
        </div>
    </div>
</form>

<section class="card" style="margin-top: 1.5rem;">
    <div class="card-body" style="font-size: 0.86rem;">
        <h3 style="margin-top: 0;">Note</h3>
        <ul style="margin: 0; padding-left: 1.2rem; line-height: 1.6;">
            <li>L'<strong>amministratore</strong> ha sempre accesso a tutti i campi (non configurabile).</li>
            <li>Il <strong>dipendente</strong> vede sempre i propri dati personali; la spunta "Dipendenti (altri)" controlla cosa vede degli ALTRI dipendenti.</li>
            <li>L'<strong>admin reparto</strong> vede solo i dipendenti del proprio reparto, ma solo i campi qui marcati.</li>
            <li>Il <strong>commercialista</strong> vede solo i campi qui marcati (di solito tutti per le buste paga).</li>
        </ul>
    </div>
</section>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
