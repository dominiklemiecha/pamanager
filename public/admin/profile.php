<?php
/**
 * Profilo Admin/Consulente/Accountant - modifica nome, email, password
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser(['admin', 'accountant', 'consulente_lavoro']);

$user = Auth::getUser();
$userId = (int) $user['id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            $error = 'Nome obbligatorio';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email non valida';
        } else {
            try {
                Database::update('users',
                    ['name' => $name, 'email' => ($email !== '' ? $email : null)],
                    'id = ?', [$userId]
                );
                Auth::refreshUser();
                header('Location: profile.php?message=updated');
                exit;
            } catch (Throwable $e) {
                $error = 'Errore aggiornamento: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'upload_photo') {
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Foto mancante o errore upload';
        } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
            $error = 'File troppo grande (max 3MB)';
        } else {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp'];
            if (!in_array($ext, $allowed, true)) {
                $error = 'Solo immagini JPG, PNG, WEBP';
            } else {
                $dir = ROOT_PATH . '/public/uploads/users';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $useWebp = class_exists('ImageProcessor') && ImageProcessor::isAvailable();
                $filename = 'u' . $userId . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . ($useWebp ? '.webp' : '.' . $ext);
                $dest = $dir . '/' . $filename;
                $ok = false;
                if ($useWebp) {
                    $result = ImageProcessor::toWebp($_FILES['photo']['tmp_name'], $dest, 256, 82);
                    $ok = $result['success'];
                    if (!$ok) $error = 'Conversione foto fallita';
                } else {
                    $ok = move_uploaded_file($_FILES['photo']['tmp_name'], $dest);
                    if (!$ok) $error = 'Caricamento file fallito';
                }
                if ($ok) {
                    $old = $user['photo_path'] ?? '';
                    $relPath = 'uploads/users/' . $filename;
                    try {
                        Database::update('users', ['photo_path' => $relPath], 'id = ?', [$userId]);
                        Auth::refreshUser();
                        if ($old) {
                            $oldAbs = ROOT_PATH . '/public/' . ltrim($old, '/');
                            if (is_file($oldAbs)) @unlink($oldAbs);
                        }
                        header('Location: profile.php?message=photo');
                        exit;
                    } catch (Throwable $e) {
                        @unlink($dest);
                        $error = 'Errore salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'remove_photo') {
        $old = $user['photo_path'] ?? '';
        try {
            Database::update('users', ['photo_path' => null], 'id = ?', [$userId]);
            Auth::refreshUser();
            if ($old) {
                $oldAbs = ROOT_PATH . '/public/' . ltrim($old, '/');
                if (is_file($oldAbs)) @unlink($oldAbs);
            }
            header('Location: profile.php?message=photo_removed');
            exit;
        } catch (Throwable $e) {
            $error = 'Errore rimozione foto';
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $error = 'Le password non coincidono';
        } else {
            $result = Auth::changeUserPassword($userId, $current, $new);
            if ($result['success']) {
                header('Location: profile.php?message=password');
                exit;
            }
            $error = $result['error'] ?? implode(' ', $result['errors'] ?? ['Errore cambio password']);
        }
    }
}

if (($_GET['message'] ?? '') === 'updated')  $message = 'Profilo aggiornato.';
if (($_GET['message'] ?? '') === 'password') $message = 'Password cambiata.';
if (($_GET['message'] ?? '') === 'photo')    $message = 'Foto profilo aggiornata.';
if (($_GET['message'] ?? '') === 'photo_removed') $message = 'Foto profilo rimossa.';

$pageTitle = 'Il mio profilo';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<style>
.profile-grid {
    display: grid;
    grid-template-columns: 280px 1fr 1fr;
    gap: var(--sp-4);
    align-items: start;
}
.profile-photo-wrap {
    width: 140px; height: 140px;
    border-radius: 50%;
    margin: 0 auto;
    overflow: hidden;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    display: flex; align-items: center; justify-content: center;
    border: 3px solid var(--surface);
    box-shadow: 0 4px 16px rgba(11,58,164,0.18);
}
.profile-photo-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.profile-photo-initials { color: white; font-family: 'Space Grotesk', sans-serif; font-size: 48px; font-weight: 700; }

@media (max-width: 1100px) {
    .profile-grid { grid-template-columns: 280px 1fr; }
    .profile-photo-card { grid-row: span 2; }
}
@media (max-width: 720px) {
    .profile-grid { grid-template-columns: 1fr; }
    .profile-photo-card { grid-row: auto; }
    .profile-photo-wrap { width: 120px; height: 120px; }
    .profile-photo-initials { font-size: 40px; }
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom: var(--sp-4);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: var(--sp-4);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="profile-grid">
    <div class="card profile-photo-card">
        <div class="card-h"><h3>Foto profilo</h3></div>
        <div class="card-b" style="text-align:center;">
            <?php
            $__userPhoto = $user['photo_path'] ?? '';
            $__initials = '';
            foreach (preg_split('/\s+/', trim($user['name'] ?? '')) as $p) {
                if ($p !== '') $__initials .= mb_substr($p, 0, 1);
                if (mb_strlen($__initials) >= 2) break;
            }
            $__initials = mb_strtoupper($__initials ?: 'U');
            ?>
            <div class="profile-photo-wrap">
                <?php if ($__userPhoto): ?>
                    <img src="<?= htmlspecialchars($baseUrl . '/' . ltrim($__userPhoto, '/')) ?>" alt="">
                <?php else: ?>
                    <span class="profile-photo-initials"><?= htmlspecialchars($__initials) ?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top: 14px;">
                <form method="POST" enctype="multipart/form-data" style="margin:0;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" name="photo" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.submit()">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('profilePhotoInput').click()">
                        <?= $__userPhoto ? 'Cambia foto' : 'Carica foto' ?>
                    </button>
                </form>
                <?php if ($__userPhoto): ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Rimuovere la foto?')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="remove_photo">
                        <button type="submit" class="btn btn-secondary">Rimuovi</button>
                    </form>
                <?php endif; ?>
            </div>
            <p style="font-size: 11px; color: var(--muted); margin: 10px 0 0;">JPG, PNG o WEBP · max <?= round(MAX_FILE_SIZE/1024/1024) ?>MB</p>
        </div>
    </div>

    <div class="card">
        <div class="card-h"><h3>Informazioni account</h3></div>
        <div class="card-b">
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update_info">

                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="name">Nome visualizzato</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="nome@azienda.it">
                </div>
                <button type="submit" class="btn btn-primary">Salva modifiche</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-h"><h3>Cambia password</h3></div>
        <div class="card-b">
            <form method="POST" action="profile.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="current_password">Password attuale</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="new_password">Nuova password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <div class="form-group" style="margin-bottom: var(--sp-3);">
                    <label class="form-label" for="confirm_password">Conferma nuova password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required autocomplete="new-password" minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">Cambia password</button>
            </form>
        </div>
    </div>
</div>

<div class="card" style="margin-top: var(--sp-4); border-color: var(--danger-200, #fecaca);">
    <div class="card-h"><h3 style="color: var(--danger-700);">Sessione</h3></div>
    <div class="card-b">
        <p style="font-size: var(--text-sm); color: var(--muted); margin: 0 0 var(--sp-3);">
            Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.
        </p>
        <a href="<?= $baseUrl ?>/auth/logout.php" class="btn btn-danger">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
            Esci dall'account
        </a>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
