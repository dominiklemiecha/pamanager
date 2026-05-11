<?php
/**
 * Gestione Commercialisti - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id = isset($_POST['id']) && $_POST['id'] !== ''
    ? (int) $_POST['id']
    : (isset($_GET['id']) ? (int) $_GET['id'] : null);
$message = '';
$error = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $postAction = $_POST['action'] ?? '';

    switch ($postAction) {
        case 'create':
            $result = User::create([
                'username' => $_POST['username'] ?? '',
                'password' => $_POST['password'] ?? '',
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'role' => 'accountant'
            ]);

            if ($result['success']) {
                header('Location: accountant.php?message=created');
                exit;
            }
            $error = $result['error'];
            $action = 'new';
            break;

        case 'update':
            if ($id) {
                $result = User::update($id, [
                    'username' => $_POST['username'] ?? '',
                    'name' => $_POST['name'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'is_active' => isset($_POST['is_active'])
                ]);

                if ($result['success']) {
                    header('Location: accountant.php?message=updated');
                    exit;
                }
                $error = $result['error'];
                $action = 'edit';
            }
            break;

        case 'delete':
            if ($id) {
                $result = User::delete($id);
                if ($result['success']) {
                    header('Location: accountant.php?message=deleted');
                    exit;
                }
                $error = $result['error'];
            }
            break;

        case 'reset_password':
            if ($id) {
                $result = User::resetPassword($id);
                if ($result['success']) {
                    $message = 'Nuova password: <code>' . e($result['password']) . '</code><br>Comunicala al commercialista.';
                } else {
                    $error = $result['error'];
                }
            }
            break;

        case 'toggle_active':
            if ($id) {
                $accountant = User::getById($id);
                if ($accountant) {
                    $result = $accountant['is_active']
                        ? User::deactivate($id)
                        : User::activate($id);
                    if ($result['success']) {
                        header('Location: accountant.php?message=updated');
                        exit;
                    }
                    $error = $result['error'];
                }
            }
            break;
    }
}

// Messaggi di conferma
if (isset($_GET['message'])) {
    $messages = [
        'created' => 'Commercialista creato con successo',
        'updated' => 'Commercialista aggiornato con successo',
        'deleted' => 'Commercialista eliminato con successo'
    ];
    $message = $message ?: ($messages[$_GET['message']] ?? '');
}

// Carica dati
$accountant = null;
$accountants = [];

if ($action === 'list') {
    $accountants = User::getAccountants();
} elseif (($action === 'edit' || $action === 'view') && $id) {
    $accountant = User::getById($id);
    if (!$accountant || $accountant['role'] !== 'accountant') {
        header('Location: accountant.php?error=not_found');
        exit;
    }
}

$pageTitle = $action === 'new' ? 'Nuovo Commercialista'
           : ($action === 'edit' ? 'Modifica Commercialista'
           : 'Gestione Commercialisti');
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="admin-page">
    <div class="page-header">
        <h1>Gestione Commercialisti</h1>
        <?php if ($action === 'list'): ?>
            <a href="?action=new" class="btn btn-primary">Nuovo Commercialista</a>
        <?php else: ?>
            <a href="accountant.php" class="btn btn-secondary">Torna alla Lista</a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Lista Commercialisti -->
        <?php if (empty($accountants)): ?>
            <p class="empty-state">Nessun commercialista registrato</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table responsive">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Ultimo Accesso</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accountants as $acc): ?>
                            <tr class="<?= !$acc['is_active'] ? 'inactive' : '' ?>">
                                <td data-label="Username"><code><?= e($acc['username']) ?></code></td>
                                <td data-label="Nome"><?= e($acc['name']) ?></td>
                                <td data-label="Email"><?= e($acc['email'] ?? '-') ?></td>
                                <td data-label="Ultimo Accesso"><?= $acc['last_login'] ? formatDateTime($acc['last_login']) : 'Mai' ?></td>
                                <td data-label="Stato">
                                    <span class="badge <?= $acc['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $acc['is_active'] ? 'Attivo' : 'Disattivato' ?>
                                    </span>
                                </td>
                                <td data-label="Azioni" class="actions">
                                    <a href="?action=edit&id=<?= $acc['id'] ?>" class="btn btn-sm">Modifica</a>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Resettare la password?')">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">Reset</button>
                                    </form>
                                    <form method="POST" class="inline-form">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-<?= $acc['is_active'] ? 'danger' : 'success' ?>">
                                            <?= $acc['is_active'] ? 'Off' : 'On' ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare definitivamente <?= e($acc['username']) ?>? Operazione IRREVERSIBILE.')">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $acc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <!-- Form Commercialista -->
        <form method="POST" class="form-card">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $action === 'new' ? 'create' : 'update' ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required
                           minlength="3" maxlength="50" pattern="[a-zA-Z0-9_\.]+"
                           value="<?= e($accountant['username'] ?? $_POST['username'] ?? '') ?>">
                    <small>Solo lettere, numeri, underscore e punti</small>
                </div>

                <?php if ($action === 'new'): ?>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required
                               minlength="8" maxlength="100">
                        <small>Minimo 8 caratteri, con maiuscole, minuscole, numeri e caratteri speciali</small>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="name">Nome Completo *</label>
                    <input type="text" id="name" name="name" required maxlength="100"
                           value="<?= e($accountant['name'] ?? $_POST['name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="100"
                           value="<?= e($accountant['email'] ?? $_POST['email'] ?? '') ?>">
                </div>

                <?php if ($action === 'edit'): ?>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" <?= $accountant['is_active'] ? 'checked' : '' ?>>
                            Account attivo
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $action === 'new' ? 'Crea Commercialista' : 'Salva Modifiche' ?>
                </button>
                <a href="accountant.php" class="btn btn-secondary">Annulla</a>
            </div>
        </form>

        <?php if ($action === 'new'): ?>
            <div class="info-box">
                <h4>Requisiti Password</h4>
                <ul>
                    <li>Almeno 8 caratteri</li>
                    <li>Almeno una lettera maiuscola</li>
                    <li>Almeno una lettera minuscola</li>
                    <li>Almeno un numero</li>
                    <li>Almeno un carattere speciale (!@#$%^&*...)</li>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
