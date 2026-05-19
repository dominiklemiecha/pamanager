<?php
/**
 * Saldi ferie e permessi: gestione CCNL per dipendente + override + saldi manuali.
 * Triggera l'accrual mensile lazy al caricamento.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user      = Auth::getUser();
$companyId = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$message   = '';
$error     = '';

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'set_ccnl': {
                $empId = (int) ($_POST['employee_id'] ?? 0);
                $ccnlId = !empty($_POST['ccnl_id']) ? (int) $_POST['ccnl_id'] : null;
                $ferieOv = $_POST['ferie_override'] ?? '';
                $permOv  = $_POST['permessi_override'] ?? '';
                $ferieOv = $ferieOv === '' ? null : (float) $ferieOv;
                $permOv  = $permOv  === '' ? null : (float) $permOv;
                Database::update('employees', [
                    'ccnl_id'                 => $ccnlId,
                    'ferie_year_override'     => $ferieOv,
                    'permessi_year_override'  => $permOv,
                ], 'id = ? AND company_id = ?', [$empId, $companyId]);
                LeaveBalance::ensureCurrentYearAccrual($empId, $companyId);
                $message = 'CCNL e maturazione aggiornati per il dipendente.';
                break;
            }
            case 'set_balance': {
                $empId  = (int) ($_POST['employee_id'] ?? 0);
                $type   = $_POST['type'] ?? '';
                $year   = (int) ($_POST['year'] ?? date('Y'));
                $ent    = (float) ($_POST['entitled'] ?? 0);
                $carry  = (float) ($_POST['carried'] ?? 0);
                $manual = (float) ($_POST['manual_used'] ?? 0);
                $notes  = trim($_POST['notes'] ?? '') ?: null;
                LeaveBalance::save($empId, $companyId, $year, $type, $ent, $carry, $manual, $notes, (int) $user['id']);
                $message = 'Saldo aggiornato.';
                break;
            }
            case 'refresh_all':
                $n = LeaveBalance::ensureCurrentYearAccrualForCompany($companyId);
                $message = "Saldi ricalcolati per $n dipendenti.";
                break;
            default:
                $error = 'Azione non valida';
        }
    } catch (Throwable $e) {
        $error = 'Errore: ' . $e->getMessage();
    }
}

// Trigger accrual lazy on load (idempotente)
try { LeaveBalance::ensureCurrentYearAccrualForCompany($companyId); } catch (Throwable $e) { /* ignora */ }

$year = (int) date('Y');
$employees = Database::fetchAll(
    "SELECT e.id, e.first_name, e.last_name, e.photo_path, e.ccnl_id,
            e.ferie_year_override, e.permessi_year_override,
            c.name AS ccnl_name, c.ferie_days_year, c.permessi_hours_year,
            d.name AS department_name
     FROM employees e
     LEFT JOIN ccnl_templates c ON c.id = e.ccnl_id
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE e.company_id = ? AND e.is_active = TRUE
     ORDER BY e.last_name, e.first_name",
    [$companyId]
);

$ccnls = LeaveBalance::availableCcnls($companyId);

$pageTitle = 'Saldi ferie e permessi';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.lb-page { display: flex; flex-direction: column; gap: 16px; }
.lb-header-card {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    padding: 18px 22px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.lb-header-card h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0 0 4px;
    font-size: 19px; font-weight: 700; color: #0b3aa4; letter-spacing: -0.02em;
}
.lb-header-card p { margin: 0; color: #6e7191; font-size: 13px; }
.lb-refresh-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: 1px solid #e6e8f0; border-radius: 10px;
    background: white; color: #0b3aa4; cursor: pointer;
    font-family: inherit; font-size: 13px; font-weight: 600;
    transition: all .12s ease;
}
.lb-refresh-btn:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.04); }

