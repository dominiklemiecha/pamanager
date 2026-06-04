<?php
/**
 * Firma contratto - Area dipendente.
 * Visualizza il PDF del contratto + canvas per firma grafica.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$emp = Auth::getEmployee();
$id = (int)($_GET['id'] ?? 0);

// Verifica accesso
$hr = $id > 0 ? Database::fetchOne("SELECT * FROM hire_requests WHERE id = ?", [$id]) : null;
if (!$hr || (int)$hr['employee_id'] !== (int)$emp['id']) {
    http_response_code(404);
    exit('Contratto non trovato');
}

// Immagine firma inline
if (($_GET['action'] ?? '') === 'signature') {
    $signed = Database::fetchOne(
        "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'signature_image' ORDER BY id DESC LIMIT 1",
        [$id]
    );
    if (!$signed) { http_response_code(404); exit('Firma non trovata'); }
    $path = HireRequest::fileFsPath($signed);
    if (!is_file($path)) { http_response_code(404); exit('File firma non trovato'); }
    header('Content-Type: image/png');
    readfile($path);
    exit;
}

// Download PDF inline
if (($_GET['action'] ?? '') === 'pdf') {
    $contract = Database::fetchOne(
        "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1",
        [$id]
    );
    if (!$contract) { http_response_code(404); exit('Contratto non disponibile'); }
    $path = HireRequest::fileFsPath($contract);
    if (!is_file($path)) { http_response_code(404); exit('File non trovato'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="contratto.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// POST firma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign') {
    CSRF::verifyOrDie();
    $sig = $_POST['signature'] ?? '';
    $res = HireRequest::signContract($id, $sig);
    if ($res['success']) {
        header('Location: contract-sign.php?id=' . $id . '&signed=1');
        exit;
    }
    $error = $res['error'] ?? 'Errore firma';
}

$contract = Database::fetchOne(
    "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1",
    [$id]
);
$signed = Database::fetchOne(
    "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'signature_image' ORDER BY id DESC LIMIT 1",
    [$id]
);

$pageTitle = 'Firma contratto';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<div style="width:100%; max-width:1100px; margin:1.5rem auto; padding:0 1rem;">
    <h1 style="font-size:1.5rem; margin:0 0 .25rem;">Contratto di assunzione</h1>
    <p style="color:#64748b; margin:0 0 1rem;">Leggi attentamente il contratto e firmalo qui sotto.</p>

    <?php if ($hr['status'] === 'contract_pending'): ?>
        <div style="padding:.85rem 1.1rem; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #eab308; border-radius:8px; margin-bottom:1.5rem; font-size:.88rem; color:#854d0e;">
            <strong>Devi firmare il contratto per accedere al portale.</strong> Fino alla firma non puoi navigare verso altre sezioni.
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['signed']) || $hr['status'] === 'contract_signed'): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">
            <strong>✓ Contratto firmato</strong> il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($signed['uploaded_at'] ?? 'now'))) ?>.
            Il documento e ora disponibile nei tuoi documenti.
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$contract): ?>
        <div class="alert alert-warning">Il contratto non e ancora disponibile. Attendi che il consulente lo carichi.</div>
    <?php else: ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:0;">
                <div style="padding:.85rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
                    <strong style="font-size:.95rem;">Documento contratto</strong>
                    <a href="contract-sign.php?id=<?= $id ?>&action=pdf" target="_blank" style="font-size:.82rem;">Apri in nuova scheda</a>
                </div>
                <iframe src="contract-sign.php?id=<?= $id ?>&action=pdf" style="width:100%; height:600px; border:0;"></iframe>
            </div>
        </div>

        <?php if ($hr['status'] === 'contract_pending'): ?>
            <div class="card" style="border:2px solid #044bff;">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="margin-top:0; font-size:1rem;">Firma qui sotto</h3>
                    <p style="font-size:.85rem; color:#64748b;">Usa il dito (mobile) o il mouse per disegnare la tua firma nello spazio bianco.</p>
                    <div style="border:1.5px dashed #cbd5e1; border-radius:10px; background:#fff; position:relative;">
                        <canvas id="sigCanvas" style="width:100%; height:200px; cursor:crosshair; touch-action:none; display:block; border-radius:10px;"></canvas>
                        <button type="button" id="clearSig" style="position:absolute; top:8px; right:8px; padding:4px 10px; border:1px solid #cbd5e1; background:#fff; border-radius:6px; cursor:pointer; font-size:.78rem;">Cancella</button>
                    </div>
                    <p style="font-size:.72rem; color:#94a3b8; margin-top:.5rem;">Firmando dichiari di aver letto e accettato il contratto. Vengono registrati data, ora, IP e hash del documento ai fini legali.</p>
                    <form method="POST" action="contract-sign.php?id=<?= $id ?>" id="signForm" style="margin-top:1rem;">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="sign">
                        <input type="hidden" name="signature" id="signatureData">
                        <button type="submit" class="btn btn-primary" id="submitSig" disabled style="padding:.7rem 1.5rem; font-weight:600;">Firma e conferma</button>
                    </form>
                </div>
            </div>

            <script>
            (function(){
                const canvas = document.getElementById('sigCanvas');
                const ctx = canvas.getContext('2d');
                const dpi = window.devicePixelRatio || 1;
                function fit() {
                    const r = canvas.getBoundingClientRect();
                    canvas.width = r.width * dpi;
                    canvas.height = r.height * dpi;
                    ctx.scale(dpi, dpi);
                    ctx.lineWidth = 2.2;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#0f172a';
                }
                fit();
                window.addEventListener('resize', fit);
                let drawing = false, hasDrawn = false, last = null;
                function pos(e) {
                    const r = canvas.getBoundingClientRect();
                    const t = e.touches && e.touches[0];
                    const x = (t ? t.clientX : e.clientX) - r.left;
                    const y = (t ? t.clientY : e.clientY) - r.top;
                    return {x, y};
                }
                function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    const p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(last.x, last.y);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                    last = p;
                    hasDrawn = true;
                    document.getElementById('submitSig').disabled = false;
                }
                function end() { drawing = false; last = null; }
                canvas.addEventListener('mousedown', start);
                canvas.addEventListener('mousemove', move);
                canvas.addEventListener('mouseup', end);
                canvas.addEventListener('mouseleave', end);
                canvas.addEventListener('touchstart', start, {passive:false});
                canvas.addEventListener('touchmove', move, {passive:false});
                canvas.addEventListener('touchend', end);

                document.getElementById('clearSig').addEventListener('click', () => {
                    ctx.clearRect(0,0,canvas.width,canvas.height);
                    hasDrawn = false;
                    document.getElementById('submitSig').disabled = true;
                });

                document.getElementById('signForm').addEventListener('submit', (e) => {
                    if (!hasDrawn) { e.preventDefault(); alert('Disegna la firma prima di confermare.'); return; }
                    document.getElementById('signatureData').value = canvas.toDataURL('image/png');
                });
            })();
            </script>
        <?php elseif ($signed): ?>
            <div class="card">
                <div class="card-body" style="padding:1.5rem;">
                    <h3 style="margin-top:0; font-size:1rem;">La tua firma</h3>
                    <img src="contract-sign.php?id=<?= $id ?>&action=signature" style="max-width:300px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; padding:.5rem;">
                    <div style="margin-top:1rem; font-size:.82rem; color:#64748b;">
                        Firmato il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($signed['uploaded_at']))) ?><br>
                        IP: <code><?= htmlspecialchars($signed['signed_ip']) ?></code><br>
                        SHA256 contratto: <code style="font-size:.72rem;"><?= htmlspecialchars($signed['signature_hash']) ?></code>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
