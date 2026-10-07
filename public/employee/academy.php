<?php
/**
 * Academy - area dipendente.
 * Elenco dei corsi assegnati e pagina del corso: "Inizia", apertura dei materiali
 * (ogni apertura viene registrata da academy-file.php), "Completato" con
 * dichiarazione, download dell'attestato quando HR lo ha generato.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$employeeId = (int) $employee['id'];
$courseId = (int) ($_GET['course'] ?? $_POST['course_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $assignment = Academy::getEmployeeAssignment($employeeId, $courseId);
    $url = PUBLIC_URL . '/employee/academy.php?course=' . $courseId;
    if (!$assignment) {
        header('Location: ' . PUBLIC_URL . '/employee/academy.php');
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'start') {
        Academy::start($assignment);
        header('Location: ' . $url);
        exit;
    }
    if ($action === 'complete') {
        $r = Academy::complete($assignment, !empty($_POST['declaration']));
        header('Location: ' . $url . ($r['success'] ? '&done=1' : '&err=' . urlencode($r['error'])));
        exit;
    }
    header('Location: ' . $url);
    exit;
}

Academy::runDueReminders((int) $employee['company_id']);

$fmtDate = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '';
$statusBadge = ['not_started' => 'badge-neutral', 'in_progress' => 'badge-primary', 'completed' => 'badge-success', 'overdue' => 'badge-danger'];

$course = null;
if ($courseId > 0) {
    $assignment = Academy::getEmployeeAssignment($employeeId, $courseId);
    if (!$assignment) {
        header('Location: ' . PUBLIC_URL . '/employee/academy.php');
        exit;
    }
    $course = Academy::getCourse($courseId);
    $materials = Academy::getMaterials($courseId);
    $opened = Academy::openedMaterialIds((int) $assignment['id']);
    $allOpened = count(array_filter($materials, fn($m) => !in_array((int) $m['id'], $opened, true))) === 0;
    $status = Academy::statusOf($assignment, $course['due_date']);
} else {
    $courses = Academy::getEmployeeCourses($employeeId);
}

$pageTitle = $course ? $course['title'] : 'Academy';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<style>
.ea-hero { padding: var(--sp-5) var(--sp-6); margin-bottom: var(--sp-5); display: block; }
.ea-hero h2 { font-family: 'Space Grotesk', var(--font-sans); font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; color: var(--accent); }
.ea-hero p { margin: 0; font-size: var(--text-sm); color: #6e7191; }
.ea-back { font-size: 0.82rem; color: #64748b; text-decoration: none; display: inline-block; margin-bottom: 6px; }
.ea-alert { padding: 0.75rem 0.9rem; border-radius: 8px; font-size: 0.88rem; margin-bottom: var(--sp-4); }
.ea-alert.ok { background: #dcfce7; color: #166534; }
.ea-alert.err { background: #fee2e2; color: #991b1b; }
.ea-list { display: grid; gap: var(--sp-3); }
.ea-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.2rem; text-decoration: none; color: inherit; }
.ea-item:hover { box-shadow: 0 6px 20px rgba(11,58,164,.08); }
.ea-item .body { flex: 1; min-width: 0; }
.ea-item h4 { margin: 0 0 4px; font-size: 0.98rem; color: #0f172a; }
.ea-item .meta { font-size: 0.8rem; color: #64748b; }
.ea-item .arrow { color: #94a3b8; font-size: 1.2rem; }
.ea-progress { height: 6px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 0.6rem; max-width: 260px; }
.ea-progress > span { display: block; height: 100%; background: var(--accent); }
.ea-section { padding: 1.3rem; margin-bottom: var(--sp-4); }
.ea-section h3 { margin: 0 0 0.9rem; font-size: 1rem; }
.ea-mat { display: flex; align-items: center; gap: 0.8rem; padding: 0.75rem 0; border-top: 1px solid #eef2f7; }
.ea-mat:first-of-type { border-top: none; }
.ea-mat .ic { width: 38px; height: 38px; border-radius: 9px; background: #eef2ff; color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 0.66rem; font-weight: 700; text-transform: uppercase; flex-shrink: 0; }
.ea-mat .ic.done { background: #dcfce7; color: #166534; font-size: 1rem; }
.ea-mat .tt { flex: 1; min-width: 0; }
.ea-mat .tt strong { display: block; font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; }
.ea-mat .tt span { font-size: 0.76rem; color: #94a3b8; }
.ea-viewer { display: none; margin-top: var(--sp-4); }
.ea-viewer.open { display: block; }
.ea-viewer-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; }
.ea-viewer iframe { width: 100%; height: 75vh; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
.ea-viewer video, .ea-viewer img { width: 100%; max-height: 75vh; border-radius: 10px; background: #000; object-fit: contain; }
.ea-viewer audio { width: 100%; }
.ea-check { display: flex; gap: 0.6rem; align-items: flex-start; font-size: 0.88rem; color: #334155; margin: 0.4rem 0 1rem; }
.ea-check input { margin-top: 3px; width: 18px; height: 18px; }
.ea-done { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
@media (max-width: 640px) { .ea-viewer iframe { height: 65vh; } }
</style>

<?php if (!$course): /* ======================= ELENCO ======================= */ ?>

