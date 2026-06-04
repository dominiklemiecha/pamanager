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

// Download PDF inline (sovrascrive headers anti-framing per consentire l'iframe same-origin)
if (($_GET['action'] ?? '') === 'pdf') {
    $contract = Database::fetchOne(
        "SELECT * FROM hire_request_files WHERE hire_request_id = ? AND category = 'contract' ORDER BY id DESC LIMIT 1",
        [$id]
    );
    if (!$contract) { http_response_code(404); exit('Contratto non disponibile'); }
    $path = HireRequest::fileFsPath($contract);
    if (!is_file($path)) { http_response_code(404); exit('File non trovato'); }
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'");
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
$__isPending = $hr['status'] === 'contract_pending';
$__justSigned = !empty($_GET['signed']) && $hr['status'] === 'contract_signed';

// === Schermata di benvenuto post-firma (fullscreen, con coriandoli) ===
if ($__justSigned):
    $__company = Database::fetchOne("SELECT name FROM companies WHERE id = ?", [(int)$hr['company_id']]);
    $__companyName = $__company['name'] ?? 'Connecteed HR';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benvenuto in <?= htmlspecialchars($__companyName) ?>!</title>
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css">
    <style>
        html, body { margin:0; padding:0; height:100%; overflow:hidden; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        body {
            background: radial-gradient(circle at 30% 20%, #4fa1ff 0%, #044bff 45%, #003dd6 100%);
            display:flex; align-items:center; justify-content:center;
            color:#fff;
        }
        #confetti-canvas { position:fixed; inset:0; pointer-events:none; z-index:10; }
        .welcome {
            position:relative; z-index:5; text-align:center;
            padding:2.5rem 1.5rem; max-width:640px;
            animation: pop .6s cubic-bezier(.18,1.4,.34,1) both;
        }
        @keyframes pop {
            0% { transform: scale(.6) translateY(20px); opacity:0; }
            100% { transform: scale(1) translateY(0); opacity:1; }
        }
        .welcome__emoji { font-size:5rem; line-height:1; margin-bottom:1rem; animation: wave 1.6s ease-in-out infinite; transform-origin: 70% 70%; display:inline-block; }
        @keyframes wave { 0%,60%,100%{ transform: rotate(0); } 10%,30% { transform: rotate(14deg); } 20%,40% { transform: rotate(-8deg); } }
        .welcome__title { font-size:clamp(1.8rem, 5vw, 3rem); font-weight:800; margin:0 0 .75rem; letter-spacing:-.02em; line-height:1.1; }
        .welcome__company { color:#fff; background:rgba(255,255,255,.18); padding:.05em .35em; border-radius:.25em; }
        .welcome__sub { font-size:1.05rem; opacity:.92; margin:0 auto 2rem; max-width:480px; line-height:1.5; }
        .welcome__btn {
            display:inline-flex; align-items:center; gap:8px;
            padding:.85rem 1.6rem; background:#fff; color:#044bff;
            border-radius:999px; font-weight:700; font-size:1rem;
            text-decoration:none; cursor:pointer; border:0;
            box-shadow:0 12px 30px -6px rgba(0,0,0,.35);
            transition: transform .15s, box-shadow .15s;
        }
        .welcome__btn:hover { transform: translateY(-2px); box-shadow:0 18px 38px -8px rgba(0,0,0,.45); }
        .welcome__btn svg { width:16px; height:16px; }
        .welcome__footnote { margin-top:1.5rem; font-size:.78rem; opacity:.7; }
    </style>
</head>
<body>
    <canvas id="confetti-canvas"></canvas>
    <div class="welcome">
        <div class="welcome__emoji">🎉</div>
        <h1 class="welcome__title">
            Benvenuto nella famiglia<br>
            <span class="welcome__company"><?= htmlspecialchars($__companyName) ?></span>!
        </h1>
        <p class="welcome__sub">
            Il tuo contratto è stato firmato con successo.<br>
            Siamo davvero felici di averti con noi. Ora puoi accedere al portale e iniziare il tuo viaggio.
        </p>
        <a href="<?= PUBLIC_URL ?>/employee/" class="welcome__btn">
            Entra nel portale
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
        <div class="welcome__footnote">Firmato il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($signed['uploaded_at'] ?? 'now'))) ?></div>
    </div>

    <script>
    // Coriandoli DIY (no dipendenze esterne)
    (function(){
        const canvas = document.getElementById('confetti-canvas');
        const ctx = canvas.getContext('2d');
        const dpi = window.devicePixelRatio || 1;
        function fit() {
            canvas.width = innerWidth * dpi;
            canvas.height = innerHeight * dpi;
            canvas.style.width = innerWidth + 'px';
            canvas.style.height = innerHeight + 'px';
            ctx.setTransform(dpi, 0, 0, dpi, 0, 0);
        }
        fit();
        window.addEventListener('resize', fit);

        const colors = ['#ffffff','#81c8ff','#4fa1ff','#fde047','#f472b6','#34d399','#fbbf24'];
        const particles = [];
        function spawn(burstCount, originY) {
            for (let i = 0; i < burstCount; i++) {
                particles.push({
                    x: Math.random() * innerWidth,
                    y: originY ?? -20 - Math.random() * 80,
                    vx: (Math.random() - 0.5) * 4,
                    vy: 2 + Math.random() * 4,
                    g: 0.12 + Math.random() * 0.08,
                    size: 6 + Math.random() * 8,
                    rot: Math.random() * Math.PI,
                    vr: (Math.random() - 0.5) * 0.25,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    shape: Math.random() < 0.5 ? 'rect' : 'circle',
                });
            }
        }
        // Burst iniziali
        spawn(180);
        setTimeout(() => spawn(120), 350);
        setTimeout(() => spawn(120), 800);
        // Sparate dai bordi inferiori
        setTimeout(() => {
            for (let k = 0; k < 80; k++) {
                particles.push({
                    x: Math.random() < 0.5 ? -10 : innerWidth + 10,
                    y: innerHeight - 50,
                    vx: (Math.random() < 0.5 ? 1 : -1) * (6 + Math.random() * 4),
                    vy: -8 - Math.random() * 6,
                    g: 0.18,
                    size: 6 + Math.random() * 8,
                    rot: Math.random() * Math.PI,
                    vr: (Math.random() - 0.5) * 0.3,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    shape: Math.random() < 0.5 ? 'rect' : 'circle',
                });
            }
        }, 300);

        function draw() {
            ctx.clearRect(0, 0, innerWidth, innerHeight);
            for (let i = particles.length - 1; i >= 0; i--) {
                const p = particles[i];
                p.vy += p.g;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vr;
                p.vx *= 0.995;
                if (p.y > innerHeight + 30) { particles.splice(i, 1); continue; }
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                if (p.shape === 'rect') {
                    ctx.fillRect(-p.size/2, -p.size/3, p.size, p.size * 0.6);
                } else {
                    ctx.beginPath();
                    ctx.arc(0, 0, p.size/2, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.restore();
            }
            requestAnimationFrame(draw);
        }
        draw();
    })();
    </script>
</body>
</html>
<?php
exit;
endif;
// Se in attesa firma: render full-screen modal senza header/footer normali per zero distrazioni
if ($__isPending && empty($_GET['signed'])):
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Firma contratto - Connecteed HR</title>
    <link rel="stylesheet" href="<?= PUBLIC_URL ?>/assets/css/style.css">
    <style>
        html, body { margin:0; padding:0; height:100%; background:rgba(15, 23, 42, 0.72); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; overflow:hidden; }
        .sign-modal {
            position:fixed; inset:16px; max-width:1100px; margin:auto;
            background:#fff; border-radius:16px;
            box-shadow:0 30px 80px -10px rgba(0,0,0,.45);
            display:flex; flex-direction:column;
            overflow:hidden;
        }
        .sign-modal__header {
            flex:0 0 auto;
            padding:1.1rem 1.5rem;
            border-bottom:1px solid #e2e8f0;
            background:linear-gradient(90deg,#eff5ff 0%, #fff 100%);
            display:flex; align-items:center; gap:12px;
        }
        .sign-modal__header svg { color:#044bff; }
        .sign-modal__title { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0; }
        .sign-modal__subtitle { font-size:.82rem; color:#64748b; margin:2px 0 0; }
        .sign-modal__warning {
            margin:0; padding:.75rem 1.5rem;
            background:#fffbeb; border-bottom:1px solid #fde68a;
            font-size:.82rem; color:#854d0e;
        }
        .sign-modal__body {
            flex:1 1 auto; min-height:0;
            display:flex; flex-direction:column;
        }
        .sign-modal__pdf {
            flex:1 1 auto; min-height:0;
            background:#1e293b; position:relative;
        }
        .sign-modal__pdf iframe {
            width:100%; height:100%; border:0; display:block;
        }
        .sign-modal__sign {
            flex:0 0 auto;
            padding:1rem 1.5rem 1.25rem;
            border-top:1px solid #e2e8f0;
            background:#fff;
        }
        .sign-modal__sign h3 { margin:0 0 .25rem; font-size:.95rem; color:#0f172a; }
        .sign-modal__sign p { margin:0 0 .65rem; font-size:.78rem; color:#64748b; }
        .sig-wrap {
            border:1.5px dashed #cbd5e1; border-radius:10px;
            background:#fff; position:relative; height:130px;
        }
        #sigCanvas { width:100%; height:100%; display:block; border-radius:10px; cursor:crosshair; touch-action:none; }
        #clearSig {
            position:absolute; top:8px; right:8px;
            padding:5px 10px; border:1px solid #cbd5e1;
            background:#fff; border-radius:6px; cursor:pointer; font-size:.75rem; color:#475569;
        }
        .sign-modal__legal { font-size:.7rem; color:#94a3b8; margin:.55rem 0 .75rem; }
        .sign-modal__actions { display:flex; gap:.75rem; align-items:center; justify-content:flex-end; }
        #submitSig {
            padding:.7rem 1.5rem; font-weight:600; font-size:.9rem;
            background:#044bff; color:#fff; border:0; border-radius:8px; cursor:pointer;
            transition:background .15s, transform .1s;
        }
        #submitSig:hover:not(:disabled) { background:#003dd6; }
        #submitSig:disabled { background:#cbd5e1; cursor:not-allowed; }
        .sign-modal__err { background:#fee2e2; color:#991b1b; padding:.5rem 1rem; border-radius:6px; margin-bottom:.75rem; font-size:.82rem; }
        @media (max-width: 720px) {
            .sign-modal { inset:0; border-radius:0; }
            .sign-modal__header { padding:.8rem 1rem; }
            .sign-modal__sign { padding:.75rem 1rem 1rem; }
            .sig-wrap { height:110px; }
        }
    </style>
</head>
<body>
    <div class="sign-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="sign-modal__header">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <div style="flex:1;">
                <h2 id="modalTitle" class="sign-modal__title">Contratto di assunzione da firmare</h2>
                <div class="sign-modal__subtitle">Sfoglia il documento, poi firma in fondo per accedere al portale.</div>
            </div>
            <a href="contract-pdf.php?id=<?= $id ?>" target="_blank" style="font-size:.8rem; color:#044bff;">Apri PDF in nuova scheda</a>
        </div>

        <div class="sign-modal__warning">
            <strong>Non puoi accedere alle altre sezioni</strong> finché non firmi il contratto.
        </div>

        <div class="sign-modal__body">
            <div class="sign-modal__pdf">
                <iframe src="contract-pdf.php?id=<?= $id ?>#view=FitH" title="Contratto"></iframe>
            </div>

            <div class="sign-modal__sign">
                <h3>Firma nello spazio sotto</h3>
                <p>Usa il dito su mobile o il mouse su desktop.</p>

                <?php if (!empty($error)): ?>
                    <div class="sign-modal__err"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="sig-wrap">
                    <canvas id="sigCanvas"></canvas>
                    <button type="button" id="clearSig">Cancella</button>
                </div>
                <div class="sign-modal__legal">
                    Firmando dichiari di aver letto e accettato il contratto. Vengono registrati data, ora, IP e hash SHA256 del documento ai fini legali.
                </div>
                <form method="POST" action="contract-sign.php?id=<?= $id ?>" id="signForm" class="sign-modal__actions">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="sign">
                    <input type="hidden" name="signature" id="signatureData">
                    <button type="submit" id="submitSig" disabled>Firma e conferma</button>
                </form>
            </div>
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
            ctx.setTransform(1,0,0,1,0,0);
            ctx.clearRect(0,0,canvas.width,canvas.height);
            ctx.scale(dpi, dpi);
            ctx.lineWidth = 2.2; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.strokeStyle='#0f172a';
            hasDrawn = false;
            document.getElementById('submitSig').disabled = true;
        });
        document.getElementById('signForm').addEventListener('submit', (e) => {
            if (!hasDrawn) { e.preventDefault(); alert('Disegna la firma prima di confermare.'); return; }
            document.getElementById('signatureData').value = canvas.toDataURL('image/png');
        });
    })();
    </script>
</body>
</html>
<?php
exit; // niente header/footer normali quando il modal è attivo
endif;

include dirname(__DIR__) . '/includes/header-employee.php';
?>

<div style="width:100%; max-width:1100px; margin:1.5rem auto; padding:0 1rem;">
    <h1 style="font-size:1.5rem; margin:0 0 .25rem;">Contratto di assunzione</h1>
    <p style="color:#64748b; margin:0 0 1rem;">Il tuo contratto firmato.</p>

    <?php if (!empty($_GET['signed']) || $hr['status'] === 'contract_signed'): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">
            <strong>✓ Contratto firmato</strong> il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($signed['uploaded_at'] ?? 'now'))) ?>.
            Il documento è ora disponibile nei tuoi documenti.
        </div>
    <?php endif; ?>

    <?php if (!$contract): ?>
        <div class="alert alert-warning">Il contratto non è ancora disponibile.</div>
    <?php else: ?>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-body" style="padding:0;">
                <div style="padding:.85rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
                    <strong style="font-size:.95rem;">Documento contratto</strong>
                    <a href="contract-pdf.php?id=<?= $id ?>" target="_blank" style="font-size:.82rem;">Apri in nuova scheda</a>
                </div>
                <iframe src="contract-pdf.php?id=<?= $id ?>" style="width:100%; height:600px; border:0;"></iframe>
            </div>
        </div>

        <?php if ($signed): ?>
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
