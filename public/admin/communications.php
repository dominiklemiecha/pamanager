<?php
/**
 * Gestione Comunicazioni - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$message = '';
$error = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $postAction = $_POST['action'] ?? '';

    switch ($postAction) {
        case 'create':
            $result = Communication::create([
                'title' => $_POST['title'] ?? '',
                'content' => $_POST['content'] ?? '',
                'priority' => $_POST['priority'] ?? 'normal',
                'is_published' => isset($_POST['is_published']),
                'publish_date' => $_POST['publish_date'] ?? date('Y-m-d'),
                'expire_date' => $_POST['expire_date'] ?: null
            ]);

            if ($result['success']) {
                header('Location: communications.php?message=created');
                exit;
            }
            $error = $result['error'];
            $action = 'new';
            break;

        case 'update':
            if ($id) {
                $result = Communication::update($id, [
                    'title' => $_POST['title'] ?? '',
                    'content' => $_POST['content'] ?? '',
                    'priority' => $_POST['priority'] ?? 'normal',
                    'is_published' => isset($_POST['is_published']),
                    'publish_date' => $_POST['publish_date'] ?? '',
                    'expire_date' => $_POST['expire_date'] ?: null
                ]);

                if ($result['success']) {
                    header('Location: communications.php?message=updated');
                    exit;
                }
                $error = $result['error'];
                $action = 'edit';
            }
            break;

        case 'delete':
            if ($id) {
                $result = Communication::delete($id);
                if ($result['success']) {
                    header('Location: communications.php?message=deleted');
                    exit;
                }
                $error = $result['error'];
            }
            break;

        case 'toggle_publish':
            if ($id) {
                $result = Communication::togglePublish($id);
                if ($result['success']) {
                    header('Location: communications.php?message=updated');
                    exit;
                }
                $error = $result['error'];
            }
            break;
    }
}

// Messaggi di conferma
if (isset($_GET['message'])) {
    $messages = [
        'created' => 'Comunicazione creata con successo',
        'updated' => 'Comunicazione aggiornata con successo',
        'deleted' => 'Comunicazione eliminata con successo'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Carica dati in base all'azione
$communication = null;
$communications = [];
$search = $_GET['search'] ?? '';
$includePast = isset($_GET['include_past']);

if ($action === 'list') {
    $communications = Communication::getAll($includePast, $search);
} elseif ($action === 'edit' && $id) {
    $communication = Communication::getById($id);
    if (!$communication) {
        header('Location: communications.php?error=not_found');
        exit;
    }
} elseif ($action === 'view' && $id) {
    $communication = Communication::getById($id);
    if (!$communication) {
        header('Location: communications.php?error=not_found');
        exit;
    }
    $readStats = Communication::getReadStats($id);
}

$pageTitle = $action === 'new' ? 'Nuova Comunicazione'
           : ($action === 'edit' ? 'Modifica Comunicazione'
           : ($action === 'view' ? 'Dettaglio Comunicazione'
           : 'Gestione Comunicazioni'));
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="admin-page">
    <div class="page-header">
        <h1>Gestione Comunicazioni</h1>
        <?php if ($action === 'list'): ?>
            <a href="?action=new" class="btn btn-primary">Nuova Comunicazione</a>
        <?php else: ?>
            <a href="communications.php" class="btn btn-secondary">Torna alla Lista</a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Lista Comunicazioni -->
        <div class="filters">
            <form method="GET" class="search-form">
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Cerca per titolo o contenuto...">
                <button type="submit" class="btn btn-secondary">Cerca</button>
                <?php if ($search): ?>
                    <a href="communications.php" class="btn btn-link">Reset</a>
                <?php endif; ?>
            </form>
            <label class="checkbox-label">
                <input type="checkbox" name="include_past" <?= $includePast ? 'checked' : '' ?>
                       onchange="window.location='?include_past=' + (this.checked ? '1' : '')">
                Includi scadute
            </label>
        </div>

        <?php if (empty($communications)): ?>
            <p class="empty-state">Nessuna comunicazione trovata</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table responsive">
                    <thead>
                        <tr>
                            <th>Titolo</th>
                            <th>Priorità</th>
                            <th>Pubblicazione</th>
                            <th>Scadenza</th>
                            <th>Letture</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($communications as $comm): ?>
                            <tr>
                                <td data-label="Titolo">
                                    <a href="?action=view&id=<?= $comm['id'] ?>">
                                        <?= e($comm['title']) ?>
                                    </a>
                                </td>
                                <td data-label="Priorità">
                                    <span class="badge priority-<?= e($comm['priority']) ?>">
                                        <?= e(Communication::PRIORITIES[$comm['priority']] ?? $comm['priority']) ?>
                                    </span>
                                </td>
                                <td data-label="Pubblicazione"><?= formatDate($comm['publish_date']) ?></td>
                                <td data-label="Scadenza"><?= $comm['expire_date'] ? formatDate($comm['expire_date']) : '-' ?></td>
                                <td data-label="Letture">
                                    <?= $comm['read_count'] ?>/<?= $comm['total_employees'] ?>
                                    <small>(<?= $comm['total_employees'] > 0 ? round(($comm['read_count'] / $comm['total_employees']) * 100) : 0 ?>%)</small>
                                </td>
                                <td data-label="Stato">
                                    <span class="badge <?= $comm['is_published'] ? 'badge-success' : 'badge-warning' ?>">
                                        <?= $comm['is_published'] ? 'Pubblicata' : 'Bozza' ?>
                                    </span>
                                </td>
                                <td data-label="Azioni" class="actions">
                                    <a href="?action=edit&id=<?= $comm['id'] ?>" class="btn btn-sm">Modifica</a>
                                    <form method="POST" class="inline-form">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_publish">
                                        <input type="hidden" name="id" value="<?= $comm['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-<?= $comm['is_published'] ? 'warning' : 'success' ?>">
                                            <?= $comm['is_published'] ? 'Nascondi' : 'Pubblica' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <!-- Form Comunicazione -->
        <form method="POST" class="form-card">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $action === 'new' ? 'create' : 'update' ?>">

            <div class="form-group">
                <label for="title">Titolo *</label>
                <input type="text" id="title" name="title" required maxlength="255"
                       value="<?= e($communication['title'] ?? $_POST['title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="content">Contenuto *</label>
                <textarea id="content" name="content" required rows="10"><?= e($communication['content'] ?? $_POST['content'] ?? '') ?></textarea>
                <small>Puoi usare formattazione HTML di base (grassetto, corsivo, liste)</small>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="priority">Priorità</label>
                    <select id="priority" name="priority">
                        <?php foreach (Communication::PRIORITIES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($communication['priority'] ?? $_POST['priority'] ?? 'normal') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="publish_date">Data Pubblicazione *</label>
                    <input type="date" id="publish_date" name="publish_date" required
                           value="<?= e($communication['publish_date'] ?? $_POST['publish_date'] ?? date('Y-m-d')) ?>">
                </div>

                <div class="form-group">
                    <label for="expire_date">Data Scadenza</label>
                    <input type="date" id="expire_date" name="expire_date"
                           value="<?= e($communication['expire_date'] ?? $_POST['expire_date'] ?? '') ?>">
                    <small>Lascia vuoto per nessuna scadenza</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_published"
                               <?= ($communication['is_published'] ?? $_POST['is_published'] ?? true) ? 'checked' : '' ?>>
                        Pubblica immediatamente
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $action === 'new' ? 'Crea Comunicazione' : 'Salva Modifiche' ?>
                </button>
                <a href="communications.php" class="btn btn-secondary">Annulla</a>
            </div>
        </form>

    <?php elseif ($action === 'view' && $communication): ?>
        <!-- Dettaglio Comunicazione -->
        <div class="detail-card">
            <div class="detail-header">
                <h2><?= e($communication['title']) ?></h2>
                <div class="badges">
                    <span class="badge priority-<?= e($communication['priority']) ?>">
                        <?= e(Communication::PRIORITIES[$communication['priority']] ?? $communication['priority']) ?>
                    </span>
                    <span class="badge <?= $communication['is_published'] ? 'badge-success' : 'badge-warning' ?>">
                        <?= $communication['is_published'] ? 'Pubblicata' : 'Bozza' ?>
                    </span>
                </div>
            </div>

            <div class="detail-content">
                <?= nl2br(e($communication['content'])) ?>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <label>Autore</label>
                    <span><?= e($communication['author_name']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Data Pubblicazione</label>
                    <span><?= formatDate($communication['publish_date']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Data Scadenza</label>
                    <span><?= $communication['expire_date'] ? formatDate($communication['expire_date']) : 'Nessuna' ?></span>
                </div>
                <div class="detail-item">
                    <label>Creata il</label>
                    <span><?= formatDateTime($communication['created_at']) ?></span>
                </div>
            </div>

            <div class="detail-actions">
                <a href="?action=edit&id=<?= $communication['id'] ?>" class="btn btn-primary">Modifica</a>
                <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare questa comunicazione?')">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $communication['id'] ?>">
                    <button type="submit" class="btn btn-danger">Elimina</button>
                </form>
            </div>

            <h3>Statistiche Lettura</h3>
            <div class="read-stats">
                <div class="stat-box">
                    <span class="stat-number"><?= $readStats['read_count'] ?></span>
                    <span class="stat-label">Letture</span>
                </div>
                <div class="stat-box">
                    <span class="stat-number"><?= $readStats['unread_count'] ?></span>
                    <span class="stat-label">Non lette</span>
                </div>
                <div class="stat-box">
                    <span class="stat-number"><?= $readStats['percentage'] ?>%</span>
                    <span class="stat-label">Percentuale</span>
                </div>
            </div>

            <?php if (!empty($readStats['readers'])): ?>
                <h4>Chi ha letto</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dipendente</th>
                            <th>Data Lettura</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($readStats['readers'] as $reader): ?>
                            <tr>
                                <td><?= e($reader['last_name'] . ' ' . $reader['first_name']) ?></td>
                                <td><?= formatDateTime($reader['read_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
