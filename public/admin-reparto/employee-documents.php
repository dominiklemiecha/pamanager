<?php
/**
 * Gestione Documenti Dipendente - Admin Reparto.
 * Lista + azioni (upload, rename, toggle, delete, download) in unico file.
 * Scope: solo dipendenti del proprio reparto.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = (int) ($user['department_id'] ?? 0);
if (!$departmentId) {
    http_response_code(403);
    exit('Nessun reparto assegnato');
}

function ed_assert_in_dept(int $employeeId, int $departmentId): array
{
    $emp = Employee::getById($employeeId);
    if (!$emp || (int) ($emp['department_id'] ?? 0) !== $departmentId) {
        if (class_exists('AuditLog')) {
            AuditLog::logUnauthorizedAccess('employee_document', [
                'employee_id' => $employeeId,
                'reason' => 'cross_department'
            ]);
        }
        http_response_code(403);
        exit('Dipendente fuori dal tuo reparto');
    }
    return $emp;
}

// Download GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $doc = EmployeeDocument::getById($docId);
    if ($doc) {
        ed_assert_in_dept((int) $doc['employee_id'], $departmentId);
    }
    $result = EmployeeDocument::download($docId);
    if (!$result['success']) {
        http_response_code(403);
        echo htmlspecialchars($result['error']);
        exit;
    }
    $d = $result['document'];
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $d['original_name'] ?? $d['file_name']);
    setDownloadHeaders($downloadName, $d['mime_type'], filesize($result['file_path']));
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

// POST azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    if (!$employeeId) {
        http_response_code(400);
        exit('employee_id mancante');
    }
    ed_assert_in_dept($employeeId, $departmentId);

    $status = '';
    if ($action === 'upload') {
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
    } elseif (in_array($action, ['rename', 'toggle_visibility', 'update_expiry', 'delete'], true)) {
        $docId = (int) ($_POST['document_id'] ?? 0);
        $current = $docId ? EmployeeDocument::getById($docId) : null;
        if (!$current) {
            $status = 'notfound';
        } else {
            ed_assert_in_dept((int) $current['employee_id'], $departmentId);
            if ($action === 'delete') {
                $result = EmployeeDocument::delete($docId);
            } else {
                $payload = [];
                if ($action === 'rename') {
                    $payload['name'] = $_POST['name'] ?? '';
                } elseif ($action === 'toggle_visibility') {
                    $payload['visible_to_employee'] = (int) $current['visible_to_employee'] === 1 ? 0 : 1;
                } elseif ($action === 'update_expiry') {
                    $payload['expires_on'] = $_POST['expires_on'] ?? '';
                }
                $result = EmployeeDocument::update($docId, $payload);
            }
            $status = $result['success'] ? ($action === 'delete' ? 'deleted' : 'updated') : 'error_' . ($result['error'] ?? 'unknown');
        }
    }

    header('Location: employee-documents.php?employee_id=' . $employeeId . '&ed_status=' . urlencode($status) . '#docs');
    exit;
}

// GET render lista
$employeeId = (int) ($_GET['employee_id'] ?? 0);
if (!$employeeId) {
    header('Location: employees.php');
    exit;
}
$employee = ed_assert_in_dept($employeeId, $departmentId);

$documents = EmployeeDocument::getByEmployee($employeeId);
$edStatus = $_GET['ed_status'] ?? '';

$pageTitle = 'Documenti dipendente';
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
?>

<div class="dashboard">
    <div style="margin-bottom:1rem;">
        <a href="employees.php" class="btn btn-sm btn-secondary">&larr; Torna ai dipendenti</a>
    </div>

    <div class="dashboard-card dashboard-card-full">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;">Documenti di <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></h3>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.document.getElementById('ed-upload-modal').style.display='flex';">Carica documento</button>
        </div>

        <div class="card-body">
            <?php if ($edStatus === 'uploaded'): ?>
                <div class="alert alert-success">Documento caricato.</div>
            <?php elseif ($edStatus === 'updated'): ?>
                <div class="alert alert-success">Documento aggiornato.</div>
            <?php elseif ($edStatus === 'deleted'): ?>
                <div class="alert alert-success">Documento eliminato.</div>
            <?php elseif (strpos((string) $edStatus, 'error') === 0): ?>
                <div class="alert alert-danger">Errore: <?= htmlspecialchars(substr((string) $edStatus, 6)) ?></div>
            <?php endif; ?>

            <?php if (empty($documents)): ?>
                <p style="color:#666;">Nessun documento caricato.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Dimensione</th>
                        <th>Visibile</th>
                        <th>Scadenza</th>
                        <th>Caricato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= number_format($d['file_size'] / 1024, 1) ?> KB</td>
                        <td>
                            <form method="post" action="employee-documents.php" style="display:inline;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_visibility">
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $d['visible_to_employee'] ? 'btn-success' : 'btn-secondary' ?>">
                                    <?= $d['visible_to_employee'] ? 'Si' : 'No' ?>
                                </button>
                            </form>
                        </td>
                        <td><?= $d['expires_on'] ? htmlspecialchars($d['expires_on']) : '-' ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?><br><small><?= htmlspecialchars($d['uploaded_by_name']) ?></small></td>
                        <td style="white-space:nowrap;">
                            <a href="employee-documents.php?download=<?= (int) $d['id'] ?>" class="btn btn-sm btn-info">Scarica</a>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="var n=prompt('Nuovo nome:', <?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES, 'UTF-8') ?>); if(n){var f=window.document.getElementById('ed-rename-<?= (int) $d['id'] ?>'); f.querySelector('input[name=name]').value=n; f.submit();}">Rinomina</button>
                            <form id="ed-rename-<?= (int) $d['id'] ?>" method="post" action="employee-documents.php" style="display:none;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                <input type="hidden" name="name" value="">
                            </form>
                            <form method="post" action="employee-documents.php" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente?');">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modale upload -->
<div id="ed-upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;padding:2rem;border-radius:10px;max-width:480px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.3);">
        <h3 style="margin-top:0;">Carica documento</h3>
        <form method="post" action="employee-documents.php" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
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
                <label><input type="checkbox" name="visible_to_employee" value="1"> Rendi visibile al dipendente (invia notifica)</label>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="window.document.getElementById('ed-upload-modal').style.display='none';">Annulla</button>
                <button type="submit" class="btn btn-primary">Carica</button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
