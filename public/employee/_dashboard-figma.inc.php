<?php
/**
 * Dashboard dipendente — variante grafica dedicata (design Figma
 * "Dashboard Dipendente HR — Ricostruzione 1:1", nodo 1:2).
 *
 * Inclusa da employee/index.php solo per le email elencate in
 * EMPLOYEE_FIGMA_DASHBOARD_EMAILS. Riusa i dati gia' calcolati da index.php
 * ($employee, $__balances, $__recentLeaves, $communications, ...).
 * Pagina standalone: niente header/sidebar standard, menu a dock in basso.
 */

$baseUrl = PUBLIC_URL;
$__img   = $baseUrl . '/assets/images/dash-dipendente';

// Stessi redirect bloccanti dell'header standard
try {
    $__pwdRow = Database::fetchOne("SELECT must_change_password FROM employees WHERE id = ?", [(int)$employee['id']]);
    if (!empty($__pwdRow['must_change_password'])) {
        header('Location: ' . $baseUrl . '/employee/change-password.php');
        exit;
    }
} catch (Throwable $__e) {}
try {
    $__pending = Database::fetchOne(
        "SELECT id FROM hire_requests WHERE employee_id = ? AND status = 'contract_pending' ORDER BY id DESC LIMIT 1",
        [(int)$employee['id']]
    );
    if ($__pending) {
        header('Location: ' . $baseUrl . '/employee/contract-sign.php?id=' . (int)$__pending['id']);
        exit;
    }
} catch (Throwable $__e) {}

$fdNum = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
};
$fdMonths = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$fdMonthsCap = ['', 'Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
$fdDate = static function (?string $dt) use ($fdMonths): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return (int)date('j', $ts) . ' ' . $fdMonths[(int)date('n', $ts)] . ' ' . date('Y', $ts);
};
$fdDayMonth = static function (string $d) use ($fdMonthsCap): string {
    $ts = strtotime($d);
    return date('d', $ts) . ' ' . $fdMonthsCap[(int)date('n', $ts)];
};

// Foto profilo
$fdPhoto = '';
try {
    $__p = Database::fetchColumn("SELECT photo_path FROM employees WHERE id = ?", [(int)$employee['id']]);
    if (!empty($__p)) $fdPhoto = $baseUrl . '/' . ltrim($__p, '/');
} catch (Throwable $__e) {}

// Saldi: geometria anello (raggio medio 80.84, spessore 26.32 come nel design)
$fdR    = 80.84;
$fdCirc = 2 * pi() * $fdR;
$fdBal  = [];
foreach (['ferie', 'permesso'] as $__t) {
    $__b = $__balances[$__t] ?? null;
    $__total = $__b ? (float)$__b['total'] : 0;
    $__used  = $__b ? (float)$__b['used'] : 0;
    $fdBal[$__t] = [
        'total' => $__total,
        'used'  => $__used,
        'resid' => $__b ? (float)$__b['residual'] : 0,
        'pct'   => $__total > 0 ? max(0, min(1, $__used / $__total)) : 0,
    ];
}

// Presenze di oggi: colleghi attivi dell'azienda, assenti = assenza approvata oggi (smart working escluso)
$fdToday = date('Y-m-d');
$fdPeople = [];
try {
    $fdPeople = Database::fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.photo_path,
                EXISTS (SELECT 1 FROM leave_requests lr
                         WHERE lr.employee_id = e.id AND lr.status = 'approved'
                           AND lr.leave_type <> 'smart_working'
                           AND lr.start_date <= ? AND lr.end_date >= ?) AS is_absent
         FROM employees e
         WHERE e.is_active = TRUE AND e.company_id = ?
         ORDER BY is_absent DESC, e.last_name, e.first_name",
        [$fdToday, $fdToday, (int)$employee['company_id']]
    );
} catch (Throwable $__e) {}
$fdAbsent = 0;
foreach ($fdPeople as $__p) if ((int)$__p['is_absent'] === 1) $fdAbsent++;
$fdAvailable = count($fdPeople) - $fdAbsent;
$fdAvatars = array_slice($fdPeople, 0, 7);

