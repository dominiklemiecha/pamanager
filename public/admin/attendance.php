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
    "SELECT id, first_name, last_name, photo_path, department_id, ccnl_id
     FROM employees
     WHERE company_id = ? AND is_active = TRUE
     ORDER BY last_name, first_name",
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

$pageTitle = 'Timbrature';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.att-page { display: flex; flex-direction: column; gap: 16px; }
.att-toolbar {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    padding: 14px 18px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.att-toolbar h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0; font-size: 18px; color: #0b3aa4; letter-spacing: -0.01em;
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

.att-emp {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    padding: 16px 18px;
    display: grid; grid-template-columns: 220px 1fr auto; gap: 18px;
    align-items: center;
}
.att-emp-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
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

    <?php foreach ($employees as $emp):
        $eid = (int) $emp['id'];
        $list = $byEmp[$eid] ?? [];
        $initials = mb_strtoupper(mb_substr($emp['first_name'] ?? '?', 0, 1) . mb_substr($emp['last_name'] ?? '', 0, 1));
        // Calcola ore stimato
        $totalSec = 0; $openIn = null;
        foreach ($list as $p) {
            if ($p['kind'] === 'in') $openIn = strtotime($p['punch_at']);
            elseif ($p['kind'] === 'out' && $openIn !== null) { $totalSec += strtotime($p['punch_at']) - $openIn; $openIn = null; }
        }
        $h = floor($totalSec / 3600); $m = floor(($totalSec % 3600) / 60);
    ?>
    <div class="att-emp">
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
                <div class="totals"><?= count($list) ?> timbrature · <?= sprintf('%dh %02dm', $h, $m) ?></div>
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