<div class="welcome-card ea-hero">
    <h2>Academy</h2>
    <p>I corsi che ti sono stati assegnati. Apri tutti i materiali e alla fine premi "Completato": quando HR genera l'attestato lo trovi qui.</p>
</div>

<?php if (empty($courses)): ?>
    <section class="card ea-section"><p style="margin:0; color:#6e7191;">Non hai corsi assegnati al momento.</p></section>
<?php else: ?>
    <div class="ea-list">
        <?php foreach ($courses as $c):
            $st = Academy::statusOf($c, $c['due_date']);
            $tot = (int) $c['material_count'];
            $pct = $st === 'completed' ? 100 : ($tot > 0 ? round(min((int) $c['opened_count'], $tot) / $tot * 100) : 0); ?>
            <a class="card ea-item" href="?course=<?= (int) $c['course_id'] ?>">
                <div class="body">
                    <h4><?= htmlspecialchars($c['title']) ?></h4>
                    <div class="meta">
                        <span class="badge badge-dot <?= $statusBadge[$st] ?>"><?= Academy::STATUS_LABELS[$st] ?></span>
                        <?php if ($c['due_date'] && $st !== 'completed'): ?> &nbsp;entro il <?= $fmtDate($c['due_date']) ?><?php endif; ?>
                        <?php if ($c['certificate_id'] && empty($c['employee_downloaded_at'])): ?>
                            &nbsp;<span class="badge badge-dot badge-success">Nuovo attestato</span>
                        <?php elseif ($c['certificate_id']): ?> &nbsp;· attestato disponibile<?php endif; ?>
                    </div>
                    <div class="ea-progress"><span style="width: <?= $pct ?>%;"></span></div>
                </div>
                <span class="arrow">›</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php else: /* ======================= CORSO ======================= */ ?>

<div class="welcome-card ea-hero">
    <a class="ea-back" href="<?= PUBLIC_URL ?>/employee/academy.php">&larr; I miei corsi</a>
    <h2><?= htmlspecialchars($course['title']) ?></h2>
    <p>
        <span class="badge badge-dot <?= $statusBadge[$status] ?>"><?= Academy::STATUS_LABELS[$status] ?></span>
        <?php if ($course['duration_hours'] !== null): ?> &nbsp;<?= rtrim(rtrim(number_format((float) $course['duration_hours'], 2, ',', ''), '0'), ',') ?> ore<?php endif; ?>
        <?php if ($course['due_date'] && $status !== 'completed'): ?> &nbsp;· da completare entro il <?= $fmtDate($course['due_date']) ?><?php endif; ?>
    </p>
</div>

<?php if (isset($_GET['done'])): ?><div class="ea-alert ok">Corso completato. Riceverai una notifica quando l'attestato sarà disponibile.</div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="ea-alert err"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

<?php if ($course['description']): ?>
    <section class="card ea-section">
        <p style="margin:0; white-space:pre-line; color:#334155; font-size:0.92rem;"><?= htmlspecialchars($course['description']) ?></p>
    </section>
<?php endif; ?>

<?php if (!$assignment['started_at']): ?>
    <section class="card ea-section" style="text-align:center;">
        <p style="margin:0 0 1rem; color:#475569;">Quando sei pronto premi <strong>Inizia</strong>: da quel momento vengono registrati data e ora dei tuoi accessi ai materiali.</p>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <button type="submit" class="btn btn-primary">Inizia il corso</button>
        </form>
    </section>
