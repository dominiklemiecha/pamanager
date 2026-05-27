<?php
/**
 * Widget Heatmap Presenze Settimanale
 *
 * Variabili attese in input:
 *   $heatmapDepartmentId  int|null  Se valorizzato, filtra solo dipendenti di quel reparto
 *   $heatmapBaseUrl       string    URL pagina corrente (per nav prev/next)
 *   $heatmapShowScopeToggle bool    Mostra toggle "Tutti / Mio reparto" (admin_reparto)
 *   $heatmapMyDepartmentId int|null Reparto dell'utente loggato (per il toggle)
 *
 * Querystring riconosciute: ?w=YYYY-MM-DD (lunedi della settimana), ?scope=mine|all
 */

$heatmapDepartmentId   = $heatmapDepartmentId   ?? null;
$heatmapBaseUrl        = $heatmapBaseUrl        ?? ('?');
$heatmapShowScopeToggle = $heatmapShowScopeToggle ?? false;
$heatmapMyDepartmentId = $heatmapMyDepartmentId ?? null;
$heatmapDefaultScope   = $heatmapDefaultScope   ?? 'mine';

// Risolvi settimana (lunedi)
$weekParam = $_GET['w'] ?? '';
try {
    $ref = $weekParam ? new DateTime($weekParam) : new DateTime('today');
} catch (Throwable $e) {
    $ref = new DateTime('today');
}
$dow = (int) $ref->format('N'); // 1 = lun
$weekStart = (clone $ref)->modify('-' . ($dow - 1) . ' days');
$weekStart->setTime(0, 0, 0);
$weekEnd = (clone $weekStart)->modify('+6 days');

$prevWeek = (clone $weekStart)->modify('-7 days')->format('Y-m-d');
$nextWeek = (clone $weekStart)->modify('+7 days')->format('Y-m-d');
$today    = (new DateTime('today'))->format('Y-m-d');

// Giorni lavorativi aziendali (config admin)
$__hmCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$__hmWorkingDays = ['mon','tue','wed','thu','fri']; // fallback
if (class_exists('LeaveBalance')) {
    try {
        $__hmDefaults = LeaveBalance::companyDefaults($__hmCid);
        if (!empty($__hmDefaults['days']) && is_array($__hmDefaults['days'])) {
            $__hmWorkingDays = $__hmDefaults['days'];
        }
    } catch (Throwable $e) {}
}
$__hmKeyMap = [1=>'mon',2=>'tue',3=>'wed',4=>'thu',5=>'fri',6=>'sat',7=>'sun'];

// Range giorni (tutti i 7 giorni; quelli non lavorativi marcati visivamente)
$days = [];
$dayLabelsMap = [1=>'Lun',2=>'Mar',3=>'Mer',4=>'Gio',5=>'Ven',6=>'Sab',7=>'Dom'];
$dayLabels = [];
$dayIsWorking = [];
$dayHolidayName = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $weekStart)->modify('+' . $i . ' days');
    $dowNum = (int) $d->format('N');
    $key = $__hmKeyMap[$dowNum] ?? null;
    $ymd = $d->format('Y-m-d');
    $days[] = $ymd;
    $dayLabels[] = $dayLabelsMap[$dowNum];
    $holiday = class_exists('ItalianHolidays') ? ItalianHolidays::nameFor($ymd) : null;
    $dayHolidayName[] = $holiday;
    // Festivita prevale sui giorni lavorativi configurati
    $dayIsWorking[] = ($holiday === null) && ($key !== null && in_array($key, $__hmWorkingDays, true));
}

// Range etichetta header
$labelMonthStart = ['','gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'][(int)$weekStart->format('n')];
$labelMonthEnd   = ['','gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'][(int)$weekEnd->format('n')];
$rangeLabel = $weekStart->format('j') . ' ' . $labelMonthStart;
if ($labelMonthStart !== $labelMonthEnd) {
    $rangeLabel .= ' - ' . $weekEnd->format('j') . ' ' . $labelMonthEnd;
} else {
    $rangeLabel .= ' - ' . $weekEnd->format('j') . ' ' . $labelMonthEnd;
}
$rangeLabel .= ' ' . $weekEnd->format('Y');

// Carica dipendenti (scoped per azienda corrente)
$__heatmap_cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$empSql = "SELECT id, first_name, last_name, photo_path, department_id, availability_status, availability_set_at
           FROM employees WHERE is_active = TRUE AND company_id = ?";
$empParams = [$__heatmap_cid];
// Filtra per reparto solo se scope='mine' (o se non c'è toggle, mantiene il comportamento legacy)
$__hmCurrentScope = $_GET['scope'] ?? $heatmapDefaultScope;
$__applyDeptFilter = $heatmapDepartmentId !== null
    && (!$heatmapShowScopeToggle || $__hmCurrentScope === 'mine');
