<?php
/**
 * Partial calendario condiviso (admin, admin_reparto, employee, accountant, consulente_lavoro).
 *
 * Variabili attese:
 *   $callerType (string)
 *   $callerId   (int)
 *   $callerName (string)
 *   $departmentId (int|null, opzionale)
 *   $pageBase   (string) URL pagina corrente (per fetch AJAX), default '?'
 *
 * Querystring riconosciute:
 *   ?view=day|week  (default: week)
 *   ?d=YYYY-MM-DD   (giorno di riferimento, default: oggi)
 */

if (!isset($callerType, $callerId, $callerName)) {
    throw new RuntimeException('_calendar.inc.php: $callerType, $callerId e $callerName sono obbligatori');
}
$departmentId = $departmentId ?? null;
$pageBase     = $pageBase     ?? '';

// ============ POST AJAX ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_event': {
                $participants = [];
                $rawParts = $_POST['participants'] ?? '[]';
                $decoded = is_string($rawParts) ? json_decode($rawParts, true) : $rawParts;
                if (is_array($decoded)) {
                    foreach ($decoded as $p) {
                        if (isset($p['user_type'], $p['user_id'])) $participants[] = $p;
                    }
                }
                $start = $_POST['start_at'] ?? '';
                $end   = $_POST['end_at']   ?? '';
                $result = CalendarEvent::create([
                    'owner_type'   => $callerType,
                    'owner_id'     => $callerId,
                    'title'        => $_POST['title'] ?? '',
                    'description'  => $_POST['description'] ?? null,
                    'location'     => $_POST['location'] ?? null,
                    'start_at'     => $start,
                    'end_at'       => $end,
                    'color'        => $_POST['color'] ?? null,
                    'all_day'      => !empty($_POST['all_day']),
                    'participants' => $participants,
                ]);
                if ($result['success']) {
                    // Riporta conflict info (informativo, non blocca)
                    $conflicts = CalendarEvent::checkConflicts($participants, $start, $end, (int) $result['id']);
                    $result['conflicts'] = $conflicts;
                }
                echo json_encode($result);
                exit;
            }
            case 'delete_event': {
                $id = (int) ($_POST['event_id'] ?? 0);
                echo json_encode(CalendarEvent::delete($id, $callerType, $callerId));
                exit;
            }
            case 'respond_event': {
                $id = (int) ($_POST['event_id'] ?? 0);
                $status = $_POST['status'] ?? '';
                echo json_encode(CalendarEvent::respond($id, $callerType, $callerId, $status));
                exit;
            }
            case 'check_conflicts': {
                $rawParts = $_POST['participants'] ?? '[]';
                $decoded = json_decode($rawParts, true) ?: [];
                $conflicts = CalendarEvent::checkConflicts($decoded, $_POST['start_at'] ?? '', $_POST['end_at'] ?? '');
                echo json_encode(['success' => true, 'conflicts' => $conflicts]);
                exit;
            }
            default:
                echo json_encode(['success' => false, 'error' => 'Azione non valida']);
                exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        exit;
    }
}

// ============ RENDER ============
$__view = ($_GET['view'] ?? 'week') === 'day' ? 'day' : 'week';
$__refDate = $_GET['d'] ?? date('Y-m-d');
try {
    $__ref = new DateTime($__refDate);
} catch (Throwable $e) {
    $__ref = new DateTime('today');
}
$__today = (new DateTime('today'))->format('Y-m-d');

if ($__view === 'day') {
    $__rangeStart = (clone $__ref)->setTime(0,0,0);
    $__rangeEnd   = (clone $__ref)->setTime(23,59,59);
    $__days = [$__ref->format('Y-m-d')];
} else {
    // Settimana lunedi-domenica
    $dow = (int) $__ref->format('N');
    $__rangeStart = (clone $__ref)->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0);
    $__rangeEnd   = (clone $__rangeStart)->modify('+6 days')->setTime(23,59,59);
    $__days = [];
    for ($i = 0; $i < 7; $i++) {
        $__days[] = (clone $__rangeStart)->modify('+' . $i . ' days')->format('Y-m-d');
    }
}

