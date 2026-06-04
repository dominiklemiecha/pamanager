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
    header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    readfile($path);
    exit;
}

$pageTitle = 'Richieste di assunzione';
include dirname(__DIR__) . '/includes/header-admin.php';

if ($id > 0) {
    $hr = HireRequest::getById($id);
    if (!$hr) {
        echo '<div class="alert alert-error">Richiesta non trovata o non assegnata a te.</div>';
        include dirname(__DIR__) . '/includes/footer-admin.php';
        exit;
    }
    $files = HireRequest::getFiles($id);
    $byCat = [];
    foreach ($files as $f) $byCat[$f['category']][] = $f;

    $statusColors = [
        'awaiting_prospects' => '#eab308', 'prospects_review' => '#0ea5e9',
        'approved' => '#16a34a', 'contract_pending' => '#7c3aed',
        'contract_signed' => '#16a34a', 'rejected' => '#dc2626', 'cancelled' => '#64748b',
    ];
    $statusColor = $statusColors[$hr['status']] ?? '#64748b';
?>
<div style="width:100%; margin:1.5rem 0;">
    <div style="display:flex; gap:10px; align-items:center; margin-bottom:1rem;">
        <a href="hire-requests.php" class="btn-back">Indietro</a>
        <h1 style="margin:0; flex:1; font-size:1.5rem;"><?= htmlspecialchars($hr['employee_first_name'] . ' ' . $hr['employee_last_name']) ?></h1>
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
            $done   = !$__isRejected && $__currentIdx !== false && $i < $__currentIdx;
            $active = !$__isRejected && $__currentIdx !== false && $i === $__currentIdx;
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

    <?php if ($hr['status'] === 'awaiting_prospects'): ?>
        <div class="alert alert-info" style="margin-bottom:1rem;">
            <strong>Azione richiesta:</strong> carica i prospetti di assunzione. (Funzionalita' in arrivo nella fase 2.)
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Dati anagrafici</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Datore di lavoro</div><div><?= htmlspecialchars($hr['employer_name']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Codice fiscale</div><div><?= htmlspecialchars($hr['fiscal_code']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Data di nascita</div><div><?= htmlspecialchars($hr['employee_birth_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Nato a</div><div><?= htmlspecialchars($hr['birth_city'] . ' (' . $hr['birth_state'] . ')') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Residenza</div><div><?= htmlspecialchars($hr['residence_address'] . ', ' . $hr['residence_cap'] . ' ' . $hr['residence_city'] . ' (' . $hr['residence_province'] . ')') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Stato civile</div><div><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $hr['marital_status']))) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Istruzione</div><div><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $hr['education_level']))) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Email</div><div><?= htmlspecialchars($hr['employee_email']) ?></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding:1.25rem;">
            <h3 style="margin-top:0; font-size:1rem;">Contratto richiesto</h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; font-size:.88rem;">
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Tipologia</div><div><?= htmlspecialchars(HireRequest::contractTypesLabels($hr)) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Inizio</div><div><?= htmlspecialchars($hr['start_date']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Fine</div><div><?= htmlspecialchars($hr['end_date'] ?: '— (indeterminato)') ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Ore settimanali</div><div><?= htmlspecialchars($hr['weekly_hours']) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Giorni</div><div><?= htmlspecialchars(HireRequest::workDaysLabels($hr['work_days'])) ?></div></div>
                <div><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Sede</div><div><?= htmlspecialchars($hr['workplace']) ?></div></div>
            </div>
            <div style="margin-top:1rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Mansioni</div><div><?= nl2br(htmlspecialchars($hr['role_description'])) ?></div></div>
            <?php if ($hr['notes']): ?><div style="margin-top:.75rem;"><div style="color:#64748b; font-size:.72rem; text-transform:uppercase;">Note admin</div><div><?= nl2br(htmlspecialchars($hr['notes'])) ?></div></div><?php endif; ?>
        </div>
    </div>

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
       AND (hr.assigned_consulente_user_id = ? OR hr.assigned_consulente_user_id IS NULL)
     ORDER BY hr.created_at DESC",
    [$cid, (int)$user['id']]
);
$statusColors = [
    'awaiting_prospects' => '#eab308', 'prospects_review' => '#0ea5e9',
    'approved' => '#16a34a', 'contract_pending' => '#7c3aed',
    'contract_signed' => '#16a34a', 'rejected' => '#dc2626', 'cancelled' => '#64748b',
];
?>
<div style="width:100%; margin:1.5rem 0;">
    <h1 style="margin:0 0 1rem; font-size:1.5rem;">Richieste di assunzione</h1>

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
