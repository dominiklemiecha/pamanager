<?php
/**
 * Consulente del lavoro - Richieste di assunzione assegnate (read-only fase 1).
 * Fase 2 (TODO): caricamento prospetti.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$user = Auth::getUser();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$error = '';

// === POST: caricamento contratto ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_contract') {
    CSRF::verifyOrDie();
    $reqId = (int)($_POST['id'] ?? 0);
    $res = HireRequest::addContract($reqId, $_FILES['contract'] ?? []);
    if ($res['success']) {
        header('Location: hire-requests.php?id=' . $reqId . '&contract_uploaded=1');
        exit;
    }
    header('Location: hire-requests.php?id=' . $reqId . '&err=' . urlencode($res['error'] ?? 'Errore'));
    exit;
}

// === POST: caricamento prospetti ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_prospects') {
    CSRF::verifyOrDie();
    $reqId = (int)($_POST['id'] ?? 0);
    $displayNames = $_POST['display_names'] ?? [];
    $res = HireRequest::addProspects($reqId, $_FILES['prospects'] ?? [], $displayNames);
    if ($res['success']) {
        header('Location: hire-requests.php?id=' . $reqId . '&uploaded=1');
        exit;
    }
    header('Location: hire-requests.php?id=' . $reqId . '&err=' . urlencode($res['error'] ?? 'Errore'));
    exit;
}

// === Download file ===
if (($_GET['action'] ?? '') === 'file' && $id > 0) {
    $hr = HireRequest::getById($id);
    if (!$hr) { http_response_code(404); exit('Non trovata'); }
    $fileId = (int)($_GET['file_id'] ?? 0);
    $f = Database::fetchOne("SELECT * FROM hire_request_files WHERE id = ? AND hire_request_id = ?", [$fileId, $id]);
    if (!$f) { http_response_code(404); exit('File non trovato'); }
    $path = HireRequest::fileFsPath($f);
    if (!is_file($path)) { http_response_code(404); exit('File non disponibile'); }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['original_name']);
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header_remove('Cross-Origin-Embedder-Policy');
    header_remove('Cross-Origin-Resource-Policy');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    readfile($path);
    exit;
}

$pageTitle = 'Richieste di assunzione';
include dirname(__DIR__) . '/includes/header-admin.php';

if ($id > 0) {
    $hr = HireRequest::getById($id);
    // Le bozze esistono solo lato admin: per il consulente non sono visibili
    if (!$hr || $hr['status'] === 'draft') {
        echo '<div class="alert alert-error">Richiesta non trovata o non assegnata a te.</div>';
        include dirname(__DIR__) . '/includes/footer-admin.php';
        exit;
    }
    $files = HireRequest::getFiles($id);
    $byCat = [];
    foreach ($files as $f) $byCat[$f['category']][] = $f;
    // Display helper: campi facoltativi nella richiesta di simulazione
    $hv = static function ($v, string $fallback = '—'): string {
        $v = trim((string)($v ?? ''));
        return $v === '' ? $fallback : htmlspecialchars($v);
    };

    $statusColors = [
        'awaiting_prospects' => '#eab308', 'prospects_review' => '#0ea5e9',
        'approved' => '#16a34a', 'contract_pending' => '#044bff',
        'contract_signed' => '#16a34a', 'rejected' => '#dc2626', 'cancelled' => '#64748b',
    ];
    $statusColor = $statusColors[$hr['status']] ?? '#64748b';
?>
<div style="width:100%; margin:1.5rem 0;">
    <div style="display:flex; gap:10px; align-items:center; margin-bottom:1rem;">
        <a href="hire-requests.php" class="btn-back">Indietro</a>
        <h1 style="margin:0; flex:1; font-size:1.5rem;"><?= $hv(trim(($hr['employee_first_name'] ?? '') . ' ' . ($hr['employee_last_name'] ?? '')), 'Richiesta #' . (int)$hr['id']) ?></h1>
        <span style="background:<?= $statusColor ?>; color:#fff; padding:6px 12px; border-radius:999px; font-size:.78rem; font-weight:700;">
            <?= htmlspecialchars(HireRequest::statusLabel($hr['status'])) ?>
        </span>
    </div>

    <?php
    $__steps = [
        'awaiting_prospects' => 'Richiesta inviata',
        'prospects_review'   => 'Prospetti caricati',
        'approved'           => 'Approvata',
        'contract_pending'   => 'Contratto da firmare',
        'contract_signed'    => 'Firmato',
    ];
    $__order = array_keys($__steps);
    $__currentIdx = array_search($hr['status'], $__order, true);
    $__isRejected = in_array($hr['status'], ['rejected','cancelled'], true);
    ?>
    <div style="display:flex; align-items:center; gap:0; margin:0 0 1.5rem; padding:1rem 1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:12px;">
        <?php foreach ($__order as $i => $stepKey):
            $lbl = $__steps[$stepKey];
            $__isCompleted = $hr['status'] === 'contract_signed';
            $done   = !$__isRejected && ($__isCompleted || ($__currentIdx !== false && $i < $__currentIdx));
            $active = !$__isRejected && !$__isCompleted && $__currentIdx !== false && $i === $__currentIdx;
            $color  = $__isRejected ? '#cbd5e1' : ($done ? '#16a34a' : ($active ? '#044bff' : '#cbd5e1'));
            $bg     = $__isRejected ? '#f1f5f9' : ($done ? '#dcfce7' : ($active ? '#eff5ff' : '#f8fafc'));
        ?>
            <div style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; min-width:90px; text-align:center;">
                <div style="width:36px; height:36px; border-radius:50%; background:<?= $bg ?>; border:2px solid <?= $color ?>; color:<?= $color ?>; display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; <?= $active ? 'box-shadow:0 0 0 4px rgba(4,75,255,.15); animation:pulseStep 1.4s infinite;' : '' ?>">
                    <?= $done ? '✓' : ($i+1) ?>
                </div>
                <div style="margin-top:6px; font-size:.72rem; font-weight:600; color:<?= $color ?>; max-width:100px; line-height:1.2;"><?= htmlspecialchars($lbl) ?></div>
            </div>
            <?php if ($i < count($__order) - 1): ?>
                <div style="flex:1; height:3px; background:<?= ($done ? '#16a34a' : '#e2e8f0') ?>; margin:0 4px 24px; border-radius:2px;"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <style>@keyframes pulseStep { 0%,100% { transform:scale(1); } 50% { transform:scale(1.08); } }</style>

    <?php if (!empty($_GET['uploaded'])): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">Prospetti caricati. L'admin e' stato notificato.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <?php if (!empty($_GET['contract_uploaded'])): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">Contratto caricato. Il dipendente e' stato notificato e potrà firmarlo dal portale.</div>
    <?php endif; ?>

    <?php if (in_array($hr['status'], ['approved','contract_pending'], true)): ?>
        <div class="card" style="margin-bottom:1rem; overflow:hidden; border:1px solid #e2e8f0;">
            <div style="padding:1rem 1.5rem; background:linear-gradient(90deg,#eff5ff 0%, #fff 100%); border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:12px;">
                <div style="width:38px; height:38px; border-radius:50%; background:#dbeafe; display:flex; align-items:center; justify-content:center;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#044bff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
                </div>
                <div style="flex:1;">
                    <div style="font-size:1.05rem; font-weight:700; color:#0f172a;">
                        <?= $hr['status'] === 'approved' ? 'Carica il contratto da firmare' : 'Sostituisci contratto (in attesa firma)' ?>
                    </div>
                    <div style="font-size:.82rem; color:#64748b;">Un solo PDF. Il dipendente riceverà notifica e potrà firmare digitalmente.</div>
                </div>
            </div>
            <form method="POST" action="hire-requests.php" enctype="multipart/form-data" style="padding:1.5rem;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="upload_contract">
                <input type="hidden" name="id" value="<?= (int)$hr['id'] ?>">
                <label style="display:flex; align-items:center; gap:.75rem; padding:.9rem 1rem; border:1.5px dashed #cbd5e1; border-radius:10px; background:#f8fafc; cursor:pointer; margin-bottom:1rem; position:relative;">
                    <input type="file" name="contract" required accept=".pdf" style="position:absolute; inset:0; opacity:0; cursor:pointer;" onchange="this.nextElementSibling.nextElementSibling.textContent='✓ ' + this.files[0].name;">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span style="font-size:.85rem; color:#475569;"><strong style="color:#044bff;">Scegli contratto</strong> (solo PDF)</span>
                </label>
                <button type="submit" class="btn btn-primary" style="background:#044bff; border-color:#044bff; padding:.7rem 1.4rem; font-weight:600;">Invia contratto al dipendente</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (in_array($hr['status'], ['awaiting_prospects','prospects_review'], true)): ?>
        <div class="card" style="margin-bottom:1rem; border:2px solid #044bff;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem; color:#044bff;">
                    <?= $hr['status'] === 'awaiting_prospects' ? 'Carica i prospetti di assunzione' : 'Aggiungi altri prospetti (già consegnati: ' . count($byCat['prospect'] ?? []) . ')' ?>
                </h3>
                <p style="color:#64748b; font-size:.85rem; margin-bottom:1rem;">Puoi caricare piu file. L'admin ricevera una notifica e potra approvare o rifiutare.</p>
                <form method="POST" action="hire-requests.php" enctype="multipart/form-data">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="upload_prospects">
                    <input type="hidden" name="id" value="<?= (int)$hr['id'] ?>">
                    <div id="prospects-list"></div>
                    <label for="prospects-input" class="file-drop" id="prospects-drop" style="display:flex; align-items:center; gap:.75rem; padding:.9rem 1rem; border:1.5px dashed #cbd5e1; border-radius:10px; background:#f8fafc; cursor:pointer; margin-bottom:1rem;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span style="font-size:.85rem; color:#475569;"><strong style="color:#044bff;">Scegli file</strong> o trascina qui — uno o piu' PDF</span>
                    </label>
                    <input type="file" name="prospects[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp" id="prospects-input" style="position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; clip:rect(0 0 0 0);">
                    <button type="submit" class="btn btn-primary">Invia prospetti all'admin</button>
                </form>
                <script>
                (function(){
                    const inp = document.getElementById('prospects-input');
                    const list = document.getElementById('prospects-list');
                    const drop = document.getElementById('prospects-drop');
                    // Drag & drop sul box (oltre al click nativo via label[for])
                    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = '#044bff'; drop.style.background = '#eff5ff'; }));
                    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = '#cbd5e1'; drop.style.background = '#f8fafc'; }));
                    drop.addEventListener('drop', e => { if (e.dataTransfer.files && e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; inp.dispatchEvent(new Event('change')); } });
                    inp.addEventListener('change', () => {
                        const files = Array.from(inp.files || []);
                        list.innerHTML = '';
                        files.forEach((f, i) => {
                            const row = document.createElement('div');
                            row.style.cssText = 'display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; padding:.5rem; background:#f8fafc; border-radius:6px;';
                            row.innerHTML = '<span style="flex:0 0 auto; color:#16a34a;">✓</span><span style="flex:1; font-size:.85rem; color:#475569;">' + f.name.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</span>' +
                                '<input type="text" name="display_names[' + i + ']" placeholder="Nome custom (es: Prospetto Indeterminato)" style="flex:0 0 280px; padding:.35rem .55rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.82rem;">';
                            list.appendChild(row);
                        });
                    });
                })();
                </script>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Dati anagrafici</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Datore di lavoro</div><div><?= $hv($hr['employer_name']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Codice fiscale</div><div><?= $hv($hr['fiscal_code']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Data di nascita</div><div><?= $hv($hr['employee_birth_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Nato a</div><div><?= $hv(trim(($hr['birth_city'] ?? '') . ' ' . (!empty($hr['birth_state']) ? '(' . $hr['birth_state'] . ')' : ''))) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Residenza</div><div><?= $hv(HireRequest::composeAddress($hr)) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Stato civile</div><div><?= $hv(!empty($hr['marital_status']) ? ucfirst(str_replace('_', ' ', $hr['marital_status'])) : null) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Istruzione</div><div><?= $hv(!empty($hr['education_level']) ? ucfirst(str_replace('_', ' ', $hr['education_level'])) : null) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Email</div><div><?= $hv($hr['employee_email']) ?></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Contratto richiesto</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Tipologia</div><div><?= $hv(HireRequest::contractTypesLabels($hr)) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Inizio</div><div><?= $hv($hr['start_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Fine</div><div><?= $hv($hr['end_date'], '— (indeterminato)') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Ore settimanali</div><div><?= $hv($hr['weekly_hours']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Giorni</div><div><?= $hv(HireRequest::workDaysLabels($hr['work_days'])) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Sede</div><div><?= $hv($hr['workplace']) ?></div></div>
            </div>
            <div style="margin-top:1rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Mansioni</div><div><?= nl2br($hv($hr['role_description'])) ?></div></div>
            <?php if ($hr['notes']): ?><div style="margin-top:.75rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Note admin</div><div><?= nl2br(htmlspecialchars($hr['notes'])) ?></div></div><?php endif; ?>
        </div>
    </div>

    <?php if (!empty($byCat['prospect'])): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem;">Prospetti già inviati</h3>
                <?php foreach ($byCat['prospect'] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.88rem;">
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank">
                            <strong><?= htmlspecialchars($f['display_name'] ?: $f['original_name']) ?></strong>
                        </a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($byCat['contract']) || !empty($byCat['signature_image']) || !empty($byCat['signed_contract'])): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem;">Contratto</h3>
                <?php foreach ($byCat['signed_contract'] ?? [] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.88rem;">
                        <span style="display:inline-block; background:#16a34a; color:#fff; padding:1px 8px; border-radius:999px; font-size:.7rem; margin-right:6px;">FIRMATO</span>
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank"><strong>Contratto firmato</strong></a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($byCat['contract'] ?? [] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.88rem;">
                        <span style="display:inline-block; background:#94a3b8; color:#fff; padding:1px 8px; border-radius:999px; font-size:.7rem; margin-right:6px;">ORIGINALE</span>
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank"><?= htmlspecialchars($f['original_name']) ?></a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($byCat['signature_image'] ?? [] as $f): ?>
                    <div style="margin-top:.75rem; padding:.85rem; background:#f0fdf4; border-radius:8px; font-size:.85rem;">
                        <strong style="color:#16a34a;">✓ Firmato dal dipendente</strong>
                        <div style="margin-top:.5rem; color:#475569;">Data: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></div>
                        <?php if ($f['signed_ip']): ?><div style="color:#475569;">IP: <code><?= htmlspecialchars($f['signed_ip']) ?></code></div><?php endif; ?>
                        <?php if ($f['signature_hash']): ?><div style="color:#475569;">SHA256 contratto: <code style="font-size:.72rem;"><?= htmlspecialchars($f['signature_hash']) ?></code></div><?php endif; ?>
                        <div style="margin-top:.5rem;">
                            <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank">Vedi immagine firma</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Allegati admin</h3>
            <?php foreach (['id_doc'=>'Documento di riconoscimento','fiscal_code_doc'=>'Codice fiscale','permit'=>'Permesso di soggiorno','c2'=>'Modello C2'] as $cat => $lbl): ?>
                <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.88rem;">
                    <strong><?= $lbl ?>:</strong>
                    <?php if (!empty($byCat[$cat])): foreach ($byCat[$cat] as $f): ?>
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank" style="margin-left:.5rem;"><?= htmlspecialchars($f['original_name']) ?></a>
                    <?php endforeach; else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
    include dirname(__DIR__) . '/includes/footer-admin.php';
    exit;
}

// === Lista richieste assegnate al consulente corrente ===
$cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$rows = Database::fetchAll(
    "SELECT hr.*, u.name AS created_by_name
     FROM hire_requests hr
     LEFT JOIN users u ON u.id = hr.created_by_user_id
     WHERE hr.company_id = ?
       AND hr.status <> 'draft'
       AND (hr.assigned_consulente_user_id = ? OR hr.assigned_consulente_user_id IS NULL)
     ORDER BY hr.created_at DESC",
    [$cid, (int)$user['id']]
);
$statusColors = [
    'awaiting_prospects' => '#eab308', 'prospects_review' => '#0ea5e9',
    'approved' => '#16a34a', 'contract_pending' => '#044bff',
    'contract_signed' => '#16a34a', 'rejected' => '#dc2626', 'cancelled' => '#64748b',
];
?>
<?php
$__needsProspects = 0;
$__needsContract  = 0;
$__inProgress     = 0;
foreach ($rows as $__r) {
    if ($__r['status'] === 'awaiting_prospects') $__needsProspects++;
    if ($__r['status'] === 'approved')           $__needsContract++;
    if (in_array($__r['status'], ['awaiting_prospects','prospects_review','approved','contract_pending'], true)) $__inProgress++;
}
?>
<div style="width:100%; margin:1.5rem 0;">
    <div class="welcome-card lp-hero">
        <div>
            <h2>Assunzioni</h2>
            <p>Carica i prospetti che l'admin deve approvare e successivamente il contratto da far firmare al dipendente.</p>
            <?php if ($__needsProspects > 0 || $__needsContract > 0): ?>
                <p style="margin-top:6px;"><strong style="color:#dc2626;">
                    <?php if ($__needsProspects > 0): ?>
                        <?= $__needsProspects ?> da prospettare<?= $__needsContract > 0 ? ' · ' : '' ?>
                    <?php endif; ?>
                    <?php if ($__needsContract > 0): ?>
                        <?= $__needsContract ?> contratt<?= $__needsContract === 1 ? 'o' : 'i' ?> da caricare
                    <?php endif; ?>
                </strong></p>
            <?php elseif ($__inProgress > 0): ?>
                <p style="margin-top:6px;"><strong style="color:#044bff;"><?= $__inProgress ?> richiest<?= $__inProgress === 1 ? 'a' : 'e' ?> in corso, niente in attesa per te.</strong></p>
            <?php else: ?>
                <p style="margin-top:6px;"><strong style="color:#0c8a8a;">Tutto in ordine, nessuna richiesta in corso.</strong></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="card"><div class="card-body" style="text-align:center; padding:3rem;">
            <h3 style="margin:0 0 .5rem;">Nessuna richiesta</h3>
            <p style="color:#64748b; margin:0;">Quando l'admin avvia una nuova assunzione la troverai qui.</p>
        </div></div>
    <?php else: ?>
        <div class="card">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; font-size:.78rem; text-transform:uppercase; color:#64748b;">
                        <th style="text-align:left; padding:.75rem;">Risorsa</th>
                        <th style="text-align:left; padding:.75rem;">Codice fiscale</th>
                        <th style="text-align:left; padding:.75rem;">Stato</th>
                        <th style="text-align:left; padding:.75rem;">Inviata il</th>
                        <th style="text-align:right; padding:.75rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $col = $statusColors[$r['status']] ?? '#64748b';
                        $__nome = trim(($r['employee_first_name'] ?? '') . ' ' . ($r['employee_last_name'] ?? ''));
                    ?>
                        <tr style="border-top:1px solid #f1f5f9; font-size:.88rem;">
                            <td style="padding:.75rem;"><strong><?= $__nome !== '' ? htmlspecialchars($__nome) : '<span style="color:#94a3b8;">Richiesta #' . (int)$r['id'] . '</span>' ?></strong></td>
                            <td style="padding:.75rem; font-family:monospace;"><?= !empty($r['fiscal_code']) ? htmlspecialchars($r['fiscal_code']) : '—' ?></td>
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
