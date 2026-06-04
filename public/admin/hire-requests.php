<?php
/**
 * Gestione Richieste di Assunzione - Admin
 * Flusso: admin compila form -> consulente carica prospetti -> admin approva -> contratto -> firma.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$error = '';

// === Download di un file della richiesta ===
if ($action === 'file' && $id > 0) {
    $hr = HireRequest::getById($id);
    if (!$hr) { http_response_code(404); exit('Richiesta non trovata'); }
    $fileId = (int)($_GET['file_id'] ?? 0);
    $f = Database::fetchOne("SELECT * FROM hire_request_files WHERE id = ? AND hire_request_id = ?", [$fileId, $id]);
    if (!$f) { http_response_code(404); exit('File non trovato'); }
    $path = HireRequest::fileFsPath($f);
    if (!is_file($path)) { http_response_code(404); exit('File non disponibile sul filesystem'); }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['original_name']);
    header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// === POST: creazione nuova richiesta ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    CSRF::verifyOrDie();
    $data = $_POST;
    $files = [
        'id_doc'          => $_FILES['id_doc']          ?? [],
        'fiscal_code_doc' => $_FILES['fiscal_code_doc'] ?? [],
        'permit'          => $_FILES['permit']          ?? [],
        'c2'              => $_FILES['c2']              ?? [],
    ];
    $res = HireRequest::create($data, $files);
    if ($res['success']) {
        header('Location: hire-requests.php?id=' . $res['id'] . '&created=1');
        exit;
    } else {
        $error = $res['error'] ?? 'Errore creazione richiesta';
        $action = 'new';
        $form = $data;
    }
}

$pageTitle = 'Richieste di assunzione';
include dirname(__DIR__) . '/includes/header-admin.php';

// === Dettaglio richiesta ===
if ($id > 0 && $action !== 'new') {
    $hr = HireRequest::getById($id);
    if (!$hr) {
        echo '<div class="alert alert-error">Richiesta non trovata.</div>';
        include dirname(__DIR__) . '/includes/footer-admin.php';
        exit;
    }
    $files = HireRequest::getFiles($id);
    $byCat = [];
    foreach ($files as $f) $byCat[$f['category']][] = $f;
    $statusLabel = HireRequest::statusLabel($hr['status']);
    $statusColors = [
        'awaiting_prospects' => '#eab308',
        'prospects_review'   => '#0ea5e9',
        'approved'           => '#16a34a',
        'contract_pending'   => '#7c3aed',
        'contract_signed'    => '#16a34a',
        'rejected'           => '#dc2626',
        'cancelled'          => '#64748b',
    ];
    $statusColor = $statusColors[$hr['status']] ?? '#64748b';
?>
<div style="width:100%; margin:1.5rem 0;">
    <?php if (!empty($_GET['created'])): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">Richiesta creata. In attesa che il consulente carichi i prospetti.</div>
    <?php endif; ?>
    <div style="display:flex; gap:10px; align-items:center; margin-bottom:1rem;">
        <a href="hire-requests.php" class="btn btn-secondary">← Torna alla lista</a>
        <h1 style="margin:0; flex:1; font-size:1.5rem;"><?= htmlspecialchars($hr['employee_first_name'] . ' ' . $hr['employee_last_name']) ?></h1>
        <span style="background:<?= $statusColor ?>; color:#fff; padding:6px 12px; border-radius:999px; font-size:.78rem; font-weight:700;">
            <?= htmlspecialchars($statusLabel) ?>
        </span>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Dati anagrafici</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem .75rem 1rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Datore di lavoro</div><div><?= htmlspecialchars($hr['employer_name']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Codice fiscale</div><div><?= htmlspecialchars($hr['fiscal_code']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Data di nascita</div><div><?= htmlspecialchars($hr['employee_birth_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Nato a</div><div><?= htmlspecialchars($hr['birth_city'] . ' (' . $hr['birth_state'] . ')') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Residenza</div><div><?= htmlspecialchars($hr['residence_address'] . ', ' . $hr['residence_cap'] . ' ' . $hr['residence_city'] . ' (' . $hr['residence_province'] . ')') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Stato civile</div><div><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $hr['marital_status']))) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Istruzione</div><div><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $hr['education_level']))) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Email account</div><div><?= htmlspecialchars($hr['employee_email']) ?></div></div>
                <?php if ($hr['personal_email']): ?><div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Email personale</div><div><?= htmlspecialchars($hr['personal_email']) ?></div></div><?php endif; ?>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Username (generato)</div><div><code><?= htmlspecialchars((string)$hr['generated_username']) ?></code></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Contratto</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Tipologia</div><div><?= htmlspecialchars(HireRequest::contractTypesLabels($hr)) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Inizio</div><div><?= htmlspecialchars($hr['start_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Fine</div><div><?= htmlspecialchars($hr['end_date'] ?: '— (indeterminato)') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Ore settimanali</div><div><?= htmlspecialchars($hr['weekly_hours']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Giorni</div><div><?= htmlspecialchars(HireRequest::workDaysLabels($hr['work_days'])) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Sede di lavoro</div><div><?= htmlspecialchars($hr['workplace']) ?></div></div>
                <?php if ($hr['cost_center']): ?><div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Centro di costo</div><div><?= htmlspecialchars($hr['cost_center']) ?></div></div><?php endif; ?>
                <?php if ($hr['iban']): ?><div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">IBAN</div><div><?= htmlspecialchars($hr['iban']) ?></div></div><?php endif; ?>
            </div>
            <div style="margin-top:1rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Mansioni</div><div><?= nl2br(htmlspecialchars($hr['role_description'])) ?></div></div>
            <?php if ($hr['notes']): ?><div style="margin-top:.75rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Note</div><div><?= nl2br(htmlspecialchars($hr['notes'])) ?></div></div><?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Allegati iniziali (admin)</h3>
            <?php foreach (['id_doc'=>'Documento di riconoscimento','fiscal_code_doc'=>'Codice fiscale','permit'=>'Permesso di soggiorno','c2'=>'Modello C2'] as $cat => $lbl): ?>
                <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:.88rem;"><strong><?= $lbl ?>:</strong>
                        <?php if (!empty($byCat[$cat])): foreach ($byCat[$cat] as $f): ?>
                            <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank" style="margin-left:.5rem;"><?= htmlspecialchars($f['original_name']) ?></a>
                        <?php endforeach; else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($byCat['prospect'])): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem;">Prospetti caricati dal consulente</h3>
                <?php foreach ($byCat['prospect'] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9;">
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank">
                            <?= htmlspecialchars($f['display_name'] ?: $f['original_name']) ?>
                        </a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars($f['uploaded_at']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($hr['status'] === 'prospects_review'): ?>
                    <div style="margin-top:1rem; padding:.75rem; background:#fffbeb; border-radius:8px; font-size:.85rem;">
                        I prospetti sono pronti per la tua decisione. (Approvazione/rifiuto disponibili nella fase 2.)
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hr['rejection_reason']): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;">
            <strong>Motivo rifiuto:</strong> <?= htmlspecialchars($hr['rejection_reason']) ?>
        </div>
    <?php endif; ?>
</div>

<?php
    include dirname(__DIR__) . '/includes/footer-admin.php';
    exit;
}

// === Form nuova richiesta ===
if ($action === 'new') {
    $form = $form ?? [];
    if (empty($form['employer_name'])) {
        $__cc = class_exists('Tenant') ? Tenant::currentCompany() : null;
        if ($__cc && !empty($__cc['name'])) $form['employer_name'] = $__cc['name'];
    }
?>
<style>
    /* Radio-pill: card che si evidenzia quando selezionato */
    .pill-radio { transition: border-color .15s, background .15s, color .15s; }
    .pill-radio:hover { border-color: #cbd5e1; background:#f8fafc; }
    .pill-radio input[type="radio"], .pill-radio input[type="checkbox"] { accent-color: #044bff; }
    .pill-radio:has(input:checked) { border-color: #044bff; background:#eff5ff; color:#044bff; font-weight:600; }

    /* Forza radio e checkbox a rendering quadrato custom identico (no piu' radio tondi) */
    .pill-radio input[type="radio"], .pill-radio input[type="checkbox"] {
        appearance: none;
        -webkit-appearance: none;
        width: 16px; height: 16px;
        border: 1.5px solid #cbd5e1;
        border-radius: 3px;
        background: #fff;
        margin: 0;
        cursor: pointer;
        flex-shrink: 0;
        position: relative;
    }
    .pill-radio input:checked {
        border-color: #044bff;
        background: #044bff;
    }
    .pill-radio input:checked::after {
        content: '';
        position: absolute;
        left: 4px; top: 1px;
        width: 4px; height: 8px;
        border: solid #fff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
    }

    /* File drop card */
    .file-drop {
        position: relative;
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .9rem 1rem;
        border: 1.5px dashed #cbd5e1;
        border-radius: 10px;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color .15s, background .15s, transform .1s;
    }
    .file-drop:hover { border-color: #044bff; background: #eff5ff; }
    .file-drop.is-dragover { border-color: #044bff; background: #dde7ff; transform: scale(1.01); }
    .file-drop.has-file { border-style: solid; border-color: #16a34a; background: #f0fdf4; }
    .file-drop-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    .file-drop-icon { color: #64748b; flex-shrink: 0; }
    .file-drop.has-file .file-drop-icon { color: #16a34a; }
    .file-drop-text { font-size: .85rem; color: #475569; line-height: 1.3; }
    .file-drop-text strong { color: #044bff; font-weight: 600; }
    .file-drop-text small { display: block; color: #94a3b8; font-size: .72rem; margin-top: 2px; }
    .file-drop-name { font-size: .85rem; color: #16a34a; font-weight: 600; word-break: break-all; }
    .file-drop-list { display: block; font-size: .82rem; color: #16a34a; word-break: break-all; }
    .file-drop-list ul { list-style: none; padding: 0; margin: 0; }
    .file-drop-list li { padding: 2px 0; line-height: 1.3; }
    .file-drop-list li::before { content: '✓ '; color: #16a34a; font-weight: 700; }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.file-drop').forEach(drop => {
            const input = drop.querySelector('.file-drop-input');
            const listEl = drop.querySelector('.file-drop-list');
            const textEl = drop.querySelector('.file-drop-text');
            const renderList = () => {
                const files = Array.from(input.files || []);
                if (files.length === 0) {
                    listEl.hidden = true;
                    listEl.innerHTML = '';
                    textEl.style.display = '';
                    drop.classList.remove('has-file');
                    return;
                }
                listEl.hidden = false;
                textEl.style.display = 'none';
                drop.classList.add('has-file');
                const items = files.map(f => '<li>' + f.name.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</li>').join('');
                listEl.innerHTML = '<strong>' + files.length + ' file selezionati:</strong><ul>' + items + '</ul>';
            };
            input.addEventListener('change', renderList);
            ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-dragover'); }));
            ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-dragover'); }));
            drop.addEventListener('drop', e => {
                if (e.dataTransfer.files && e.dataTransfer.files.length) {
                    // merge: somma file gia' selezionati + nuovi (DataTransfer)
                    const dt = new DataTransfer();
                    Array.from(input.files || []).forEach(f => dt.items.add(f));
                    Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
                    input.files = dt.files;
                    renderList();
                }
            });
        });
    });
</script>
<?php
?>
<div style="width:100%; margin:1.5rem 0;">
    <a href="hire-requests.php" class="btn btn-secondary" style="margin-bottom:1rem;">← Torna alla lista</a>
    <h1 style="font-size:1.5rem; margin:0 0 .25rem;">Nuova assunzione</h1>
    <p style="color:#64748b; margin:0 0 1.5rem;">Compila i dati anagrafici e carica i documenti. La richiesta sara' inoltrata al consulente del lavoro.</p>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="hire-requests.php" enctype="multipart/form-data" class="card">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="create">

        <div class="card-body" style="padding:1.5rem;">
            <h3 style="margin-top:0; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Datore di lavoro</h3>
            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Nome azienda/Datore di lavoro *</label>
                <input type="text" name="employer_name" required readonly value="<?= htmlspecialchars($form['employer_name'] ?? '') ?>" class="form-input" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#475569;">
                <p style="font-size:.75rem; color:#64748b; margin:4px 0 0;">Impostato automaticamente dall'azienda corrente.</p>
            </div>

            <h3 style="margin-top:1.5rem; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Anagrafica risorsa</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Nome *</label>
                    <input type="text" name="employee_first_name" required value="<?= htmlspecialchars($form['employee_first_name'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Cognome *</label>
                    <input type="text" name="employee_last_name" required value="<?= htmlspecialchars($form['employee_last_name'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Data di nascita *</label>
                    <input type="date" name="employee_birth_date" required value="<?= htmlspecialchars($form['employee_birth_date'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Codice fiscale *</label>
                    <input type="text" name="fiscal_code" required maxlength="16" pattern="[A-Za-z0-9]{16}" value="<?= htmlspecialchars($form['fiscal_code'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px; text-transform:uppercase;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Stato di nascita *</label>
                    <input type="text" name="birth_state" required value="<?= htmlspecialchars($form['birth_state'] ?? 'Italia') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Comune di nascita *</label>
                    <input type="text" name="birth_city" required value="<?= htmlspecialchars($form['birth_city'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>

            <h3 style="margin-top:1.5rem; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Residenza</h3>
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:1rem; margin-bottom:1rem;">
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Indirizzo *</label>
                    <input type="text" name="residence_address" required value="<?= htmlspecialchars($form['residence_address'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">CAP *</label>
                    <input type="text" name="residence_cap" required maxlength="10" value="<?= htmlspecialchars($form['residence_cap'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Comune *</label>
                    <input type="text" name="residence_city" required value="<?= htmlspecialchars($form['residence_city'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Provincia *</label>
                    <input type="text" name="residence_province" required maxlength="80" value="<?= htmlspecialchars($form['residence_province'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:6px;">Stato civile *</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:.5rem;">
                    <?php foreach (['celibe_nubile'=>'Celibe/Nubile','coniugato'=>'Coniugato/a','divorziato'=>'Divorziato/a','vedovo'=>'Vedovo/a','unione_civile'=>'Unione civile','separato'=>'Separato/a'] as $v=>$lbl): ?>
                        <label class="pill-radio" style="display:flex; gap:6px; align-items:center; padding:.5rem; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:.85rem;">
                            <input type="radio" name="marital_status" value="<?= $v ?>" required <?= (($form['marital_status'] ?? '') === $v) ? 'checked' : '' ?>>
                            <?= $lbl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:6px;">Livello di istruzione *</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:.5rem;">
                    <?php foreach (['nessuno'=>'Nessun titolo','licenza_elementare'=>'Licenza elementare','licenza_media'=>'Licenza media','diploma'=>'Diploma','laurea_triennale'=>'Laurea triennale','laurea_magistrale'=>'Laurea magistrale','dottorato'=>'Dottorato'] as $v=>$lbl): ?>
                        <label class="pill-radio" style="display:flex; gap:6px; align-items:center; padding:.5rem; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:.85rem;">
                            <input type="radio" name="education_level" value="<?= $v ?>" required <?= (($form['education_level'] ?? '') === $v) ? 'checked' : '' ?>>
                            <?= $lbl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <h3 style="margin-top:1.5rem; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Contratto</h3>
            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:6px;">Tipologia (seleziona almeno una) *</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:.5rem;">
                    <?php foreach (['contract_indeterminato'=>'Tempo indeterminato','contract_determinato'=>'Tempo determinato','contract_apprendistato'=>'Apprendistato','contract_tirocinio'=>'Tirocinio/Stage','contract_agevolata'=>'Agevolata da discutere'] as $k=>$lbl): ?>
                        <label class="pill-radio" style="display:flex; gap:6px; align-items:center; padding:.5rem; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:.85rem;">
                            <input type="checkbox" name="<?= $k ?>" value="1" <?= !empty($form[$k]) ? 'checked' : '' ?>>
                            <?= $lbl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem;">
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Data inizio *</label>
                    <input type="date" name="start_date" required value="<?= htmlspecialchars($form['start_date'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Data fine (se determinato)</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($form['end_date'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Ore settimanali *</label>
                    <input type="number" step="0.5" min="1" max="60" name="weekly_hours" required value="<?= htmlspecialchars($form['weekly_hours'] ?? '40') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Mansioni *</label>
                <textarea name="role_description" required rows="2" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;"><?= htmlspecialchars($form['role_description'] ?? '') ?></textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:6px;">Giorni di lavoro *</label>
                <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                    <?php foreach (['mon'=>'Lun','tue'=>'Mar','wed'=>'Mer','thu'=>'Gio','fri'=>'Ven','sat'=>'Sab','sun'=>'Dom'] as $k=>$lbl): ?>
                        <label class="pill-radio" style="display:flex; gap:4px; align-items:center; padding:.4rem .7rem; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:.85rem;">
                            <input type="checkbox" name="work_days[]" value="<?= $k ?>" <?= in_array($k, (array)($form['work_days'] ?? ['mon','tue','wed','thu','fri']), true) ? 'checked' : '' ?>>
                            <?= $lbl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Sede di lavoro *</label>
                    <input type="text" name="workplace" required value="<?= htmlspecialchars($form['workplace'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Centro di costo</label>
                    <input type="text" name="cost_center" value="<?= htmlspecialchars($form['cost_center'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>

            <h3 style="margin-top:1.5rem; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Account</h3>
            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Email account *</label>
                <input type="email" name="employee_email" required value="<?= htmlspecialchars($form['employee_email'] ?? '') ?>" style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;">
                <p style="font-size:.75rem; color:#64748b; margin:4px 0 0;">L'username verra' generato automaticamente da nome.cognome.</p>
            </div>

            <h3 style="margin-top:1.5rem; font-size:1rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0;">Allegati</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                <?php
                $__uploads = [
                    ['name'=>'id_doc',          'label'=>'Documento di riconoscimento', 'required'=>true,  'hint'=>'PDF, JPG o PNG'],
                    ['name'=>'fiscal_code_doc', 'label'=>'Codice fiscale',              'required'=>true,  'hint'=>'PDF o immagine del retro/fronte'],
                    ['name'=>'permit',          'label'=>'Permesso di soggiorno',       'required'=>false, 'hint'=>'Se necessario'],
                    ['name'=>'c2',              'label'=>'Modello storico C2',          'required'=>false, 'hint'=>'Per sgravi contributivi'],
                ];
                foreach ($__uploads as $u):
                ?>
                    <div class="hr-upload">
                        <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;"><?= htmlspecialchars($u['label']) ?> <?= $u['required'] ? '<span style="color:#dc2626;">*</span>' : '<span style="color:#94a3b8; font-weight:500;">(opzionale)</span>' ?> <span style="color:#94a3b8; font-weight:500; font-size:.75rem;">— piu' file ammessi</span></label>
                        <label class="file-drop" data-target="<?= $u['name'] ?>">
                            <input type="file" name="<?= $u['name'] ?>[]" multiple <?= $u['required'] ? 'required' : '' ?> accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="file-drop-input">
                            <span class="file-drop-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </span>
                            <span class="file-drop-text">
                                <strong>Scegli file</strong> o trascina qui
                                <small><?= htmlspecialchars($u['hint']) ?> — puoi selezionarne piu' di uno</small>
                            </span>
                            <span class="file-drop-list" hidden></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:4px;">Note finali</label>
                <textarea name="notes" rows="3" placeholder="RAL, livello di inquadramento, domande specifiche..." style="width:100%; padding:.55rem; border:1px solid #e2e8f0; border-radius:8px;"><?= htmlspecialchars($form['notes'] ?? '') ?></textarea>
            </div>

            <div style="display:flex; gap:.75rem; justify-content:flex-end; margin-top:1.5rem;">
                <a href="hire-requests.php" class="btn btn-secondary">Annulla</a>
                <button type="submit" class="btn btn-primary">Invia al consulente</button>
            </div>
        </div>
    </form>
</div>

<?php
    include dirname(__DIR__) . '/includes/footer-admin.php';
    exit;
}

// === Lista richieste ===
$rows = HireRequest::listForCurrent();
$statusFilter = $_GET['status'] ?? '';
?>
<div style="width:100%; margin:1.5rem 0;">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
        <h1 style="margin:0; font-size:1.5rem; flex:1;">Richieste di assunzione</h1>
        <a href="hire-requests.php?action=new" class="btn btn-primary">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:middle; margin-right:6px;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Nuova assunzione
        </a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="card"><div class="card-body" style="text-align:center; padding:3rem;">
            <h3 style="margin:0 0 .5rem;">Nessuna richiesta</h3>
            <p style="color:#64748b; margin:0 0 1rem;">Avvia una nuova assunzione per iniziare il flusso.</p>
            <a href="hire-requests.php?action=new" class="btn btn-primary">Nuova assunzione</a>
        </div></div>
    <?php else: ?>
        <div class="card">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; font-size:.78rem; text-transform:uppercase; color:#64748b;">
                        <th style="text-align:left; padding:.75rem;">Risorsa</th>
                        <th style="text-align:left; padding:.75rem;">Codice fiscale</th>
                        <th style="text-align:left; padding:.75rem;">Stato</th>
                        <th style="text-align:left; padding:.75rem;">Creata il</th>
                        <th style="text-align:right; padding:.75rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $statusColors = [
                        'awaiting_prospects' => '#eab308',
                        'prospects_review'   => '#0ea5e9',
                        'approved'           => '#16a34a',
                        'contract_pending'   => '#7c3aed',
                        'contract_signed'    => '#16a34a',
                        'rejected'           => '#dc2626',
                        'cancelled'          => '#64748b',
                    ];
                    foreach ($rows as $r):
                        $col = $statusColors[$r['status']] ?? '#64748b';
                    ?>
                        <tr style="border-top:1px solid #f1f5f9; font-size:.88rem;">
                            <td style="padding:.75rem;"><strong><?= htmlspecialchars($r['employee_first_name'] . ' ' . $r['employee_last_name']) ?></strong></td>
                            <td style="padding:.75rem; font-family:monospace;"><?= htmlspecialchars($r['fiscal_code']) ?></td>
                            <td style="padding:.75rem;">
                                <span style="background:<?= $col ?>; color:#fff; padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:700;">
                                    <?= htmlspecialchars(HireRequest::statusLabel($r['status'])) ?>
                                </span>
                            </td>
                            <td style="padding:.75rem; color:#64748b;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                            <td style="padding:.75rem; text-align:right;">
                                <a href="hire-requests.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">Dettaglio</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