.lb-table-wrap {
    background: white; border: 1px solid #e6e8f0; border-radius: 14px;
    overflow-x: auto;
}
.lb-table {
    width: 100%; border-collapse: collapse;
    min-width: 1100px;
}
.lb-table thead th {
    background: #fafbfd; border-bottom: 1px solid #e6e8f0;
    text-align: left; padding: 12px 14px;
    font-size: 11px; font-weight: 700; color: #6e7191;
    text-transform: uppercase; letter-spacing: 0.04em;
    white-space: nowrap;
}
.lb-table tbody td {
    padding: 14px; border-bottom: 1px solid #f1f5f9;
    font-size: 13px; vertical-align: middle;
}
.lb-table tbody tr:hover { background: #fafbfd; }
.lb-emp { display: flex; align-items: center; gap: 10px; min-width: 200px; }
.lb-emp-av {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4, #082b7b);
    color: white; display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; flex-shrink: 0; overflow: hidden;
    text-transform: uppercase;
}
.lb-emp-av img { width: 100%; height: 100%; object-fit: cover; }
.lb-emp-name { font-weight: 600; color: #1e1e2f; }
.lb-emp-dept { font-size: 11px; color: #94a3b8; margin-top: 1px; }

.lb-balance-cell { min-width: 220px; }
.lb-balance-row {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.lb-stat { display: flex; flex-direction: column; min-width: 50px; }
.lb-stat .l { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; }
.lb-stat .v { font-size: 14px; font-weight: 700; color: #1e1e2f; font-variant-numeric: tabular-nums; }
.lb-stat.is-resid .v { color: #0b3aa4; }

.lb-ccnl-form {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.lb-ccnl-form select, .lb-ccnl-form input[type=number] {
    padding: 7px 10px; border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 12.5px;
    background: white;
}
.lb-ccnl-form select { min-width: 200px; }
.lb-ccnl-form input[type=number] { width: 75px; }
.lb-ccnl-form .lb-ov-grp {
    display: inline-flex; align-items: center; gap: 4px;
    background: #f1f5f9; border-radius: 8px; padding: 4px 8px;
    font-size: 11px; color: #6e7191;
}
.lb-ccnl-form button {
    background: #0b3aa4; color: white; border: none;
    padding: 7px 14px; border-radius: 8px;
    font-family: inherit; font-size: 12px; font-weight: 600;
    cursor: pointer;
}
.lb-ccnl-form button:hover { background: #082b7b; }

.lb-edit-btn {
    padding: 6px 12px; border: 1px solid #e6e8f0; border-radius: 7px;
    background: white; cursor: pointer; font-family: inherit; font-size: 12px;
    color: #475569;
}
.lb-edit-btn:hover { border-color: #0b3aa4; color: #0b3aa4; }

/* Modal saldo */
.lb-modal {
    position: fixed; inset: 0; background: rgba(15,23,42,0.45);
    display: none; align-items: center; justify-content: center; z-index: 1000;
    padding: 16px;
}
.lb-modal.show { display: flex; }
.lb-modal-inner {
    background: white; border-radius: 16px; max-width: 460px; width: 100%;
    box-shadow: 0 24px 64px rgba(15,23,42,0.25);
    overflow: hidden;
}
.lb-modal-h { padding: 18px 22px; border-bottom: 1px solid #e6e8f0; }
.lb-modal-h h3 { margin: 0; font-family: 'Host Grotesk', sans-serif; font-size: 16px; font-weight: 700; color: #1e1e2f; }
.lb-modal-b { padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
.lb-modal-b label { font-size: 11px; font-weight: 600; color: #475569; text-transform: uppercase; margin-bottom: 4px; display: block; }
.lb-modal-b input, .lb-modal-b select, .lb-modal-b textarea {
    width: 100%; padding: 10px 12px;
    border: 1px solid #e6e8f0; border-radius: 8px;
    font-family: inherit; font-size: 14px;
}
.lb-modal-f { padding: 14px 22px; border-top: 1px solid #e6e8f0; display: flex; justify-content: flex-end; gap: 8px; }
.lb-modal-f button {
    padding: 9px 18px; border-radius: 8px; border: 1px solid transparent;
    font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
}
.lb-btn-ghost { background: white; color: #475569; border-color: #e6e8f0; }
.lb-btn-primary { background: #0b3aa4; color: white; }

@media (max-width: 720px) {
    .lb-table { min-width: 800px; }
}
</style>

<div class="lb-page">
    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div>   <?php endif; ?>

    <div class="lb-header-card">
        <div>
            <h2>Saldi ferie e permessi <?= $year ?></h2>
            <p>Configura il CCNL di ogni dipendente o imposta override individuali. Maturazione automatica mensile (1/12 dell'annuo).</p>
        </div>
        <form method="POST" style="margin:0;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="refresh_all">
            <button type="submit" class="lb-refresh-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Ricalcola saldi
            </button>
        </form>
    </div>

    <div class="lb-table-wrap">
        <table class="lb-table">
            <thead>
                <tr>
                    <th>Dipendente</th>
                    <th>CCNL / Override</th>
                    <th>Ferie (giorni)</th>
                    <th>Permessi (ore)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp):
                    $bF = LeaveBalance::getOne((int)$emp['id'], $year, 'ferie');
                    $bP = LeaveBalance::getOne((int)$emp['id'], $year, 'permesso');
                    $annualF = LeaveBalance::getAnnualForEmployee((int)$emp['id'], 'ferie');
                    $annualP = LeaveBalance::getAnnualForEmployee((int)$emp['id'], 'permesso');
                    $initials = mb_strtoupper(mb_substr(($emp['first_name'] ?? '?'), 0, 1) . mb_substr(($emp['last_name'] ?? ''), 0, 1));
                ?>
                <tr>
                    <td>
                        <div class="lb-emp">
                            <div class="lb-emp-av">
                                <?php if (!empty($emp['photo_path'])): ?>
                                    <img src="<?= e(PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/')) ?>" alt="">
                                <?php else: ?>
                                    <?= e($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="lb-emp-name"><?= e($emp['last_name'] . ' ' . $emp['first_name']) ?></div>
                                <?php if (!empty($emp['department_name'])): ?>
                                    <div class="lb-emp-dept"><?= e($emp['department_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <form method="POST" class="lb-ccnl-form">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="set_ccnl">
                            <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                            <select name="ccnl_id">
                                <option value="">— nessuno —</option>
                                <?php foreach ($ccnls as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)$emp['ccnl_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?> (<?= rtrim(rtrim(number_format($c['ferie_days_year'], 1, ',', '.'),'0'),',') ?>gg / <?= rtrim(rtrim(number_format($c['permessi_hours_year'], 1, ',', '.'),'0'),',') ?>h)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="lb-ov-grp">
                                F<input type="number" name="ferie_override" step="0.5" min="0" placeholder="<?= $annualF ?>" value="<?= $emp['ferie_year_override'] !== null ? rtrim(rtrim(number_format($emp['ferie_year_override'], 2, '.', ''), '0'), '.') : '' ?>" title="Override giorni ferie/anno">
                            </span>
                            <span class="lb-ov-grp">
                                P<input type="number" name="permessi_override" step="0.5" min="0" placeholder="<?= $annualP ?>" value="<?= $emp['permessi_year_override'] !== null ? rtrim(rtrim(number_format($emp['permessi_year_override'], 2, '.', ''), '0'), '.') : '' ?>" title="Override ore permessi/anno">
                            </span>
                            <button type="submit" title="Salva configurazione">Salva</button>
                        </form>
                    </td>
                    <td class="lb-balance-cell">
                        <div class="lb-balance-row">
                            <div class="lb-stat"><span class="l">Matur.</span><span class="v"><?= number_format($bF['entitled'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat"><span class="l">Riporto</span><span class="v"><?= number_format($bF['carried'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat"><span class="l">Usati</span><span class="v"><?= number_format($bF['used'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat is-resid"><span class="l">Residui</span><span class="v"><?= number_format($bF['residual'], 1, ',', '.') ?></span></div>
                        </div>
                    </td>
                    <td class="lb-balance-cell">
                        <div class="lb-balance-row">
                            <div class="lb-stat"><span class="l">Matur.</span><span class="v"><?= number_format($bP['entitled'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat"><span class="l">Riporto</span><span class="v"><?= number_format($bP['carried'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat"><span class="l">Usati</span><span class="v"><?= number_format($bP['used'], 1, ',', '.') ?></span></div>
                            <div class="lb-stat is-resid"><span class="l">Residui</span><span class="v"><?= number_format($bP['residual'], 1, ',', '.') ?></span></div>
                        </div>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="lb-edit-btn" onclick='lbEdit(<?= htmlspecialchars(json_encode([
                            "emp_id" => (int)$emp["id"],
                            "name"   => trim($emp["last_name"] . " " . $emp["first_name"]),
                            "ferie"  => $bF, "permesso" => $bP, "year" => $year,
                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                            Modifica saldi
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal modifica saldi -->
<div class="lb-modal" id="lbModal" onclick="if(event.target===this) lbClose()">
    <div class="lb-modal-inner">
        <div class="lb-modal-h"><h3>Modifica saldo — <span id="lbModName"></span></h3></div>
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="set_balance">
            <input type="hidden" name="employee_id" id="lbModEmpId">
            <input type="hidden" name="year" value="<?= $year ?>">
            <div class="lb-modal-b">
                <div>
                    <label>Tipo</label>
                    <select name="type" id="lbModType" onchange="lbSwitchType(this.value)">
                        <option value="ferie">Ferie (giorni)</option>
                        <option value="permesso">Permessi (ore)</option>
                    </select>
                </div>
                <div>
                    <label>Maturati (entitled)</label>
                    <input type="number" name="entitled" id="lbModEnt" step="0.5" min="0">
                    <small style="color:#94a3b8;">Quanto è stato maturato nell'anno. Aggiornato in automatico mensilmente.</small>
                </div>
                <div>
                    <label>Riporto da anno precedente</label>
                    <input type="number" name="carried" id="lbModCarry" step="0.5" min="0">
                </div>
                <div>
                    <label>Correttivo manuale usati</label>
                    <input type="number" name="manual_used" id="lbModManual" step="0.5" min="0">
                    <small style="color:#94a3b8;">Da sommare ai consumi calcolati dalle richieste approvate.</small>
                </div>
                <div>
                    <label>Note</label>
                    <textarea name="notes" id="lbModNotes" rows="2"></textarea>
                </div>
            </div>
            <div class="lb-modal-f">
                <button type="button" class="lb-btn-ghost" onclick="lbClose()">Annulla</button>
                <button type="submit" class="lb-btn-primary">Salva</button>
            </div>
        </form>
    </div>
</div>

<script>
const lbModal = document.getElementById('lbModal');
let lbData = null;
function lbEdit(d) {
    lbData = d;
    document.getElementById('lbModName').textContent = d.name;
    document.getElementById('lbModEmpId').value = d.emp_id;
    document.getElementById('lbModType').value = 'ferie';
    lbSwitchType('ferie');
    lbModal.classList.add('show');
}
function lbSwitchType(type) {
    const b = lbData[type] || {};
    document.getElementById('lbModEnt').value    = b.entitled    ?? 0;
    document.getElementById('lbModCarry').value  = b.carried     ?? 0;
    document.getElementById('lbModManual').value = b.manual_used ?? 0;
    document.getElementById('lbModNotes').value  = b.notes || '';
}
function lbClose() { lbModal.classList.remove('show'); }
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
