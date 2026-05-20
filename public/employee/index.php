<?php
/**
 * Dashboard Dipendente
 * PAManager
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$employeeDeptId = $employee['department_id'] ?? null;

$allDocs = Document::getByEmployee($employee['id']);
$docStats = ['payslip' => 0, 'cud' => 0, 'other' => 0, 'total' => count($allDocs)];
foreach ($allDocs as $d) {
    if (isset($docStats[$d['type']])) $docStats[$d['type']]++;
}

$unreadCount   = Communication::countUnread($employee['id'], $employeeDeptId);
$recentDocs    = array_slice($allDocs, 0, 4);
$communications= Communication::getActive($employee['id']);
$recentComms   = array_slice($communications, 0, 3);

// Ultima busta paga
$latestPayslip = null;
foreach ($allDocs as $d) {
    if ($d['type'] === 'payslip') { $latestPayslip = $d; break; }
}

$monthNames = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
               7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];

function emp_time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'pochi secondi fa';
    if ($diff < 3600) return floor($diff / 60) . ' min fa';
    if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
    if ($diff < 604800) return floor($diff / 86400) . ' giorni fa';
    return date('d M Y', strtotime($datetime));
}

// Saldi ferie/permessi
$__year = (int) date('Y');
$__balances = class_exists('LeaveBalance') ? LeaveBalance::getForEmployee((int) $employee['id'], $__year) : null;

// Richieste recenti
$__recentLeaves = [];
try {
    $__recentLeaves = Database::fetchAll(
        "SELECT id, leave_type, start_date, end_date, is_full_day, status, created_at
         FROM leave_requests
         WHERE employee_id = ?
         ORDER BY created_at DESC LIMIT 4",
        [(int) $employee['id']]
    );
} catch (Throwable $__e) {}

$__statusCfg = [
    'approved' => ['#11baba', '#bff3ee', 'Approvata'],
    'pending'  => ['#d97706', '#fff3df', 'In attesa'],
    'rejected' => ['#dc2626', '#fde2e5', 'Rifiutata'],
    'cancelled'=> ['#64748b', '#e2e8f0', 'Annullata'],
];
$__typeLabel = [
    'ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia',
    'permesso_104' => 'L.104', 'congedo_parentale' => 'Cong. parentale',
    'congedo_separazione' => 'Cong. separazione', 'congedo_mestruale' => 'Cong. mestruale',
    'altro' => 'Altro', 'chiusura' => 'Chiusura',
];

$__leaveCfg = [
    'ferie'    => ['label' => 'Ferie ' . $__year, 'color' => '#0b3aa4', 'icon' => 'beach'],
    'permesso' => ['label' => 'Permessi ' . $__year, 'color' => '#1e4cb0', 'icon' => 'clock'],
];
$__gaugeArc = pi() * 85;

// Pending leave count
$__pendingLeaveCount = 0;
try {
    $__pendingLeaveCount = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'",
        [(int)$employee['id']]
    );
} catch (Throwable $__e) {}

$__newDocs = Document::getUnreadCountForEmployee($employee['id'])
    + (class_exists('EmployeeDocument') ? EmployeeDocument::getUnreadCountForEmployee($employee['id']) : 0);
$__buste = 0;
foreach ($allDocs as $d) {
    if ($d['type'] === 'payslip' && (int)($d['year'] ?? 0) === $__year) $__buste++;
}

// Greeting time-based
$__hour = (int) date('H');
$__greeting = $__hour < 12 ? 'Buongiorno' : ($__hour < 18 ? 'Buon pomeriggio' : 'Buonasera');

$pageTitle = 'Home';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<style>
/* ===== Employee Home — design system ConnecteedHR ===== */
.eh-banner {
    background: white;
    border: 1px solid #e6e8f0;
    border-left: 4px solid #0b3aa4;
    border-radius: 16px;
    padding: 22px 26px;
    margin-bottom: 18px;
    display: flex; justify-content: space-between; align-items: center;
    gap: 18px; flex-wrap: wrap;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.eh-banner h2 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 22px; font-weight: 700;
    color: #0b3aa4; margin: 0 0 4px;
    letter-spacing: -0.02em;
}
.eh-banner p { margin: 0; color: #6e7191; font-size: 13px; }
.eh-banner-meta {
    display: flex; gap: 10px; align-items: center;
    flex-wrap: wrap;
}
.eh-banner-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: rgba(11,58,164,0.06);
    border: 1px solid rgba(11,58,164,0.16);
    border-radius: 999px;
    font-size: 12px; font-weight: 600;
    color: #0b3aa4;
}
.eh-banner-chip.warn { background: rgba(255,187,85,0.10); border-color: rgba(255,187,85,0.30); color: #b07023; }

/* ===== Quick actions ===== */
.eh-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
.eh-action {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    padding: 16px;
    text-decoration: none;
    display: flex; flex-direction: column; gap: 8px;
    transition: all .15s ease;
    position: relative;
    cursor: pointer;
}
.eh-action:hover {
    border-color: #0b3aa4;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(11,58,164,0.10);
    text-decoration: none;
}
.eh-action-ic {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: rgba(11,58,164,0.10);
    color: #0b3aa4;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.eh-action-ic.is-green { background: rgba(17,186,186,0.10); color: #0c8a8a; }
.eh-action-ic.is-gold  { background: rgba(255,187,85,0.14); color: #b07023; }
.eh-action-ic.is-coral { background: rgba(247,92,108,0.10); color: #cc2d39; }
.eh-action-ic svg { width: 18px; height: 18px; }
.eh-action-title {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 14px; font-weight: 700; color: #1e1e2f;
    letter-spacing: -0.01em;
}
.eh-action-sub { font-size: 12px; color: #6e7191; line-height: 1.4; }
.eh-action-badge {
    position: absolute; top: 12px; right: 12px;
    background: #f75c6c; color: white;
    font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 999px;
}

/* ===== Gauges saldo ferie/permessi ===== */
.eh-balance-card {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 16px;
    padding: 22px 24px;
    margin-bottom: 18px;
}
.eh-balance-h {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px;
}
.eh-balance-h h3 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 16px; font-weight: 700;
    margin: 0; color: #0b3aa4;
    letter-spacing: -0.01em;
}
.eh-balance-h a {
    font-size: 13px; color: #0b3aa4;
    text-decoration: none; font-weight: 600;
}
.eh-balance-h a:hover { text-decoration: underline; }

.eh-gauges {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
}
.eh-gauge-cell {
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    padding: 16px 18px 14px;
    text-align: center;
    background: linear-gradient(180deg, #fafbff, white);
}
.eh-gauge-cell h4 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 13px; font-weight: 600;
    margin: 0 0 10px;
    color: #1e1e2f;
}
.eh-gauge {
    position: relative;
    width: 100%; max-width: 220px;
    margin: 0 auto;
    aspect-ratio: 200 / 110;
}
.eh-gauge svg { width: 100%; height: 100%; display: block; }
.eh-gauge .g-track { fill: none; stroke: #e0e7ff; stroke-width: 18; stroke-linecap: round; }
.eh-gauge .g-arc { fill: none; stroke-width: 18; stroke-linecap: round; transition: stroke-dasharray .6s ease; }
.eh-gauge .g-center {
    position: absolute; left: 0; right: 0; bottom: 4px;
    text-align: center;
}
.eh-gauge .g-big {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 36px; font-weight: 700;
    letter-spacing: -0.04em;
    color: #1e1e2f;
    line-height: 1;
}
.eh-gauge .g-lbl {
    font-size: 10px; color: #6e7191;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-top: 4px; font-weight: 600;
}
.eh-gauge-ends {
    display: flex; justify-content: space-between;
    font-size: 10px; color: #94a3b8;
    margin: -6px 18px 0;
    font-weight: 500;
}
.eh-gauge-stats {
    display: flex; justify-content: space-around;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #e6e8f0;
}
.eh-gauge-stats .ss { text-align: center; }
.eh-gauge-stats .l {
    font-size: 9px; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;
}
.eh-gauge-stats .v {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 16px; font-weight: 700;
    margin-top: 3px;
    letter-spacing: -0.02em;
    color: #1e1e2f;
}
.eh-gauge-empty {
    padding: 30px 12px; color: #94a3b8; font-size: 13px;
}

/* ===== Split layout ===== */
.eh-split {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 16px;
}

/* Card generica */
.eh-card {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    padding: 20px 22px;
}
.eh-card-h {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px;
}
.eh-card-h h3 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 15px; font-weight: 700;
    margin: 0; color: #1e1e2f;
}
.eh-card-h a {
    font-size: 12px; color: #0b3aa4;
    text-decoration: none; font-weight: 600;
}
.eh-card-h a:hover { text-decoration: underline; }

/* Documents list */
.eh-doc {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
}
.eh-doc:last-child { border-bottom: none; }
.eh-doc-ic {
    width: 36px; height: 36px; border-radius: 9px;
    background: rgba(11,58,164,0.10); color: #0b3aa4;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.eh-doc-ic.cud { background: rgba(255,187,85,0.14); color: #b07023; }
.eh-doc-ic.other { background: rgba(17,186,186,0.10); color: #0c8a8a; }
.eh-doc-info { flex: 1; min-width: 0; }
.eh-doc-info .t {
    font-size: 13px; font-weight: 600; color: #1e1e2f; margin: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.eh-doc-info .s { font-size: 11px; color: #94a3b8; margin: 2px 0 0; }
.eh-doc-new-dot {
    display: inline-block; width: 6px; height: 6px;
    background: #f75c6c; border-radius: 50%; margin-left: 6px;
    vertical-align: middle;
}
.eh-doc-dl {
    width: 32px; height: 32px; border-radius: 8px;
    background: white; border: 1px solid #e6e8f0;
    color: #475569;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all .12s ease;
}
.eh-doc-dl:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
.eh-doc-dl svg { width: 14px; height: 14px; }

/* Recent requests */
.eh-req {
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: 12px;
}
.eh-req:last-child { border-bottom: none; }
.eh-req-info { flex: 1; min-width: 0; }
.eh-req-info .t { font-size: 13px; font-weight: 600; color: #1e1e2f; }
.eh-req-info .s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.eh-req-pill {
    padding: 3px 10px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    white-space: nowrap;
}

/* Communications */
.eh-comm {
    display: block;
    padding: 12px;
    border: 1px solid #e6e8f0;
    border-radius: 10px;
    text-decoration: none;
    margin-bottom: 8px;
    transition: all .12s ease;
}
.eh-comm:last-child { margin-bottom: 0; }
.eh-comm:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.02); text-decoration: none; }
.eh-comm h4 {
    margin: 0 0 4px;
    font-size: 13px; font-weight: 700; color: #1e1e2f;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.eh-comm .meta { font-size: 11px; color: #94a3b8; }
.eh-comm.urgent { border-left: 3px solid #f75c6c; }

.eh-empty {
    text-align: center; padding: 24px 12px;
    color: #94a3b8; font-size: 13px;
}

@media (max-width: 1000px) {
    .eh-actions { grid-template-columns: 1fr 1fr; }
    .eh-split { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .eh-banner { padding: 18px 20px; }
    .eh-banner h2 { font-size: 19px; }
    .eh-actions { grid-template-columns: 1fr 1fr; gap: 10px; }
    .eh-action { padding: 14px; }
    .eh-action-ic { width: 36px; height: 36px; }
    .eh-gauges { grid-template-columns: 1fr; }
    .eh-card { padding: 16px; }
}
</style>

<?php
try {
    $__sickLate = LeaveRequest::sickPendingDocs(24, (int) $employee['id']);
} catch (Throwable $e) { $__sickLate = []; }
?>
<?php if (!empty($__sickLate)): ?>
<a href="<?= PUBLIC_URL ?>/employee/leave-requests.php" class="eh-sick-alert">
    <div class="eh-sick-alert-ic">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div class="eh-sick-alert-body">
        <strong>Hai <?= count($__sickLate) ?> richiest<?= count($__sickLate) === 1 ? 'a' : 'e' ?> di malattia senza documenti</strong>
        <div>Carica numero protocollo INPS e certificato medico — vai a "Ferie e permessi".</div>
    </div>
</a>
<style>
.eh-sick-alert {
    display: flex; align-items: center; gap: 12px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-left: 4px solid #dc2626;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 14px;
    text-decoration: none;
    transition: filter .12s ease;
}
.eh-sick-alert:hover { filter: brightness(0.98); text-decoration: none; }
.eh-sick-alert-ic {
    width: 36px; height: 36px; border-radius: 9px;
    background: rgba(220,38,38,0.10); color: #b91c1c;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.eh-sick-alert-body strong { color: #991b1b; font-size: 13.5px; display: block; }
.eh-sick-alert-body div { color: #7f1d1d; font-size: 12px; margin-top: 2px; line-height: 1.4; }
</style>
<?php endif; ?>

<!-- ======== Welcome banner ======== -->
<div class="eh-banner">
    <div>
        <h2><?= htmlspecialchars($__greeting) ?>, <?= htmlspecialchars($employee['first_name']) ?> 👋</h2>
        <p><?= htmlspecialchars(ucfirst(getItalianDate())) ?></p>
    </div>
    <div class="eh-banner-meta">
        <?php if ($__pendingLeaveCount > 0): ?>
            <span class="eh-banner-chip warn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?= $__pendingLeaveCount ?> richiest<?= $__pendingLeaveCount === 1 ? 'a' : 'e' ?> in attesa
            </span>
        <?php endif; ?>
        <?php if ($unreadCount > 0): ?>
            <span class="eh-banner-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <?= $unreadCount ?> da leggere
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- ======== Timbra entrata/uscita ======== -->
<?php
$__lastPunch = class_exists('AttendancePunch') ? AttendancePunch::lastPunch((int)$employee['id']) : null;
$__todayLast = null;
if ($__lastPunch && date('Y-m-d', strtotime($__lastPunch['punch_at'])) === date('Y-m-d')) {
    $__todayLast = $__lastPunch;
}
$__nextKind = ($__todayLast && $__todayLast['kind'] === 'in') ? 'out' : 'in';
$__nextLabel = $__nextKind === 'in' ? 'Timbra entrata' : 'Timbra uscita';
$__sub = $__todayLast
    ? 'Ultima: ' . ($__todayLast['kind'] === 'in' ? 'entrata' : 'uscita') . ' alle ' . date('H:i', strtotime($__todayLast['punch_at']))
    : 'Nessuna timbratura oggi';

// URL tenant-specifica
$__compRow = Database::fetchOne("SELECT slug FROM companies WHERE id = ?", [(int)$employee['company_id']]);
$__cSlug = $__compRow['slug'] ?? '';
$__punchUrl = PUBLIC_URL . '/punch.php' . ($__cSlug ? '?c=' . urlencode($__cSlug) : '');
?>
<button type="button" class="eh-punch-btn <?= $__nextKind ?>" onclick="ehPunchOpen()">
    <span class="eh-punch-ic">
        <?php if ($__nextKind === 'in'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <?php endif; ?>
    </span>
    <span class="eh-punch-body">
        <span class="eh-punch-title"><?= $__nextLabel ?></span>
        <span class="eh-punch-sub"><?= htmlspecialchars($__sub) ?></span>
    </span>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="color:rgba(255,255,255,0.65);"><polyline points="9 18 15 12 9 6"/></svg>
</button>

<!-- Bottom-sheet timbratura -->
<div class="eh-sheet-backdrop" id="ehSheetBackdrop" onclick="ehPunchClose()"></div>
<div class="eh-sheet" id="ehSheet" role="dialog" aria-modal="true">
    <button type="button" class="eh-sheet-back" onclick="ehPunchClose()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="15 18 9 12 15 6"/></svg>
        Torna indietro
    </button>
    <div class="eh-sheet-body">
        <div class="eh-nfc-illu">
            <!-- Telefono che tocca tag NFC con onde -->
            <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                <!-- Onde NFC -->
                <path d="M120 70 Q 140 100 120 130" stroke="#0b3aa4" stroke-width="3" fill="none" stroke-linecap="round" opacity="0.4">
                    <animate attributeName="opacity" values="0.2;0.7;0.2" dur="1.5s" repeatCount="indefinite"/>
                </path>
                <path d="M135 60 Q 165 100 135 140" stroke="#0b3aa4" stroke-width="3" fill="none" stroke-linecap="round" opacity="0.3">
                    <animate attributeName="opacity" values="0.1;0.5;0.1" dur="1.5s" begin="0.3s" repeatCount="indefinite"/>
                </path>
                <path d="M150 50 Q 190 100 150 150" stroke="#0b3aa4" stroke-width="3" fill="none" stroke-linecap="round" opacity="0.2">
                    <animate attributeName="opacity" values="0;0.35;0" dur="1.5s" begin="0.6s" repeatCount="indefinite"/>
                </path>
                <!-- Telefono -->
                <rect x="35" y="40" width="80" height="130" rx="14" fill="white" stroke="#0b3aa4" stroke-width="3"/>
                <rect x="42" y="50" width="66" height="100" rx="4" fill="#eef2ff"/>
                <circle cx="75" cy="160" r="4" fill="#0b3aa4"/>
                <!-- Logo dentro schermo -->
                <text x="75" y="105" text-anchor="middle" font-family="Inter, sans-serif" font-size="22" font-weight="700" fill="#0b3aa4">CHR</text>
            </svg>
        </div>
        <h3 class="eh-sheet-title">Avvicina il telefono alla carta NFC</h3>
        <p class="eh-sheet-text">Tieni il telefono vicino al lato della carta NTAG215. La timbratura parte in automatico al contatto.</p>
    </div>
</div>

<style>
.eh-punch-btn {
    display: flex; align-items: center; gap: 14px;
    width: 100%;
    padding: 16px 20px;
    background: #0b3aa4; color: white;
    border: none; border-radius: 14px;
    cursor: pointer;
    box-shadow: 0 6px 16px rgba(11,58,164,0.25);
    margin-bottom: 14px;
    text-align: left;
    transition: transform .08s ease, box-shadow .12s ease, filter .12s ease;
    font-family: inherit;
}
.eh-punch-btn.out { background: #d97706; box-shadow: 0 6px 16px rgba(217,119,6,0.25); }
.eh-punch-btn:hover { filter: brightness(1.05); }
.eh-punch-btn:active { transform: scale(0.99); }
.eh-punch-ic {
    width: 44px; height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,0.18);
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.eh-punch-ic svg { width: 22px; height: 22px; }
.eh-punch-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.eh-punch-title {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 17px; font-weight: 700;
    letter-spacing: -0.01em;
}
.eh-punch-sub { font-size: 12px; opacity: 0.85; }

/* Bottom sheet */
.eh-sheet-backdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.55);
    z-index: 2090;
    opacity: 0; pointer-events: none;
    transition: opacity .25s ease;
}
.eh-sheet-backdrop.show { opacity: 1; pointer-events: auto; }
.eh-sheet {
    position: fixed; left: 0; right: 0; bottom: 0;
    height: 60vh; max-height: 600px;
    background: white;
    border-radius: 24px 24px 0 0;
    box-shadow: 0 -24px 64px rgba(15,23,42,0.20);
    z-index: 2100;
    transform: translateY(100%);
    transition: transform .3s cubic-bezier(0.32, 0.72, 0, 1);
    display: flex; flex-direction: column;
}
.eh-sheet.show { transform: translateY(0); }
.eh-sheet::before {
    content: ''; position: absolute; top: 8px; left: 50%;
    transform: translateX(-50%);
    width: 40px; height: 4px;
    background: #cbd5e0; border-radius: 999px;
}
.eh-sheet-back {
    position: absolute; top: 14px; left: 14px;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 12px;
    border: none; background: transparent;
    color: #0b3aa4; font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer;
    border-radius: 8px;
}
.eh-sheet-back:hover { background: rgba(11,58,164,0.08); }
.eh-sheet-body {
    padding: 56px 28px 32px;
    text-align: center;
    overflow-y: auto;
    flex: 1;
}
.eh-nfc-illu {
    width: 180px; height: 180px;
    margin: 0 auto 14px;
}
.eh-nfc-illu svg { width: 100%; height: 100%; }
.eh-sheet-title {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 19px; font-weight: 700;
    color: #0b3aa4;
    margin-bottom: 8px;
    letter-spacing: -0.02em;
}
.eh-sheet-text {
    color: #6e7191; font-size: 13.5px;
    line-height: 1.5;
    margin-bottom: 22px;
    max-width: 340px;
    margin-left: auto; margin-right: auto;
}
.eh-sheet-cta {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 24px;
    background: #0b3aa4; color: white;
    border-radius: 12px;
    font-size: 14px; font-weight: 600;
    text-decoration: none;
    transition: background .12s ease;
}
.eh-sheet-cta:hover { background: #082b7b; color: white; text-decoration: none; }
.eh-sheet-hint {
    margin-top: 18px;
    font-size: 11.5px; color: #94a3b8;
    line-height: 1.5;
}
.eh-sheet-hint code {
    background: #f1f5f9; padding: 2px 6px; border-radius: 4px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    word-break: break-all;
}

@media (max-width: 480px) {
    .eh-sheet { height: 70vh; }
    .eh-nfc-illu { width: 150px; height: 150px; }
}
</style>

<script>
function ehPunchOpen() {
    document.getElementById('ehSheet').classList.add('show');
    document.getElementById('ehSheetBackdrop').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function ehPunchClose() {
    document.getElementById('ehSheet').classList.remove('show');
    document.getElementById('ehSheetBackdrop').classList.remove('show');
    document.body.style.overflow = '';
}
</script>

<!-- ======== Quick actions ======== -->
<div class="eh-actions">
    <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new&type=ferie" class="eh-action">
        <div class="eh-action-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v4M10 16h4"/></svg>
        </div>
        <div class="eh-action-title">Richiedi ferie</div>
        <div class="eh-action-sub">Crea una nuova richiesta</div>
    </a>
    <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new&type=permesso" class="eh-action">
        <div class="eh-action-ic is-gold">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="eh-action-title">Richiedi permesso</div>
        <div class="eh-action-sub">Ore o intera giornata</div>
    </a>
    <?php if ($latestPayslip): ?>
        <a href="<?= PUBLIC_URL ?>/employee/documents.php?download=<?= (int)$latestPayslip['id'] ?>" class="eh-action">
            <div class="eh-action-ic is-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </div>
            <div class="eh-action-title">Ultima busta paga</div>
            <div class="eh-action-sub"><?= htmlspecialchars($monthNames[(int)$latestPayslip['month']] . ' ' . $latestPayslip['year']) ?></div>
        </a>
    <?php else: ?>
        <a href="<?= PUBLIC_URL ?>/employee/documents.php" class="eh-action">
            <div class="eh-action-ic is-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="eh-action-title">Documenti</div>
            <div class="eh-action-sub">Buste paga e CU</div>
        </a>
    <?php endif; ?>
    <a href="<?= PUBLIC_URL ?>/employee/chat.php?with_admin=1" class="eh-action">
        <div class="eh-action-ic is-coral">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="eh-action-title">Chat HR</div>
        <div class="eh-action-sub">Parla con amministrazione</div>
    </a>
</div>

<!-- ======== Saldo ferie/permessi (gauge) ======== -->
<div class="eh-balance-card">
    <div class="eh-balance-h">
        <h3>Il tuo saldo <?= $__year ?></h3>
        <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php">Vedi richieste →</a>
    </div>
    <div class="eh-gauges">
        <?php foreach (['ferie','permesso'] as $type):
            $b   = $__balances[$type] ?? null;
            $cfg = $__leaveCfg[$type];
            $total = $b ? (float)$b['total'] : 0;
            $used  = $b ? (float)$b['used'] : 0;
            $resid = $b ? (float)$b['residual'] : 0;
            $pctUsed = $total > 0 ? min(100, ($used / $total) * 100) : 0;
            $dashUsed = ($pctUsed / 100) * $__gaugeArc;
            $unit = $b ? ($b['unit'] ?? 'gg') : 'gg';
        ?>
            <div class="eh-gauge-cell">
                <h4><?= htmlspecialchars($cfg['label']) ?></h4>
                <?php if ($total > 0): ?>
                    <div class="eh-gauge">
                        <svg viewBox="0 0 200 110" preserveAspectRatio="xMidYMax meet">
                            <path class="g-track" d="M 15 100 A 85 85 0 0 1 185 100"/>
                            <path class="g-arc" d="M 15 100 A 85 85 0 0 1 185 100"
                                  stroke-dasharray="<?= number_format($dashUsed, 2, '.', '') ?> <?= number_format($__gaugeArc, 2, '.', '') ?>"
                                  style="stroke: <?= $cfg['color'] ?>;"/>
                        </svg>
                        <div class="g-center">
                            <div class="g-big"><?= rtrim(rtrim(number_format($resid, 1, ',', '.'), '0'), ',') ?></div>
                            <div class="g-lbl"><?= htmlspecialchars($unit) ?> residui</div>
                        </div>
                    </div>
                    <div class="eh-gauge-stats">
                        <div class="ss"><div class="l">Utilizzati</div><div class="v"><?= rtrim(rtrim(number_format($used, 1, ',', '.'), '0'), ',') ?></div></div>
                        <div class="ss"><div class="l">Totale</div><div class="v"><?= rtrim(rtrim(number_format($total, 1, ',', '.'), '0'), ',') ?></div></div>
                    </div>
                <?php else: ?>
                    <div class="eh-gauge-empty">Saldo non configurato</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ======== Heatmap presenze settimanale ======== -->
<?php
$heatmapDepartmentId    = !empty($employee['department_id']) ? (int) $employee['department_id'] : null;
$heatmapMyDepartmentId  = $heatmapDepartmentId;
$heatmapBaseUrl         = PUBLIC_URL . '/employee/';
$heatmapShowScopeToggle = $heatmapDepartmentId !== null; // mostra "Mio reparto / Tutti" se ha un reparto
$heatmapDefaultScope    = 'all';
include dirname(__DIR__) . '/includes/widget-availability-heatmap.php';
?>

<!-- ======== Split: documenti + richieste | comunicazioni ======== -->
<div class="eh-split">
    <div style="display: grid; gap: 16px;">
        <div class="eh-card">
            <div class="eh-card-h">
                <h3>Documenti recenti</h3>
                <a href="<?= PUBLIC_URL ?>/employee/documents.php">Vedi tutti →</a>
            </div>
            <?php if (empty($recentDocs)): ?>
                <div class="eh-empty">Nessun documento disponibile.</div>
            <?php else: foreach ($recentDocs as $d):
                $tCls = $d['type'] === 'cud' ? 'cud' : ($d['type'] === 'other' ? 'other' : '');
                $tLbl = ['payslip' => 'Busta paga', 'cud' => 'CU', 'other' => 'Documento'][$d['type']] ?? 'Documento';
                $period = isset($monthNames[(int)$d['month']]) ? $monthNames[(int)$d['month']] . ' ' . $d['year'] : '';
                $isNew = !empty($d['is_unread_for_employee']) || (!empty($d['created_at']) && (time() - strtotime($d['created_at'])) < 86400 * 3);
            ?>
                <div class="eh-doc">
                    <div class="eh-doc-ic <?= $tCls ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="eh-doc-info">
                        <p class="t"><?= htmlspecialchars($tLbl) ?><?php if ($period): ?> · <?= htmlspecialchars($period) ?><?php endif; ?><?php if ($isNew): ?> <span class="eh-doc-new-dot" title="Nuovo"></span><?php endif; ?></p>
                        <p class="s">Caricato <?= emp_time_ago($d['created_at']) ?></p>
                    </div>
                    <a class="eh-doc-dl" href="<?= PUBLIC_URL ?>/employee/documents.php?download=<?= (int)$d['id'] ?>" title="Scarica">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </a>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="eh-card">
            <div class="eh-card-h">
                <h3>Le tue richieste</h3>
                <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new">+ Nuova</a>
            </div>
            <?php if (empty($__recentLeaves)): ?>
                <div class="eh-empty">Nessuna richiesta inviata.</div>
            <?php else: foreach ($__recentLeaves as $lr):
                $sc = $__statusCfg[$lr['status']] ?? ['#64748b','#e2e8f0',$lr['status']];
                $rs = date('d M', strtotime($lr['start_date']));
                $re = date('d M', strtotime($lr['end_date']));
                $period = $rs === $re ? $rs : "$rs – $re";
            ?>
                <div class="eh-req">
                    <div class="eh-req-info">
                        <div class="t"><?= htmlspecialchars($__typeLabel[$lr['leave_type']] ?? $lr['leave_type']) ?></div>
                        <div class="s"><?= htmlspecialchars($period) ?> · creata <?= emp_time_ago($lr['created_at']) ?></div>
                    </div>
                    <span class="eh-req-pill" style="background: <?= $sc[1] ?>; color: <?= $sc[0] ?>;"><?= $sc[2] ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="eh-card">
        <div class="eh-card-h">
            <h3>Comunicazioni</h3>
            <?php if ($unreadCount > 0): ?>
                <span class="eh-req-pill" style="background:#fde2e5;color:#cc2d39;">+<?= $unreadCount ?></span>
            <?php endif; ?>
        </div>
        <?php if (empty($recentComms)): ?>
            <div class="eh-empty">Nessuna comunicazione attiva.</div>
        <?php else: foreach ($recentComms as $c):
            $urgent = !empty($c['priority']) && ($c['priority'] === 'urgent' || $c['priority'] === 'high');
        ?>
            <a href="<?= PUBLIC_URL ?>/employee/communications.php?id=<?= (int)$c['id'] ?>" class="eh-comm <?= $urgent ? 'urgent' : '' ?>">
                <h4><?php if ($urgent): ?>⚠️ <?php endif; ?><?= htmlspecialchars($c['title']) ?></h4>
                <span class="meta"><?= emp_time_ago($c['created_at'] ?? $c['publish_date']) ?></span>
            </a>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
