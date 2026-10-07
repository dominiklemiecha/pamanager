<?php
/**
 * Academy - pagina staff condivisa (HR admin, consulente del lavoro, responsabile di reparto).
 *
 * Il wrapper che la include deve aver gia chiamato Auth::init(), setSecurityHeaders()
 * e Auth::requireUser() con i ruoli ammessi.
 *
 * Il responsabile di reparto vede solo i dipendenti del suo reparto, in sola lettura
 * (puo scaricare attestati e registro, non modifica nulla).
 *
 * Viste: elenco corsi | ?course=ID&tab=info|materials|people|log
 */

$__user = Auth::getUser();
$__role = $__user['role'];
$readOnly = $__role === 'admin_reparto';
$deptScope = $readOnly ? (int) ($__user['department_id'] ?? 0) : null;
$area = ['admin' => 'admin', 'consulente_lavoro' => 'consulente-lavoro', 'admin_reparto' => 'admin-reparto', 'formatore' => 'formatore'][$__role];
$self = PUBLIC_URL . '/' . $area . '/academy.php';

$courseId = (int) ($_GET['course'] ?? $_POST['course_id'] ?? 0);
$tab = (string) ($_GET['tab'] ?? 'people');

$redirect = function (string $query, ?string $ok = null, ?string $err = null) use ($self) {
    $q = $query;
    if ($ok !== null) $q .= ($q ? '&' : '') . 'ok=' . urlencode($ok);
    if ($err !== null) $q .= ($q ? '&' : '') . 'err=' . urlencode($err);
    header('Location: ' . $self . ($q ? '?' . $q : ''));
    exit;
};

// ---------------------------------------------------------------------
// Export registro (GET)
// ---------------------------------------------------------------------
if (isset($_GET['export']) && $courseId > 0) {
    $course = Academy::getCourse($courseId);
    if (!$course) { http_response_code(404); exit('Corso non trovato'); }
    $assignmentFilter = isset($_GET['assignment']) ? (int) $_GET['assignment'] : null;
    $events = Academy::getEvents($courseId, $assignmentFilter, $deptScope);
    $base = preg_replace('/[^A-Za-z0-9_-]/', '_', 'registro_' . $course['title']);
    if ($_GET['export'] === 'pdf') {
        Academy::streamEventsPdf($events, 'Registro frequenza - ' . $course['title'], $base . '.pdf');
    }
    Academy::streamEventsCsv($events, $base . '.csv');
}

if (isset($_GET['zip']) && $courseId > 0) {
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
    AcademyCertificate::streamZip($courseId, $ids, $deptScope);
}

// ---------------------------------------------------------------------
// Azioni (POST) - non disponibili in sola lettura
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    if ($readOnly) {
        http_response_code(403);
        exit('Sola lettura');
    }
    $action = (string) ($_POST['action'] ?? '');

    // AJAX: blocco di notifiche di assegnazione
    if ($action === 'notify_batch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(Academy::processNotificationBatch($courseId, (int) ($_POST['limit'] ?? 4)));
        exit;
    }
    // AJAX: blocco di attestati
    if ($action === 'generate_batch') {
        header('Content-Type: application/json; charset=utf-8');
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        echo json_encode(AcademyCertificate::generateBatch($courseId, $ids, (int) ($_POST['limit'] ?? 5)));
        exit;
    }

    $back = 'course=' . $courseId;
    switch ($action) {
        case 'save_course':
            $r = Academy::saveCourse($_POST, $courseId > 0 ? $courseId : null);
            if (!$r['success']) $redirect($courseId > 0 ? $back . '&tab=info' : 'new=1', null, $r['error']);
            $redirect('course=' . $r['id'] . '&tab=' . ($courseId > 0 ? 'info' : 'materials'),
                $courseId > 0 ? 'Corso aggiornato' : 'Corso creato: ora aggiungi i materiali');
        case 'set_status':
            $r = Academy::setCourseStatus($courseId, (string) $_POST['status']);
            $redirect($back . '&tab=' . ($_POST['return_tab'] ?? 'people'), $r['success'] ? 'Stato del corso aggiornato' : null, $r['error'] ?? null);
        case 'delete_course':
            $r = Academy::deleteCourse($courseId);
            if ($r['success']) $redirect('', 'Corso eliminato');
            $redirect($back . '&tab=info', null, $r['error']);
        case 'add_files':
            $r = Academy::addFiles($courseId, $_FILES['files'] ?? []);
            $msg = $r['success'] ? ($r['added'] . ($r['added'] === 1 ? ' file caricato' : ' file caricati')) : null;
            $err = $r['success'] ? (empty($r['errors']) ? null : 'Alcuni file non sono stati caricati: ' . implode(' - ', $r['errors'])) : $r['error'];
            $redirect($back . '&tab=materials', $msg, $err);
        case 'add_link':
            $r = Academy::addLink($courseId, (string) ($_POST['title'] ?? ''), (string) ($_POST['url'] ?? ''));
            $redirect($back . '&tab=materials', $r['success'] ? 'Link aggiunto' : null, $r['error'] ?? null);
        case 'rename_material':
            $r = Academy::renameMaterial((int) $_POST['material_id'], (string) ($_POST['title'] ?? ''));
            $redirect($back . '&tab=materials', $r['success'] ? 'Titolo aggiornato' : null, $r['error'] ?? null);
        case 'move_material':
            Academy::moveMaterial((int) $_POST['material_id'], (string) $_POST['dir']);
            $redirect($back . '&tab=materials');
        case 'delete_material':
            $r = Academy::deleteMaterial((int) $_POST['material_id']);
            $redirect($back . '&tab=materials', $r['success'] ? 'Materiale rimosso' : null, $r['error'] ?? null);
        case 'assign':
            $mode = (string) ($_POST['mode'] ?? 'selected');
            if ($mode === 'all') {
                $ids = array_column(Employee::getAll(true), 'id');
            } elseif ($mode === 'department') {
                $deptId = (int) ($_POST['department_id'] ?? 0);
                $ids = array_column(array_filter(Employee::getAll(true), fn($e) => (int) $e['department_id'] === $deptId), 'id');
            } else {
                $ids = (array) ($_POST['employee_ids'] ?? []);
            }
            $r = Academy::assign($courseId, $ids, !empty($_POST['send_email']));
            if (!$r['success']) $redirect($back . '&tab=people', null, $r['error']);
            $msg = $r['count'] . ($r['count'] === 1 ? ' dipendente assegnato' : ' dipendenti assegnati')
                 . ($r['skipped'] > 0 ? ' (' . $r['skipped'] . ' già assegnati o non attivi)' : '');
            $redirect($back . '&tab=people&notify=1', $msg);
        case 'unassign':
            $r = Academy::unassign((int) $_POST['assignment_id']);
            $redirect($back . '&tab=people', $r['success'] ? 'Assegnazione rimossa' : null, $r['error'] ?? null);
        case 'remind':
            $r = Academy::remind((int) $_POST['assignment_id']);
            $redirect($back . '&tab=people', $r['success'] ? 'Sollecito inviato' : null, $r['error'] ?? null);
        case 'remind_all':
            $r = Academy::remindAllPending($courseId);
            $redirect($back . '&tab=people', 'Sollecito inviato a ' . $r['count'] . ($r['count'] === 1 ? ' dipendente' : ' dipendenti'));
    }
    $redirect($courseId > 0 ? $back : '');
}