$__events = CalendarEvent::getForUserInRange(
    $callerType, $callerId,
    $__rangeStart->format('Y-m-d H:i:s'),
    $__rangeEnd->format('Y-m-d H:i:s')
);

// Naviga prev/next
$__navUnit = $__view === 'day' ? '1 day' : '7 days';
$__prevDate = (clone $__ref)->modify('-' . $__navUnit)->format('Y-m-d');
$__nextDate = (clone $__ref)->modify('+' . $__navUnit)->format('Y-m-d');

// Label header
$__monthsIt = ['','gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'];
if ($__view === 'day') {
    $__headerLabel = $__ref->format('j') . ' ' . $__monthsIt[(int)$__ref->format('n')];
} else {
    $startM = $__monthsIt[(int)$__rangeStart->format('n')];
    $endM   = $__monthsIt[(int)$__rangeEnd->format('n')];
    $__headerLabel = $__rangeStart->format('j') . ' ' . $startM .
                     ' – ' . $__rangeEnd->format('j') . ' ' . $endM;
}

// Contatti invitabili
$__contacts = CalendarEvent::listInvitableContacts($callerType, $callerId, $departmentId);

// Colori palette per gli eventi (rotazione su owner_id)
$__palette = [
    ['bg' => '#fef3c7', 'border' => '#f59e0b', 'text' => '#92400e'], // amber
    ['bg' => '#dbeafe', 'border' => '#3b82f6', 'text' => '#1e40af'], // blue
    ['bg' => '#dcfce7', 'border' => '#22c55e', 'text' => '#166534'], // green
    ['bg' => '#fee2e2', 'border' => '#ef4444', 'text' => '#991b1b'], // red
    ['bg' => '#ede9fe', 'border' => '#8b5cf6', 'text' => '#5b21b6'], // violet
    ['bg' => '#ffedd5', 'border' => '#f97316', 'text' => '#9a3412'], // orange
];

// Pre-calcola partecipanti per evento (per evitare query nel loop)
$__participantsByEv = [];
foreach ($__events as $ev) {
    $__participantsByEv[(int)$ev['id']] = CalendarEvent::getParticipants((int)$ev['id']);
}
?>