if ($__applyDeptFilter) {
    $empSql .= " AND department_id = ?";
    $empParams[] = $heatmapDepartmentId;
}
$empSql .= " ORDER BY last_name, first_name";

try {
    $employees = Database::fetchAll($empSql, $empParams);
} catch (Throwable $e) {
    // Probabilmente migration 012 non ancora eseguita: retry senza availability_status
    $fallbackSql = str_replace(', availability_status, availability_set_at', '', $empSql);
    try {
        $employees = Database::fetchAll($fallbackSql, $empParams);
        foreach ($employees as &$__e) { $__e['availability_status'] = 'operative'; }
        unset($__e);
    } catch (Throwable $e2) {
        $employees = [];
    }
}

$empIds = array_column($employees, 'id');

// Carica reparti per nome (per tooltip)
$deptMap = [];
try {
    $depts = Database::fetchAll("SELECT id, name FROM departments WHERE company_id = ?", [$__heatmap_cid]);
    foreach ($depts as $d) $deptMap[$d['id']] = $d['name'];
} catch (Throwable $e) {}

// Carica leave approvate + pending che si sovrappongono alla settimana
$leavesByEmp = [];
if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $params = array_merge($empIds, [$weekEnd->format('Y-m-d'), $weekStart->format('Y-m-d')]);
    try {
        $rows = Database::fetchAll(
            "SELECT employee_id, leave_type, start_date, end_date, start_time, end_time, status
             FROM leave_requests
             WHERE employee_id IN ($placeholders)
               AND status IN ('approved','pending')
               AND start_date <= ?
               AND end_date >= ?",
            $params
        );
        foreach ($rows as $r) {
            $leavesByEmp[(int)$r['employee_id']][] = $r;
        }
    } catch (Throwable $e) {}
}

$leaveTypeLabels = [
    'ferie' => 'Ferie',
    'permesso' => 'Permesso',
    'malattia' => 'Malattia',
    'permesso_104' => 'Permesso L.104',
    'congedo_parentale' => 'Congedo Parentale',
    'altro' => 'Assenza',
];

// Privacy L.104: dato sanitario sensibile. Nella tooltip del widget la causale
// L.104 e' visibile solo a chi ne ha bisogno per legge/paghe: admin (HR) e
// accountant (commercialista/consulente del lavoro). admin_reparto e employee
// vedono un generico "Permesso". L'utente vede sempre la causale sulle proprie
// assenze.
$__hmCurrentRole  = null;
$__hmCurrentEmpId = 0;
if (class_exists('Auth')) {
    try {
        $__hmU = Auth::getUser();
        if ($__hmU && !empty($__hmU['role'])) {
            $__hmCurrentRole = $__hmU['role'];
        } else {
            $__hmE = Auth::getEmployee();
            if ($__hmE && !empty($__hmE['id'])) {
                $__hmCurrentRole  = 'employee';
                $__hmCurrentEmpId = (int) $__hmE['id'];
            }
        }
    } catch (Throwable $e) {}
}
$__hmMaskL104 = !in_array($__hmCurrentRole, ['admin', 'accountant'], true);

$availabilityLabels = [
    'operative'  => 'Operativo',
    'in_call'    => 'In chiamata',
    'in_meeting' => 'In riunione',
];

// Helper iniziali colorate (hash deterministico)
$initialsColor = function(string $first, string $last): string {
    $palette = ['#3182ce','#805ad5','#d53f8c','#dd6b20','#38a169','#319795','#d69e2e','#2b6cb0'];
    $h = crc32($first . $last);
    return $palette[$h % count($palette)];
};

// Costruisci URL helper preservando scope esistente
$scopeQS = isset($_GET['scope']) ? '&scope=' . urlencode($_GET['scope']) : '';
$buildWeekUrl = function(string $w) use ($heatmapBaseUrl, $scopeQS) {
    $sep = strpos($heatmapBaseUrl, '?') !== false ? '&' : '?';
    return $heatmapBaseUrl . $sep . 'w=' . $w . $scopeQS;
};