<?php else: ?>
    <section class="card ea-section">
        <h3>Materiali <span style="font-weight:500; color:#94a3b8; font-size:0.85rem;">(<?= count(array_intersect($opened, array_map(fn($m) => (int) $m['id'], $materials))) ?>/<?= count($materials) ?> aperti)</span></h3>
        <?php foreach ($materials as $m):
            $isOpened = in_array((int) $m['id'], $opened, true);
            $src = PUBLIC_URL . '/academy-file.php?m=' . (int) $m['id'];
            $mime = (string) $m['mime_type'];
            $view = $m['kind'] === 'link' ? 'link'
                : (str_starts_with($mime, 'video/') ? 'video'
                : (str_starts_with($mime, 'audio/') ? 'audio'
                : (str_starts_with($mime, 'image/') ? 'image'
                : ($mime === 'application/pdf' ? 'pdf' : 'download'))));
            $ext = $m['kind'] === 'link' ? 'link' : strtolower(pathinfo((string) $m['original_name'], PATHINFO_EXTENSION)); ?>
            <div class="ea-mat" data-material="<?= (int) $m['id'] ?>">
                <span class="ic <?= $isOpened ? 'done' : '' ?>"><?= $isOpened ? '✓' : htmlspecialchars(mb_substr($ext, 0, 4)) ?></span>
                <div class="tt">
                    <strong><?= htmlspecialchars($m['title']) ?></strong>
                    <span><?= ['link' => 'Link esterno', 'video' => 'Video', 'audio' => 'Audio', 'image' => 'Immagine', 'pdf' => 'Documento PDF', 'download' => 'File da scaricare'][$view] ?><?= $isOpened ? ' · aperto' : '' ?></span>
                </div>
                <?php if ($view === 'link'): ?>
                    <a class="btn btn-sm btn-secondary ea-open" href="<?= $src ?>" target="_blank" rel="noopener">Apri</a>
                <?php elseif ($view === 'download'): ?>
                    <a class="btn btn-sm btn-secondary ea-open" href="<?= $src ?>&dl=1">Scarica</a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-primary ea-view" data-src="<?= htmlspecialchars($src) ?>" data-view="<?= $view ?>" data-title="<?= htmlspecialchars($m['title']) ?>">Apri</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="ea-viewer" id="eaViewer">
            <div class="ea-viewer-head">
                <strong id="eaViewerTitle"></strong>
                <span style="display:flex; gap:0.4rem;">
                    <a class="btn btn-sm btn-ghost" id="eaViewerNewTab" target="_blank" rel="noopener">Apri a schermo intero</a>
                    <button type="button" class="btn btn-sm btn-ghost" id="eaViewerClose">Chiudi</button>
                </span>
            </div>
            <div id="eaViewerBody"></div>
        </div>
    </section>

    <section class="card ea-section" id="eaComplete">
        <?php if ($assignment['completed_at']): ?>
            <h3>Corso completato</h3>
            <div class="ea-done">
                <span style="color:#475569; font-size:0.9rem;">Completato il <?= date('d/m/Y \a\l\l\e H:i', strtotime($assignment['completed_at'])) ?>.</span>
                <?php if ($assignment['certificate_id']): ?>
                    <a class="btn btn-primary" href="<?= PUBLIC_URL ?>/academy-file.php?cert=<?= (int) $assignment['certificate_id'] ?>" target="_blank" rel="noopener"
                       onclick="var b=document.getElementById('eaNewCert'); if (b) b.remove();">Scarica attestato</a>
                    <?php if (empty($assignment['employee_downloaded_at'])): ?><span class="badge badge-dot badge-success" id="eaNewCert">Nuovo</span><?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-dot badge-neutral">Attestato in preparazione</span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h3>Conferma il completamento</h3>
            <form method="POST" id="eaCompleteForm">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <label class="ea-check">
                    <input type="checkbox" name="declaration" value="1" required>
                    <span><?= htmlspecialchars(Academy::DECLARATION_TEXT) ?></span>
                </label>
                <button type="submit" class="btn btn-primary" id="eaCompleteBtn" <?= $allOpened ? '' : 'disabled' ?>>Completato</button>
                <p id="eaCompleteHint" style="margin:0.6rem 0 0; font-size:0.8rem; color:#94a3b8; <?= $allOpened ? 'display:none;' : '' ?>">
                    Il pulsante si attiva dopo aver aperto tutti i materiali.
                </p>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script>
(function () {
    var total = <?= (int) count($materials ?? []) ?>;
    var opened = {};
    <?php foreach (($opened ?? []) as $oid): ?>opened[<?= (int) $oid ?>] = true;<?php endforeach; ?>

    // Segno subito il materiale come aperto: la registrazione vera la fa il server
    // quando il file viene richiesto (anteprima, download o redirect del link).
    function markOpened(row) {
        var id = row.dataset.material;
        opened[id] = true;
        var ic = row.querySelector('.ic');
        ic.classList.add('done');
        ic.textContent = '✓';
        var count = document.querySelectorAll('.ea-mat').length;
        var done = Object.keys(opened).filter(function (k) { return document.querySelector('.ea-mat[data-material="' + k + '"]'); }).length;
        var btn = document.getElementById('eaCompleteBtn');
        if (btn && done >= count) {
            btn.disabled = false;
            var hint = document.getElementById('eaCompleteHint');
            if (hint) hint.style.display = 'none';
        }
    }

    document.querySelectorAll('.ea-open').forEach(function (a) {
        a.addEventListener('click', function () { markOpened(a.closest('.ea-mat')); });
    });

    var viewer = document.getElementById('eaViewer');
    var body = document.getElementById('eaViewerBody');
    document.querySelectorAll('.ea-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var src = b.dataset.src, view = b.dataset.view, html = '';
            if (view === 'pdf') html = '<iframe src="' + src + '" title="Documento"></iframe>';
            if (view === 'video') html = '<video src="' + src + '" controls controlsList="nodownload" playsinline></video>';
            if (view === 'audio') html = '<audio src="' + src + '" controls></audio>';
            if (view === 'image') html = '<img src="' + src + '" alt="">';
            body.innerHTML = html;
            document.getElementById('eaViewerTitle').textContent = b.dataset.title;
            document.getElementById('eaViewerNewTab').href = src;
            viewer.classList.add('open');
            viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            markOpened(b.closest('.ea-mat'));
        });
    });
    var close = document.getElementById('eaViewerClose');
    if (close) close.addEventListener('click', function () { body.innerHTML = ''; viewer.classList.remove('open'); });
})();
</script>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