<style>
/* ============ CALENDAR ============ */
.cal-wrap {
    --cal-hour-h: 60px;
    --cal-time-w: 56px;
    --cal-bg: #fafbfd;
    --cal-line: #e6e8f0;
    --cal-now: #22c55e;
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    overflow: hidden;
}
.cal-toolbar {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px;
    border-bottom: 1px solid var(--cal-line);
    flex-wrap: wrap;
}
.cal-nav { display: inline-flex; align-items: center; gap: 6px; }
.cal-nav-btn {
    width: 34px; height: 34px;
    border: 1px solid var(--cal-line);
    background: white; border-radius: 999px;
    color: #475569;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; cursor: pointer;
    transition: all .12s ease;
}
.cal-nav-btn:hover { border-color: #0b3aa4; color: #0b3aa4; }
.cal-nav-label {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 16px; font-weight: 700;
    color: #1e1e2f; padding: 0 6px;
    text-transform: capitalize;
    letter-spacing: -0.01em;
}
.cal-view-toggle {
    margin-left: auto;
    background: #f1f5f9; border-radius: 999px; padding: 3px;
    display: inline-flex; gap: 2px;
}
.cal-view-toggle a {
    padding: 6px 16px; border-radius: 999px;
    font-size: 12px; font-weight: 600;
    color: #6e7191; text-decoration: none;
    transition: all .12s ease;
}
.cal-view-toggle a.active { background: #1e1e2f; color: white; }
.cal-add-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px;
    border: none; border-radius: 999px;
    background: #1e1e2f; color: white;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer;
    transition: all .12s ease;
}
.cal-add-btn:hover { background: #0b3aa4; }
.cal-add-btn svg { width: 14px; height: 14px; }

/* ============ GRID ============ */
.cal-grid {
    display: grid;
    grid-template-columns: var(--cal-time-w) 1fr;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.cal-time-col {
    border-right: 1px solid var(--cal-line);
    background: white;
    position: relative;
}
.cal-time-slot {
    height: var(--cal-hour-h);
    padding-right: 8px;
    text-align: right;
    font-size: 10px; color: #94a3b8;
    font-weight: 600;
    border-top: 1px solid transparent;
}
.cal-time-slot:first-child { padding-top: 0; }
.cal-days {
    display: grid;
    grid-auto-rows: 1fr;
    position: relative;
    background: var(--cal-bg);
}
.cal-days.is-week { grid-template-columns: repeat(7, minmax(0, 1fr)); }
.cal-days.is-day  { grid-template-columns: 1fr; }
.cal-day-col {
    border-right: 1px solid var(--cal-line);
    position: relative;
    min-height: calc(var(--cal-hour-h) * 14);
}
.cal-day-col:last-child { border-right: none; }
.cal-day-col.is-today { background: rgba(34,197,94,0.04); }

.cal-day-head {
    position: sticky; top: 0; z-index: 5;
    background: white;
    padding: 8px 6px;
    text-align: center;
    border-bottom: 1px solid var(--cal-line);
    border-right: 1px solid var(--cal-line);
}
.cal-day-head .dow { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
.cal-day-head .dnum {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 18px; font-weight: 700;
    color: #1e1e2f; letter-spacing: -0.02em;
    margin-top: 2px;
}
.cal-day-head.is-today .dnum { color: #0b3aa4; }
.cal-day-head.is-today { background: rgba(11,58,164,0.06); }

/* Linee orarie nelle colonne giorno */
.cal-day-col::before {
    content: '';
    position: absolute; inset: 0;
    background-image: repeating-linear-gradient(
        to bottom,
        transparent 0,
        transparent calc(var(--cal-hour-h) - 1px),
        var(--cal-line) calc(var(--cal-hour-h) - 1px),
        var(--cal-line) var(--cal-hour-h)
    );
    pointer-events: none;
}

/* Indicatore "ora attuale" */
.cal-now-line {
    position: absolute; left: 0; right: 0; height: 0;
    border-top: 2px solid var(--cal-now);
    z-index: 4;
}
.cal-now-line::before {
    content: '';
    position: absolute;
    left: -6px; top: -6px;
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--cal-now);
    box-shadow: 0 0 0 3px rgba(34,197,94,0.20);
}

/* Eventi (posizionati assolutamente nella colonna giorno) */
.cal-evt {
    position: absolute;
    left: 4px; right: 4px;
    padding: 8px 10px;
    border-radius: 10px;
    border-left: 3px solid currentColor;
    cursor: pointer;
    overflow: hidden;
    font-size: 12px;
    line-height: 1.3;
    z-index: 2;
    transition: filter .12s ease, transform .12s ease;
    text-decoration: none;
}
.cal-evt:hover { filter: brightness(0.96); text-decoration: none; transform: translateY(-1px); }
.cal-evt .evt-title {
    font-weight: 700;
    color: currentColor;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cal-evt .evt-time { font-size: 10.5px; opacity: 0.85; margin-top: 2px; }
.cal-evt .evt-attendees { display: flex; margin-top: 4px; }
.cal-evt .evt-attendees .av {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid white;
    margin-left: -6px;
    background: #cbd5e0; color: white;
    font-size: 9px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    text-transform: uppercase;
    overflow: hidden;
}
.cal-evt .evt-attendees .av:first-child { margin-left: 0; }
.cal-evt .evt-attendees .av img { width: 100%; height: 100%; object-fit: cover; }
.cal-evt.is-declined { opacity: 0.45; text-decoration: line-through; }
.cal-evt.is-pending::after {
    content: '⌛';
    position: absolute; top: 4px; right: 6px;
    font-size: 10px;
}

/* ============ MODAL ============ */
.cal-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.45);
    display: none;
    align-items: center; justify-content: center;
    z-index: 1000;
    padding: 16px;
}
.cal-modal-overlay.show { display: flex; }
.cal-modal {
    background: white;
    border-radius: 16px;
    width: 100%; max-width: 460px;
    box-shadow: 0 24px 64px rgba(15,23,42,0.25);
    overflow: hidden;
}
.cal-modal-h {
    padding: 18px 22px;
    border-bottom: 1px solid var(--cal-line);
    display: flex; justify-content: space-between; align-items: center;
}
.cal-modal-h h3 {
    margin: 0;
    font-family: 'Host Grotesk', sans-serif;
    font-size: 16px; font-weight: 700;
    color: #1e1e2f;
}
.cal-modal-close {
    background: transparent; border: none; cursor: pointer;
    color: #94a3b8; font-size: 22px; line-height: 1;
    padding: 0; width: 28px; height: 28px;
}
.cal-modal-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 12px; }
.cal-fg label {
    font-size: 11px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: .04em;
    margin-bottom: 4px; display: block;
}
.cal-fg input[type=text],
.cal-fg input[type=datetime-local],
.cal-fg input[type=date],
.cal-fg input[type=time],
.cal-fg textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--cal-line); border-radius: 8px;
    font-family: inherit; font-size: 14px;
    background: white;
}
.cal-fg input:focus, .cal-fg textarea:focus {
    outline: none; border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}
