<?php
/**
 * Documenti aziendali - distribuzione massiva.
 * Un upload viene agganciato a tutti i dipendenti attivi dell'azienda corrente:
 * il documento compare nella loro area personale e riceve la notifica standard.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

// Download della copia di riferimento (il file e condiviso da tutte le righe)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $result = EmployeeDocument::download((int) $_GET['download']);
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

$status = '';
$statusType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_upload') {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            $status = 'Seleziona un file da caricare.';
        } else {
            $result = EmployeeDocument::uploadForAllEmployees($_FILES['document'], [
                'name' => $_POST['name'] ?? '',
                'expires_on' => $_POST['expires_on'] ?? null,
                'visible_to_employee' => 1,
                'send_email' => !empty($_POST['send_email'])
            ]);
            if ($result['success']) {
                $status = 'Documento distribuito a ' . $result['count'] . ' ' . ($result['count'] === 1 ? 'dipendente' : 'dipendenti') . '.';
                $statusType = 'ok';
            } else {
                $status = $result['error'];
            }
        }
    } elseif ($action === 'bulk_delete') {
        $result = EmployeeDocument::deleteBulkGroup((string) ($_POST['bulk_group'] ?? ''));
        if ($result['success']) {
            $status = 'Distribuzione eliminata (' . $result['count'] . ' copie rimosse).';
            $statusType = 'ok';
        } else {
            $status = $result['error'];
        }
    }
}

$groups = EmployeeDocument::getBulkGroups();
$activeEmployees = count(Employee::getAll(true));

$pageTitle = 'Documenti aziendali';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<section class="card" style="max-width: 900px; margin: 1.5rem auto;">
    <div class="card-body" style="padding: 1.75rem;">
        <h2 style="margin-top: 0;">Documenti aziendali</h2>
        <p class="text-muted" style="margin-bottom: 1.5rem; font-size: 0.9rem;">
            Carica un documento una sola volta: viene agganciato a tutti i
            <strong><?= $activeEmployees ?></strong> dipendenti attivi, compare nella loro area
            personale e ognuno riceve la notifica.
        </p>

        <?php if ($status !== ''): ?>
            <div style="margin-bottom: 1.25rem; padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.86rem;
                        background: <?= $statusType === 'ok' ? '#dcfce7' : '#fee2e2' ?>;
                        color: <?= $statusType === 'ok' ? '#166534' : '#991b1b' ?>;">
                <?= htmlspecialchars($status) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" action="documenti-aziendali.php"
              onsubmit="return confirm('Distribuire il documento a tutti i <?= $activeEmployees ?> dipendenti attivi?');">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="bulk_upload">

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <div>
                    <label style="display:block; font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom: 4px;">Nome documento *</label>
                    <input type="text" name="name" required maxlength="255" placeholder="Es. Regolamento aziendale 2026"
                           style="width:100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                </div>
                <div>
                    <label style="display:block; font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom: 4px;">File *</label>
                    <input type="file" name="document" required
                           style="width:100%; padding: 0.45rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem;">
                </div>
                <div>
                    <label style="display:block; font-size:0.78rem; font-weight:600; color:#64748b; margin-bottom: 4px;">Scadenza (opzionale)</label>
                    <input type="date" name="expires_on"
                           style="width:100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                </div>
            </div>

            <label style="display:flex; align-items:center; gap:0.5rem; margin-top: 1rem; font-size: 0.86rem; color:#475569;">
                <input type="checkbox" name="send_email" value="1" checked>
                Invia anche l'email di avviso (oltre alla notifica push)
            </label>

            <button type="submit" class="btn btn-primary" style="margin-top: 1.25rem; padding: 0.6rem 1.2rem; font-size: 0.92rem;">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="vertical-align: middle; margin-right: 6px;"><path d="M9 16h6v-6h4l-7-7-7 7h4zM5 18h14v2H5z"/></svg>
                Carica e distribuisci
            </button>
            <p class="text-muted" style="margin: 0.75rem 0 0; font-size: 0.78rem;">
                Formati: pdf, jpg, png, gif, doc/docx, xls/xlsx, csv, txt, odt, ods - massimo 30MB.
                I dipendenti assunti dopo la distribuzione non ricevono il documento in automatico.
            </p>
        </form>
    </div>
</section>

<section class="card" style="max-width: 900px; margin: 1.5rem auto;">
    <div class="card-body" style="padding: 1.75rem;">
        <h3 style="margin-top: 0;">Distribuzioni precedenti</h3>

        <?php if (empty($groups)): ?>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 0;">Nessun documento distribuito finora.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="table" style="width: 100%; font-size: 0.86rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Documento</th>
                            <th style="text-align:left;">Data</th>
                            <th style="text-align:left;">Destinatari</th>
                            <th style="text-align:left;">Scaricato da</th>
                            <th style="text-align:left;">Scadenza</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($g['name']) ?></strong><br>
                                    <span class="text-muted" style="font-size: 0.78rem;">
                                        <?= htmlspecialchars($g['original_name']) ?>
                                        (<?= number_format(((int) $g['file_size']) / 1024, 0, ',', '.') ?> KB)
                                    </span>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($g['created_at'])) ?></td>
                                <td><?= (int) $g['employee_count'] ?></td>
                                <td><?= (int) $g['downloaded_count'] ?> / <?= (int) $g['employee_count'] ?></td>
                                <td><?= $g['expires_on'] ? date('d/m/Y', strtotime($g['expires_on'])) : '-' ?></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a class="btn btn-sm" href="documenti-aziendali.php?download=<?= (int) $g['sample_id'] ?>">Scarica</a>
                                    <form method="POST" action="documenti-aziendali.php" style="display:inline;"
                                          onsubmit="return confirm('Eliminare il documento da tutti i <?= (int) $g['employee_count'] ?> dipendenti?');">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="bulk_delete">
                                        <input type="hidden" name="bulk_group" value="<?= htmlspecialchars($g['bulk_group']) ?>">
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
</section>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
