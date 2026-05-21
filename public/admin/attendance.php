<?php
/**
 * Gestione timbrature (admin).
 * - Selettore giorno
 * - Lista timbrature di tutti i dipendenti di quel giorno
 * - Force timbratura manuale, modifica, elimina
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$companyId = Tenant::currentCompanyId();
$message = '';
$error = '';

// Se le timbrature sono disabilitate, redirect a punch-settings con avviso
$__punchEnabled = (int) (Database::fetchColumn(
    "SELECT timbratura_enabled FROM companies WHERE id = ?", [$companyId]
) ?? 1) === 1;
if (!$__punchEnabled) {
    header('Location: punch-settings.php?msg=enable_first');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_manual': {
                $eid = (int) ($_POST['employee_id'] ?? 0);
                $date = trim($_POST['punch_date'] ?? '');
                $time = trim($_POST['punch_time'] ?? '');
                $kind = $_POST['kind'] ?? '';
                $notes = trim($_POST['notes'] ?? '') ?: null;
                if (!$eid || !$date || !$time || !$kind) {
                    $error = 'Compila tutti i campi obbligatori.';
                    break;
                }
                $punchAt = $date . ' ' . $time . ':00';
                $r = AttendancePunch::createManual($eid, $punchAt, $kind, $notes);
                if ($r['success']) {
                    $message = 'Timbratura aggiunta manualmente.';
                } else {
                    $error = $r['error'] ?? 'Errore';
                }
                break;
            }
            case 'update_manual': {
                $id = (int) ($_POST['punch_id'] ?? 0);
                $date = trim($_POST['punch_date'] ?? '');
                $time = trim($_POST['punch_time'] ?? '');
                $kind = $_POST['kind'] ?? '';
                $notes = trim($_POST['notes'] ?? '') ?: null;
                if (!$id || !$date || !$time || !$kind) {
                    $error = 'Dati non validi.';
                    break;
                }
                // Verifica che la timbratura appartenga alla tenant
                $p = AttendancePunch::getById($id);
                if (!$p || (int) $p['company_id'] !== (int) $companyId) {
                    $error = 'Timbratura non trovata.';
                    break;
                }
                $r = AttendancePunch::updateManual($id, $date . ' ' . $time . ':00', $kind, $notes);
                if ($r['success']) $message = 'Timbratura aggiornata.';
                else $error = $r['error'] ?? 'Errore';
                break;
            }
            case 'delete': {
                $id = (int) ($_POST['punch_id'] ?? 0);
                $p = AttendancePunch::getById($id);
                if (!$p || (int) $p['company_id'] !== (int) $companyId) {
                    $error = 'Timbratura non trovata.';
                    break;
                }
                AttendancePunch::deleteOne($id);
                $message = 'Timbratura eliminata.';
                break;
            }
        }
    } catch (Throwable $e) {
        $error = 'Errore: ' . $e->getMessage();
    }
}

$day = $_GET['d'] ?? date('Y-m-d');
$employees = Database::fetchAll(
    "SELECT e.id, e.first_name, e.last_name, e.photo_path, e.department_id, e.ccnl_id, d.name AS department_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE e.company_id = ? AND e.is_active = TRUE
     ORDER BY e.last_name, e.first_name",
    [$companyId]
);

// Raggruppa timbrature per employee_id
$punches = Database::fetchAll(
    "SELECT * FROM attendance_punches
     WHERE company_id = ? AND DATE(punch_at) = ?
     ORDER BY punch_at ASC",
    [$companyId, $day]
);
$byEmp = [];
foreach ($punches as $p) {
    $byEmp[(int) $p['employee_id']][] = $p;
}

// ===== Statistiche =====
$totalEmp = count($employees);
$empWithPunches = 0;
$empCurrentlyIn = 0; // chi ha ultima timbratura = IN
$totalHours = 0;
$manualCount = 0;
foreach ($employees as $emp) {
    $list = $byEmp[(int) $emp['id']] ?? [];
    if (empty($list)) continue;
    $empWithPunches++;
    $openIn = null;
    foreach ($list as $p) {
        if ($p['source'] === 'manual') $manualCount++;
        if ($p['kind'] === 'in') $openIn = strtotime($p['punch_at']);
        elseif ($p['kind'] === 'out' && $openIn !== null) {
            $totalHours += (strtotime($p['punch_at']) - $openIn) / 3600;
            $openIn = null;
        }
    }
    // Se è ancora "dentro" oggi
    if ($openIn !== null) {
        $empCurrentlyIn++;
        if ($day === date('Y-m-d')) $totalHours += (time() - $openIn) / 3600;
    }
}
$empAbsent = $totalEmp - $empWithPunches;

$pageTitle = 'Timbrature';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.att-page { display: flex; flex-direction: column; gap: 14px; }
.att-toolbar {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    padding: 14px 18px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.att-toolbar h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0; font-size: 18px; color: #0b3aa4; letter-spacing: -0.01em;
}

/* Stats cards */
.att-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.att-stat {
    background: white; border: 1px solid #e6e8f0; border-radius: 12px;
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
}
.att-stat-ic {
    width: 40px; height: 40px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.att-stat-ic svg { width: 20px; height: 20px; }
.att-stat-ic.present  { background: rgba(22,163,74,0.10);  color: #16a34a; }
.att-stat-ic.now-in   { background: rgba(11,58,164,0.10);  color: #0b3aa4; }
.att-stat-ic.absent   { background: rgba(217,119,6,0.10);  color: #d97706; }
.att-stat-ic.hours    { background: rgba(139,92,246,0.10); color: #8b5cf6; }
.att-stat-label { font-size: 11px; font-weight: 700; color: #6e7191; text-transform: uppercase; letter-spacing: 0.04em; }
.att-stat-value {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 22px; font-weight: 700; color: #1e1e2f;
    letter-spacing: -0.02em; line-height: 1.05;
    font-variant-numeric: tabular-nums;
}
.att-stat-hint { font-size: 11px; color: #94a3b8; margin-top: 1px; }
@media (max-width: 720px) {
    .att-stats { grid-template-columns: repeat(2, 1fr); }
}

/* Search bar */
.att-search-bar {
    background: white; border: 1px solid #e6e8f0; border-radius: 12px;
    padding: 8px 12px;
    display: flex; align-items: center; gap: 10px;
}
.att-search-bar svg { color: #94a3b8; flex-shrink: 0; }
.att-search-bar input {
    flex: 1; min-width: 0;
    border: none; background: transparent;
    font-family: inherit; font-size: 14px;
    color: #1e1e2f; outline: none;
    padding: 6px 0;
}
.att-search-bar select {
    padding: 6px 10px; border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 13px; background: white;
}
.att-toolbar .date-nav {
    display: inline-flex; align-items: center; gap: 4px;
    margin-left: auto;
}
.att-toolbar .date-nav a, .att-toolbar .date-nav button {
    padding: 7px 10px; border: 1px solid #e6e8f0; border-radius: 8px;
    background: white; color: #475569; cursor: pointer;
    text-decoration: none; font-family: inherit; font-size: 12px; font-weight: 600;
}
.att-toolbar .date-nav input[type=date] {
    padding: 7px 10px; border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 13px;
}
.att-toolbar .date-nav a:hover, .att-toolbar .date-nav button:hover { border-color: #0b3aa4; color: #0b3aa4; }

.att-list {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    overflow: hidden;
}
.att-emp {
    padding: 14px 18px;
    display: grid; grid-template-columns: 260px 1fr auto; gap: 16px;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    transition: background .12s ease;
}
.att-emp:last-child { border-bottom: none; }
.att-emp:hover { background: #fafbfd; }
.att-emp.is-absent .att-emp-status { color: #d97706; }
.att-emp.is-in     .att-emp-status { color: #16a34a; }
.att-emp.is-out    .att-emp-status { color: #6e7191; }
.att-emp-status {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    margin-top: 2px;
}
.att-emp-status::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}
.att-emp-info { display: flex; align-items: center; gap: 12px; min-width: 0; }
.att-emp-info .av {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4, #082b7b);
    color: white; display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; overflow: hidden; flex-shrink: 0;
    text-transform: uppercase;
}
.att-emp-info .av img { width: 100%; height: 100%; object-fit: cover; }
.att-emp-info .name { font-weight: 600; font-size: 13.5px; color: #1e1e2f; }
.att-emp-info .totals { font-size: 11.5px; color: #6e7191; margin-top: 1px; }

.att-punches {
    display: flex; gap: 6px; flex-wrap: wrap;
}
.att-punch {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px;
    background: #f8fafc;
    border-radius: 999px;
    font-size: 12.5px; font-weight: 600;
    color: #475569;
    cursor: pointer;
    border: 1px solid #e6e8f0;
}
.att-punch:hover { border-color: #0b3aa4; }
.att-punch .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.att-punch.in  .dot { background: #16a34a; }
.att-punch.out .dot { background: #d97706; }
.att-punch.manual::after {
    content: 'M'; width: 14px; height: 14px;
    background: #0b3aa4; color: white;
    font-size: 9px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    margin-left: 2px;
}
.att-punch .t { font-variant-numeric: tabular-nums; }
.att-emp-empty { color: #94a3b8; font-size: 12.5px; font-style: italic; }

.att-add-btn {
    padding: 8px 14px;
    background: white; color: #0b3aa4;
    border: 1px solid #e6e8f0; border-radius: 8px;
    cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 5px;
}
.att-add-btn:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.04); }

/* Modal punch */
.pm-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.45);
    display: none; align-items: center; justify-content: center;
    z-index: 1000; padding: 16px;
}
.pm-overlay.show { display: flex; }
.pm-card {
    background: white; border-radius: 14px; max-width: 420px; width: 100%;
    overflow: hidden;
}
.pm-h {
    padding: 16px 20px; border-bottom: 1px solid #e6e8f0;
    display: flex; justify-content: space-between; align-items: center;
}
.pm-h h3 { margin: 0; font-family: 'Host Grotesk', sans-serif; font-size: 15px; color: #1e1e2f; }
.pm-h button { background: transparent; border: none; cursor: pointer; font-size: 20px; color: #94a3b8; }
.pm-b { padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; }
.pm-b label { display: block; font-size: 11px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.pm-b input, .pm-b select, .pm-b textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 14px;
}
.pm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pm-f { padding: 14px 20px; border-top: 1px solid #e6e8f0; display: flex; justify-content: space-between; gap: 8px; }
.pm-btn { padding: 9px 16px; border-radius: 8px; border: 1px solid transparent; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
.pm-btn-ghost { background: white; color: #475569; border-color: #e6e8f0; }
.pm-btn-primary { background: #0b3aa4; color: white; }
.pm-btn-danger { background: white; color: #b91c1c; border-color: #fecaca; }

@media (max-width: 720px) {
    .att-emp { grid-template-columns: 1fr; gap: 10px; }
    .pm-row { grid-template-columns: 1fr; }
}
</style>

<div class="att-page">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="att-toolbar">
        <h2>Timbrature</h2>
        <div class="date-nav">
            <a href="?d=<?= date('Y-m-d', strtotime($day . ' -1 day')) ?>" title="Giorno precedente">‹</a>
            <form method="GET" style="margin:0;">
                <input type="date" name="d" value="<?= htmlspecialchars($day) ?>" onchange="this.form.submit()">
            </form>
            <a href="?d=<?= date('Y-m-d', strtotime($day . ' +1 day')) ?>" title="Giorno successivo">›</a>
            <a href="?d=<?= date('Y-m-d') ?>">Oggi</a>
        </div>
    </div>

    <!-- Stats -->
    <div class="att-stats">
        <div class="att-stat">
            <div class="att-stat-ic present">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
                <div class="att-stat-label">Presenti oggi</div>
                <div class="att-stat-value"><?= $empWithPunches ?>/<?= $totalEmp ?></div>
                <div class="att-stat-hint">timbrature registrate</div>
            </div>
        </div>
        <div class="att-stat">
            <div class="att-stat-ic now-in">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            </div>
            <div>
                <div class="att-stat-label">Attualmente in sede</div>
                <div class="att-stat-value"><?= $empCurrentlyIn ?></div>
                <div class="att-stat-hint">ultima timbratura = IN</div>
            </div>
        </div>
        <div class="att-stat">
            <div class="att-stat-ic absent">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            </div>
            <div>
                <div class="att-stat-label">Senza timbrature</div>
                <div class="att-stat-value"><?= $empAbsent ?></div>
                <div class="att-stat-hint">nessuna timbratura oggi</div>
            </div>
        </div>
        <div class="att-stat">
            <div class="att-stat-ic hours">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="att-stat-label">Ore totali</div>
                <div class="att-stat-value"><?= number_format($totalHours, 1, ',', '.') ?>h</div>
                <div class="att-stat-hint"><?php if ($manualCount > 0): ?><?= $manualCount ?> manuali<?php else: ?>tutte da NFC<?php endif; ?></div>
            </div>
        </div>
    </div>

    <!-- Search + filter -->
    <div class="att-search-bar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="attSearch" placeholder="Cerca dipendente o reparto...">
        <select id="attFilter">
            <option value="">Tutti</option>
            <option value="in">In sede</option>
            <option value="out">Usciti</option>
            <option value="absent">Senza timbrature</option>
            <option value="manual">Con manuali</option>
        </select>
    </div>

    <div class="att-list">
    <?php foreach ($employees as $emp):
        $eid = (int) $emp['id'];
        $list = $byEmp[$eid] ?? [];
        $initials = mb_strtoupper(mb_substr($emp['first_name'] ?? '?', 0, 1) . mb_substr($emp['last_name'] ?? '', 0, 1));
        // Calcola ore stimato + stato
        $totalSec = 0; $openIn = null; $hasManual = false; $lastKind = '';
        foreach ($list as $p) {
            if ($p['source'] === 'manual') $hasManual = true;
            $lastKind = $p['kind'];
            if ($p['kind'] === 'in') $openIn = strtotime($p['punch_at']);
            elseif ($p['kind'] === 'out' && $openIn !== null) { $totalSec += strtotime($p['punch_at']) - $openIn; $openIn = null; }
        }
        $h = floor($totalSec / 3600); $m = floor(($totalSec % 3600) / 60);
        if (empty($list)) { $statusCls = 'is-absent'; $statusLbl = 'Senza timbrature'; $filterKey = 'absent'; }
        elseif ($lastKind === 'in') { $statusCls = 'is-in'; $statusLbl = 'In sede'; $filterKey = 'in'; }
        else { $statusCls = 'is-out'; $statusLbl = 'Uscito'; $filterKey = 'out'; }
        $searchKey = mb_strtolower(($emp['last_name'] ?? '') . ' ' . ($emp['first_name'] ?? '') . ' ' . ($emp['department_name'] ?? ''));
    ?>
    <div class="att-emp <?= $statusCls ?>"
         data-search="<?= htmlspecialchars($searchKey) ?>"
         data-status="<?= $filterKey ?>"
         data-manual="<?= $hasManual ? '1' : '0' ?>">
        <div class="att-emp-info">
            <div class="av">
                <?php if (!empty($emp['photo_path'])): ?>
                    <img src="<?= htmlspecialchars(PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/')) ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($initials) ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="name"><?= htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']) ?></div>
                <div class="totals">
                    <?= count($list) ?> timbrature · <?= sprintf('%dh %02dm', $h, $m) ?>
                    <?php if (!empty($emp['department_name'])): ?> · <?= htmlspecialchars($emp['department_name']) ?><?php endif; ?>
                </div>
                <div class="att-emp-status"><?= htmlspecialchars($statusLbl) ?></div>
            </div>
        </div>
        <div class="att-punches">
            <?php if (empty($list)): ?>
                <span class="att-emp-empty">Nessuna timbratura</span>
            <?php else: ?>
                <?php foreach ($list as $p):
                    $time = date('H:i', strtotime($p['punch_at']));
                    $payload = json_encode([
                        'id' => (int) $p['id'],
                        'kind' => $p['kind'],
                        'date' => date('Y-m-d', strtotime($p['punch_at'])),
                        'time' => $time,
                        'notes' => $p['notes'] ?? '',
                        'employee_id' => $eid,
                        'employee_name' => $emp['last_name'] . ' ' . $emp['first_name'],
                    ]);
                ?>
                <button type="button" class="att-punch <?= $p['kind'] ?> <?= $p['source'] === 'manual' ? 'manual' : '' ?>"
                        onclick='pmEdit(<?= htmlspecialchars($payload, ENT_QUOTES) ?>)'
                        title="<?= $p['source'] === 'manual' ? 'Inserita manualmente' : ucfirst($p['source']) ?>">
                    <span class="dot"></span>
                    <span class="k"><?= $p['kind'] === 'in' ? 'IN' : 'OUT' ?></span>
                    <span class="t"><?= htmlspecialchars($time) ?></span>
                </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div>
            <button type="button" class="att-add-btn" onclick="pmAdd(<?= $eid ?>, '<?= htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Aggiungi
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div id="attEmpty" style="display:none; text-align:center; padding:30px; color:#94a3b8; font-size:13px;">
        Nessun dipendente corrisponde alla ricerca.
    </div>
</div>

<!-- Modal aggiungi/modifica timbratura -->
<div class="pm-overlay" id="pmModal" onclick="if(event.target===this) pmClose()">
    <div class="pm-card">
        <div class="pm-h">
            <h3 id="pmTitle">Aggiungi timbratura</h3>
            <button type="button" onclick="pmClose()">×</button>
        </div>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" id="pmAction" value="create_manual">
            <input type="hidden" name="punch_id" id="pmPunchId">
            <input type="hidden" name="employee_id" id="pmEmployeeId">
            <div class="pm-b">
                <div>
                    <label>Dipendente</label>
                    <div id="pmEmployeeName" style="padding: 9px 12px; background: #f8fafc; border-radius: 8px; font-size: 14px; color: #1e1e2f; font-weight: 600;"></div>
                </div>
                <div class="pm-row">
                    <div>
                        <label>Data</label>
                        <input type="date" name="punch_date" id="pmDate" required value="<?= htmlspecialchars($day) ?>">
                    </div>
                    <div>
                        <label>Ora</label>
                        <input type="time" name="punch_time" id="pmTime" step="60" required value="09:00">
                    </div>
                </div>
                <div>
                    <label>Tipo</label>
                    <select name="kind" id="pmKind" required>
                        <option value="in">Entrata</option>
                        <option value="out">Uscita</option>
                    </select>
                </div>
                <div>
                    <label>Note (opzionale)</label>
                    <textarea name="notes" id="pmNotes" rows="2" placeholder="Es. dimenticato di timbrare, smart-working..."></textarea>
                </div>
            </div>
            <div class="pm-f">
                <button type="button" class="pm-btn pm-btn-danger" id="pmDeleteBtn" style="display:none;" onclick="pmDelete()">Elimina</button>
                <div style="display:flex; gap:8px; margin-left:auto;">
                    <button type="button" class="pm-btn pm-btn-ghost" onclick="pmClose()">Annulla</button>
                    <button type="submit" class="pm-btn pm-btn-primary">Salva</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function pmAdd(empId, empName) {
    document.getElementById('pmTitle').textContent = 'Aggiungi timbratura';
    document.getElementById('pmAction').value = 'create_manual';
    document.getElementById('pmPunchId').value = '';
    document.getElementById('pmEmployeeId').value = empId;
    document.getElementById('pmEmployeeName').textContent = empName;
    document.getElementById('pmKind').value = 'in';
    document.getElementById('pmNotes').value = '';
    document.getElementById('pmTime').value = '09:00';
    document.getElementById('pmDeleteBtn').style.display = 'none';
    document.getElementById('pmModal').classList.add('show');
}
function pmEdit(d) {
    document.getElementById('pmTitle').textContent = 'Modifica timbratura';
    document.getElementById('pmAction').value = 'update_manual';
    document.getElementById('pmPunchId').value = d.id;
    document.getElementById('pmEmployeeId').value = d.employee_id;
    document.getElementById('pmEmployeeName').textContent = d.employee_name;
    document.getElementById('pmDate').value = d.date;
    document.getElementById('pmTime').value = d.time;
    document.getElementById('pmKind').value = d.kind;
    document.getElementById('pmNotes').value = d.notes || '';
    document.getElementById('pmDeleteBtn').style.display = 'inline-flex';
    document.getElementById('pmModal').classList.add('show');
}
function pmClose() { document.getElementById('pmModal').classList.remove('show'); }
// Search + filter live
(function() {
    const q = document.getElementById('attSearch');
    const f = document.getElementById('attFilter');
    const empty = document.getElementById('attEmpty');
    function apply() {
        const txt = (q.value || '').toLowerCase().trim();
        const filt = f.value;
        let visible = 0;
        document.querySelectorAll('.att-emp').forEach(row => {
            const hay = row.dataset.search || '';
            const matchTxt = !txt || hay.includes(txt);
            let matchFilt = true;
            if (filt === 'in' || filt === 'out' || filt === 'absent') matchFilt = row.dataset.status === filt;
            else if (filt === 'manual') matchFilt = row.dataset.manual === '1';
            const show = matchTxt && matchFilt;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        empty.style.display = visible === 0 ? 'block' : 'none';
    }
    q?.addEventListener('input', apply);
    f?.addEventListener('change', apply);
})();

function pmDelete() {
    if (!confirm('Eliminare questa timbratura?')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<?= CSRF::field() ?>' +
        '<input type="hidden" name="action" value="delete">' +
        '<input type="hidden" name="punch_id" value="' + document.getElementById('pmPunchId').value + '">';
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