// ---------------------------------------------------------------------
// Dati vista
// ---------------------------------------------------------------------
$okMessage = (string) ($_GET['ok'] ?? '');
$errMessage = (string) ($_GET['err'] ?? '');
$fmtDate = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '-';
$fmtDateTime = fn($d) => $d ? date('d/m/Y H:i', strtotime($d)) : '-';
$statusBadge = [
    'not_started' => 'badge-neutral', 'in_progress' => 'badge-primary',
    'completed' => 'badge-success', 'overdue' => 'badge-danger',
];
$courseBadge = ['draft' => 'badge-neutral', 'published' => 'badge-success', 'archived' => 'badge-warning'];

$course = null;
if ($courseId > 0) {
    $course = Academy::getCourse($courseId);
    if (!$course) $redirect('', null, 'Corso non trovato');
    if ($readOnly) $tab = in_array($tab, ['people', 'log'], true) ? $tab : 'people';
    $materials = Academy::getMaterials($courseId);
    $assignments = Academy::getAssignments($courseId, $deptScope);
    $pendingNotify = $readOnly ? 0 : Academy::countPendingNotifications($courseId);
    $missingCerts = count(array_filter($assignments, fn($a) => $a['completed_at'] && !$a['certificate_id']));
    $withCerts = count(array_filter($assignments, fn($a) => $a['certificate_id']));
    if ($tab === 'log') {
        $logAssignment = isset($_GET['assignment']) ? (int) $_GET['assignment'] : null;
        $events = Academy::getEvents($courseId, $logAssignment, $deptScope);
        $chain = Academy::verifyChain();
    }
    if ($tab === 'info' || $tab === 'people') {
        $templates = $readOnly ? [] : AcademyCertificate::getTemplates();
    }
    if ($tab === 'people' && !$readOnly) {
        $assignedIds = array_map(fn($a) => (int) $a['employee_id'], $assignments);
        $assignable = array_filter(Employee::getAll(true), fn($e) => !in_array((int) $e['id'], $assignedIds, true));
        $departments = Database::fetchAll("SELECT id, name FROM departments WHERE company_id = ? ORDER BY name", [Tenant::currentCompanyId()]);
    }
} else {
    $courses = Academy::getCourses(null, $deptScope);
    if ($readOnly) {
        $courses = array_values(array_filter($courses, fn($c) => (int) $c['assigned_count'] > 0));
    }
    $templates = $readOnly ? [] : AcademyCertificate::getTemplates();
    $formatoriCount = $__role === 'admin' ? count(User::getFormatori()) : 0;
}

$pageTitle = $course ? 'Academy - ' . $course['title'] : 'Academy';
include __DIR__ . ($readOnly ? '/header-admin-reparto.php' : '/header-admin.php');
?>

