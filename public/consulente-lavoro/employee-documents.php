<?php
/**
 * Documenti dipendente - vista globale Consulente del lavoro.
 * Permette di scegliere un dipendente e gestire i suoi documenti generici
 * (contratti, certificati, attestati). Riusa la classe EmployeeDocument.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$user = Auth::getUser();

// Download
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = EmployeeDocument::download($docId);
    if (!$result['success']) {
        http_response_code(403);
        echo htmlspecialchars($result['error']);
        exit;
    }
    $doc = $result['document'];
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
    setDownloadHeaders($downloadName, $doc['mime_type'], filesize($result['file_path']));
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

// Azioni POST
$status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';
    $employeeId = (int) ($_POST['employee_id'] ?? 0);

    if ($action === 'upload' && $employeeId) {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            $status = 'no_file';
        } else {
            $result = EmployeeDocument::upload($_FILES['document'], [
                'employee_id' => $employeeId,
                'name' => $_POST['name'] ?? '',
                'visible_to_employee' => !empty($_POST['visible_to_employee']) ? 1 : 0,
                'expires_on' => $_POST['expires_on'] ?? null
            ]);
            $status = $result['success'] ? 'uploaded' : 'error_' . $result['error'];
        }
    } elseif (in_array($action, ['rename', 'toggle_visibility', 'delete'], true)) {
        $docId = (int) ($_POST['document_id'] ?? 0);
        $current = $docId ? EmployeeDocument::getById($docId) : null;
        if ($current) {
            if ($action === 'delete') {
                $r = EmployeeDocument::delete($docId);
            } elseif ($action === 'rename') {
                $r = EmployeeDocument::update($docId, ['name' => $_POST['name'] ?? '']);
            } else {
                $r = EmployeeDocument::update($docId, ['visible_to_employee' => (int) $current['visible_to_employee'] === 1 ? 0 : 1]);
            }
            $status = $r['success'] ? ($action === 'delete' ? 'deleted' : 'updated') : 'error_' . ($r['error'] ?? '');
        }
    }
    header('Location: employee-documents.php?employee_id=' . $employeeId . '&ed_status=' . urlencode($status));
    exit;
}

$employees = Employee::getAll(true);
$selectedId = (int) ($_GET['employee_id'] ?? 0);
$selectedEmployee = $selectedId ? Employee::getById($selectedId) : null;
$documents = $selectedEmployee ? EmployeeDocument::getByEmployee($selectedId) : [];
$edStatus = $_GET['ed_status'] ?? '';

$pageTitle = 'Documenti dipendente';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.cl-doc-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 1rem; }
.cl-doc-card .header { padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.cl-doc-card .header h3 { margin: 0; font-size: 1rem; }
.cl-doc-card .body { padding: 1rem 1.25rem; }
.cl-select { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.9rem; }
.cl-doc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.5rem; border-bottom: 1px solid #f7fafc; }
.cl-doc-row:last-child { border-bottom: none; }
.cl-doc-row .icon { width: 32px; height: 32px; background: #bee3f8; color: #2c5282; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cl-doc-row .icon svg { width: 16px; height: 16px; }
.cl-doc-row .info { flex: 1; min-width: 0; }
.cl-doc-row .info .name { font-size: 0.85rem; color: #2d3748; font-weight: 500; }
.cl-doc-row .info .meta { font-size: 0.7rem; color: #a0aec0; }
.cl-doc-row .actions { display: flex; gap: 0.3rem; flex-shrink: 0; }
.cl-doc-row .actions button, .cl-doc-row .actions a { background: #edf2f7; border: none; padding: 0; width: 28px; height: 28px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; color: #3182ce; cursor: pointer; text-decoration: none; }
.cl-doc-row .actions button:hover, .cl-doc-row .actions a:hover { background: #3182ce; color: white; }
.cl-doc-row .actions .danger:hover { background: #e53e3e; }
.cl-doc-row .actions svg { width: 14px; height: 14px; }
.cl-badge { font-size: 0.65rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; flex-shrink: 0; }
.cl-badge.visible { background: #c6f6d5; color: #276749; }
.cl-badge.hidden { background: #e2e8f0; color: #718096; }
</style>

<div class="dashboard">
    <h2 style="margin: 0 0 1rem;">Documenti dipendente</h2>

    <?php if ($edStatus === 'uploaded'): ?><div class="alert alert-success">Documento caricato.</div>
    <?php elseif ($edStatus === 'updated'): ?><div class="alert alert-success">Documento aggiornato.</div>
    <?php elseif ($edStatus === 'deleted'): ?><div class="alert alert-success">Documento eliminato.</div>
    <?php elseif (strpos((string) $edStatus, 'error') === 0): ?><div class="alert alert-danger">Errore: <?= htmlspecialchars(substr((string) $edStatus, 6)) ?></div>
    <?php endif; ?>

    <!-- Selezione dipendente -->
    <div class="cl-doc-card">
        <div class="body">
            <form method="GET">
                <label style="display:block;font-size:.75rem;color:#4a5568;font-weight:600;margin-bottom:.3rem;text-transform:uppercase;">Seleziona dipendente</label>
                <select name="employee_id" class="cl-select" onchange="this.form.submit()">
                    <option value="">— Scegli un dipendente —</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= $selectedId === (int) $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']) ?>
                            <?= !empty($emp['fiscal_code']) ? ' — ' . htmlspecialchars($emp['fiscal_code']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($selectedEmployee): ?>
        <div class="cl-doc-card">
            <div class="header">
                <h3>Documenti di <?= htmlspecialchars($selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name']) ?></h3>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.document.getElementById('cl-upload-modal').style.display='flex';">Carica documento</button>
            </div>
            <div class="body">
                <?php if (empty($documents)): ?>
                    <p style="color:#a0aec0;text-align:center;padding:2rem;">Nessun documento caricato.</p>
                <?php else: ?>
                    <?php foreach ($documents as $d): ?>
                        <div class="cl-doc-row">
                            <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg></div>
                            <div class="info">
                                <span class="name"><?= htmlspecialchars($d['name']) ?></span>
                                <span class="meta">
                                    <?= number_format($d['file_size'] / 1024, 1) ?> KB · <?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?>
                                    <?php if ($d['expires_on']): ?> · scade <?= htmlspecialchars(date('d/m/Y', strtotime($d['expires_on']))) ?><?php endif; ?>
                                </span>
                            </div>
                            <span class="cl-badge <?= $d['visible_to_employee'] ? 'visible' : 'hidden' ?>"><?= $d['visible_to_employee'] ? 'Visibile' : 'Nascosto' ?></span>
                            <div class="actions">
                                <a href="?download=<?= (int) $d['id'] ?>" title="Scarica"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></a>
                                <form method="post" style="display:inline;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_visibility">
                                    <input type="hidden" name="employee_id" value="<?= $selectedId ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" title="<?= $d['visible_to_employee'] ? 'Nascondi' : 'Rendi visibile' ?>">
                                        <?php if ($d['visible_to_employee']): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                        <?php else: ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27z"/></svg>
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <button type="button" title="Rinomina"
                                        onclick="var n=prompt('Nuovo nome:', <?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES, 'UTF-8') ?>); if(n){var f=window.document.getElementById('cl-rename-<?= (int) $d['id'] ?>'); f.querySelector('input[name=name]').value=n; f.submit();}">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <form id="cl-rename-<?= (int) $d['id'] ?>" method="post" style="display:none;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="employee_id" value="<?= $selectedId ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                    <input type="hidden" name="name" value="">
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente?');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="employee_id" value="<?= $selectedId ?>">
                                    <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" class="danger" title="Elimina"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modale upload -->
        <div id="cl-upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:2rem;border-radius:10px;max-width:480px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.3);">
                <h3 style="margin-top:0;">Carica documento</h3>
                <form method="post" enctype="multipart/form-data">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="employee_id" value="<?= $selectedId ?>">
                    <div style="margin-bottom:1rem;">
                        <label style="display:block;font-weight:600;margin-bottom:.25rem;">Nome documento *</label>
                        <input type="text" name="name" required maxlength="255" class="form-control" style="width:100%;" placeholder="es. Contratto 2026">
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="display:block;font-weight:600;margin-bottom:.25rem;">File *</label>
                        <input type="file" name="document" required class="form-control" style="width:100%;">
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="display:block;font-weight:600;margin-bottom:.25rem;">Scadenza (opzionale)</label>
                        <input type="date" name="expires_on" class="form-control" style="width:100%;">
                    </div>
                    <div style="margin-bottom:1.25rem;">
                        <label><input type="checkbox" name="visible_to_employee" value="1"> Rendi visibile al dipendente</label>
                    </div>
                    <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="window.document.getElementById('cl-upload-modal').style.display='none';">Annulla</button>
                        <button type="submit" class="btn btn-primary">Carica</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