.cal-fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

.cal-participants {
    display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
    padding: 8px;
    border: 1px solid var(--cal-line); border-radius: 8px;
    min-height: 48px;
}
.cal-participants .av {
    width: 32px; height: 32px; border-radius: 50%;
    background: #cbd5e0; color: white;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase;
    overflow: hidden;
    position: relative;
    cursor: pointer;
}
.cal-participants .av img { width: 100%; height: 100%; object-fit: cover; }
.cal-participants .av .x {
    position: absolute; top: -3px; right: -3px;
    width: 14px; height: 14px;
    background: #ef4444; color: white;
    border-radius: 50%;
    font-size: 10px; line-height: 14px; text-align: center;
    display: none;
}
.cal-participants .av:hover .x { display: block; }
.cal-part-add-btn {
    width: 32px; height: 32px; border-radius: 50%;
    border: 1.5px dashed #cbd5e0;
    background: transparent;
    color: #6e7191;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 18px;
    transition: all .12s ease;
}
.cal-part-add-btn:hover { border-color: #0b3aa4; color: #0b3aa4; }

.cal-contact-picker {
    max-height: 200px; overflow-y: auto;
    border: 1px solid var(--cal-line); border-radius: 8px;
    margin-top: 8px;
    display: none;
}
.cal-contact-picker.show { display: block; }
.cal-contact-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}
.cal-contact-item:last-child { border-bottom: none; }
.cal-contact-item:hover { background: var(--cal-bg); }
.cal-contact-item .av { width: 28px; height: 28px; }
.cal-contact-item.selected { background: rgba(11,58,164,0.06); }

.cal-conflicts {
    background: #fef2f2; border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 12px;
    color: #991b1b;
    display: none;
}
.cal-conflicts.show { display: block; }
.cal-conflicts strong { display: block; margin-bottom: 4px; }