// Documenti recenti: buste paga/CU + documenti personali visibili
$fdDocs = [];
foreach ($allDocs as $__d) {
    $fdDocs[] = [
        'title' => $__d['title'],
        'date'  => $__d['created_at'],
        'url'   => $baseUrl . '/employee/documents.php?download=' . (int)$__d['id'],
    ];
}
if (class_exists('EmployeeDocument')) {
    try {
        foreach (EmployeeDocument::getByEmployee((int)$employee['id'], true) as $__d) {
            $fdDocs[] = [
                'title' => $__d['name'],
                'date'  => $__d['created_at'],
                'url'   => $baseUrl . '/employee/documents.php?download_personal=' . (int)$__d['id'],
            ];
        }
    } catch (Throwable $__e) {}
}
usort($fdDocs, static fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
$fdDocs = array_slice($fdDocs, 0, 3);

$fdComms = array_slice($communications, 0, 2);

$fdStatus = [
    'approved'  => ['#c5edc6', 'APPROVATA'],
    'pending'   => ['#edf7c6', 'IN ATTESA'],
    'rejected'  => ['#ffd6d6', 'RIFIUTATA'],
    'cancelled' => ['#e6e6e6', 'ANNULLATA'],
];

$fdName = htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Home</title>
    <link rel="manifest" href="<?= $baseUrl ?>/manifest.json.php">
    <link rel="apple-touch-icon" href="<?= $baseUrl ?>/assets/images/icon.php?size=180&v=4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#82a6ba">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; overflow-x: hidden; }
body {
    min-height: 100vh;
    font-family: 'Urbanist', sans-serif;
    font-weight: 700;
    color: #111;
    background: #db3804;
}
body::before {
    content: ''; position: fixed; inset: 0; z-index: -1;
    background: linear-gradient(180deg, rgb(130,166,186) 0%, rgb(199,184,178) 27%, rgb(250,178,122) 55%, rgb(255,122,31) 76%, rgb(219,56,4) 100%);
    background-size: 100% 137.12%;
    background-position: 0 -10px;
}
a { color: inherit; text-decoration: none; }
p { margin: 0; }
img { display: block; max-width: none; }

.fd-stage { position: relative; width: 1408px; height: 1056px; margin: 0 auto; }
.fd-stage::before {
    content: ''; position: absolute; left: 24px; top: 18px; width: 1360px; height: 867px;
    background: rgba(234,234,234,0.4); border-radius: 18px;
}
.fd-card {
    position: absolute; overflow: hidden; display: block;
    background: rgba(255,255,255,0.84);
    border: 1px solid rgba(255,255,255,0.85);
    border-radius: 18px;
}
.fd-abs { position: absolute; white-space: nowrap; line-height: normal; }

