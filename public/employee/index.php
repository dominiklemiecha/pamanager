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

$unreadCount = Communication::countUnread($employee['id'], $employeeDeptId);
$recentDocuments = array_slice($allDocs, 0, 4);
$communications  = Communication::getActive($employee['id']);
$recentComms     = array_slice($communications, 0, 4);

// Ultima busta paga
$latestPayslip = null;
foreach ($allDocs as $d) {
    if ($d['type'] === 'payslip') { $latestPayslip = $d; break; }
}

$monthNames = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',
               7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];

function timeAgoEmp(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'pochi secondi fa';
    if ($diff < 3600) return floor($diff / 60) . ' min fa';
    if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
    if ($diff < 604800) return floor($diff / 86400) . ' giorni fa';
    return date('d M Y', strtotime($datetime));
}

$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<?php include dirname(__DIR__) . '/includes/widget-birthday-banner.php'; ?>

<?php
$__empInitials = strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1));
$__heroPhoto = '';
try {
    $__pr = Database::fetchOne("SELECT photo_path FROM employees WHERE id = ?", [(int)$employee['id']]);
    if (!empty($__pr['photo_path'])) {
        $__heroPhoto = PUBLIC_URL . '/' . ltrim($__pr['photo_path'], '/');
    }
} catch (Throwable $__e) {}
$__balances = class_exists('LeaveBalance') ? LeaveBalance::getForEmployee((int) $employee['id'], (int) date('Y')) : null;
$__ferieResidue = $__balances ? (int) round($__balances['ferie']['residual']) : 0;
$__year = (int) date('Y');
$__buste = 0;
foreach ($allDocs as $d) {
    if ($d['type'] === 'payslip' && (int)($d['year'] ?? 0) === $__year) $__buste++;
}
$__newDocs = Document::getUnreadCountForEmployee($employee['id'])
    + (class_exists('EmployeeDocument') ? EmployeeDocument::getUnreadCountForEmployee($employee['id']) : 0);
// Richieste ferie recenti
$__recentLeaves = [];
try {
    $__recentLeaves = Database::fetchAll(
        "SELECT id, leave_type, start_date, end_date, is_full_day, status
         FROM leave_requests
         WHERE employee_id = ?
         ORDER BY created_at DESC LIMIT 5",
        [(int) $employee['id']]
    );
} catch (Throwable $__e) {}
$__statusBadge = [
    'approved' => ['badge-success', 'Approvata'],
    'pending'  => ['badge-warning', 'In attesa'],
    'rejected' => ['badge-danger', 'Rifiutata'],
    'cancelled'=> ['badge-secondary', 'Annullata'],
];
$__typeLabel = [
    'ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia',
    'permesso_104' => 'L.104', 'congedo_parentale' => 'Cong. parentale',
    'congedo_separazione' => 'Cong. separaz.', 'congedo_mestruale' => 'Cong. mestruale',
    'altro' => 'Altro', 'chiusura' => 'Chiusura',
];
?>

<!-- Hero employee -->
<div class="hero-emp">
    <div class="hero-grid">
        <div>
            <p class="greeting"><?= htmlspecialchars(getItalianDate()) ?></p>
            <h1>Ciao <?= htmlspecialchars($employee['first_name']) ?> 👋</h1>
            <div class="quick-stats">
                <div class="quick-stat">
                    <div class="num"><?= $__ferieResidue ?></div>
                    <span class="lbl">Giorni ferie residui</span>
                </div>
                <div class="quick-stat">
                    <div class="num"><?= $__buste ?></div>
                    <span class="lbl">Buste paga <?= $__year ?></span>
                </div>
                <div class="quick-stat">
                    <div class="num"><?= $__newDocs ?></div>
                    <span class="lbl">Documenti nuovi</span>
                </div>
            </div>
        </div>
        <div class="hero-avatar<?= $__heroPhoto ? ' has-photo' : '' ?>">
            <?php if ($__heroPhoto): ?>
                <img src="<?= htmlspecialchars($__heroPhoto) ?>" alt="<?= htmlspecialchars($employee['first_name']) ?>" loading="lazy">
            <?php else: ?>
                <?= htmlspecialchars($__empInitials) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick actions tile grid -->
<div class="actions-row">
    <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new" class="action-tile">
        <div class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg></div>
        <h4>Richiedi ferie</h4>
        <p>Crea una nuova richiesta</p>
    </a>
    <a href="<?= PUBLIC_URL ?>/employee/documents.php" class="action-tile">
        <div class="ic" style="background:var(--success-50, #ecfdf5); color:var(--success-600, #059669)"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></div>
        <h4>Ultima busta paga</h4>
        <p><?php if ($latestPayslip): echo htmlspecialchars($monthNames[(int)$latestPayslip['month']] . ' ' . $latestPayslip['year']); else: echo 'Nessuna disponibile'; endif; ?></p>
    </a>
    <a href="<?= PUBLIC_URL ?>/employee/chat.php" class="action-tile">
        <div class="ic" style="background:var(--info-50, #eff6ff); color:var(--info-600, #2563eb)"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></div>
        <h4>Chat HR</h4>
        <p>Parla con amministrazione</p>
    </a>
    <a href="<?= PUBLIC_URL ?>/employee/profile.php" class="action-tile">
        <div class="ic" style="background:var(--warning-50, #fef3c7); color:var(--warning-600, #d97706)"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>
        <h4>Il mio profilo</h4>
        <p>Dati e impostazioni</p>
    </a>