<style>
.ac-hero { padding: var(--sp-5) var(--sp-6); margin-bottom: var(--sp-5); display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
.ac-hero h2 { font-family: 'Space Grotesk', var(--font-sans); font-size: 1.6rem; font-weight: 700; letter-spacing: -0.025em; line-height: 1.15; margin: 0 0 6px; color: var(--accent); }
.ac-hero p { margin: 0; font-size: var(--text-sm); color: #6e7191; max-width: 680px; }
.ac-hero-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.ac-back { font-size: 0.82rem; color: #64748b; text-decoration: none; display: inline-block; margin-bottom: 6px; }
.ac-back:hover { color: var(--accent); }
.ac-alert { padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.86rem; margin-bottom: var(--sp-4); }
.ac-alert.ok { background: #dcfce7; color: #166534; }
.ac-alert.err { background: #fee2e2; color: #991b1b; }
.ac-alert.info { background: #e0f2fe; color: #075985; }
.ac-card { padding: 1.4rem; }
.ac-card h3 { margin: 0 0 1rem; font-size: 1rem; }
.ac-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: var(--sp-4); }
.ac-course { display: block; text-decoration: none; color: inherit; padding: 1.2rem; transition: box-shadow .15s, transform .15s; }
.ac-course:hover { box-shadow: 0 8px 24px rgba(11,58,164,.10); transform: translateY(-1px); }
.ac-course h4 { margin: 0.6rem 0 0.3rem; font-size: 1rem; color: #0f172a; }
.ac-course .meta { font-size: 0.8rem; color: #64748b; }
.ac-progress { height: 6px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 0.8rem; }
.ac-progress > span { display: block; height: 100%; background: var(--accent); }
.ac-tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid #e2e8f0; margin-bottom: var(--sp-5); overflow-x: auto; }
.ac-tabs a { padding: 0.65rem 1rem; font-size: 0.88rem; font-weight: 600; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent; white-space: nowrap; }
.ac-tabs a.active { color: var(--accent); border-bottom-color: var(--accent); }
.ac-tabs a .count { font-weight: 500; color: #94a3b8; margin-left: 4px; }
.ac-field { margin-bottom: 1rem; }
.ac-field label { display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 4px; }
.ac-field input[type=text], .ac-field input[type=url], .ac-field input[type=number], .ac-field input[type=date],
.ac-field input[type=file], .ac-field select, .ac-field textarea {
    width: 100%; padding: 0.55rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; background: #fff; box-sizing: border-box; font-family: inherit;
}
.ac-field textarea { min-height: 90px; resize: vertical; }
.ac-field small { display: block; font-size: 0.75rem; color: #94a3b8; margin-top: 4px; }
.ac-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 640px) { .ac-row { grid-template-columns: 1fr; } }
.ac-two { display: grid; grid-template-columns: minmax(300px, 380px) 1fr; gap: var(--sp-5); align-items: start; }
@media (max-width: 1100px) { .ac-two { grid-template-columns: 1fr; } }
.ac-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.ac-table th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 600; padding: 0 0.6rem 0.5rem; white-space: nowrap; }
.ac-table td { padding: 0.65rem 0.6rem; border-top: 1px solid #eef2f7; vertical-align: middle; }
.ac-table tbody tr:hover { background: #f8fafc; }
.ac-table .sub { display: block; font-size: 0.75rem; color: #94a3b8; }
.ac-actions { text-align: right; white-space: nowrap; }
.ac-actions form { display: inline; }
.ac-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
.ac-toolbar .spacer { flex: 1; }
.ac-empty { font-size: 0.88rem; color: #6e7191; margin: 0; }
.ac-mat-icon { width: 34px; height: 34px; border-radius: 8px; background: #eef2ff; color: var(--accent); display: inline-flex; align-items: center; justify-content: center; font-size: 0.66rem; font-weight: 700; text-transform: uppercase; flex-shrink: 0; }
.ac-mat { display: flex; align-items: center; gap: 0.75rem; }
.ac-emp-list { max-height: 280px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.35rem; }
.ac-emp-list label { display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.45rem; font-size: 0.86rem; border-radius: 6px; cursor: pointer; }
.ac-emp-list label:hover { background: #f8fafc; }
.ac-emp-list .dept { color: #94a3b8; font-size: 0.76rem; margin-left: auto; }
.ac-mode { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
.ac-mode label { border: 1px solid #e2e8f0; border-radius: 999px; padding: 0.35rem 0.8rem; font-size: 0.82rem; cursor: pointer; display: flex; gap: 0.35rem; align-items: center; }
.ac-mode input:checked + span { color: var(--accent); font-weight: 600; }
.ac-check { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.86rem; color: #475569; }
.ac-progress-box .ac-progress { height: 8px; }
.ac-chain { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; }
.ac-tab-badge { display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 6px; border-radius: 999px; background: #f59e0b; color: #fff; font-size: 0.7rem; line-height: 18px; text-align: center; }
.ac-table tbody tr.ac-row-new { background: #fffbeb; box-shadow: inset 3px 0 0 #f59e0b; }
.ac-table tbody tr.ac-row-new:hover { background: #fef3c7; }
.ac-pulse::before { animation: acPulse 1.6s ease-in-out infinite; }
@keyframes acPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }
@media (max-width: 760px) {
    .ac-table.people th:nth-child(3), .ac-table.people td:nth-child(3),
    .ac-table.people th:nth-child(5), .ac-table.people td:nth-child(5),
    .ac-table.people th:nth-child(7), .ac-table.people td:nth-child(7) { display: none; }
}
</style>

<?php if (!$course): /* ============================== ELENCO ============================== */ ?>

<div class="welcome-card ac-hero">
    <div>
        <h2>Academy</h2>
        <p>
            <?php if ($readOnly): ?>
                Avanzamento dei corsi assegnati ai dipendenti del tuo reparto.
            <?php else: ?>
                Crea corsi con slide, documenti, video o link, assegnali ai dipendenti e segui chi li ha iniziati e completati.
                Ogni accesso viene registrato con data, ora e IP; a corso completato generi l'attestato.
            <?php endif; ?>
        </p>
    </div>
    <?php if (!$readOnly): ?>
        <div class="ac-hero-actions">
            <?php if ($__role === 'admin'): ?>
                <a class="btn btn-secondary" href="<?= PUBLIC_URL ?>/admin/formatori.php"
                   title="Utenze che vedono solo l'Academy">Responsabili formazione<?= $formatoriCount > 0 ? ' (' . $formatoriCount . ')' : '' ?></a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= PUBLIC_URL . '/' . $area ?>/academy-templates.php">Modelli attestato</a>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('acNew').style.display='block'; this.style.display='none'; document.getElementById('acNewTitle').focus();">+ Nuovo corso</button>
        </div>
    <?php endif; ?>
</div>

<?php if ($okMessage !== ''): ?><div class="ac-alert ok"><?= htmlspecialchars($okMessage) ?></div><?php endif; ?>
<?php if ($errMessage !== ''): ?><div class="ac-alert err"><?= htmlspecialchars($errMessage) ?></div><?php endif; ?>

<?php if (!$readOnly): ?>
<section class="card ac-card" id="acNew" style="display:<?= isset($_GET['new']) ? 'block' : 'none' ?>; margin-bottom: var(--sp-5);">
    <h3>Nuovo corso</h3>
    <form method="POST" action="<?= $self ?>">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="save_course">
        <div class="ac-field">
            <label>Titolo *</label>
            <input type="text" name="title" id="acNewTitle" required maxlength="200" placeholder="Es. Procedure di sicurezza in ufficio">
        </div>
        <div class="ac-field">
            <label>Descrizione</label>
            <textarea name="description" placeholder="Cosa imparerà il dipendente (facoltativo)"></textarea>
        </div>
        <div class="ac-row">
            <div class="ac-field">
                <label>Durata (ore)</label>
                <input type="number" name="duration_hours" min="0" step="0.5" placeholder="Es. 4">
                <small>Compare sull'attestato</small>
            </div>
            <div class="ac-field">
                <label>Scadenza (facoltativa)</label>
                <input type="date" name="due_date">
                <small>Promemoria automatico <?= Academy::DUE_REMINDER_DAYS ?> giorni prima</small>
            </div>
        </div>
        <div class="ac-field">
            <label>Modello attestato</label>
            <select name="certificate_template_id">
                <option value="">Modello predefinito</option>
                <?php foreach ($templates as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Crea corso</button>
    </form>
</section>
<?php endif; ?>

<?php if (empty($courses)): ?>
    <section class="card ac-card"><p class="ac-empty">
        <?= $readOnly ? 'Nessun corso assegnato ai dipendenti del tuo reparto.' : 'Nessun corso ancora. Crea il primo con "+ Nuovo corso".' ?>
    </p></section>
<?php else: ?>
    <div class="ac-grid">
        <?php foreach ($courses as $c):
            $assigned = (int) $c['assigned_count'];
            $completed = (int) $c['completed_count'];
            $pct = $assigned > 0 ? round($completed / $assigned * 100) : 0;
            $overdue = $c['due_date'] && $c['due_date'] < date('Y-m-d') && $completed < $assigned;
        ?>
            <a class="card ac-course" href="<?= $self ?>?course=<?= (int) $c['id'] ?>">
                <span class="badge badge-dot <?= $courseBadge[$c['status']] ?>"><?= Academy::COURSE_STATUSES[$c['status']] ?></span>
                <?php if ($overdue): ?><span class="badge badge-dot badge-danger">Scaduto</span><?php endif; ?>
                <?php if (!$readOnly && (int) $c['to_generate_count'] > 0): ?>
                    <span class="badge badge-dot badge-warning ac-pulse"><?= (int) $c['to_generate_count'] ?> <?= (int) $c['to_generate_count'] === 1 ? 'attestato' : 'attestati' ?> da generare</span>
                <?php endif; ?>
                <h4><?= htmlspecialchars($c['title']) ?></h4>
                <div class="meta">
                    <?= (int) $c['material_count'] ?> materiali
                    <?php if ($c['duration_hours'] !== null): ?> · <?= rtrim(rtrim(number_format((float) $c['duration_hours'], 2, ',', ''), '0'), ',') ?> ore<?php endif; ?>
                    <?php if ($c['due_date']): ?> · entro il <?= $fmtDate($c['due_date']) ?><?php endif; ?>
                </div>
                <div class="meta" style="margin-top:0.5rem;">
                    <strong style="color:#0f172a;"><?= $completed ?>/<?= $assigned ?></strong> completati
                    <?php if ((int) $c['in_progress_count'] > 0): ?> · <?= (int) $c['in_progress_count'] ?> in corso<?php endif; ?>
                </div>
                <div class="ac-progress"><span style="width: <?= $pct ?>%;"></span></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php else: /* ============================== DETTAGLIO CORSO ============================== */ ?>

<div class="welcome-card ac-hero">
    <div>
        <a class="ac-back" href="<?= $self ?>">&larr; Tutti i corsi</a>
        <h2><?= htmlspecialchars($course['title']) ?></h2>
        <p>
            <span class="badge badge-dot <?= $courseBadge[$course['status']] ?>"><?= Academy::COURSE_STATUSES[$course['status']] ?></span>
            <?php if ($course['duration_hours'] !== null): ?> &nbsp;<?= rtrim(rtrim(number_format((float) $course['duration_hours'], 2, ',', ''), '0'), ',') ?> ore<?php endif; ?>
            <?php if ($course['due_date']): ?> &nbsp;· scadenza <?= $fmtDate($course['due_date']) ?><?php endif; ?>
            &nbsp;· attestato: <?= htmlspecialchars($course['template_name'] ?? 'modello predefinito') ?>
        </p>
    </div>
    <?php if (!$readOnly): ?>
        <div class="ac-hero-actions">
            <?php foreach (['published' => 'Pubblica', 'draft' => 'Riporta in bozza', 'archived' => 'Archivia'] as $st => $label):
                if ($st === $course['status']) continue;
                if ($st === 'draft' && !empty($assignments)) continue; ?>
                <form method="POST" action="<?= $self ?>" style="display:inline;"
                      <?= $st === 'archived' ? 'onsubmit="return confirm(\'Archiviare il corso? I dipendenti che non l\\\'hanno iniziato non lo vedranno più.\');"' : '' ?>>
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="status" value="<?= $st ?>">
                    <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                    <button type="submit" class="btn <?= $st === 'published' ? 'btn-primary' : 'btn-secondary' ?>"><?= $label ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($okMessage !== ''): ?><div class="ac-alert ok"><?= htmlspecialchars($okMessage) ?></div><?php endif; ?>
<?php if ($errMessage !== ''): ?><div class="ac-alert err"><?= htmlspecialchars($errMessage) ?></div><?php endif; ?>
<?php if (!$readOnly && $course['status'] === 'draft'): ?>
    <div class="ac-alert info">Il corso è in bozza: aggiungi i materiali, poi premi <strong>Pubblica</strong> per poterlo assegnare.</div>
<?php endif; ?>

<nav class="ac-tabs">
    <a href="<?= $self ?>?course=<?= $courseId ?>&tab=people" class="<?= $tab === 'people' ? 'active' : '' ?>">Partecipanti<span class="count"><?= count($assignments) ?></span><?php if (!$readOnly && $missingCerts > 0): ?><span class="ac-tab-badge" title="Attestati da generare"><?= $missingCerts ?></span><?php endif; ?></a>
    <?php if (!$readOnly): ?>
        <a href="<?= $self ?>?course=<?= $courseId ?>&tab=materials" class="<?= $tab === 'materials' ? 'active' : '' ?>">Materiali<span class="count"><?= count($materials) ?></span></a>
        <a href="<?= $self ?>?course=<?= $courseId ?>&tab=info" class="<?= $tab === 'info' ? 'active' : '' ?>">Dettagli</a>
    <?php endif; ?>
    <a href="<?= $self ?>?course=<?= $courseId ?>&tab=log" class="<?= $tab === 'log' ? 'active' : '' ?>">Registro</a>
</nav>

<?php if ($tab === 'info'): /* ---------------- DETTAGLI ---------------- */ ?>
<div class="ac-two">
    <section class="card ac-card">
        <h3>Dettagli del corso</h3>
        <form method="POST" action="<?= $self ?>">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_course">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div class="ac-field">
                <label>Titolo *</label>
                <input type="text" name="title" required maxlength="200" value="<?= htmlspecialchars($course['title']) ?>">
            </div>
            <div class="ac-field">
                <label>Descrizione</label>
                <textarea name="description"><?= htmlspecialchars((string) $course['description']) ?></textarea>
            </div>
            <div class="ac-row">
                <div class="ac-field">
                    <label>Durata (ore)</label>
                    <input type="number" name="duration_hours" min="0" step="0.5" value="<?= $course['duration_hours'] !== null ? (float) $course['duration_hours'] : '' ?>">
                </div>
                <div class="ac-field">
                    <label>Scadenza</label>
                    <input type="date" name="due_date" value="<?= htmlspecialchars((string) $course['due_date']) ?>">
                </div>
            </div>
            <div class="ac-field">
                <label>Modello attestato</label>
                <select name="certificate_template_id">
                    <option value="">Modello predefinito</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= (int) $course['certificate_template_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Gli attestati già generati non cambiano.</small>
            </div>
            <button type="submit" class="btn btn-primary">Salva</button>
        </form>
    </section>
    <section class="card ac-card">
        <h3>Eliminazione</h3>
        <?php if (empty($assignments)): ?>
            <p class="ac-empty" style="margin-bottom:1rem;">Il corso non è mai stato assegnato: puoi eliminarlo insieme ai suoi materiali.</p>
            <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Eliminare definitivamente il corso e i suoi materiali?');">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_course">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <button type="submit" class="btn btn-danger">Elimina corso</button>
            </form>
        <?php else: ?>
            <p class="ac-empty">Il corso è stato assegnato: per conservare il registro di frequenza non si può eliminare. Usa <strong>Archivia</strong> in alto.</p>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($tab === 'materials'): /* ---------------- MATERIALI ---------------- */ ?>
<div class="ac-two">
    <div>
        <section class="card ac-card" style="margin-bottom: var(--sp-4);">
            <h3>Carica file</h3>
            <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" id="acUploadForm">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_files">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <div class="ac-field">
                    <input type="file" name="files[]" multiple required
                           accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.odt,.odp,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mp3">
                    <small>Puoi selezionare più file insieme. PDF, slide (PPT/PPTX), documenti, immagini, video MP4 - massimo 200MB per file.
                        PDF, immagini e video si aprono dentro il gestionale; slide e documenti Office vengono scaricati.
                        Consiglio: salva le slide anche in PDF per farle vedere direttamente.</small>
                </div>
                <button type="submit" class="btn btn-primary" id="acUploadBtn">Carica</button>
            </form>
        </section>
        <section class="card ac-card">
            <h3>Aggiungi link</h3>
            <form method="POST" action="<?= $self ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_link">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <div class="ac-field">
                    <label>Titolo</label>
                    <input type="text" name="title" maxlength="255" placeholder="Es. Video introduttivo">
                </div>
                <div class="ac-field">
                    <label>Link *</label>
                    <input type="url" name="url" required placeholder="https://...">
                    <small>Si apre in una nuova scheda: registriamo il clic, non il tempo passato sul sito esterno.</small>
                </div>
                <button type="submit" class="btn btn-secondary">Aggiungi link</button>
            </form>
        </section>
    </div>
    <section class="card ac-card">
        <h3>Materiali del corso</h3>
        <?php if (empty($materials)): ?>
            <p class="ac-empty">Nessun materiale. Il dipendente dovrà aprirli tutti prima di poter completare il corso.</p>
        <?php else: ?>
            <p class="ac-empty" style="margin-bottom: 1rem; font-size: 0.8rem;">Il dipendente deve aprire tutti i materiali prima di poter premere "Completato".</p>
            <table class="ac-table">
                <tbody>
                <?php foreach ($materials as $i => $m):
                    $ext = $m['kind'] === 'link' ? 'link' : strtolower(pathinfo((string) $m['original_name'], PATHINFO_EXTENSION)); ?>
                    <tr>
                        <td>
                            <div class="ac-mat">
                                <span class="ac-mat-icon"><?= htmlspecialchars(mb_substr($ext, 0, 4)) ?></span>
                                <div>
                                    <form method="POST" action="<?= $self ?>" style="display:flex; gap:0.4rem; align-items:center;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="rename_material">
                                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                        <input type="hidden" name="material_id" value="<?= (int) $m['id'] ?>">
                                        <input type="text" name="title" value="<?= htmlspecialchars($m['title']) ?>" maxlength="255"
                                               style="border:1px solid transparent; border-radius:6px; padding:3px 6px; font-weight:600; font-size:0.88rem; min-width:180px;"
                                               onfocus="this.style.borderColor='#e2e8f0'" onblur="this.style.borderColor='transparent'"
                                               onchange="this.form.submit()">
                                    </form>
                                    <span class="sub" style="padding-left:6px;">
                                        <?= $m['kind'] === 'link'
                                            ? htmlspecialchars(mb_strimwidth((string) $m['url'], 0, 60, '…'))
                                            : htmlspecialchars((string) $m['original_name']) . ' · ' . number_format(((int) $m['file_size']) / 1048576, 1, ',', '.') . ' MB' ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="ac-actions">
                            <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow):
                                if (($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($materials) - 1)) continue; ?>
                                <form method="POST" action="<?= $self ?>">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="move_material">
                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                    <input type="hidden" name="material_id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="dir" value="<?= $dir ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost" title="Sposta"><?= $arrow ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="btn btn-sm btn-secondary" target="_blank" rel="noopener" href="<?= PUBLIC_URL ?>/academy-file.php?m=<?= (int) $m['id'] ?>">Apri</a>
                            <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Rimuovere questo materiale?');">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete_material">
                                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                <input type="hidden" name="material_id" value="<?= (int) $m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Rimuovi</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($tab === 'people'): /* ---------------- PARTECIPANTI ---------------- */ ?>

<?php if ($pendingNotify > 0): ?>
    <div class="ac-alert info ac-progress-box" id="acNotifyBox" data-total="<?= $pendingNotify ?>" data-auto="<?= isset($_GET['notify']) ? '1' : '0' ?>">
        <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
            <strong id="acNotifyLabel"><?= isset($_GET['notify']) ? 'Invio notifiche in corso...' : $pendingNotify . ' notifiche di assegnazione non ancora inviate.' ?></strong>
            <button type="button" class="btn btn-sm" id="acNotifyStart" <?= isset($_GET['notify']) ? 'style="display:none;"' : '' ?>>Invia ora</button>
        </div>
        <div style="font-size:0.8rem; margin-top:2px;" id="acNotifyCounter">0 / <?= $pendingNotify ?> inviate</div>
        <div class="ac-progress"><span id="acNotifyBar" style="width:0;"></span></div>
    </div>
<?php endif; ?>

<?php if (!$readOnly && $course['status'] === 'published'): ?>
<section class="card ac-card" style="margin-bottom: var(--sp-5);">
    <details <?= empty($assignments) ? 'open' : '' ?>>
        <summary style="cursor:pointer; font-weight:600; font-size:1rem;">Assegna il corso</summary>
        <?php if (empty($assignable)): ?>
            <p class="ac-empty" style="margin-top:1rem;">Tutti i dipendenti attivi hanno già questo corso.</p>
        <?php else: ?>
        <form method="POST" action="<?= $self ?>" style="margin-top:1rem;" id="acAssignForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div class="ac-mode">
                <label><input type="radio" name="mode" value="selected" checked><span>Scegli dipendenti</span></label>
                <label><input type="radio" name="mode" value="department"><span>Un reparto</span></label>
                <label><input type="radio" name="mode" value="all"><span>Tutti (<?= count($assignable) ?>)</span></label>
            </div>
            <div data-mode="selected">
                <div class="ac-toolbar" style="margin-bottom:0.5rem;">
                    <input type="text" id="acEmpSearch" placeholder="Cerca dipendente..." style="flex:1; min-width:180px; padding:0.45rem 0.7rem; border:1px solid #e2e8f0; border-radius:8px;">
                    <button type="button" class="btn btn-sm btn-ghost" id="acSelAll">Seleziona visibili</button>
                </div>
                <div class="ac-emp-list" id="acEmpList">
                    <?php foreach ($assignable as $e): ?>
                        <label data-name="<?= htmlspecialchars(mb_strtolower($e['last_name'] . ' ' . $e['first_name'] . ' ' . ($e['department_name'] ?? ''))) ?>">
                            <input type="checkbox" name="employee_ids[]" value="<?= (int) $e['id'] ?>">
                            <?= htmlspecialchars($e['last_name'] . ' ' . $e['first_name']) ?>
                            <span class="dept"><?= htmlspecialchars($e['department_name'] ?? '') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div data-mode="department" style="display:none;">
                <div class="ac-field">
                    <select name="department_id">
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="ac-check" style="margin-top:0.9rem;">
                <input type="checkbox" name="send_email" value="1" checked>
                <span>Invia anche l'email (oltre a notifica e push)</span>
            </label>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Assegna</button>
        </form>
        <?php endif; ?>
    </details>
</section>
<?php endif; ?>

<section class="card ac-card">
    <?php if (empty($assignments)): ?>
        <p class="ac-empty"><?= $course['status'] === 'published' || $readOnly ? 'Nessun dipendente assegnato.' : 'Pubblica il corso per poterlo assegnare.' ?></p>
    <?php else: ?>
        <div class="ac-toolbar">
            <?php if (!$readOnly): ?>
                <button type="button" class="btn btn-sm btn-primary" id="acGenSelected" disabled>Genera attestati selezionati</button>
                <?php if ($missingCerts > 0): ?>
                    <button type="button" class="btn btn-sm btn-secondary" id="acGenAll">Genera tutti i mancanti (<?= $missingCerts ?>)</button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($withCerts > 0): ?>
                <a class="btn btn-sm btn-secondary" id="acZip" href="<?= $self ?>?course=<?= $courseId ?>&zip=1">Scarica attestati (ZIP)</a>
            <?php endif; ?>
            <span class="spacer"></span>
            <?php if (!$readOnly && count(array_filter($assignments, fn($a) => !$a['completed_at'])) > 0): ?>
                <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Inviare un sollecito a tutti i dipendenti che non hanno completato?');">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="remind_all">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <button type="submit" class="btn btn-sm btn-ghost">Sollecita tutti i non completati</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="ac-alert info ac-progress-box" id="acGenBox" style="display:none;">
            <strong id="acGenLabel">Generazione attestati...</strong>
            <div class="ac-progress"><span id="acGenBar" style="width:0;"></span></div>
        </div>
        <div style="overflow-x:auto;">
        <table class="ac-table people">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="acCheckAll" title="Seleziona tutti"></th>
                    <th>Dipendente</th>
                    <th>Iniziato</th>
                    <th>Stato</th>
                    <th>Materiali</th>
                    <th>Completato</th>
                    <th>Solleciti</th>
                    <th>Attestato</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $a):
                $st = Academy::statusOf($a, $course['due_date']);
                $needsCert = !$readOnly && $a['completed_at'] && !$a['certificate_id']; ?>
                <tr class="<?= $needsCert ? 'ac-row-new' : '' ?>">
                    <td><input type="checkbox" class="acRow" value="<?= (int) $a['id'] ?>" data-done="<?= $a['completed_at'] ? 1 : 0 ?>" data-cert="<?= $a['certificate_id'] ? 1 : 0 ?>"></td>
                    <td>
                        <strong><?= htmlspecialchars($a['last_name'] . ' ' . $a['first_name']) ?></strong>
                        <span class="sub"><?= htmlspecialchars($a['department_name'] ?? '') ?><?= !$a['is_active'] ? ' · non attivo' : '' ?></span>
                    </td>
                    <td><?= $fmtDateTime($a['started_at']) ?></td>
                    <td><span class="badge badge-dot <?= $statusBadge[$st] ?>"><?= Academy::STATUS_LABELS[$st] ?></span></td>
                    <td><?= min((int) $a['opened_count'], count($materials)) ?>/<?= count($materials) ?></td>
                    <td>
                        <?= $fmtDateTime($a['completed_at']) ?>
                        <?php if ($a['completed_ip']): ?><span class="sub">IP <?= htmlspecialchars($a['completed_ip']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?= (int) $a['reminder_count'] ?>
                        <?php if ($a['last_reminder_at']): ?><span class="sub">ultimo <?= $fmtDate($a['last_reminder_at']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['certificate_id']): ?>
                            <a href="<?= PUBLIC_URL ?>/academy-file.php?cert=<?= (int) $a['certificate_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($a['certificate_number']) ?></a>
                            <span class="sub"><?= $fmtDate($a['certificate_issued_at']) ?></span>
                        <?php elseif ($a['completed_at']): ?>
                            <span class="badge badge-dot badge-warning ac-pulse">Da generare</span>
                        <?php else: ?>
                            <span class="sub">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="ac-actions">
                        <a class="btn btn-sm btn-ghost" href="<?= $self ?>?course=<?= $courseId ?>&tab=log&assignment=<?= (int) $a['id'] ?>">Registro</a>
                        <?php if (!$readOnly): ?>
                            <?php if ($a['completed_at'] && !$a['certificate_id']): ?>
                                <button type="button" class="btn btn-sm btn-primary acGenOne" data-id="<?= (int) $a['id'] ?>">Genera</button>
                            <?php endif; ?>
                            <?php if (!$a['completed_at']): ?>
                                <form method="POST" action="<?= $self ?>">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="remind">
                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">Sollecita</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$a['started_at']): ?>
                                <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Rimuovere il corso a questo dipendente?');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                    <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost" title="Rimuovi assegnazione">✕</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'log'): /* ---------------- REGISTRO ---------------- */ ?>
<section class="card ac-card">
    <div class="ac-toolbar">
        <?php if ($logAssignment):
            $la = Academy::getAssignment($logAssignment); ?>
            <strong>Registro di <?= htmlspecialchars(trim(($la['first_name'] ?? '') . ' ' . ($la['last_name'] ?? ''))) ?></strong>
            <a class="btn btn-sm btn-ghost" href="<?= $self ?>?course=<?= $courseId ?>&tab=log">Mostra tutti</a>
        <?php else: ?>
            <strong>Registro completo del corso</strong>
        <?php endif; ?>
        <span class="spacer"></span>
        <span class="ac-chain">
            <?php if ($chain['ok']): ?>
                <span class="badge badge-dot badge-success">Registro integro</span>
            <?php else: ?>
                <span class="badge badge-dot badge-danger">Registro alterato alla riga <?= (int) $chain['broken_id'] ?></span>
            <?php endif; ?>
        </span>
        <?php $exp = $self . '?course=' . $courseId . ($logAssignment ? '&assignment=' . $logAssignment : ''); ?>
        <a class="btn btn-sm btn-secondary" href="<?= $exp ?>&export=pdf">Scarica PDF</a>
        <a class="btn btn-sm btn-secondary" href="<?= $exp ?>&export=csv">Scarica Excel</a>
    </div>
    <p class="ac-empty" style="font-size:0.8rem; margin-bottom:1rem;">
        Ogni riga ha l'ora del server, l'IP e il browser di chi ha fatto l'azione. Le righe non si possono modificare:
        ognuna contiene l'impronta (hash) della precedente, così un'alterazione manuale del database viene segnalata qui sopra.
    </p>
    <?php if (empty($events)): ?>
        <p class="ac-empty">Nessun evento registrato.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="ac-table">
            <thead><tr><th>Data e ora</th><th>Evento</th><th>Dipendente</th><th>Dettagli</th><th>Eseguito da</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($events) as $ev): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= date('d/m/Y H:i:s', strtotime($ev['created_at'])) ?></td>
                    <td><?= htmlspecialchars(Academy::EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></td>
                    <td><?= htmlspecialchars(trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''))) ?: '-' ?></td>
                    <td><?= htmlspecialchars($ev['material_title'] ?: ($ev['event'] === 'completed' ? 'Dichiarazione accettata' : mb_strimwidth((string) $ev['details'], 0, 70, '…'))) ?></td>
                    <td><?= htmlspecialchars(Academy::actorLabel($ev)) ?></td>
                    <td>
                        <?= htmlspecialchars((string) $ev['ip_address']) ?>
                        <?php if ($ev['user_agent']): ?><span class="sub" title="<?= htmlspecialchars($ev['user_agent']) ?>"><?= htmlspecialchars(mb_strimwidth($ev['user_agent'], 0, 32, '…')) ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function () {
    var token = '<?= CSRF::getToken() ?>';
    var endpoint = '<?= $self ?>';
    var courseId = '<?= $courseId ?>';

    function post(data) {
        var body = new FormData();
        body.append('csrf_token', token);
        body.append('course_id', courseId);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    // Upload: feedback sul bottone
    var upForm = document.getElementById('acUploadForm');
    if (upForm) {
        upForm.addEventListener('submit', function () {
            var b = document.getElementById('acUploadBtn');
            setTimeout(function () { b.disabled = true; b.textContent = 'Caricamento in corso...'; }, 0);
        });
    }

    // Assegnazione: modalita, ricerca, selezione
    var assignForm = document.getElementById('acAssignForm');
    if (assignForm) {
        assignForm.querySelectorAll('input[name=mode]').forEach(function (r) {
            r.addEventListener('change', function () {
                assignForm.querySelectorAll('[data-mode]').forEach(function (box) {
                    box.style.display = box.dataset.mode === r.value ? '' : 'none';
                });
            });
        });
        var search = document.getElementById('acEmpSearch');
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            document.querySelectorAll('#acEmpList label').forEach(function (l) {
                l.style.display = !q || l.dataset.name.indexOf(q) !== -1 ? '' : 'none';
            });
        });
        document.getElementById('acSelAll').addEventListener('click', function () {
            document.querySelectorAll('#acEmpList label').forEach(function (l) {
                if (l.style.display !== 'none') l.querySelector('input').checked = true;
            });
        });
        assignForm.addEventListener('submit', function (e) {
            var mode = assignForm.querySelector('input[name=mode]:checked').value;
            if (mode === 'selected' && !assignForm.querySelector('#acEmpList input:checked')) {
                e.preventDefault();
                alert('Seleziona almeno un dipendente.');
                return;
            }
            if (mode === 'all' && !confirm('Assegnare il corso a tutti i dipendenti attivi?')) e.preventDefault();
        });
    }

    // Coda notifiche di assegnazione
    var nBox = document.getElementById('acNotifyBox');
    if (nBox) {
        var total = parseInt(nBox.dataset.total, 10) || 0, sent = 0, running = false;
        var nBar = document.getElementById('acNotifyBar'), nCount = document.getElementById('acNotifyCounter');
        var nLabel = document.getElementById('acNotifyLabel'), nStart = document.getElementById('acNotifyStart');
        function nStep() {
            post({ action: 'notify_batch', limit: 4 }).then(function (d) {
                if (!d || !d.success) { nLabel.textContent = 'Invio interrotto: ricarica la pagina per riprendere.'; running = false; return; }
                sent += d.sent || 0;
                nBar.style.width = Math.min(100, Math.round(sent / total * 100)) + '%';
                nCount.textContent = sent + ' / ' + total + ' inviate';
                if (d.remaining > 0 && d.sent > 0) { nStep(); }
                else { running = false; nLabel.textContent = d.remaining > 0 ? 'Invio interrotto: ricarica per riprendere.' : 'Notifiche inviate.'; }
            }).catch(function () { running = false; nLabel.textContent = 'Invio interrotto (rete): ricarica la pagina per riprendere.'; });
        }
        function nGo() { if (running) return; running = true; nStart.style.display = 'none'; nLabel.textContent = 'Invio notifiche in corso...'; nStep(); }
        nStart.addEventListener('click', nGo);
        if (nBox.dataset.auto === '1') nGo();
    }

    // Selezione righe partecipanti
    var rows = document.querySelectorAll('.acRow');
    var genSel = document.getElementById('acGenSelected');
    var zip = document.getElementById('acZip');
    var zipBase = zip ? zip.getAttribute('href') : '';
    function selected() { return Array.prototype.filter.call(rows, function (r) { return r.checked; }); }
    function refreshSel() {
        var s = selected();
        if (genSel) genSel.disabled = !s.some(function (r) { return r.dataset.done === '1' && r.dataset.cert === '0'; });
        if (zip) {
            var withCert = s.filter(function (r) { return r.dataset.cert === '1'; }).map(function (r) { return r.value; });
            zip.setAttribute('href', withCert.length ? zipBase + '&ids=' + withCert.join(',') : zipBase);
            zip.textContent = withCert.length ? 'Scarica selezionati (ZIP)' : 'Scarica attestati (ZIP)';
        }
    }
    rows.forEach(function (r) { r.addEventListener('change', refreshSel); });
    var all = document.getElementById('acCheckAll');
    if (all) all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); refreshSel(); });

    // Generazione attestati a blocchi
    function generate(ids, expected) {
        var box = document.getElementById('acGenBox'), bar = document.getElementById('acGenBar'), label = document.getElementById('acGenLabel');
        box.style.display = '';
        var done = 0;
        function step() {
            post({ action: 'generate_batch', ids: ids.join(','), limit: 5 }).then(function (d) {
                done += d.generated || 0;
                bar.style.width = Math.min(100, Math.round(done / Math.max(1, expected) * 100)) + '%';
                label.textContent = 'Generati ' + done + ' di ' + expected + '...';
                if (d.remaining > 0 && d.generated > 0) { step(); return; }
                if (d.errors && d.errors.length) { label.textContent = 'Generati ' + done + '. Errore: ' + d.errors[0]; return; }
                label.textContent = 'Generati ' + done + ' attestati. Aggiorno la pagina...';
                setTimeout(function () { location.reload(); }, 700);
            }).catch(function () { label.textContent = 'Generazione interrotta (rete). Ricarica e riprova: quelli già creati restano.'; });
        }
        step();
    }
    if (genSel) genSel.addEventListener('click', function () {
        var ids = selected().filter(function (r) { return r.dataset.done === '1' && r.dataset.cert === '0'; }).map(function (r) { return r.value; });
        if (ids.length) generate(ids, ids.length);
    });
    var genAll = document.getElementById('acGenAll');
    if (genAll) genAll.addEventListener('click', function () {
        if (confirm('Generare gli attestati di tutti i dipendenti che hanno completato?')) generate([], <?= (int) ($missingCerts ?? 0) ?>);
    });
    document.querySelectorAll('.acGenOne').forEach(function (b) {
        b.addEventListener('click', function () { b.disabled = true; generate([b.dataset.id], 1); });
    });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/footer-admin.php'; ?>
