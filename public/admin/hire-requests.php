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

// === POST: approva prospetti / crea employee ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    CSRF::verifyOrDie();
    $reqId = (int)($_POST['id'] ?? 0);
    $toFloat = static function($v) { if ($v === '' || $v === null) return null; return (float)str_replace(',', '.', (string)$v); };
    $extra = [
        'department_id'  => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
        'position'       => trim($_POST['position'] ?? ''),
        'job_level'      => trim($_POST['job_level'] ?? ''),
        'monthly_salary' => $toFloat($_POST['monthly_salary'] ?? null),
        'ral_amount'     => $toFloat($_POST['ral_amount'] ?? null),
    ];
    $res = HireRequest::approveProspects($reqId, $extra);
    if ($res['success']) {
        $qs = 'approved=1';
        if (!$res['email_sent']) $qs .= '&email_err=' . urlencode($res['email_error'] ?? 'Email non inviata');
        header('Location: hire-requests.php?id=' . $reqId . '&' . $qs);
        exit;
    }
    header('Location: hire-requests.php?id=' . $reqId . '&err=' . urlencode($res['error'] ?? 'Errore'));
    exit;
}

// === POST: rifiuto prospetti ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    CSRF::verifyOrDie();
    $reqId = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $res = HireRequest::rejectProspects($reqId, $reason);
    if ($res['success']) {
        header('Location: hire-requests.php?id=' . $reqId . '&rejected=1');
        exit;
    }
    header('Location: hire-requests.php?id=' . $reqId . '&err=' . urlencode($res['error'] ?? 'Errore'));
    exit;
}