</div>

<!-- Split: docs + requests | balance + comms -->
<div class="split">
    <div>
        <div class="card" style="margin-bottom: var(--sp-4)">
            <div class="card-h">
                <h3>I tuoi documenti</h3>
                <a href="<?= PUBLIC_URL ?>/employee/documents.php" style="font-size:var(--text-sm); color:var(--primary-600)">Vedi tutto →</a>
            </div>
            <?php if (empty($recentDocuments)): ?>
                <div class="card-b"><div class="empty"><p>Nessun documento disponibile.</p></div></div>
            <?php else: foreach ($recentDocuments as $d):
                $typeClass = $d['type'] === 'cud' ? 'cud' : ($d['type'] === 'other' ? 'other' : '');
                $typeLabel = ['payslip' => 'Busta paga', 'cud' => 'CU', 'other' => 'Documento'][$d['type']] ?? 'Documento';
                $period = isset($monthNames[(int)$d['month']]) ? $monthNames[(int)$d['month']] . ' ' . $d['year'] : '';
                $isNew = !empty($d['is_unread_for_employee']) || (!empty($d['created_at']) && (time() - strtotime($d['created_at'])) < 86400 * 3);
            ?>
                <div class="doc-row">
                    <div class="ic <?= $typeClass ?>"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg></div>
                    <div class="info">
                        <p class="t"><?= htmlspecialchars($typeLabel) ?><?php if ($period): ?> · <?= htmlspecialchars($period) ?><?php endif; ?> <?php if ($isNew): ?><span class="new-dot"></span><?php endif; ?></p>
                        <p class="s">Caricato <?= timeAgoEmp($d['created_at']) ?></p>
                    </div>
                    <a class="dl" href="<?= PUBLIC_URL ?>/employee/documents.php?download=<?= (int) $d['id'] ?>" title="Scarica">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    </a>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="card-h">
                <h3>Le tue richieste recenti</h3>
                <a href="<?= PUBLIC_URL ?>/employee/leave-requests.php?action=new" style="font-size:var(--text-sm); color:var(--primary-600)">Nuova →</a>
            </div>
            <?php if (empty($__recentLeaves)): ?>
                <div class="card-b"><div class="empty"><p>Nessuna richiesta inviata.</p></div></div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="tbl" style="border-radius:0">
                        <thead><tr><th>Tipo</th><th>Periodo</th><th>Stato</th></tr></thead>
                        <tbody>
                            <?php foreach ($__recentLeaves as $lr):
                                $bs = $__statusBadge[$lr['status']] ?? ['badge-secondary', $lr['status']];
                                $rs = date('d M', strtotime($lr['start_date']));
                                $re = date('d M', strtotime($lr['end_date']));
                                $period = $rs === $re ? $rs : "$rs - $re";
                            ?>
                                <tr>
                                    <td style="font-weight:600;"><?= htmlspecialchars($__typeLabel[$lr['leave_type']] ?? $lr['leave_type']) ?></td>
                                    <td><?= htmlspecialchars($period) ?></td>
                                    <td><span class="badge <?= $bs[0] ?>"><?= $bs[1] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php
        $widgetEmployeeId = (int) $employee['id'];
        $widgetYear = $__year;
        include dirname(__DIR__) . '/includes/widget-leave-balance.php';
        ?>

        <div class="card">
            <div class="card-h">
                <h3>Comunicazioni</h3>
                <?php if ($unreadCount > 0): ?><span class="badge badge-primary"><?= $unreadCount ?></span><?php endif; ?>
            </div>
            <div class="card-b">
                <?php if (empty($recentComms)): ?>
                    <div class="empty"><p>Nessuna comunicazione.</p></div>
                <?php else: foreach ($recentComms as $c):
                    $urgent = !empty($c['priority']) && $c['priority'] === 'urgent';
                ?>
                    <a href="<?= PUBLIC_URL ?>/employee/communications.php" class="comm-card <?= $urgent ? 'urgent' : '' ?>" style="display:block; text-decoration:none;">
                        <h4><?php if ($urgent): ?>⚠️ <?php endif; ?><?= htmlspecialchars($c['title']) ?></h4>
                        <p><?= timeAgoEmp($c['created_at'] ?? $c['publish_date']) ?></p>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
