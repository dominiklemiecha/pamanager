<?php
/**
 * Documenti aziendali - distribuzione massiva.
 * Un upload viene agganciato a tutti i dipendenti attivi dell'azienda corrente:
 * il documento compare nella loro area personale e riceve la notifica standard.
 *
 * L'upload salva solo le righe (immediato). Le notifiche restano in coda
 * (notify_pending) e vengono smaltite a batch dal browser via AJAX, cosi la
 * pagina non resta appesa sugli invii SMTP.
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

// Endpoint AJAX: smaltisce un blocco di notifiche
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_batch') {
    CSRF::verifyOrDie();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(EmployeeDocument::processNotificationBatch(
        (string) ($_POST['bulk_group'] ?? ''),
        (int) ($_POST['limit'] ?? 4)
    ));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_upload') {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            header('Location: documenti-aziendali.php?err=' . urlencode('Seleziona un file da caricare.'));
            exit;
        }
        $result = EmployeeDocument::uploadForAllEmployees($_FILES['document'], [
            'name' => $_POST['name'] ?? '',
            'expires_on' => $_POST['expires_on'] ?? null,
            'visible_to_employee' => 1,
            'send_email' => !empty($_POST['send_email'])
        ]);
        if ($result['success']) {
            header('Location: documenti-aziendali.php?uploaded=' . urlencode($result['bulk_group']) . '&n=' . (int) $result['count']);
        } else {
            header('Location: documenti-aziendali.php?err=' . urlencode($result['error']));
        }
        exit;
    }

    if ($action === 'bulk_delete') {
        $result = EmployeeDocument::deleteBulkGroup((string) ($_POST['bulk_group'] ?? ''));
        if ($result['success']) {
            header('Location: documenti-aziendali.php?ok=' . urlencode('Distribuzione eliminata (' . $result['count'] . ' copie rimosse).'));
        } else {
            header('Location: documenti-aziendali.php?err=' . urlencode($result['error']));
        }
        exit;
    }

    header('Location: documenti-aziendali.php');
    exit;
}

$uploadedGroup = isset($_GET['uploaded']) ? (string) $_GET['uploaded'] : '';
$uploadedCount = (int) ($_GET['n'] ?? 0);
$okMessage = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$errMessage = isset($_GET['err']) ? (string) $_GET['err'] : '';

$groups = EmployeeDocument::getBulkGroups();
$activeEmployees = count(Employee::getAll(true));

// Coda rimasta indietro (es. tab chiusa a meta invio): si puo riprendere
$resumeGroup = $uploadedGroup !== '' ? '' : (EmployeeDocument::getPendingNotificationGroup() ?? '');
$resumePending = $resumeGroup !== '' ? EmployeeDocument::countPendingNotifications($resumeGroup) : 0;

$jobGroup = $uploadedGroup !== '' ? $uploadedGroup : $resumeGroup;
$jobTotal = $uploadedGroup !== ''
    ? EmployeeDocument::countPendingNotifications($uploadedGroup)
    : $resumePending;

$pageTitle = 'Documenti aziendali';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.doc-hero { padding: var(--sp-5) var(--sp-6); margin-bottom: var(--sp-5); }
.doc-hero h2 {
    font-family: 'Space Grotesk', var(--font-sans);
    font-size: 1.6rem; font-weight: 700; letter-spacing: -0.025em; line-height: 1.1;
    margin: 0 0 6px; color: var(--accent);
}
.doc-hero p { margin: 0; font-size: var(--text-sm); color: #6e7191; }
@media (min-width: 900px) { .doc-hero h2 { font-size: 1.85rem; } }

.doc-layout { display: grid; grid-template-columns: minmax(320px, 380px) 1fr; gap: var(--sp-5); align-items: start; }
@media (max-width: 1100px) { .doc-layout { grid-template-columns: 1fr; } }

.doc-field { margin-bottom: var(--sp-4); }
.doc-field label { display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 4px; }
.doc-field input[type="text"],
.doc-field input[type="date"],
.doc-field input[type="file"] {
    width: 100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0;
    border-radius: 8px; font-size: 0.9rem; background: #fff; box-sizing: border-box;
}
.doc-check { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.86rem; color: #475569; }
.doc-alert { padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.86rem; margin-bottom: var(--sp-4); }
.doc-alert.ok { background: #dcfce7; color: #166534; }
.doc-alert.err { background: #fee2e2; color: #991b1b; }
.doc-alert.info { background: #e0f2fe; color: #075985; }

.doc-progress-track { height: 8px; border-radius: 999px; background: #dbeafe; overflow: hidden; margin-top: 0.6rem; }
.doc-progress-bar { height: 100%; width: 0; background: var(--accent); transition: width 0.25s ease; }

.doc-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
.doc-table th {
    text-align: left; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;
    color: #94a3b8; font-weight: 600; padding: 0 0.6rem 0.5rem; white-space: nowrap;
}
.doc-table td { padding: 0.7rem 0.6rem; border-top: 1px solid #eef2f7; vertical-align: middle; }
.doc-table tbody tr:hover { background: #f8fafc; }
.doc-actions { text-align: right; white-space: nowrap; }
.doc-empty { font-size: 0.88rem; color: #6e7191; margin: 0; }
@media (max-width: 720px) {
    .doc-table th:nth-child(4), .doc-table td:nth-child(4),
    .doc-table th:nth-child(5), .doc-table td:nth-child(5) { display: none; }
}
</style>

<div class="welcome-card doc-hero">
    <h2>Documenti aziendali</h2>
    <p>
        Carica un documento una sola volta: viene agganciato a tutti i
        <strong><?= $activeEmployees ?></strong> dipendenti attivi, compare nella loro area
        personale e ognuno riceve la notifica.
    </p>
</div>

<?php if ($okMessage !== ''): ?>
    <div class="doc-alert ok"><?= htmlspecialchars($okMessage) ?></div>
<?php endif; ?>
<?php if ($errMessage !== ''): ?>
    <div class="doc-alert err"><?= htmlspecialchars($errMessage) ?></div>
<?php endif; ?>
<?php if ($uploadedGroup !== ''): ?>
    <div class="doc-alert ok">
        Documento agganciato a <?= $uploadedCount ?> <?= $uploadedCount === 1 ? 'dipendente' : 'dipendenti' ?>:
        e' gia visibile nella loro area personale.
    </div>
<?php endif; ?>

<?php if ($jobGroup !== '' && $jobTotal > 0): ?>
    <div class="doc-alert info" id="docNotifyBox"
         data-group="<?= htmlspecialchars($jobGroup) ?>"
         data-total="<?= $jobTotal ?>"
         data-auto="<?= $uploadedGroup !== '' ? '1' : '0' ?>">
        <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
            <strong id="docNotifyLabel">
                <?= $uploadedGroup !== ''
                    ? 'Invio notifiche in corso in background...'
                    : 'Ci sono ' . $jobTotal . ' notifiche non inviate della distribuzione precedente.' ?>
            </strong>
            <button type="button" class="btn btn-sm" id="docNotifyStart"
                    <?= $uploadedGroup !== '' ? 'style="display:none;"' : '' ?>>Riprendi invio</button>
        </div>
        <div style="font-size:0.8rem; margin-top:2px;" id="docNotifyCounter">
            0 / <?= $jobTotal ?> inviate
        </div>
        <div class="doc-progress-track"><div class="doc-progress-bar" id="docNotifyBar"></div></div>
        <div style="font-size:0.78rem; margin-top:6px; opacity:0.85;">
            Puoi continuare a lavorare: se chiudi la pagina l'invio riprende da qui alla prossima visita.
        </div>
    </div>
<?php endif; ?>

<div class="doc-layout">
    <section class="card">
        <div class="card-body" style="padding: 1.5rem;">
            <h3 style="margin: 0 0 1.25rem; font-size: 1rem;">Nuova distribuzione</h3>

            <form method="POST" enctype="multipart/form-data" action="documenti-aziendali.php" id="docUploadForm"
                  onsubmit="return confirm('Distribuire il documento a tutti i <?= $activeEmployees ?> dipendenti attivi?');">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="bulk_upload">

                <div class="doc-field">
                    <label>Nome documento *</label>
                    <input type="text" name="name" required maxlength="255" placeholder="Es. Regolamento aziendale 2026">
                </div>
                <div class="doc-field">
                    <label>File *</label>
                    <input type="file" name="document" required>
                </div>
                <div class="doc-field">
                    <label>Scadenza (opzionale)</label>
                    <input type="date" name="expires_on">
                </div>

                <label class="doc-check">
                    <input type="checkbox" name="send_email" value="1" checked>
                    <span>Invia anche l'email di avviso (oltre alla notifica push)</span>
                </label>

                <button type="submit" class="btn btn-primary" id="docUploadBtn" style="margin-top: 1.25rem; width: 100%;">
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

    <section class="card">
        <div class="card-body" style="padding: 1.5rem;">
            <h3 style="margin: 0 0 1rem; font-size: 1rem;">Distribuzioni precedenti</h3>

            <?php if (empty($groups)): ?>
                <p class="doc-empty">Nessun documento distribuito finora.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Data</th>
                                <th>Destinatari</th>
                                <th>Scaricato da</th>
                                <th>Scadenza</th>
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
                                        <?php if ((int) $g['pending_notifications'] > 0): ?>
                                            <br><span style="font-size: 0.75rem; color: #b45309;"
                                                      data-pending-group="<?= htmlspecialchars($g['bulk_group']) ?>">
                                                <?= (int) $g['pending_notifications'] ?> notifiche da inviare
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($g['created_at'])) ?></td>
                                    <td><?= (int) $g['employee_count'] ?></td>
                                    <td><?= (int) $g['downloaded_count'] ?> / <?= (int) $g['employee_count'] ?></td>
                                    <td><?= $g['expires_on'] ? date('d/m/Y', strtotime($g['expires_on'])) : '-' ?></td>
                                    <td class="doc-actions">
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
</div>

<script>
(function () {
    // Feedback immediato sul bottone: l'upload del file puo durare qualche secondo
    var form = document.getElementById('docUploadForm');
    var btn = document.getElementById('docUploadBtn');
    if (form && btn) {
        form.addEventListener('submit', function () {
            setTimeout(function () {
                btn.disabled = true;
                btn.textContent = 'Caricamento in corso...';
            }, 0);
        });
    }

    var box = document.getElementById('docNotifyBox');
    if (!box) { return; }

    var group = box.dataset.group;
    var total = parseInt(box.dataset.total, 10) || 0;
    var token = '<?= CSRF::getToken() ?>';
    var bar = document.getElementById('docNotifyBar');
    var counter = document.getElementById('docNotifyCounter');
    var label = document.getElementById('docNotifyLabel');
    var startBtn = document.getElementById('docNotifyStart');
    var running = false;

    function paint(sentTotal) {
        var pct = total > 0 ? Math.min(100, Math.round((sentTotal / total) * 100)) : 100;
        bar.style.width = pct + '%';
        counter.textContent = sentTotal + ' / ' + total + ' inviate';
    }

    function step(sentTotal) {
        var body = new FormData();
        body.append('action', 'notify_batch');
        body.append('csrf_token', token);
        body.append('bulk_group', group);
        body.append('limit', '4');

        fetch('documenti-aziendali.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    label.textContent = 'Invio notifiche interrotto: ricarica la pagina per riprendere.';
                    running = false;
                    return;
                }
                sentTotal += (data.sent || 0);
                paint(sentTotal);
                if (data.remaining > 0 && (data.sent || 0) > 0) {
                    step(sentTotal);
                } else {
                    running = false;
                    if (data.remaining > 0) {
                        label.textContent = 'Invio interrotto con ' + data.remaining + ' notifiche in coda: ricarica per riprendere.';
                    } else {
                        paint(total);
                        label.textContent = 'Notifiche inviate a tutti i dipendenti.';
                        if (startBtn) { startBtn.style.display = 'none'; }
                        document.querySelectorAll('[data-pending-group="' + group + '"]').forEach(function (el) {
                            el.remove();
                        });
                    }
                }
            })
            .catch(function () {
                running = false;
                label.textContent = 'Invio notifiche interrotto (rete): ricarica la pagina per riprendere.';
            });
    }

    function start() {
        if (running || total === 0) { return; }
        running = true;
        if (startBtn) { startBtn.style.display = 'none'; }
        label.textContent = 'Invio notifiche in corso in background...';
        paint(0);
        step(0);
    }

    if (startBtn) { startBtn.addEventListener('click', start); }
    if (box.dataset.auto === '1') { start(); }
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