/* Saldi */
.fd-widget { top: 40px; height: 273px; }
.fd-ferie { left: 62px; width: 634px; }
.fd-permessi { left: 710px; width: 636px; }
.fd-h23 { font-size: 23px; color: #111; }
.fd-ring { position: absolute; left: 46px; top: 64px; width: 188px; height: 188px; }
.fd-ring svg { position: absolute; inset: 0; overflow: visible; }
.fd-ring-bg { position: absolute; left: 0; top: 0; width: 188px; height: 188px; }
.fd-ring-center {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; padding-bottom: 3px;
}
.fd-ring-big { font-size: 40px; color: #000; line-height: normal; }
.fd-ring-lbl { font-size: 14px; color: #666266; line-height: normal; margin-top: -4px; }
.fd-chip {
    position: absolute; height: 33px; min-width: 129px; padding: 0 16px;
    border: 2px solid #6b666b; border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #111; white-space: nowrap;
}
.fd-chip.is-used { border-color: #ff9400; }
.fd-empty-bal { position: absolute; left: 294px; top: 120px; font-size: 18px; color: #6b666b; }
.fd-watermark { position: absolute; left: 423px; top: -60px; width: 277.62px; height: 286.342px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.fd-watermark img { width: 220px; height: 233px; transform: rotate(16.68deg); flex: none; }

/* Azioni */
.fd-row2 { top: 331px; height: 118px; }
.fd-act-ferie { left: 62px; width: 435px; }
.fd-act-perm { left: 510px; width: 387px; }
.fd-presenze { left: 912px; width: 434px; }
.fd-act-title { left: 28px; top: 13px; font-size: 21px; color: #111; }
.fd-act-sub { left: 28px; top: 51px; font-size: 16px; color: #69636a; }
.fd-act-btn { position: absolute; width: 54px; height: 54px; }
.fd-act-btn .bg { position: absolute; inset: 0; width: 54px; height: 54px; }
.fd-act-btn .ic { position: absolute; left: 10px; top: 9px; width: 34px; height: 35px; }
.fd-card.is-link { transition: transform .15s ease, box-shadow .15s ease; }
.fd-card.is-link:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }

/* Presenze */
.fd-avatars { position: absolute; left: 18px; top: 42px; display: flex; }
.fd-av { position: relative; width: 64px; height: 64px; flex: none; }
.fd-av + .fd-av { margin-left: -33px; }
.fd-av-img {
    width: 64px; height: 64px; border-radius: 50%; overflow: hidden;
    background: #e3e3e3; display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #4c4c4c;
}
.fd-av-img img { width: 64px; height: 64px; object-fit: cover; }
.fd-av-state { position: absolute; left: 47px; top: 40px; width: 22px; height: 22px; }
.fd-legend-dot { position: absolute; width: 13px; height: 13px; }
.fd-legend-txt { font-size: 15px; color: #111; }

/* Row 3 */
.fd-row3 { top: 465px; height: 299px; }
.fd-comm { left: 62px; width: 435px; }
.fd-docs { left: 510px; width: 387px; }
.fd-reqs { left: 913px; width: 433px; }
.fd-h21 { left: 24px; top: 18px; font-size: 21px; color: #111; }
.fd-comm-box {
    position: absolute; left: 24px; width: 387px; height: 103px; overflow: hidden;
    background: #efefef; border-radius: 4px; display: block;
}
.fd-comm-box .wm { position: absolute; left: 274px; top: -50px; width: 175.124px; height: 180.625px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.fd-comm-box .wm img { width: 138.777px; height: 146.977px; transform: rotate(16.68deg); flex: none; }
.fd-comm-t { position: absolute; left: 16px; right: 16px; top: 26px; text-align: center; font-size: 20px; color: #4c4c4c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-comm-s { position: absolute; left: 16px; right: 16px; top: 60px; text-align: center; font-size: 14px; color: #898589; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.fd-more { right: 20px; top: 21px; font-size: 16px; color: #6b666b; white-space: pre; }
.fd-doc { position: absolute; left: 24px; right: 20px; height: 58px; display: block; }
.fd-doc-ic {
    position: absolute; left: 0; top: 0; width: 58px; height: 58px;
    background: #fff; border: 2px solid #ff9400; border-radius: 15px;
}
.fd-doc-ic img { position: absolute; left: 12px; top: 12px; width: 30px; height: 30px; }
.fd-doc-t { position: absolute; left: 76px; right: 0; top: 9px; font-size: 15px; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-doc-d { position: absolute; left: 76px; top: 35px; font-size: 14px; color: #6b666b; white-space: nowrap; }
.fd-none { position: absolute; left: 24px; top: 70px; font-size: 15px; color: #6b666b; }

.fd-req { position: absolute; left: 23px; right: 20px; height: 45px; }
.fd-req-ic {
    position: absolute; left: 0; top: 0; width: 44px; height: 45px;
    background: #fff; border: 2px solid #ff9400; border-radius: 12px;
}
.fd-req-ic img { position: absolute; left: 8px; top: 8px; width: 24px; height: 24px; }
.fd-req-t { position: absolute; left: 61px; top: 3px; font-size: 15px; color: #111; white-space: nowrap; }
.fd-req-s { position: absolute; left: 61px; top: 26px; font-size: 12px; color: #666166; white-space: nowrap; }
.fd-pill {
    position: absolute; right: 0; top: 2px; width: 112px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: #111;
}

/* Menu dock */
.fd-dock {
    position: absolute; left: 50%; top: 791px; transform: translateX(-50%);
    width: 560px; height: 72px; background: #fff;
    border: 1px solid #e2eae9; border-radius: 20px;
    box-shadow: inset 0 1px 1px 0 rgba(0,0,0,0.1), inset 0 -1px 1px 0 rgba(255,255,255,0.8);
}
.fd-nav { position: absolute; top: 15px; width: 48px; height: 40px; background: #fff; display: block; }
.fd-nav.is-dim { opacity: .55; }
.fd-nav.is-off { opacity: .2; cursor: default; }
.fd-nav .ic { position: absolute; left: 12px; top: 8px; width: 24px; height: 24px; }
.fd-nav-home { top: 16px; height: 54px; }
.fd-nav-dot { position: absolute; left: 21px; top: 34px; width: 5px; height: 5px; border-radius: 5px; background: #ff9400; }
.fd-nav-avatar { background: transparent; }
.fd-nav-avatar .bg { position: absolute; inset: 0; width: 48px; height: 40px; }
.fd-nav-avatar .ph { position: absolute; left: 12px; top: 8px; width: 24px; height: 24px; border-radius: 50%; object-fit: cover; }

/* Mobile: card impilate, ciascuna scalata sulla larghezza disponibile */
@media (max-width: 899px) {
    .fd-stage { width: auto; height: auto; padding: 12px 12px 110px; }
    .fd-stage::before { display: none; }
    .fd-panel { background: rgba(234,234,234,0.4); border-radius: 18px; padding: 14px; display: flex; flex-direction: column; gap: 14px; }
    .fd-fit { position: relative; }
    .fd-card { position: relative; left: auto !important; top: auto !important; }
    .fd-dock { position: fixed; top: auto; bottom: 14px; left: 0; right: 0; margin: 0 auto; transform: none; z-index: 10; }
}
</style>
</head>
<body>
<div class="fd-stage" id="fdStage">
    <div class="fd-panel">

        <?php // ===== Ferie =====
        $__f = $fdBal['ferie']; ?>
        <div class="fd-fit" data-w="634"><div class="fd-card fd-widget fd-ferie">
            <p class="fd-abs fd-h23" style="left:86px;top:20px;">Ferie <?= (int)$__year ?></p>
            <div class="fd-watermark"><img src="<?= $__img ?>/watermark.svg" alt=""></div>
            <?php if ($__f['total'] > 0): ?>
                <div class="fd-ring">
                    <svg width="188" height="188" viewBox="0 0 188 188">
                        <circle cx="94" cy="94" r="<?= $fdR ?>" fill="none" stroke="#FF9400" stroke-opacity="0.13" stroke-width="26.32"/>
                        <circle cx="94" cy="94" r="<?= $fdR ?>" fill="none" stroke="#FF9400" stroke-width="26.32"
                                stroke-dasharray="<?= round($fdCirc * $__f['pct'], 2) ?> <?= round($fdCirc, 2) ?>"
                                transform="rotate(-90 94 94)"/>
                    </svg>
                    <div class="fd-ring-center">
                        <span class="fd-ring-big"><?= $fdNum($__f['resid']) ?></span>
                        <span class="fd-ring-lbl">giorni residui</span>
                    </div>
                </div>
                <div class="fd-chip is-used" style="left:294px;top:99px;">Utilizzati : <?= $fdNum($__f['used']) ?></div>
                <div class="fd-chip" style="left:294px;top:146px;">Totale : <?= $fdNum($__f['total']) ?></div>
            <?php else: ?>
                <p class="fd-empty-bal">Saldo non configurato</p>
            <?php endif; ?>
        </div></div>

        <?php // ===== Permessi =====
        $__p = $fdBal['permesso']; ?>
        <div class="fd-fit" data-w="636"><div class="fd-card fd-widget fd-permessi">
            <p class="fd-abs fd-h23" style="left:65px;top:18px;">Permessi <?= (int)$__year ?></p>
            <div class="fd-watermark"><img src="<?= $__img ?>/watermark.svg" alt=""></div>
            <?php if ($__p['total'] > 0): ?>
                <div class="fd-ring">
                    <img class="fd-ring-bg" src="<?= $__img ?>/bg-segment.png" width="188" height="188" alt="">
                    <svg width="188" height="188" viewBox="0 0 188 188">
                        <defs>
                            <linearGradient id="fdPermGrad" x1="0" y1="0" x2="0" y2="188" gradientUnits="userSpaceOnUse">
                                <stop offset="0.120192" stop-color="white"/>
                                <stop offset="0.947115" stop-color="#FF9400"/>
                            </linearGradient>
                        </defs>
                        <?php if ($__p['pct'] > 0): ?>
                        <circle cx="94" cy="94" r="<?= $fdR ?>" fill="none" stroke="url(#fdPermGrad)" stroke-width="26.32" stroke-linecap="round"
                                stroke-dasharray="<?= round($fdCirc * $__p['pct'], 2) ?> <?= round($fdCirc, 2) ?>"
                                transform="rotate(-90 94 94)"/>
                        <?php endif; ?>
                    </svg>
                    <div class="fd-ring-center">
                        <span class="fd-ring-big"><?= $fdNum($__p['resid']) ?></span>
                        <span class="fd-ring-lbl">ore residue</span>
                    </div>
                </div>
                <div class="fd-chip is-used" style="left:297px;top:102px;">Utilizzati : <?= $fdNum($__p['used']) ?></div>
                <div class="fd-chip" style="left:297px;top:145px;">Totale : <?= $fdNum($__p['total']) ?></div>
            <?php else: ?>
                <p class="fd-empty-bal">Saldo non configurato</p>
            <?php endif; ?>
        </div></div>

        <?php // ===== Azioni rapide ===== ?>
        <div class="fd-fit" data-w="435"><a class="fd-card fd-row2 fd-act-ferie is-link" href="<?= $baseUrl ?>/employee/leave-requests.php?action=new&type=ferie">
            <p class="fd-abs fd-act-title">Richiedi ferie</p>
            <p class="fd-abs fd-act-sub">Crea una nuova richiesta</p>
            <span class="fd-act-btn" style="left:357px;top:28px;">
                <img class="bg" src="<?= $__img ?>/pulsante.svg" alt="">
                <img class="ic" src="<?= $__img ?>/pulsante-icon.svg" alt="">
            </span>
        </a></div>

        <div class="fd-fit" data-w="387"><a class="fd-card fd-row2 fd-act-perm is-link" href="<?= $baseUrl ?>/employee/leave-requests.php?action=new&type=permesso">
            <p class="fd-abs fd-act-title" style="left:30px;font-size:20px;">Richiedi permesso</p>
            <p class="fd-abs fd-act-sub" style="left:30px;top:52px;">Ore o intera giornata</p>
            <span class="fd-act-btn" style="left:315px;top:31px;">
                <img class="bg" src="<?= $__img ?>/pulsante.svg" alt="">
                <img class="ic" src="<?= $__img ?>/pulsante-icon.svg" alt="">
            </span>
        </a></div>

        <?php // ===== Presenze di oggi ===== ?>
        <div class="fd-fit" data-w="434"><div class="fd-card fd-row2 fd-presenze">
            <p class="fd-abs" style="left:23px;top:8px;font-size:21px;color:#111;">Presenze di oggi</p>
            <div class="fd-avatars">
                <?php foreach ($fdAvatars as $__i => $__a):
                    $__absent = (int)$__a['is_absent'] === 1;
                    $__ph = !empty($__a['photo_path']) ? $baseUrl . '/' . ltrim($__a['photo_path'], '/') : '';
                    $__nm = trim($__a['first_name'] . ' ' . $__a['last_name']);
                    $__in = mb_strtoupper(mb_substr($__a['first_name'], 0, 1) . mb_substr($__a['last_name'], 0, 1));
                ?>
                    <div class="fd-av" style="z-index:<?= 10 - $__i ?>;" title="<?= htmlspecialchars($__nm . ($__absent ? ' · assente' : ' · disponibile')) ?>">
                        <div class="fd-av-img">
                            <?php if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="<?= htmlspecialchars($__nm) ?>" loading="lazy"><?php else: ?><?= htmlspecialchars($__in) ?><?php endif; ?>
                        </div>
                        <img class="fd-av-state" src="<?= $__img ?>/<?= $__absent ? 'state-red' : 'state-green' ?>.svg" alt="">
                    </div>
                <?php endforeach; ?>
            </div>
            <img class="fd-legend-dot" style="left:317px;top:52px;" src="<?= $__img ?>/dot-disponibili.svg" alt="">
            <p class="fd-abs fd-legend-txt" style="left:339px;top:50px;"><?= (int)$fdAvailable ?> disp.</p>
            <img class="fd-legend-dot" style="left:317px;top:83px;" src="<?= $__img ?>/dot-occupati.svg" alt="">
            <p class="fd-abs fd-legend-txt" style="left:339px;top:80px;"><?= (int)$fdAbsent ?> assent<?= $fdAbsent === 1 ? 'e' : 'i' ?></p>
        </div></div>

        <?php // ===== Comunicazioni ===== ?>
        <div class="fd-fit" data-w="435"><div class="fd-card fd-row3 fd-comm">
            <p class="fd-abs fd-h21">Comunicazioni</p>
            <?php if (empty($fdComms)): ?>
                <div class="fd-comm-box" style="top:93px;">
                    <div class="wm"><img src="<?= $__img ?>/watermark-small.svg" alt=""></div>
                    <p class="fd-comm-t">Nessuna comunicazione disponibile</p>
                    <p class="fd-comm-s">Qui troverai i nuovi aggiornamenti aziendali</p>
                </div>
            <?php else: foreach ($fdComms as $__i => $__c): ?>
                <a class="fd-comm-box" style="top:<?= 62 + $__i * 117 ?>px;" href="<?= $baseUrl ?>/employee/communications.php?id=<?= (int)$__c['id'] ?>">
                    <div class="wm"><img src="<?= $__img ?>/watermark-small.svg" alt=""></div>
                    <p class="fd-comm-t"><?= htmlspecialchars($__c['title']) ?></p>
                    <p class="fd-comm-s"><?= htmlspecialchars($fdDate($__c['created_at'] ?? $__c['publish_date'] ?? null)) ?></p>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        <?php // ===== Documenti recenti ===== ?>
        <div class="fd-fit" data-w="387"><div class="fd-card fd-row3 fd-docs">
            <p class="fd-abs fd-h21">Documenti recenti</p>
            <a class="fd-abs fd-more" href="<?= $baseUrl ?>/employee/documents.php"><?= 'Vedi tutti  →' ?></a>
            <?php if (empty($fdDocs)): ?>
                <p class="fd-none">Nessun documento disponibile</p>
            <?php else: foreach ($fdDocs as $__i => $__d): ?>
                <a class="fd-doc" style="top:<?= [61, 138, 214][$__i] ?>px;" href="<?= htmlspecialchars($__d['url']) ?>">
                    <span class="fd-doc-ic"><img src="<?= $__img ?>/ic-doc-box.svg" alt=""></span>
                    <span class="fd-doc-t"><?= htmlspecialchars($__d['title']) ?></span>
                    <span class="fd-doc-d"><?= htmlspecialchars($fdDate($__d['date'])) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        <?php // ===== Le tue ultime richieste ===== ?>
        <div class="fd-fit" data-w="433"><div class="fd-card fd-row3 fd-reqs">
            <p class="fd-abs fd-h21">Le tue ultime richieste</p>
            <?php if (empty($__recentLeaves)): ?>
                <p class="fd-none">Nessuna richiesta inviata</p>
            <?php else: foreach ($__recentLeaves as $__i => $__lr):
                $__st = $fdStatus[$__lr['status']] ?? ['#e6e6e6', strtoupper((string)$__lr['status'])];
                $__s = $fdDayMonth($__lr['start_date']);
                $__e = $fdDayMonth($__lr['end_date']);
                $__period = $__s === $__e ? $__s : $__s . ' – ' . $__e;
            ?>
                <a class="fd-req" style="top:<?= 60 + $__i * 58 ?>px;" href="<?= $baseUrl ?>/employee/leave-requests.php">
                    <span class="fd-req-ic"><img src="<?= $__img ?>/ic-req-ferie.svg" alt=""></span>
                    <span class="fd-req-t"><?= htmlspecialchars($__typeLabel[$__lr['leave_type']] ?? ucfirst((string)$__lr['leave_type'])) ?></span>
                    <span class="fd-req-s"><?= htmlspecialchars($__period) ?> – creata <?= htmlspecialchars(emp_time_ago($__lr['created_at'])) ?></span>
                    <span class="fd-pill" style="background:<?= $__st[0] ?>;"><?= htmlspecialchars($__st[1]) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        <?php // ===== Menu dipendente ===== ?>
        <nav class="fd-dock" aria-label="Menu">
            <a class="fd-nav fd-nav-home" style="left:13px;" href="<?= $baseUrl ?>/employee/" title="Home" aria-current="page">
                <img class="ic" src="<?= $__img ?>/nav-home.svg" alt="Home">
                <span class="fd-nav-dot"></span>
            </a>
            <a class="fd-nav is-dim" style="left:79px;" href="<?= $baseUrl ?>/employee/leave-requests.php" title="Ferie">
                <img class="ic" src="<?= $__img ?>/nav-ferie.svg" alt="Ferie">
            </a>
            <a class="fd-nav is-dim" style="left:146px;" href="<?= $baseUrl ?>/employee/leave-requests.php?action=new&type=permesso" title="Permessi">
                <img class="ic" src="<?= $__img ?>/nav-permessi.svg" alt="Permessi">
            </a>
            <a class="fd-nav is-dim" style="left:213px;" href="<?= $baseUrl ?>/employee/documents.php" title="Documenti">
                <img class="ic" src="<?= $__img ?>/nav-documenti.svg" alt="Documenti">
            </a>
            <a class="fd-nav is-dim" style="left:280px;" href="<?= $baseUrl ?>/employee/communications.php" title="Comunicazioni">
                <img class="ic" src="<?= $__img ?>/nav-comunicazioni.svg" alt="Comunicazioni">
            </a>
            <span class="fd-nav is-off" style="left:347px;" aria-disabled="true" title="Chat">
                <img class="ic" src="<?= $__img ?>/nav-chat.svg" alt="Chat">
            </span>
            <a class="fd-nav fd-nav-avatar" style="left:414px;" href="<?= $baseUrl ?>/employee/profile.php" title="<?= $fdName ?>">
                <img class="bg" src="<?= $__img ?>/nav-profilo-bg.svg" alt="">
                <?php if ($fdPhoto): ?><img class="ph" src="<?= htmlspecialchars($fdPhoto) ?>" alt="<?= $fdName ?>"><?php endif; ?>
            </a>
            <a class="fd-nav" style="left:481px;" href="<?= $baseUrl ?>/auth/logout.php" title="Esci">
                <img class="ic" src="<?= $__img ?>/nav-esci.svg" alt="Esci">
            </a>
        </nav>
    </div>
</div>
<script>
(function () {
    var stage = document.getElementById('fdStage');
    var fits = document.querySelectorAll('.fd-fit');
    var dock = document.querySelector('.fd-dock');
    function layout() {
        var w = document.documentElement.clientWidth || window.innerWidth;
        if (w >= 900) {
            stage.style.zoom = w < 1408 ? (w / 1408) : '';
            fits.forEach(function (el) { el.style.zoom = ''; });
            dock.style.zoom = '';
        } else {
            stage.style.zoom = '';
            var avail = w - 24 - 28;
            fits.forEach(function (el) {
                el.style.zoom = Math.min(1, avail / parseFloat(el.getAttribute('data-w')));
            });
            dock.style.zoom = Math.min(1, (w - 24) / 560);
        }
    }
    layout();
    window.addEventListener('resize', layout);
})();
</script>
</body>
</html>
