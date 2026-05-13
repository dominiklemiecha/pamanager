<?php
/**
 * I miei documenti (generici, non buste paga) - Dipendente.
 * Mostra solo documenti con visible_to_employee = 1.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$error = '';

if (isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = EmployeeDocument::download($docId);
    if ($result['success']) {
        $doc = $result['document'];
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
        setDownloadHeaders($downloadName, $doc['mime_type'], filesize($result['file_path']));
        if (ob_get_level()) { ob_end_clean(); }
        readfile($result['file_path']);
        exit;
    }
    $error = $result['error'];
}

$documents = EmployeeDocument::getByEmployee((int) $employee['id'], true);

$pageTitle = 'I miei documenti';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<div class="dashboard">
    <h2 style="margin-bottom:1rem;">I miei documenti</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="dashboard-card dashboard-card-full">
        <div class="card-body">
            <?php if (empty($documents)): ?>
                <p style="color:#666;">Non ci sono ancora documenti disponibili per te.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Dimensione</th>
                        <th>Scadenza</th>
                        <th>Caricato il</th>
                        <th>Azione</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= number_format($d['file_size'] / 1024, 1) ?> KB</td>
                        <td><?= $d['expires_on'] ? htmlspecialchars($d['expires_on']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?></td>
                        <td><a class="btn btn-sm btn-info" href="?download=<?= (int) $d['id'] ?>">Scarica</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