$currentScope = $_GET['scope'] ?? $heatmapDefaultScope;
?>
<section class="card heatmap-card">
    <div class="heatmap-header">
        <div class="heatmap-title-block">
            <h3>Presenze settimanali</h3>
            <span class="heatmap-range"><?= htmlspecialchars($rangeLabel) ?></span>
        </div>
        <div class="heatmap-controls">
            <?php if ($heatmapShowScopeToggle): ?>
                <div class="heatmap-scope">
                    <a href="<?= htmlspecialchars($heatmapBaseUrl . (strpos($heatmapBaseUrl,'?')!==false?'&':'?') . 'scope=mine&w=' . $weekStart->format('Y-m-d')) ?>"
                       class="heatmap-scope-btn <?= $currentScope === 'mine' ? 'active' : '' ?>">Mio reparto</a>
                    <a href="<?= htmlspecialchars($heatmapBaseUrl . (strpos($heatmapBaseUrl,'?')!==false?'&':'?') . 'scope=all&w=' . $weekStart->format('Y-m-d')) ?>"
                       class="heatmap-scope-btn <?= $currentScope === 'all' ? 'active' : '' ?>">Tutti</a>
                </div>
            <?php endif; ?>
            <div class="heatmap-nav">
                <a href="<?= htmlspecialchars($buildWeekUrl($prevWeek)) ?>" class="heatmap-nav-btn" aria-label="Settimana precedente">&larr;</a>
                <a href="<?= htmlspecialchars($buildWeekUrl($today)) ?>" class="heatmap-nav-btn heatmap-today">Oggi</a>
                <a href="<?= htmlspecialchars($buildWeekUrl($nextWeek)) ?>" class="heatmap-nav-btn" aria-label="Settimana successiva">&rarr;</a>
            </div>
        </div>
    </div>

    <div class="heatmap-toolbar">
        <div class="heatmap-search">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.5 6.5 0 1 0 13 15.5l.27.28v.79l5 5L19.49 20l-5-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
            <input type="search" class="heatmap-search-input" placeholder="Cerca dipendente..." aria-label="Cerca dipendente">
            <button type="button" class="heatmap-search-clear" aria-label="Pulisci ricerca" hidden>&times;</button>
        </div>
        <div class="heatmap-legend" role="group" aria-label="Filtra per stato">
            <button type="button" class="heatmap-legend-btn" data-filter-state="present"><i class="hm-dot hm-present"></i> Disponibile</button>
            <button type="button" class="heatmap-legend-btn" data-filter-state="busy"><i class="hm-dot hm-busy"></i> Occupato</button>
            <button type="button" class="heatmap-legend-btn" data-filter-state="pending"><i class="hm-dot hm-pending"></i> In approvazione</button>
            <button type="button" class="heatmap-legend-btn" data-filter-state="absent"><i class="hm-dot hm-absent"></i> Assente</button>
        </div>
    </div>

    <?php if (empty($employees)): ?>
        <div class="empty-state" style="padding:2rem;text-align:center;">Nessun dipendente da mostrare.</div>
    <?php else: ?>
    <div class="heatmap-days">
        <?php foreach ($days as $i => $dayDate):
            $dObj = new DateTime($dayDate);
            $isToday = $dayDate === $today;

            // Costruisci array avatar con stato per ordinamento
            $rowAvatars = [];
            foreach ($employees as $emp) {
                $fullName = trim($emp['first_name'] . ' ' . $emp['last_name']);
                $initials = strtoupper(mb_substr($emp['first_name'],0,1) . mb_substr($emp['last_name'],0,1));
                $color = $initialsColor($emp['first_name'], $emp['last_name']);
                $photoUrl = !empty($emp['photo_path']) ? PUBLIC_URL . '/' . ltrim($emp['photo_path'],'/') : '';
                $myLeaves = $leavesByEmp[(int)$emp['id']] ?? [];

                $state = 'present';
                $tooltip = 'Disponibile';

                // Approved ha priorita su pending; cerca prima approved
                $approvedHit = null; $pendingHit = null;
                foreach ($myLeaves as $lv) {
                    if ($lv['start_date'] <= $dayDate && $lv['end_date'] >= $dayDate) {
                        if ($lv['status'] === 'approved' && $approvedHit === null) $approvedHit = $lv;
                        if ($lv['status'] === 'pending'  && $pendingHit  === null) $pendingHit  = $lv;
                    }
                }
                $hit = $approvedHit ?? $pendingHit;
                if ($hit) {
                    $state = $approvedHit ? 'absent' : 'pending';
                    $__hmType = $hit['leave_type'];
                    if ($__hmMaskL104 && $__hmType === 'permesso_104' && (int)$emp['id'] !== $__hmCurrentEmpId) {
                        $label = 'Permesso';
                    } else {
                        $label = $leaveTypeLabels[$__hmType] ?? 'Assenza';
                    }
                    if (!empty($hit['start_time']) && !empty($hit['end_time'])) {
                        $st = substr($hit['start_time'], 0, 5);
                        $et = substr($hit['end_time'], 0, 5);
                        $tooltip = $label . ' ' . $st . '-' . $et;
                    } else {
                        $tooltip = $label;
                    }
                    if ($state === 'pending') $tooltip .= ' · in approvazione';
                }

                if ($isToday && $state === 'present') {
                    $av = $emp['availability_status'] ?? 'operative';
                    if ($av !== 'operative') {
                        $state = 'busy';
                        $tooltip = $availabilityLabels[$av] ?? 'Occupato';
                    }
                }

                $rowAvatars[] = [
                    'state'    => $state,
                    'name'     => $fullName,
                    'initials' => $initials,
                    'color'    => $color,
                    'photo'    => $photoUrl,
                    'tooltip'  => $tooltip,
                ];
            }

            // Ordina: present -> busy -> pending -> absent
            $stateOrder = ['present' => 0, 'busy' => 1, 'pending' => 2, 'absent' => 3];
            usort($rowAvatars, function($a, $b) use ($stateOrder) {
                return ($stateOrder[$a['state']] <=> $stateOrder[$b['state']]) ?: strcmp($a['name'], $b['name']);
            });

            $countPresent = 0; $countBusy = 0; $countPending = 0; $countAbsent = 0;
            foreach ($rowAvatars as $a) {
                if ($a['state'] === 'present') $countPresent++;
                elseif ($a['state'] === 'busy') $countBusy++;
                elseif ($a['state'] === 'pending') $countPending++;
                else $countAbsent++;
            }
        ?>
            <?php
                $__isWk = $dayIsWorking[$i] ?? true;
                $__hmHoliday = $dayHolidayName[$i] ?? null;
            ?>
            <div class="heatmap-day-row <?= $isToday ? 'is-today' : '' ?> <?= !$__isWk ? 'is-nonworking' : '' ?> <?= $__hmHoliday ? 'is-holiday' : '' ?>">
                <div class="heatmap-day-label">
                    <span class="heatmap-day-name"><?= $dayLabels[$i] ?></span>
                    <span class="heatmap-day-num"><?= $dObj->format('j') ?></span>
                    <?php if ($isToday && $__isWk): ?><span class="heatmap-day-badge">Oggi</span><?php endif; ?>
                </div>
                <?php if ($__isWk): ?>
                    <div class="heatmap-stack" tabindex="0">
                        <?php foreach ($rowAvatars as $idx => $av): ?>
                            <div class="heatmap-stack-avatar is-<?= $av['state'] ?>" tabindex="0"
                                 data-state="<?= $av['state'] ?>"
                                 data-name="<?= htmlspecialchars(mb_strtolower($av['name'])) ?>"
                                 style="--avatar-i: <?= $idx ?>">
                                <div class="heatmap-stack-photo">
                                    <?php if ($av['photo']): ?>
                                        <img src="<?= htmlspecialchars($av['photo']) ?>" alt="<?= htmlspecialchars($av['name']) ?>">
                                    <?php else: ?>
                                        <span class="heatmap-stack-initials" style="background: <?= $av['color'] ?>"><?= htmlspecialchars($av['initials']) ?></span>
                                    <?php endif; ?>
                                    <span class="heatmap-stack-overlay"></span>
                                </div>
                                <div class="heatmap-stack-tooltip" role="tooltip">
                                    <span class="hst-name"><?= htmlspecialchars($av['name']) ?></span>
                                    <span class="hst-status hst-<?= $av['state'] ?>"><?= htmlspecialchars($av['tooltip']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="heatmap-day-count">
                        <span class="hm-count hm-count-present" title="Disponibili"><?= $countPresent ?></span>
                        <?php if ($countBusy > 0): ?><span class="hm-count hm-count-busy" title="Occupati"><?= $countBusy ?></span><?php endif; ?>
                        <?php if ($countPending > 0): ?><span class="hm-count hm-count-pending" title="In approvazione"><?= $countPending ?></span><?php endif; ?>
                        <?php if ($countAbsent > 0): ?><span class="hm-count hm-count-absent" title="Assenti"><?= $countAbsent ?></span><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="heatmap-stack heatmap-stack-off">
                        <span class="heatmap-off-label">
                            <?php if ($__hmHoliday): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14" aria-hidden="true"><path d="M12 2l2.39 6.96H22l-6.18 4.49 2.36 6.96L12 16.9l-6.18 4.51 2.36-6.96L2 8.96h7.61z"/></svg>
                                Festivit&agrave; &middot; <?= htmlspecialchars($__hmHoliday) ?>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M4.93 4.93l14.14 14.14"/></svg>
                                Giorno non lavorativo
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="heatmap-day-count"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