.cal-modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--cal-line);
    display: flex; justify-content: flex-end; gap: 8px;
}
.cal-btn {
    padding: 9px 18px;
    border-radius: 8px; border: 1px solid transparent;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
}
.cal-btn-primary { background: #1e1e2f; color: white; }
.cal-btn-primary:hover { background: #0b3aa4; }
.cal-btn-ghost { background: white; color: #475569; border-color: var(--cal-line); }
.cal-btn-ghost:hover { border-color: #0b3aa4; color: #0b3aa4; }
.cal-btn-danger { background: white; color: #b91c1c; border-color: #fecaca; }
.cal-btn-danger:hover { background: #fef2f2; }

/* ============ RESPONSIVE ============ */
@media (max-width: 720px) {
    .cal-toolbar { padding: 10px 12px; }
    .cal-view-toggle { margin-left: 0; }
    .cal-add-btn { padding: 8px 12px; font-size: 12px; }
    .cal-nav-label { font-size: 14px; }
    .cal-days.is-week { grid-template-columns: repeat(7, minmax(60px, 1fr)); }
    .cal-grid { overflow-x: auto; }
    .cal-day-head .dnum { font-size: 14px; }
    .cal-day-head .dow { font-size: 9px; }
    .cal-evt { font-size: 11px; padding: 6px 8px; }
    .cal-evt .evt-time { font-size: 10px; }
    .cal-modal { max-width: 100%; max-height: 92vh; overflow-y: auto; }
}
</style>

<div class="cal-wrap">
    <div class="cal-toolbar">
        <div class="cal-nav">
            <a href="?view=<?= e($__view) ?>&d=<?= $__prevDate ?>" class="cal-nav-btn" aria-label="Precedente">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <span class="cal-nav-label"><?= e($__headerLabel) ?></span>
            <a href="?view=<?= e($__view) ?>&d=<?= $__nextDate ?>" class="cal-nav-btn" aria-label="Successivo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="?view=<?= e($__view) ?>&d=<?= $__today ?>" class="cal-nav-btn" aria-label="Oggi" style="width:auto; padding:0 12px; font-size:12px; font-weight:600;">Oggi</a>
        </div>
        <div class="cal-view-toggle">
            <a href="?view=day&d=<?= $__refDate ?>" class="<?= $__view === 'day' ? 'active' : '' ?>">Day</a>
            <a href="?view=week&d=<?= $__refDate ?>" class="<?= $__view === 'week' ? 'active' : '' ?>">Week</a>
        </div>
        <button type="button" class="cal-add-btn" onclick="calOpenModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Event
        </button>
    </div>

    <!-- Day headers (solo week) -->
    <?php if ($__view === 'week'): ?>
    <div class="cal-grid" style="border-bottom: 1px solid var(--cal-line);">
        <div class="cal-time-col" style="height: 56px;"></div>
        <div class="cal-days is-week" style="background: white; min-height: 56px;">
            <?php
            $__dowMap = ['','Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
            foreach ($__days as $d):
                $dObj = new DateTime($d);
                $isToday = $d === $__today;
            ?>
                <div class="cal-day-head <?= $isToday ? 'is-today' : '' ?>">
                    <div class="dow"><?= $__dowMap[(int)$dObj->format('N')] ?></div>
                    <div class="dnum"><?= $dObj->format('j') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="cal-grid" id="calGrid">
        <div class="cal-time-col">
            <?php for ($h = 7; $h <= 20; $h++): ?>
                <div class="cal-time-slot"><?= sprintf('%02d:00', $h) ?></div>
            <?php endfor; ?>
        </div>
        <div class="cal-days <?= $__view === 'week' ? 'is-week' : 'is-day' ?>" id="calDays">
            <?php foreach ($__days as $dIndex => $d):
                $isToday = $d === $__today;
            ?>
                <div class="cal-day-col <?= $isToday ? 'is-today' : '' ?>" data-day="<?= e($d) ?>">
                    <?php
                    // Eventi del giorno
                    foreach ($__events as $ev):
                        $evStart = new DateTime($ev['start_at']);
                        $evEnd   = new DateTime($ev['end_at']);
                        $evDayStart = $evStart->format('Y-m-d');
                        $evDayEnd   = $evEnd->format('Y-m-d');
                        if ($d < $evDayStart || $d > $evDayEnd) continue;

                        // Clip al giorno corrente
                        $dayBegin = new DateTime($d . ' 00:00:00');
                        $dayEnd   = new DateTime($d . ' 23:59:59');
                        $clipStart = max($evStart, $dayBegin);
                        $clipEnd   = min($evEnd, $dayEnd);

                        // Posizione: ore 7:00 = top 0, ore 21:00 = top 14*60=840px
                        $startMin = (int)$clipStart->format('H') * 60 + (int)$clipStart->format('i') - 7 * 60;
                        $endMin   = (int)$clipEnd->format('H')   * 60 + (int)$clipEnd->format('i')   - 7 * 60;
                        $startMin = max(0, $startMin);
                        $endMin   = max($startMin + 20, $endMin);
                        $top    = $startMin;       // 1 minute = 1 px (var --cal-hour-h = 60)
                        $height = $endMin - $startMin;

                        // Palette via owner_id
                        $palIdx = (int)$ev['owner_id'] % count($__palette);
                        $col = $__palette[$palIdx];
                        $myStatus = $ev['my_status'] ?? null;
                        $extraClass = '';
                        if ($myStatus === 'declined') $extraClass = 'is-declined';
                        elseif ($myStatus === 'pending') $extraClass = 'is-pending';
                    ?>
                        <a class="cal-evt <?= $extraClass ?>"
                           style="top: <?= $top ?>px; height: <?= $height ?>px;
                                  background: <?= $col['bg'] ?>;
                                  color: <?= $col['text'] ?>;
                                  border-left-color: <?= $col['border'] ?>;"
                           href="#"
                           onclick="calOpenDetail(<?= (int)$ev['id'] ?>); return false;"
                           data-event-id="<?= (int)$ev['id'] ?>">
                            <div class="evt-title"><?= e($ev['title']) ?></div>
                            <div class="evt-time"><?= $evStart->format('H:i') ?>–<?= $evEnd->format('H:i') ?></div>
                            <?php
                            $parts = $__participantsByEv[(int)$ev['id']] ?? [];
                            if (!empty($parts)):
                            ?>
                            <div class="evt-attendees">
                                <?php foreach (array_slice($parts, 0, 4) as $p):
                                    $initials = mb_strtoupper(mb_substr($p['name'], 0, 1));
                                ?>
                                    <span class="av" style="background: <?= $__palette[(int)$p['user_id'] % count($__palette)]['border'] ?>;">
                                        <?php if (!empty($p['photo_path'])): ?>
                                            <img src="<?= e(PUBLIC_URL . '/' . ltrim($p['photo_path'], '/')) ?>" alt="">
                                        <?php else: ?>
                                            <?= e($initials) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>

                    <?php if ($isToday):
                        $now = new DateTime();
                        $nowMin = (int)$now->format('H') * 60 + (int)$now->format('i') - 7 * 60;
                        if ($nowMin >= 0 && $nowMin <= 14 * 60):
                    ?>
                        <div class="cal-now-line" style="top: <?= $nowMin ?>px;"></div>
                    <?php endif; endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal Add/Detail Event -->
<div class="cal-modal-overlay" id="calModal" onclick="if(event.target === this) calCloseModal()">
    <div class="cal-modal">
        <div class="cal-modal-h">
            <h3 id="calModalTitle">Nuovo evento</h3>
            <button type="button" class="cal-modal-close" onclick="calCloseModal()">&times;</button>
        </div>
        <form id="calForm" onsubmit="return calSubmit(event)">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create_event">
            <input type="hidden" name="participants" id="calPartsJson" value="[]">
            <div class="cal-modal-body">
                <div class="cal-fg">
                    <label for="calTitle">Titolo</label>
                    <input type="text" id="calTitle" name="title" required placeholder="Es. Riunione team">
                </div>
                <div class="cal-fg-row">
                    <div class="cal-fg">
                        <label for="calStart">Inizio</label>
                        <input type="datetime-local" id="calStart" name="start_at" required>
                    </div>
                    <div class="cal-fg">
                        <label for="calEnd">Fine</label>
                        <input type="datetime-local" id="calEnd" name="end_at" required>
                    </div>
                </div>
                <div class="cal-fg">
                    <label for="calLocation">Luogo</label>
                    <input type="text" id="calLocation" name="location" placeholder="Aula riunioni / Online / ...">
                </div>
                <div class="cal-fg">
                    <label>Partecipanti</label>
                    <div class="cal-participants" id="calPartsList">
                        <button type="button" class="cal-part-add-btn" onclick="calToggleContacts()" aria-label="Aggiungi partecipante">+</button>
                    </div>
                    <div class="cal-contact-picker" id="calContactPicker">
                        <?php foreach ($__contacts as $groupKey => $list):
                            foreach ($list as $c):
                                if (empty($c['id'])) continue;
                                if ($groupKey === $callerType && (int)$c['id'] === $callerId) continue;
                                $cName = ($groupKey === 'employee')
                                    ? trim(($c['last_name'] ?? '') . ' ' . ($c['first_name'] ?? ''))
                                    : ($c['name'] ?? 'Utente #' . $c['id']);
                                $cInit = mb_strtoupper(mb_substr($cName, 0, 1));
                        ?>
                            <div class="cal-contact-item"
                                 data-type="<?= e($groupKey) ?>"
                                 data-id="<?= (int)$c['id'] ?>"
                                 data-name="<?= e($cName) ?>"
                                 data-photo="<?= e($c['photo_path'] ?? '') ?>"
                                 onclick="calToggleContactPick(this)">
                                <span class="av" style="background: #0b3aa4;">
                                    <?php if (!empty($c['photo_path'])): ?>
                                        <img src="<?= e(PUBLIC_URL . '/' . ltrim($c['photo_path'], '/')) ?>" alt="">
                                    <?php else: ?>
                                        <?= e($cInit) ?>
                                    <?php endif; ?>
                                </span>
                                <span><?= e($cName) ?></span>
                            </div>
                        <?php endforeach; endforeach; ?>
                    </div>
                </div>
                <div class="cal-conflicts" id="calConflicts"></div>
            </div>
            <div class="cal-modal-footer">
                <button type="button" class="cal-btn cal-btn-danger" id="calDeleteBtn" style="display:none;" onclick="calDeleteEvent()">Elimina</button>
                <button type="button" class="cal-btn cal-btn-ghost" onclick="calCloseModal()">Annulla</button>
                <button type="submit" class="cal-btn cal-btn-primary" id="calSubmitBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span id="calSubmitLabel">Aggiungi evento</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const CALLER_TYPE = <?= json_encode($callerType) ?>;
    const CALLER_ID   = <?= (int)$callerId ?>;

    const modal = document.getElementById('calModal');
    const form = document.getElementById('calForm');
    const partsList = document.getElementById('calPartsList');
    const picker = document.getElementById('calContactPicker');
    const titleEl = document.getElementById('calModalTitle');
    const deleteBtn = document.getElementById('calDeleteBtn');
    const submitLabel = document.getElementById('calSubmitLabel');
    const partsJson = document.getElementById('calPartsJson');
    const conflictsBox = document.getElementById('calConflicts');

    let selectedParts = []; // {user_type, user_id, name, photo}
    let editingEventId = null;

    window.calOpenModal = function(eventId) {
        editingEventId = null;
        form.reset();
        selectedParts = [];
        renderParts();
        picker.classList.remove('show');
        conflictsBox.classList.remove('show');
        titleEl.textContent = 'Nuovo evento';
        submitLabel.textContent = 'Aggiungi evento';
        deleteBtn.style.display = 'none';

        // Imposta default time slot (ora successiva)
        const now = new DateTime();
        const round = new Date(Math.ceil(Date.now() / (30*60*1000)) * (30*60*1000));
        const fmt = (d) => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + 'T' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
        document.getElementById('calStart').value = fmt(round);
        const end = new Date(round.getTime() + 30*60*1000);
        document.getElementById('calEnd').value = fmt(end);

        modal.classList.add('show');
    };

    // Polyfill banale
    function DateTime() { return new Date(); }

    window.calCloseModal = function() {
        modal.classList.remove('show');
    };

    window.calToggleContacts = function() {
        picker.classList.toggle('show');
    };

    window.calToggleContactPick = function(el) {
        const type = el.dataset.type;
        const id   = parseInt(el.dataset.id, 10);
        const name = el.dataset.name;
        const photo = el.dataset.photo;

        const i = selectedParts.findIndex(p => p.user_type === type && p.user_id === id);
        if (i >= 0) {
            selectedParts.splice(i, 1);
            el.classList.remove('selected');
        } else {
            selectedParts.push({ user_type: type, user_id: id, name, photo });
            el.classList.add('selected');
        }
        renderParts();
    };

    function renderParts() {
        // Pulisci avatars (ma mantieni il + btn)
        [...partsList.querySelectorAll('.av')].forEach(a => a.remove());
        const addBtn = partsList.querySelector('.cal-part-add-btn');
        selectedParts.forEach(p => {
            const av = document.createElement('span');
            av.className = 'av';
            av.style.background = '#0b3aa4';
            av.title = p.name;
            if (p.photo) {
                const img = document.createElement('img');
                img.src = (window.PAM?.baseUrl || '') + '/' + String(p.photo).replace(/^\//, '');
                img.alt = p.name;
                av.appendChild(img);
            } else {
                av.textContent = p.name.charAt(0).toUpperCase();
            }
            const x = document.createElement('span');
            x.className = 'x';
            x.textContent = '×';
            x.onclick = (ev) => {
                ev.stopPropagation();
                selectedParts = selectedParts.filter(sp => !(sp.user_type === p.user_type && sp.user_id === p.user_id));
                document.querySelector('.cal-contact-item[data-type="' + p.user_type + '"][data-id="' + p.user_id + '"]')?.classList.remove('selected');
                renderParts();
            };
            av.appendChild(x);
            partsList.insertBefore(av, addBtn);
        });
        partsJson.value = JSON.stringify(selectedParts.map(p => ({ user_type: p.user_type, user_id: p.user_id })));
    }

    window.calSubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(form);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.conflicts && data.conflicts.length > 0) {
                        const names = data.conflicts.map(c => c.name).join(', ');
                        if (!confirm('Attenzione: conflitti rilevati per ' + names + '. Continuare?')) {
                            // Lascia evento creato; chiudi comunque
                        }
                    }
                    window.location.reload();
                } else {
                    alert(data.error || 'Errore');
                }
            })
            .catch(err => { console.error(err); alert('Errore di connessione'); });
        return false;
    };

    window.calOpenDetail = function(eventId) {
        // Per ora apri modal in modalita' "modifica" minima
        editingEventId = eventId;
        // Recupera dati: per semplicita' leggiamo dal DOM
        const card = document.querySelector('[data-event-id="' + eventId + '"]');
        if (!card) return;
        const title = card.querySelector('.evt-title')?.textContent || '';
        titleEl.textContent = 'Dettaglio evento';
        submitLabel.textContent = 'Chiudi';
        deleteBtn.style.display = 'inline-flex';
        document.getElementById('calTitle').value = title;
        modal.classList.add('show');
    };

    window.calDeleteEvent = function() {
        if (!editingEventId) return;
        if (!confirm('Eliminare questo evento?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_event');
        fd.append('event_id', editingEventId);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) window.location.reload();
                else alert(data.error || 'Errore');
            });
    };

    // Auto-scroll a 8:00 al load
    const grid = document.getElementById('calGrid');
    if (grid) grid.scrollTop = 60; // 8:00 - 7:00 = 60px
})();
</script>
