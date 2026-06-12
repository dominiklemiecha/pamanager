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

    <?php if (empty($employees)): ?>
        <div class="empty-state" style="padding:2rem;text-align:center;">Nessun dipendente da mostrare.</div>
    <?php else: ?>
    <?php $__todayIdx = array_search($today, $days, true); ?>
    <div class="heatmap-days">
        <?php foreach ($days as $i => $dayDate):
            $dObj = new DateTime($dayDate);
            $isToday = $dayDate === $today;
            // Ordine per mobile: oggi come prima card (rotazione settimanale). Se oggi
            // non e' nella settimana visualizzata, ordine cronologico naturale.
            $__mobileOrder = ($__todayIdx !== false) ? (($i - $__todayIdx + 7) % 7) : $i;

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
                    'state'      => $state,
                    'name'       => $fullName,
                    'initials'   => $initials,
                    'color'      => $color,
                    'photo'      => $photoUrl,
                    'tooltip'    => $tooltip,
                    'leaveLabel' => $hit ? $label : null,
                ];
            }

            // Ordine display: prima i "mancanti" (assenti/in approvazione/occupati),
            // poi i disponibili mescolati in modo deterministico per giorno (variano
            // giorno per giorno ma restano stabili sullo stesso giorno tra reload).
            $stateOrder = ['absent' => 0, 'pending' => 1, 'busy' => 2, 'present' => 3];
            $__missing = []; $__available = [];
            foreach ($rowAvatars as $a) {
                if ($a['state'] === 'present') $__available[] = $a; else $__missing[] = $a;
            }
            usort($__missing, function($a, $b) use ($stateOrder) {
                return ($stateOrder[$a['state']] <=> $stateOrder[$b['state']]) ?: strcmp($a['name'], $b['name']);
            });
            mt_srand(crc32($dayDate));
            // Fisher-Yates con mt_rand (shuffle() non e' seedabile in modo affidabile)
            for ($__k = count($__available) - 1; $__k > 0; $__k--) {
                $__j = mt_rand(0, $__k);
                [$__available[$__k], $__available[$__j]] = [$__available[$__j], $__available[$__k]];
            }
            mt_srand();
            $rowAvatars = array_merge($__missing, $__available);
            $__CAP = 8;
            $__moreCount = max(0, count($rowAvatars) - $__CAP);

            $countPresent = 0; $countBusy = 0; $countPending = 0;
            $absentByType = []; // label causale (gia' mascherata L.104) => conteggio
            foreach ($rowAvatars as $a) {
                if ($a['state'] === 'present') $countPresent++;
                elseif ($a['state'] === 'busy') $countBusy++;
                elseif ($a['state'] === 'pending') $countPending++;
                else {
                    $__lbl = $a['leaveLabel'] ?? 'Assenza';
                    $absentByType[$__lbl] = ($absentByType[$__lbl] ?? 0) + 1;
                }
            }
            arsort($absentByType);
        ?>
            <?php
                $__isWk = $dayIsWorking[$i] ?? true;
                $__hmHoliday = $dayHolidayName[$i] ?? null;
            ?>
            <div class="heatmap-day-row <?= $isToday ? 'is-today' : '' ?> <?= !$__isWk ? 'is-nonworking' : '' ?> <?= $__hmHoliday ? 'is-holiday' : '' ?>" style="--ord: <?= (int)$__mobileOrder ?>;">
                <div class="heatmap-day-label">
                    <span class="heatmap-day-name"><?= $dayLabels[$i] ?></span>
                    <span class="heatmap-day-num"><?= $dObj->format('j') ?></span>
                    <?php if ($isToday && $__isWk): ?><span class="heatmap-day-badge">Oggi</span><?php endif; ?>
                </div>
                <?php if ($__isWk): ?>
                    <div class="heatmap-stack is-capped" tabindex="0" data-day-label="<?= htmlspecialchars($dayLabels[$i] . ' ' . $dObj->format('j')) ?>">
                        <?php foreach ($rowAvatars as $idx => $av): ?>
                            <div class="heatmap-stack-avatar is-<?= $av['state'] ?>" tabindex="0"
                                 data-state="<?= $av['state'] ?>"
                                 data-name="<?= htmlspecialchars(mb_strtolower($av['name'])) ?>"
                                 <?= $av['leaveLabel'] !== null ? 'data-leave="' . htmlspecialchars(mb_strtolower($av['leaveLabel'])) . '"' : '' ?>
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
                        <?php if ($__moreCount > 0): ?>
                            <button type="button" class="heatmap-more-btn" aria-label="Mostra tutti i <?= count($rowAvatars) ?> dipendenti">+<?= $__moreCount ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="heatmap-day-count">
                        <span class="hm-count hm-count-present" title="Disponibili"><?= $countPresent ?></span>
                        <?php if ($countBusy > 0): ?><span class="hm-count hm-count-busy" title="Occupati"><?= $countBusy ?></span><?php endif; ?>
                        <?php if ($countPending > 0): ?><span class="hm-count hm-count-pending" title="In approvazione"><?= $countPending ?></span><?php endif; ?>
                        <?php foreach ($absentByType as $__lbl => $__n): ?>
                            <span class="hm-count hm-count-absent hm-count-typed" data-leave-label="<?= htmlspecialchars(mb_strtolower($__lbl)) ?>" title="Assenti &middot; <?= htmlspecialchars($__lbl) ?>"><?= $__n ?> <?= htmlspecialchars(mb_strtolower($__lbl)) ?></span>
                        <?php endforeach; ?>
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

    <!-- Popup roster completo (popolato via JS al click su "+N") -->
    <div class="heatmap-roster-overlay" hidden>
        <div class="heatmap-roster-modal" role="dialog" aria-modal="true" aria-labelledby="heatmapRosterTitle">
            <div class="heatmap-roster-head">
                <h4 id="heatmapRosterTitle">Presenze</h4>
                <button type="button" class="heatmap-roster-close" aria-label="Chiudi">&times;</button>
            </div>
            <div class="heatmap-roster-list"></div>
        </div>
    </div>
</section>
