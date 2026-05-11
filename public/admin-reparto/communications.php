<?php
/**
 * Comunicazioni Reparto - Admin Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = $user['department_id'] ?? null;

if (!$departmentId) {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<h2>Nessun reparto assegnato</h2>';
    echo '<p>Contatta l\'amministratore per essere assegnato a un reparto.</p>';
    echo '</div>';
    exit;
}

$department = Department::getById($departmentId);
$message = '';
$error = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $data = [
                'title' => $_POST['title'] ?? '',
                'content' => $_POST['content'] ?? '',
                'priority' => $_POST['priority'] ?? 'normal',
                'publish_date' => $_POST['publish_date'] ?? date('Y-m-d'),
                'expire_date' => $_POST['expire_date'] ?? null,
                'is_published' => isset($_POST['is_published']),
                'department_id' => $departmentId // Sempre per il proprio reparto
            ];

            $result = Communication::create($data);
            if ($result['success']) {
                // Upload allegato se presente
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    Communication::uploadAttachment($_FILES['attachment'], $result['id']);
                }
                header('Location: communications.php?message=created');
                exit;
            }
            $error = $result['error'] ?? 'Errore durante la creazione';
            error_log('Communication create error: ' . print_r($result, true));
            break;

        case 'toggle':
            $commId = (int) ($_POST['communication_id'] ?? 0);
            $comm = Communication::getById($commId);
            // Verifica che sia una comunicazione del proprio reparto
            if ($comm && $comm['department_id'] == $departmentId) {
                $result = Communication::togglePublish($commId);
                if ($result['success']) {
                    header('Location: communications.php?message=toggled');
                    exit;
                }
                $error = $result['error'];
            } else {
                $error = 'Non puoi modificare questa comunicazione';
            }
            break;

        case 'delete':
            $commId = (int) ($_POST['communication_id'] ?? 0);
            $comm = Communication::getById($commId);
            // Verifica che sia una comunicazione del proprio reparto
            if ($comm && $comm['department_id'] == $departmentId) {
                $result = Communication::delete($commId);
                if ($result['success']) {
                    header('Location: communications.php?message=deleted');
                    exit;
                }
                $error = $result['error'];
            } else {
                $error = 'Non puoi eliminare questa comunicazione';
            }
            break;
    }
}

// Messaggi
if (isset($_GET['message'])) {
    $messages = [
        'created' => 'Comunicazione creata con successo',
        'toggled' => 'Stato comunicazione aggiornato',
        'deleted' => 'Comunicazione eliminata'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Carica comunicazioni (del proprio reparto + globali)
$communications = Communication::getAll(true, '', $departmentId);

$pageTitle = 'Comunicazioni - ' . htmlspecialchars($department['name']);
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
?>

<style>
.comm-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Form Section */
.form-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.form-header {
    background: var(--primary-color);
    padding: 1rem 1.25rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-header h2 {
    margin: 0;
    font-size: 1rem;
    color: white;
}

.form-header svg {
    width: 20px;
    height: 20px;
}

.form-body {
    padding: 1.25rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.form-grid .form-group {
    margin: 0;
}

.form-grid .form-group.full {
    grid-column: 1 / -1;
}

.form-grid label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
}

.form-grid input,
.form-grid select,
.form-grid textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.85rem;
}

.form-actions {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #edf2f7;
    display: flex;
    gap: 0.5rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.85rem;
    color: #4a5568;
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}

/* Communications List */
.comm-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.comm-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.comm-header h2 {
    margin: 0;
    font-size: 1rem;
    color: #2d3748;
}

.comm-count {
    font-size: 0.75rem;
    color: #718096;
    background: #edf2f7;
    padding: 0.25rem 0.6rem;
    border-radius: 10px;
}

.comm-list {
    padding: 0;
}

.comm-item {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #f7fafc;
    transition: background 0.2s;
}

.comm-item:last-child {
    border-bottom: none;
}

.comm-item:hover {
    background: #f7fafc;
}

.comm-item-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.comm-title {
    font-weight: 600;
    color: #2d3748;
    font-size: 0.95rem;
    flex: 1;
}

.comm-badge {
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 600;
}