// === POST: eliminazione richiesta ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    CSRF::verifyOrDie();
    $delId = (int)($_POST['id'] ?? 0);
    $res = HireRequest::delete($delId);
    if ($res['success']) {
        header('Location: hire-requests.php?deleted=1');
        exit;
    }
    header('Location: hire-requests.php?id=' . $delId . '&del_err=' . urlencode($res['error'] ?? 'Errore'));
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
        'contract_pending'   => '#044bff',
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
        <a href="hire-requests.php" class="btn-back">Indietro</a>
        <h1 style="margin:0; flex:1; font-size:1.5rem;"><?= htmlspecialchars($hr['employee_first_name'] . ' ' . $hr['employee_last_name']) ?></h1>
        <?php if (!in_array($hr['status'], ['contract_signed','cancelled'], true)): ?>
            <form method="POST" action="hire-requests.php" style="display:inline-block;" onsubmit="return confirm('Eliminare definitivamente questa richiesta di assunzione? L\'operazione e\' irreversibile e rimuove anche tutti i file allegati e la visibilita\' lato consulente.');">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$hr['id'] ?>">
                <button type="submit" class="btn-back" style="color:#dc2626;" title="Elimina richiesta">Elimina</button>
            </form>
        <?php endif; ?>
        <span style="background:<?= $statusColor ?>; color:#fff; padding:6px 12px; border-radius:999px; font-size:.78rem; font-weight:700;">
            <?= htmlspecialchars($statusLabel) ?>
        </span>
    </div>

    <?php
    $__steps = [
        'awaiting_prospects' => ['Richiesta inviata',     '📋'],
        'prospects_review'   => ['Prospetti caricati',    '📎'],
        'approved'           => ['Approvata',             '✅'],
        'contract_pending'   => ['Contratto da firmare',  '✍️'],
        'contract_signed'    => ['Firmato',               '🎉'],
    ];
    $__order = array_keys($__steps);
    $__currentIdx = array_search($hr['status'], $__order, true);
    $__isRejected = in_array($hr['status'], ['rejected','cancelled'], true);
    ?>
    <div class="hr-progress" style="display:flex; align-items:center; gap:0; margin:0 0 1.5rem; padding:1rem 1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:12px;">
        <?php foreach ($__order as $i => $stepKey):
            [$lbl, $icon] = $__steps[$stepKey];
            $done   = !$__isRejected && $__currentIdx !== false && $i < $__currentIdx;
            $active = !$__isRejected && $__currentIdx !== false && $i === $__currentIdx;
            $color  = $__isRejected ? '#cbd5e1' : ($done ? '#16a34a' : ($active ? '#044bff' : '#cbd5e1'));
            $bg     = $__isRejected ? '#f1f5f9' : ($done ? '#dcfce7' : ($active ? '#eff5ff' : '#f8fafc'));
        ?>
            <div style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; text-align:center; min-width:90px;">
                <div style="width:36px; height:36px; border-radius:50%; background:<?= $bg ?>; border:2px solid <?= $color ?>; color:<?= $color ?>; display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; <?= $active ? 'box-shadow:0 0 0 4px rgba(4,75,255,.15); animation:pulseStep 1.4s infinite;' : '' ?>">
                    <?= $done ? '✓' : ($i+1) ?>
                </div>
                <div style="margin-top:6px; font-size:.72rem; font-weight:600; color:<?= $color ?>; max-width:100px; line-height:1.2;"><?= htmlspecialchars($lbl) ?></div>
            </div>
            <?php if ($i < count($__order) - 1): ?>
                <div style="flex:1; height:3px; background:<?= ($done ? '#16a34a' : '#e2e8f0') ?>; margin:0 4px; border-radius:2px; margin-bottom:24px;"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($__isRejected): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;">Stato: <strong><?= htmlspecialchars($statusLabel) ?></strong></div>
    <?php endif; ?>
    <style>@keyframes pulseStep { 0%,100% { transform:scale(1); } 50% { transform:scale(1.08); } }</style>

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

    <?php if (!empty($_GET['approved'])): ?>
        <?php if (!empty($_GET['email_err'])): ?>
            <div class="alert alert-warning" style="margin-bottom:1rem;">
                Dipendente creato ma <strong>email non inviata</strong>: <?= htmlspecialchars($_GET['email_err']) ?>.
                Verifica SMTP_HOST/SMTP_USER nelle variabili d'ambiente. Le credenziali sono comunque attive.
            </div>
        <?php else: ?>
            <div class="alert alert-success" style="margin-bottom:1rem;">Approvata! Dipendente creato. Email con credenziali inviata.</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($_GET['rejected'])): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem;">Richiesta rifiutata.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <?php if (!empty($byCat['prospect'])): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem;">Prospetti caricati dal consulente</h3>
                <?php foreach ($byCat['prospect'] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9;">
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank">
                            <strong><?= htmlspecialchars($f['display_name'] ?: $f['original_name']) ?></strong>
                        </a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($hr['status'] === 'prospects_review'):
            $__depts = Database::fetchAll("SELECT id, name FROM departments WHERE company_id = ? AND is_active = TRUE ORDER BY name", [(int)$hr['company_id']]);
        ?>
            <div class="card hr-decision" style="margin-bottom:1rem; overflow:hidden; border:1px solid #e2e8f0;">
                <div style="padding:1rem 1.5rem; background:linear-gradient(90deg,#f0fdf4 0%, #fff 100%); border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:12px;">
                    <div style="width:38px; height:38px; border-radius:50%; background:#dcfce7; display:flex; align-items:center; justify-content:center;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:1.05rem; font-weight:700; color:#0f172a;">Pronto per l'approvazione</div>
                        <div style="font-size:.82rem; color:#64748b;">Approvando crei il dipendente in anagrafica, generi le credenziali e trasferisci i documenti al suo profilo.</div>
                    </div>
                </div>

                <form method="POST" action="hire-requests.php" id="approveForm" style="padding:1.5rem;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= (int)$hr['id'] ?>">

                    <div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; margin-bottom:.65rem;">Inquadramento</div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:.85rem; margin-bottom:1.25rem;">
                        <div class="hr-field">
                            <label>Reparto <span style="color:#dc2626;">*</span></label>
                            <select name="department_id" required>
                                <option value="">— Scegli reparto —</option>
                                <?php foreach ($__depts as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($__depts)): ?><small style="color:#dc2626;">Nessun reparto attivo. <a href="departments.php">Crea un reparto</a> prima di approvare.</small><?php endif; ?>
                        </div>
                        <div class="hr-field">
                            <label>Posizione</label>
                            <input type="text" name="position" value="<?= htmlspecialchars($hr['role_description']) ?>">
                        </div>
                        <div class="hr-field">
                            <label>Livello CCNL</label>
                            <input type="text" name="job_level" placeholder="es: 5° livello">
                        </div>
                    </div>

                    <div style="font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; margin-bottom:.65rem;">Retribuzione <span style="font-weight:500; text-transform:none; color:#94a3b8; letter-spacing:0;">(opzionale, modificabile dopo)</span></div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:.85rem; margin-bottom:1.5rem;">
                        <div class="hr-field">
                            <label>RAL annuale (€)</label>
                            <input type="number" step="0.01" name="ral_amount" placeholder="0,00">
                        </div>
                        <div class="hr-field">
                            <label>Stipendio mensile (€)</label>
                            <input type="number" step="0.01" name="monthly_salary" placeholder="0,00">
                        </div>
                    </div>

                    <div style="background:#f8fafc; border-radius:8px; padding:.85rem 1rem; margin-bottom:1.25rem; font-size:.82rem; color:#475569;">
                        <div style="font-weight:600; color:#0f172a; margin-bottom:.25rem;">Cosa succede approvando</div>
                        <ul style="margin:0; padding-left:1.2rem; line-height:1.55;">
                            <li>Creo il dipendente con username <code style="background:#fff; padding:1px 5px; border-radius:3px;"><?= htmlspecialchars($hr['generated_username']) ?></code></li>
                            <li>Invio email a <strong><?= htmlspecialchars($hr['employee_email']) ?></strong> con credenziali temporanee</li>
                            <li>Trasferisco documento identita, codice fiscale<?= !empty($byCat['permit']) ? ', permesso soggiorno' : '' ?><?= !empty($byCat['c2']) ? ', modello C2' : '' ?> al profilo dipendente</li>
                            <li><strong>I prospetti del consulente restano riservati</strong> (visibili solo a te e al consulente)</li>
                        </ul>
                    </div>

                    <div style="display:flex; gap:.75rem; align-items:center;">
                        <button type="submit" class="btn btn-primary" style="background:#16a34a; border-color:#16a34a; padding:.7rem 1.4rem; font-weight:600;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px; margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>
                            Approva e crea dipendente
                        </button>
                        <button type="button" id="toggleReject" class="btn-back" style="color:#dc2626;">Rifiuta i prospetti</button>
                    </div>
                </form>

                <form method="POST" action="hire-requests.php" id="rejectForm" style="display:none; padding:0 1.5rem 1.5rem; border-top:1px solid #e2e8f0; padding-top:1.25rem;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" value="<?= (int)$hr['id'] ?>">
                    <div style="font-size:.82rem; color:#dc2626; font-weight:600; margin-bottom:.5rem;">Motivo del rifiuto (sara' visibile al consulente)</div>
                    <textarea name="reason" required rows="3" placeholder="Es: prospetto contratto indeterminato non conforme alla richiesta..." style="width:100%; padding:.6rem; border:1px solid #fecaca; border-radius:8px; background:#fff5f5; margin-bottom:.75rem; font-family:inherit;"></textarea>
                    <div style="display:flex; gap:.5rem;">
                        <button type="submit" class="btn btn-sm" style="background:#dc2626; color:#fff; padding:.5rem 1rem; border-radius:6px; border:0;">Conferma rifiuto</button>
                        <button type="button" id="cancelReject" class="btn-back">Annulla</button>
                    </div>
                </form>
            </div>

            <style>
                .hr-decision .hr-field label { display:block; font-size:.78rem; font-weight:600; color:#334155; margin-bottom:5px; }
                .hr-decision .hr-field input,
                .hr-decision .hr-field select {
                    width:100%; padding:.55rem .7rem; border:1px solid #cbd5e1; border-radius:8px;
                    font-size:.88rem; background:#fff; transition:border-color .15s, box-shadow .15s;
                }
                .hr-decision .hr-field input:focus,
                .hr-decision .hr-field select:focus { outline:none; border-color:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,.12); }
                .hr-decision .hr-field small { display:block; margin-top:4px; font-size:.72rem; }
            </style>
            <script>
                (function(){
                    const tog = document.getElementById('toggleReject');
                    const cancel = document.getElementById('cancelReject');
                    const rej = document.getElementById('rejectForm');
                    const app = document.getElementById('approveForm');
                    tog && tog.addEventListener('click', () => { rej.style.display='block'; app.style.opacity='.4'; app.style.pointerEvents='none'; rej.scrollIntoView({behavior:'smooth', block:'center'}); });
                    cancel && cancel.addEventListener('click', () => { rej.style.display='none'; app.style.opacity='1'; app.style.pointerEvents='auto'; });
                })();
            </script>
        <?php elseif ($hr['status'] === 'approved' && $hr['employee_id']): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;">
                Dipendente creato: <a href="employees.php?action=view&id=<?= (int)$hr['employee_id'] ?>"><strong>vedi profilo</strong></a>.
                Ora il consulente puo' caricare il contratto.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($byCat['contract']) || !empty($byCat['signature_image'])): ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:1.25rem;">
                <h3 style="margin-top:0; font-size:1rem;">Contratto</h3>
                <?php foreach ($byCat['contract'] ?? [] as $f): ?>
                    <div style="padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.88rem;">
                        <a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank"><strong><?= htmlspecialchars($f['original_name']) ?></strong></a>
                        <span style="color:#94a3b8; font-size:.78rem; margin-left:.5rem;"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($byCat['signature_image'] ?? [] as $f): ?>
                    <div style="margin-top:.75rem; padding:.85rem; background:#f0fdf4; border-radius:8px; font-size:.85rem;">
                        <strong style="color:#16a34a;">✓ Firmato dal dipendente</strong>
                        <div style="margin-top:.5rem; color:#475569;">Data: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($f['uploaded_at']))) ?></div>
                        <?php if ($f['signed_ip']): ?><div style="color:#475569;">IP: <code><?= htmlspecialchars($f['signed_ip']) ?></code></div><?php endif; ?>
                        <?php if ($f['signature_hash']): ?><div style="color:#475569;">SHA256 contratto: <code style="font-size:.72rem;"><?= htmlspecialchars($f['signature_hash']) ?></code></div><?php endif; ?>
                        <div style="margin-top:.5rem;"><a href="?action=file&id=<?= $hr['id'] ?>&file_id=<?= $f['id'] ?>" target="_blank">Vedi immagine firma</a></div>
                    </div>
                <?php endforeach; ?>
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
                    const dt = new DataTransfer();
                    Array.from(input.files || []).forEach(f => dt.items.add(f));
                    Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
                    input.files = dt.files;
                    renderList();
                }
            });
        });

        // === Auto-compilazione da Codice Fiscale ===
        const cfInput = document.querySelector('input[name="fiscal_code"]');
        if (cfInput) {
            const fill = (name, val) => {
                const el = document.querySelector('[name="' + name + '"]');
                if (el && !el.value) el.value = val;
            };
            const lookup = async () => {
                const cf = cfInput.value.toUpperCase().replace(/\s/g,'');
                if (cf.length !== 16) return;
                try {
                    const r = await fetch('<?= PUBLIC_URL ?>/api/lookup.php?action=cf&cf=' + encodeURIComponent(cf), {credentials:'same-origin'});
                    const d = await r.json();
                    if (d.error) return;
                    fill('employee_birth_date', d.birth_date);
                    fill('birth_state', d.birth_state || 'Italia');
                    fill('birth_city', d.birth_city);
                    if (d.birth_city) cfInput.dataset.cfOk = '1';
                } catch (e) {}
            };
            cfInput.addEventListener('blur', lookup);
            cfInput.addEventListener('input', () => { if (cfInput.value.length === 16) lookup(); });
        }

        // === Auto-compilazione indirizzo (Nominatim) ===
        const addrInput = document.querySelector('input[name="residence_address"]');
        if (addrInput) {
            let timer;
            const lookupAddr = async () => {
                const q = addrInput.value.trim();
                if (q.length < 6) return;
                try {
                    const r = await fetch('<?= PUBLIC_URL ?>/api/lookup.php?action=address&q=' + encodeURIComponent(q), {credentials:'same-origin'});
                    const d = await r.json();
                    if (d.error) return;
                    const fill = (name, val) => {
                        const el = document.querySelector('[name="' + name + '"]');
                        if (el && !el.value && val) el.value = val;
                    };
                    fill('residence_cap', d.cap);
                    fill('residence_city', d.city);
                    fill('residence_province', d.province);
                } catch (e) {}
            };
            addrInput.addEventListener('blur', () => { clearTimeout(timer); timer = setTimeout(lookupAddr, 200); });
        }
    });
</script>
<?php
?>
<div style="width:100%; margin:1.5rem 0;">
    <a href="hire-requests.php" class="btn-back" style="margin-bottom:1rem;">Indietro</a>
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
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">Richiesta eliminata.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['del_err'])): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;">Impossibile eliminare: <?= htmlspecialchars($_GET['del_err']) ?></div>
    <?php endif; ?>
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
                        'contract_pending'   => '#044bff',
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
