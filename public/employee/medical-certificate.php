<?php
/**
 * Upload certificato medico - lato dipendente.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) {
        $error = 'Token CSRF non valido.';
    } elseif (($_POST['action'] ?? '') === 'upload') {
        if (!isset($_FILES['certificate'])) {
            $error = 'Nessun file ricevuto.';
        } else {
            $result = MedicalCertificate::save($_FILES['certificate'], (int)$employee['id'], [
                'issued_at'               => $_POST['issued_at'] ?? null,
                'valid_until'             => $_POST['valid_until'] ?? null,
                'notes'                   => $_POST['notes'] ?? null,
                'uploaded_by_employee_id' => (int)$employee['id'],
            ]);
            if ($result['ok']) {
                $message = 'Certificato caricato con successo.';
                // Notifica admin via email
                try {
                    $admins = Database::fetchAll("SELECT email, name FROM users WHERE role = 'admin' AND email IS NOT NULL");
                    foreach ($admins as $a) {
                        if (!empty($a['email'])) {
                            Mailer::send($a['email'], 'Nuovo certificato medico caricato',
                                "Il dipendente " . $employee['first_name'] . " " . $employee['last_name'] . " ha caricato un nuovo certificato medico nel gestionale.");
                        }
                    }
                } catch (Throwable $e) {}
            } else {
                $error = $result['error'];
            }
        }
    }
}

$certs = MedicalCertificate::getByEmployee((int)$employee['id']);

$pageTitle = 'Certificato medico';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<div class="hero-inbox">
    <div>
        <h2>Carica certificato medico</h2>
        <p>Carica i tuoi certificati medici (PDF, JPG o PNG, max 5 MB). Saranno visibili solo all'amministratore.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="hero-inbox is-clear" style="background:#dcfce7; border-color:#86efac;">
        <div><h2 style="color:#15803d;">&check; <?= htmlspecialchars($message) ?></h2></div>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="hero-inbox" style="background:#fee2e2; border-color:#fca5a5;">
        <div><h2 style="color:#991b1b;">Errore</h2><p style="color:#991b1b;"><?= htmlspecialchars($error) ?></p></div>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card" style="max-width: 600px;">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="card-body" style="padding: 1.5rem;">
        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="certificate" style="display: block; font-weight: 600; margin-bottom: 0.4rem;">File certificato *</label>
            <input type="file" id="certificate" name="certificate" required
                   accept=".pdf,.jpg,.jpeg,.png" style="width: 100%;">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="issued_at" style="display: block; font-weight: 600; margin-bottom: 0.4rem;">Data rilascio</label>
            <input type="date" id="issued_at" name="issued_at" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px;">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="valid_until" style="display: block; font-weight: 600; margin-bottom: 0.4rem;">Valido fino a</label>
            <input type="date" id="valid_until" name="valid_until" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px;">
        </div>
        <div class="form-group" style="margin-bottom: 1rem;">
            <label for="notes" style="display: block; font-weight: 600; margin-bottom: 0.4rem;">Note</label>
            <textarea id="notes" name="notes" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px;"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Carica certificato</button>
    </div>
</form>

<section class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><h3>I miei certificati caricati</h3></div>
    <div class="card-body">
        <?php if (empty($certs)): ?>
            <div class="empty-state">Nessun certificato caricato.</div>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($certs as $c): ?>
                    <li class="activity-item">
                        <div class="activity-icon is-doc">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($c['original_name']) ?></div>
                            <div class="activity-meta">
                                Caricato <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?>
                                <?php if ($c['valid_until']): ?> &middot; Valido fino <?= date('d/m/Y', strtotime($c['valid_until'])) ?><?php endif; ?>
                            </div>
                        </div>
                        <a href="<?= PUBLIC_URL ?>/<?= htmlspecialchars($c['file_path']) ?>" target="_blank" class="btn btn-sm btn-secondary">Vedi</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
