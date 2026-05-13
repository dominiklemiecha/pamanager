<?php
/**
 * Profilo Dipendente - modifica nome/cognome + foto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$employeeId = (int) $employee['id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $error = 'Nome e cognome obbligatori';
        } else {
            $result = Employee::update($employeeId, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ]);
            if ($result['success']) {
                // Refresh sessione
                if (method_exists('Auth', 'refreshEmployee')) {
                    Auth::refreshEmployee();
                }
                header('Location: profile.php?message=updated');
                exit;
            }
            $error = $result['error'] ?? 'Errore aggiornamento';
        }
    } elseif ($action === 'upload_photo') {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Seleziona un file immagine valido';
        } else {
            $f = $_FILES['photo'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $finfo = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : ($f['type'] ?? '');
            if (!isset($allowed[$finfo])) {
                $error = 'Formato non supportato (usa JPG, PNG, WEBP o GIF)';
            } elseif ($f['size'] > 3 * 1024 * 1024) {
                $error = 'File troppo grande (max 3MB)';
            } else {
                $cid = (int)($employee['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1));
                $dir = ROOT_PATH . '/public/uploads/co-' . $cid . '/profile_photos';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $filename = 'emp_' . $employeeId . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.webp';
                $dest = $dir . '/' . $filename;

                // Salva temporaneo, poi resize+webp
                $tmpDest = $f['tmp_name'];
                $useWebp = class_exists('ImageProcessor') && ImageProcessor::isAvailable();
                $ok = false;

                if ($useWebp) {
                    // Conversione foto profilo a WebP 256x256 (avatar grande)
                    $result = ImageProcessor::toWebp($tmpDest, $dest, 256, 82);
                    $ok = $result['success'];
                    if (!$ok) {
                        $error = 'Conversione foto fallita: ' . ($result['error'] ?? 'errore sconosciuto');
                    }
                } else {
                    // Fallback: salva originale
                    $ext = $allowed[$finfo];
                    $filename = 'emp_' . $employeeId . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
                    $dest = $dir . '/' . $filename;
                    $ok = move_uploaded_file($tmpDest, $dest);
                    if (!$ok) $error = 'Caricamento file fallito';
                }

                if ($ok) {
                    // Rimuovi vecchia foto
                    if (!empty($employee['photo_path'])) {
                        $old = ROOT_PATH . '/public/' . ltrim($employee['photo_path'], '/');
                        if (is_file($old)) @unlink($old);
                    }
                    $relPath = 'uploads/co-' . $cid . '/profile_photos/' . $filename;
                    try {
                        Database::update('employees', ['photo_path' => $relPath], 'id = ?', [$employeeId]);
                        if (method_exists('Auth', 'refreshEmployee')) {
                            Auth::refreshEmployee();
                        }
                        header('Location: profile.php?message=photo_updated');
                        exit;
                    } catch (Throwable $e) {
                        @unlink($dest);
                        $error = 'Errore salvataggio foto';
                    }
                }
            }
        }
    } elseif ($action === 'update_notifications') {
        $notifyEmail = !empty($_POST['notify_email']) ? 1 : 0;
        $notifyPush  = !empty($_POST['notify_push']) ? 1 : 0;
        Database::update('employees', [
            'notify_email' => $notifyEmail,
            'notify_push'  => $notifyPush,
        ], 'id = ?', [$employeeId]);
        header('Location: profile.php?message=notifications_updated');
        exit;
    } elseif ($action === 'remove_photo') {
        if (!empty($employee['photo_path'])) {
            $old = ROOT_PATH . '/public/' . ltrim($employee['photo_path'], '/');
            if (is_file($old)) @unlink($old);
        }
        Database::update('employees', ['photo_path' => null], 'id = ?', [$employeeId]);
        if (method_exists('Auth', 'refreshEmployee')) {
            Auth::refreshEmployee();
        }
        header('Location: profile.php?message=photo_removed');
        exit;
    }
}

if (isset($_GET['message'])) {
    $messages = [
        'updated' => 'Profilo aggiornato',
        'photo_updated' => 'Foto profilo aggiornata',
        'photo_removed' => 'Foto rimossa',
        'notifications_updated' => 'Preferenze notifiche aggiornate'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Ricarica dipendente fresco
$employee = Employee::getById($employeeId);
$photoUrl = !empty($employee['photo_path']) ? PUBLIC_URL . '/' . ltrim($employee['photo_path'], '/') : '';

$pageTitle = 'Il mio profilo';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<style>
.profile-page { display: flex; flex-direction: column; gap: 1rem; max-width: 640px; }
.profile-card {
    background: white; border-radius: 10px; padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.profile-card h2 { margin: 0 0 1rem; font-size: 1rem; color: #2d3748; }

.photo-section { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
.photo-preview {
    width: 96px; height: 96px; border-radius: 50%;
    background: #3182ce; color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 2rem; flex-shrink: 0;
    overflow: hidden; border: 2px solid #e2e8f0;
}
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.photo-controls { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 0.5rem; }
.photo-controls small { font-size: 0.7rem; color: #a0aec0; }

.form-row { margin-bottom: 0.75rem; }
.form-row label { display: block; font-size: 0.75rem; font-weight: 600; color: #4a5568; margin-bottom: 0.3rem; text-transform: uppercase; }
.form-row input[type="text"], .form-row input[type="email"] {
    width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #e2e8f0;
    border-radius: 6px; font-size: 0.9rem;
}
.form-row input[disabled] { background: #f7fafc; color: #718096; }

.profile-actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
.btn-inline { padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: 6px; border: 1px solid transparent; cursor: pointer; font-weight: 600; }
.btn-primary { background: #3182ce; color: white; }
.btn-primary:hover { background: #2c5282; }
.btn-secondary { background: #edf2f7; color: #2d3748; }
.btn-secondary:hover { background: #e2e8f0; }
.btn-danger-soft { background: #fff5f5; color: #c53030; border-color: #fed7d7; }
.btn-danger-soft:hover { background: #fed7d7; }

.profile-meta { font-size: 0.8rem; color: #4a5568; }
.profile-meta strong { color: #2d3748; }

input[type=file] { font-size: 0.85rem; }
</style>

<div class="profile-page">
    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="profile-card">
        <h2>Foto profilo</h2>
        <div class="photo-section">
            <div class="photo-preview">
                <?php if ($photoUrl): ?>
                    <img src="<?= e($photoUrl) ?>?v=<?= time() ?>" alt="Foto profilo" loading="lazy" decoding="async">
                <?php else: ?>
                    <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="photo-controls">
                <form method="POST" enctype="multipart/form-data">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <small>JPG, PNG, WEBP o GIF — max 3MB</small>
                    <div class="profile-actions">
                        <button type="submit" class="btn-inline btn-primary">Carica foto</button>
                        <?php if ($photoUrl): ?>
                            <button type="submit" class="btn-inline btn-danger-soft" form="removePhotoForm">Rimuovi</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($photoUrl): ?>
                    <form method="POST" id="removePhotoForm" onsubmit="return confirm('Rimuovere la foto?')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="remove_photo">
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="profile-card">
        <h2>Dati personali</h2>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="update_info">
            <div class="form-row">
                <label>Nome *</label>
                <input type="text" name="first_name" value="<?= e($employee['first_name']) ?>" required maxlength="50">
            </div>
            <div class="form-row">
                <label>Cognome *</label>
                <input type="text" name="last_name" value="<?= e($employee['last_name']) ?>" required maxlength="50">
            </div>
            <div class="form-row">
                <label>Email</label>
                <input type="email" value="<?= e($employee['email'] ?? '') ?>" disabled>
            </div>
            <div class="form-row">
                <label>Username</label>
                <input type="text" value="<?= e($employee['username']) ?>" disabled>
            </div>
            <div class="profile-actions">
                <button type="submit" class="btn-inline btn-primary">Salva modifiche</button>
            </div>
        </form>
    </div>

    <div class="profile-card">
        <h2>Notifiche</h2>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="update_notifications">
            <div style="display:flex;flex-direction:column;gap:.75rem;">
                <label style="display:flex;align-items:center;gap:.6rem;font-size:.9rem;">
                    <input type="checkbox" name="notify_email" value="1" <?= (int) ($employee['notify_email'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span><strong>Email</strong> &mdash; ricevi notifiche via email (nuovi documenti, comunicazioni, ecc.)</span>
                </label>
                <label style="display:flex;align-items:center;gap:.6rem;font-size:.9rem;">
                    <input type="checkbox" name="notify_push" value="1" <?= (int) ($employee['notify_push'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span><strong>Push</strong> &mdash; ricevi notifiche push sul browser/dispositivo</span>
                </label>
            </div>
            <div class="profile-actions">
                <button type="submit" class="btn-inline btn-primary">Salva preferenze</button>
            </div>
        </form>
    </div>

    <div class="profile-card">
        <h2>Informazioni</h2>
        <div class="profile-meta">
            <p><strong>Codice Fiscale:</strong> <?= e($employee['fiscal_code']) ?></p>
            <?php if (!empty($employee['department'])): ?>
                <p><strong>Reparto:</strong> <?= e($employee['department']) ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['position'])): ?>
                <p><strong>Posizione:</strong> <?= e($employee['position']) ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['hire_date'])): ?>
                <p><strong>Data assunzione:</strong> <?= formatDate($employee['hire_date']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Dati personali estesi - sola lettura per il dipendente (admin li gestisce)
    $hasExtra = !empty($employee['birth_date']) || !empty($employee['address'])
             || !empty($employee['job_level']) || !empty($employee['ral_amount'])
             || !empty($employee['monthly_salary']) || !empty($employee['iban']);
    ?>
    <?php if ($hasExtra): ?>
    <div class="profile-card">
        <h2>I miei dati anagrafici</h2>
        <div class="profile-meta">
            <?php if (!empty($employee['birth_date'])): ?>
                <p><strong>Data di nascita:</strong> <?= formatDate($employee['birth_date']) ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['address'])): ?>
                <p><strong>Indirizzo:</strong> <?= e($employee['address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['job_level'])): ?>
                <p><strong>Livello:</strong> <?= e($employee['job_level']) ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['ral_amount'])): ?>
                <p><strong>RAL annua:</strong> &euro; <?= number_format((float)$employee['ral_amount'], 2, ',', '.') ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['monthly_salary'])): ?>
                <p><strong>Retribuzione mensile:</strong> &euro; <?= number_format((float)$employee['monthly_salary'], 2, ',', '.') ?></p>
            <?php endif; ?>
            <?php if (!empty($employee['iban'])): ?>
                <p><strong>IBAN:</strong> <code><?= e($employee['iban']) ?></code></p>
            <?php endif; ?>
        </div>
        <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.5rem;">Per modificare questi dati contatta l'amministratore.</p>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