.comm-badge.global { background: #d4edda; color: #155724; }
.comm-badge.dept { background: #cce5ff; color: #004085; }
.comm-badge.published { background: #c6f6d5; color: #276749; }
.comm-badge.draft { background: #fed7d7; color: #c53030; }
.comm-badge.priority-high { background: #f8d7da; color: #721c24; }
.comm-badge.priority-normal { background: #fff3cd; color: #856404; }
.comm-badge.priority-low { background: #e2e8f0; color: #4a5568; }

.comm-content {
    font-size: 0.85rem;
    color: #718096;
    margin-bottom: 0.5rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.comm-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.75rem;
    color: #a0aec0;
}

.comm-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.comm-actions .btn-sm {
    padding: 0.35rem 0.6rem;
    font-size: 0.75rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #a0aec0;
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="comm-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Form Nuova Comunicazione -->
    <div class="form-section">
        <div class="form-header">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
            </svg>
            <h2>Nuova Comunicazione per il Reparto</h2>
        </div>
        <div class="form-body">
            <form method="POST" enctype="multipart/form-data">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="form-group full">
                        <label for="title">Titolo *</label>
                        <input type="text" id="title" name="title" required maxlength="255"
                               placeholder="Titolo della comunicazione...">
                    </div>

                    <div class="form-group full">
                        <label for="content">Contenuto *</label>
                        <textarea id="content" name="content" rows="4" required
                                  placeholder="Scrivi il contenuto della comunicazione..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="priority">Priorita</label>
                        <select id="priority" name="priority">
                            <?php foreach (Communication::PRIORITIES as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $key === 'normal' ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="publish_date">Data Pubblicazione</label>
                        <input type="date" id="publish_date" name="publish_date"
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label for="expire_date">Data Scadenza</label>
                        <input type="date" id="expire_date" name="expire_date">
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_published" checked>
                            Pubblica subito
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="attachment">Allegato</label>
                        <input type="file" id="attachment" name="attachment"
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Crea Comunicazione</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista Comunicazioni -->
    <div class="comm-section">
        <div class="comm-header">
            <h2>Comunicazioni Visibili al Reparto</h2>
            <span class="comm-count"><?= count($communications) ?> comunicazioni</span>
        </div>

        <?php if (empty($communications)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                <p>Nessuna comunicazione</p>
            </div>
        <?php else: ?>
            <div class="comm-list">
                <?php foreach ($communications as $comm):
                    $isOwn = $comm['department_id'] == $departmentId;
                ?>
                    <div class="comm-item">
                        <div class="comm-item-header">
                            <span class="comm-title"><?= e($comm['title']) ?></span>
                            <?php if ($comm['is_global']): ?>
                                <span class="comm-badge global">Globale</span>
                            <?php else: ?>
                                <span class="comm-badge dept"><?= e($comm['department_code'] ?? 'Reparto') ?></span>
                            <?php endif; ?>
                            <span class="comm-badge <?= $comm['is_published'] ? 'published' : 'draft' ?>">
                                <?= $comm['is_published'] ? 'Pubblicata' : 'Bozza' ?>
                            </span>
                            <span class="comm-badge priority-<?= $comm['priority'] ?>">
                                <?= e(Communication::PRIORITIES[$comm['priority']] ?? $comm['priority']) ?>
                            </span>
                        </div>
                        <div class="comm-content"><?= e(strip_tags($comm['content'])) ?></div>
                        <div class="comm-meta">
                            <span>Creata da: <?= e($comm['author_name']) ?></span>
                            <span>Pubblicazione: <?= formatDate($comm['publish_date']) ?></span>
                            <?php if ($comm['expire_date']): ?>
                                <span>Scade: <?= formatDate($comm['expire_date']) ?></span>
                            <?php endif; ?>
                            <span>Letta: <?= $comm['read_count'] ?>/<?= $comm['total_employees'] ?></span>
                        </div>
                        <?php if ($isOwn): ?>
                            <div class="comm-actions">
                                <form method="POST" style="margin:0; display:inline;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="communication_id" value="<?= $comm['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">
                                        <?= $comm['is_published'] ? 'Nascondi' : 'Pubblica' ?>
                                    </button>
                                </form>
                                <form method="POST" style="margin:0; display:inline;"
                                      onsubmit="return confirm('Eliminare questa comunicazione?')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="communication_id" value="<?= $comm['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
