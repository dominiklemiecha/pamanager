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

$fdComms = array_slice($communications, 0, 3);

// Le tue ultime richieste: il box occupa due righe, ne entrano fino a 6
$fdLeaves = [];
try {
    $fdLeaves = Database::fetchAll(
        "SELECT id, leave_type, start_date, end_date, is_full_day, status, created_at
         FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 6",
        [(int)$employee['id']]
    );
} catch (Throwable $__e) {}

// Presenze della settimana (lun-ven): assenti = assenza approvata nel giorno, smart working escluso
$fdWeek = [];
$__monday = new DateTime('monday this week');
$__friday = (clone $__monday)->modify('+4 days');
$__absByDay = [];
$fdWeekAbsentIds = [];
$__peopleById = [];
foreach ($fdPeople as $__pp) $__peopleById[(int)$__pp['id']] = $__pp;
// Privacy L.104: il dipendente vede "Permesso" sulle assenze altrui (come nella heatmap)
$__typeNames = ['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia', 'permesso_104' => 'Permesso L.104',
                'congedo_parentale' => 'Congedo parentale', 'congedo_separazione' => 'Congedo', 'congedo_mestruale' => 'Congedo',
                'altro' => 'Assenza', 'chiusura' => 'Chiusura'];
try {
    $__rows = Database::fetchAll(
        "SELECT lr.employee_id, lr.start_date, lr.end_date, lr.leave_type, lr.is_full_day, lr.start_time, lr.end_time
         FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
         WHERE e.company_id = ? AND e.is_active = TRUE
           AND lr.status = 'approved' AND lr.leave_type <> 'smart_working'
           AND lr.start_date <= ? AND lr.end_date >= ?",
        [(int)$employee['company_id'], $__friday->format('Y-m-d'), $__monday->format('Y-m-d')]
    );
    foreach ($__rows as $__r) {
        for ($__d = clone $__monday; $__d <= $__friday; $__d->modify('+1 day')) {
            $__ymd = $__d->format('Y-m-d');
            if ($__r['start_date'] > $__ymd || $__r['end_date'] < $__ymd) continue;
            $__eid = (int)$__r['employee_id'];
            $__t = $__r['leave_type'];
            if ($__t === 'permesso_104' && $__eid !== (int)$employee['id']) $__t = 'permesso';
            $__lbl = $__typeNames[$__t] ?? 'Assenza';
            if ((int)$__r['is_full_day'] !== 1 && !empty($__r['start_time']) && !empty($__r['end_time'])) {
                $__lbl .= ' ' . substr($__r['start_time'], 0, 5) . '–' . substr($__r['end_time'], 0, 5);
            }
            $__absByDay[$__ymd][$__eid] = $__lbl;
            $fdWeekAbsentIds[$__eid] = true;
        }
    }
} catch (Throwable $__e) {}
$__dayLbl = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven'];
$__dayFull = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì'];
for ($__i = 0, $__d = clone $__monday; $__i < 5; $__i++, $__d->modify('+1 day')) {
    $__ymd = $__d->format('Y-m-d');
    $__abs = isset($__absByDay[$__ymd]) ? count($__absByDay[$__ymd]) : 0;
    $__people = [];
    foreach ($__absByDay[$__ymd] ?? [] as $__eid => $__lbl) {
        $__pp = $__peopleById[$__eid] ?? null;
        if (!$__pp) continue;
        $__people[] = [
            'name'     => trim($__pp['first_name'] . ' ' . $__pp['last_name']),
            'initials' => mb_strtoupper(mb_substr($__pp['first_name'], 0, 1) . mb_substr($__pp['last_name'], 0, 1)),
            'photo'    => !empty($__pp['photo_path']) ? $baseUrl . '/' . ltrim($__pp['photo_path'], '/') : '',
            'type'     => $__lbl,
        ];
    }
    usort($__people, static fn($a, $b) => strcmp($a['name'], $b['name']));
    $fdWeek[] = [
        'full'    => $__dayFull[$__i] . ' ' . (int)$__d->format('j') . ' ' . $fdMonths[(int)$__d->format('n')],
        'people'  => $__people,
        'label'   => $__dayLbl[$__i],
        'dayNum'  => (int)$__d->format('j') . ' ' . $fdMonths[(int)$__d->format('n')],
        'present' => max(0, count($fdPeople) - $__abs),
        'absent'  => $__abs,
        'today'   => $__ymd === $fdToday,
        'holiday' => class_exists('ItalianHolidays') ? ItalianHolidays::nameFor($__ymd) : null,
    ];
}
$fdWeekRange = (int)$__monday->format('j') . ' ' . $fdMonths[(int)$__monday->format('n')] . ' – '
             . (int)$__friday->format('j') . ' ' . $fdMonths[(int)$__friday->format('n')] . ' ' . $__friday->format('Y');
// Avatar del box: prima chi e' assente almeno un giorno della settimana
$fdWeekAvatars = $fdPeople;
usort($fdWeekAvatars, static function ($a, $b) use ($fdWeekAbsentIds) {
    $x = empty($fdWeekAbsentIds[(int)$a['id']]) ? 1 : 0;
    $y = empty($fdWeekAbsentIds[(int)$b['id']]) ? 1 : 0;
    return $x <=> $y ?: strcmp($a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name']);
});
$fdWeekAvatars = array_slice($fdWeekAvatars, 0, 7);

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
    <meta name="theme-color" content="#e9ece6">
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
    background: #fff;
}
body::before {
    content: ''; position: fixed; z-index: -1;
    /* Sfondo Figma (nodo 43:467): immagine sfocata 32px, opacita' 0.7 x 0.5 su bianco;
       allargata oltre i bordi perche' la sfocatura non schiarisca i margini */
    inset: -64px;
    background: url('<?= $__img ?>/bg-sfondo.jpg') center / cover no-repeat;
    filter: blur(32px);
    opacity: 0.35;
}
a { color: inherit; text-decoration: none; }
p { margin: 0; }
img { display: block; max-width: none; }

/* Desktop: il pannello riempie la finestra con margini uguali (24px) su tutti i lati.
   Righe ad altezza fissa del design, colonne in proporzione al Figma che si allargano
   con la finestra; lo stage e' scalato via JS (zoom) in base allo spazio disponibile. */
.fd-stage { position: relative; width: 1408px; height: 876px; margin: 0 auto; }
.fd-panel {
    position: absolute; inset: 20px;
    border-radius: 18px;
    padding: 8px 38px 8px;
    display: flex; flex-direction: column; gap: 12px;
}
/* Griglia approvata (variante 1, proporzioni Figma 43-467):
   Ferie | Permessi | Richiedi ferie + Richiedi permesso
   Presenze oggi | Presenze settimana | Le tue ultime richieste (su due righe)
   Documenti | Comunicazioni | (richieste) */
.fd-main {
    flex: 1; min-width: 0; min-height: 0; display: grid; gap: 24px;
    grid-template-columns: 454fr 464fr 358fr;
    grid-template-rows: 273px 116px minmax(299px, 1fr);
    grid-template-areas: "fer per act" "po ps req" "doc com req";
}
.fd-main .fd-fit { position: relative; min-width: 0; }
.fd-main .fd-card { position: relative; width: 100%; height: 100%; }
.fd-stack { grid-area: act; display: grid; grid-template-rows: 1fr 1fr; gap: 24px; min-width: 0; min-height: 0; }
.fa-fer { grid-area: fer; } .fa-per { grid-area: per; } .fa-po { grid-area: po; } .fa-ps { grid-area: ps; }
.fa-req { grid-area: req; } .fa-doc { grid-area: doc; } .fa-com { grid-area: com; }
/* Presenze della settimana: una barra per giorno lavorativo, rosso = quota di assenti */
.fd-week-days { position: absolute; left: 20px; right: 20px; top: 42px; bottom: 12px; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
.fd-wd {
    font: inherit; color: inherit; cursor: pointer; min-width: 0;
    border: 0; border-radius: 14px; padding: 7px 6px 6px;
    background: rgba(197,237,198,0.35);
    display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 4px;
    transition: background-color .2s ease;
}
.fd-wd:hover, .fd-wd:focus-visible { background: rgba(255,148,0,0.12); outline: none; }
.fd-wd.is-today { box-shadow: inset 0 0 0 2px #ff9400; }
.fd-wd.is-holiday { background: #efefef; }
.fd-wd-l { font-size: 12px; color: #6b666b; white-space: nowrap; line-height: 1; }
.fd-wd-l em { font-style: normal; color: #111; }
.fd-wd-note { font-size: 12px; color: #6b666b; line-height: 26px; }
.fd-wd-note.is-ok { color: #2e7d32; }
.fd-wd-avs { display: flex; }
.fd-wd-av {
    width: 26px; height: 26px; flex: none; border-radius: 50%; overflow: hidden; position: relative;
    background: #e3e3e3; display: grid; place-items: center; font-size: 10px; color: #4c4c4c;
    box-shadow: 0 0 0 2px #fff;
}
.fd-wd-av + .fd-wd-av { margin-left: -9px; }
.fd-wd-av img { width: 26px; height: 26px; object-fit: cover; }
.fd-wd-av.is-more { background: #ff4949; color: #fff; }
/* Modal dettaglio settimana */
.fd-modal { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 16px; background: rgba(17,17,17,0.35); backdrop-filter: blur(4px); font-family: 'Urbanist', sans-serif; }
.fd-modal[hidden] { display: none; }
.fd-modal-box { min-width: 0; width: min(640px, 100%); max-height: calc(100vh - 32px); overflow: auto; background: #fff; border-radius: 28px; padding: 24px 28px 28px; box-shadow: 0 20px 60px rgba(0,0,0,0.18); }
.fd-modal-h { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
.fd-modal-h h2 { margin: 0; font-size: 23px; color: #111; }
.fd-modal-h p { margin: 4px 0 0; font-size: 14px; color: #6b666b; }
.fd-modal-x { flex: none; width: 40px; height: 40px; border-radius: 50%; border: 0; background: rgba(240,240,240,0.9); color: #6d6d6d; display: grid; place-items: center; cursor: pointer; transition: color .2s ease, background-color .2s ease; }
.fd-modal-x:hover, .fd-modal-x:focus-visible { color: #ff9400; background: rgba(255,148,0,0.12); outline: none; }
.fd-modal-body { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
.fd-md[hidden] { display: none; }
.fd-md { min-width: 0; border: 1px solid #ececec; border-radius: 18px; padding: 14px 16px; }
.fd-md.is-today { border: 2px solid #ff9400; }
.fd-md-h { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fd-md-h strong { font-size: 16px; color: #111; }
.fd-md-today { font-size: 12px; background: #fff1d6; color: #8a5a00; border-radius: 6px; padding: 2px 8px; }
.fd-md-cnt { margin-left: auto; font-size: 13px; color: #6b666b; }
.fd-md-note { margin: 8px 0 0; font-size: 14px; color: #6b666b; }
.fd-md-list { list-style: none; margin: 10px 0 0; padding: 0; display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; }
.fd-md-list li { display: flex; align-items: center; gap: 12px; min-width: 0; }
/* il giorno e' gia' nel titolo del modal */
.fd-md-h strong { display: none; }
.fd-md-av { width: 36px; height: 36px; flex: none; border-radius: 50%; overflow: hidden; background: #e3e3e3; display: grid; place-items: center; font-size: 13px; color: #4c4c4c; box-shadow: 0 0 0 2px #fff, 0 0 0 3px #ff4949; }
.fd-md-av img { width: 36px; height: 36px; object-fit: cover; }
.fd-md-name { font-size: 15px; color: #111; min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fd-md-type { flex: none; font-size: 12px; background: rgba(255,148,0,0.12); color: #8a5a00; border-radius: 8px; padding: 5px 10px; }
/* Lato del menu (scelto trascinandolo, come nel CRM): sotto di default */
.fd-panel.dock-top { flex-direction: column-reverse; }
.fd-panel.dock-left { flex-direction: row-reverse; }
.fd-panel.dock-right { flex-direction: row; }
.fd-card {
    position: absolute; overflow: hidden; display: block;
    background: rgba(255,255,255,0.84);
    border: 1px solid rgba(255,255,255,0.85);
    border-radius: 28px;
}
.fd-abs { position: absolute; white-space: nowrap; line-height: normal; }

/* Saldi */
.fd-widget { height: 273px; }
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
.fd-watermark { position: absolute; right: -68.62px; top: -60px; width: 277.62px; height: 286.342px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.fd-permessi .fd-watermark { right: -66.62px; }
.fd-watermark img { width: 220px; height: 233px; transform: rotate(16.68deg); flex: none; }

/* Azioni */
.fd-row2 { height: 118px; }
.fd-act-title { left: 28px; top: 13px; font-size: 21px; color: #111; }
.fd-act-sub { left: 28px; top: 51px; font-size: 16px; color: #69636a; }
.fd-act-btn { position: absolute; width: 54px; height: 54px; }
.fd-act-btn .bg { position: absolute; inset: 0; width: 54px; height: 54px; }
.fd-act-btn .ic { position: absolute; left: 10px; top: 9px; width: 34px; height: 35px; color: #6d6d6d; transform: rotate(0deg); transition: transform .6s ease-in-out, color .3s ease; }
/* Hover sul box: niente sollevamento, l'icona del pulsante gira e diventa arancione */
.fd-card.is-link:hover .fd-act-btn .ic, .fd-card.is-link:focus-visible .fd-act-btn .ic { transform: rotate(90deg); color: #ff9400; }

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
.fd-legend { position: absolute; left: calc(100% - 115px); top: 0; bottom: 0; right: 0; }
.fd-legend-dot { position: absolute; width: 13px; height: 13px; }
.fd-legend-txt { font-size: 15px; color: #111; }

/* Row 3 */
.fd-row3 { height: 299px; }
.fd-h21 { left: 24px; top: 18px; font-size: 21px; color: #111; }
.fd-comm-box {
    position: absolute; left: 24px; right: 22px; height: 103px; overflow: hidden;
    background: #efefef; border-radius: 4px; display: block;
}
.fd-comm-box .wm { position: absolute; right: -62.12px; top: -50px; width: 175.124px; height: 180.625px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.fd-comm-box .wm img { width: 138.777px; height: 146.977px; transform: rotate(16.68deg); flex: none; }
.fd-comm-t { position: absolute; left: 16px; right: 16px; top: 26px; text-align: center; font-size: 20px; color: #4c4c4c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-comm-s { position: absolute; left: 16px; right: 16px; top: 60px; text-align: center; font-size: 14px; color: #898589; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* Comunicazioni presenti: righe semplici (il box grigio resta solo per lo stato vuoto) */
.fd-comm-item { position: absolute; left: 24px; right: 20px; height: 58px; display: block; }
.fd-comm-item .t { position: absolute; left: 0; right: 0; top: 9px; font-size: 15px; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-comm-item .d { position: absolute; left: 0; top: 35px; font-size: 14px; color: #6b666b; white-space: nowrap; }

.fd-more { right: 20px; top: 21px; font-size: 16px; color: #6b666b; white-space: pre; }
.fd-doc { position: absolute; left: 24px; right: 20px; height: 58px; display: block; }
.fd-doc-ic {
    position: absolute; left: 0; top: 0; width: 58px; height: 58px;
    background: #fff; border: 2px solid #ff9400; border-radius: 15px;
}
.fd-doc-ic svg { position: absolute; left: 12px; top: 12px; width: 30px; height: 30px; }
.fd-doc-t { position: absolute; left: 76px; right: 0; top: 9px; font-size: 15px; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fd-doc-d { position: absolute; left: 76px; top: 35px; font-size: 14px; color: #6b666b; white-space: nowrap; }
.fd-none { position: absolute; left: 24px; top: 70px; font-size: 15px; color: #6b666b; }

.fd-req { position: absolute; left: 23px; right: 20px; height: 45px; }
.fd-req-ic {
    position: absolute; left: 0; top: 0; width: 44px; height: 45px;
    background: #fff; border: 2px solid #ff9400; border-radius: 12px;
}
.fd-req-ic svg { position: absolute; left: 8px; top: 8px; width: 24px; height: 24px; }
/* Hover righe documenti/richieste: fondo arancio leggero + icona piena (proposta A) */
.fd-doc, .fd-req { isolation: isolate; outline: none; }
.fd-doc::before, .fd-req::before {
    content: ''; position: absolute; inset: -6px; z-index: -1; border-radius: 14px;
    background: transparent; transition: background-color .2s ease;
}
.fd-req::before { inset: -5px -6px; }
.fd-doc-ic, .fd-req-ic { color: #ff9400; transition: background-color .2s ease, color .2s ease; }
.fd-doc:hover::before, .fd-req:hover::before,
.fd-doc:focus-visible::before, .fd-req:focus-visible::before { background: rgba(255,148,0,0.09); }
.fd-doc:hover .fd-doc-ic, .fd-req:hover .fd-req-ic,
.fd-doc:focus-visible .fd-doc-ic, .fd-req:focus-visible .fd-req-ic { background: #ff9400; color: #fff; }
.fd-doc:focus-visible::before, .fd-req:focus-visible::before { box-shadow: 0 0 0 2px #ff9400; }
.fd-req-t { position: absolute; left: 61px; top: 3px; font-size: 15px; color: #111; white-space: nowrap; }
.fd-req-s { position: absolute; left: 61px; top: 26px; font-size: 12px; color: #666166; white-space: nowrap; }
.fd-pill {
    position: absolute; right: 0; top: 2px; width: 112px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: #111;
}

/* Menu dock */
.fd-dock-wrap { position: relative; flex: none; align-self: center; width: 580px; height: 72px; }
.dock-left .fd-dock-wrap, .dock-right .fd-dock-wrap { width: 72px; height: 580px; }
.fd-dock {
    position: absolute; left: 0; top: 0;
    width: 580px; height: 72px; background: #fff;
    border: 1px solid #e2eae9; border-radius: 28px;
    box-shadow: inset 0 1px 1px 0 rgba(0,0,0,0.1), inset 0 -1px 1px 0 rgba(255,255,255,0.8);
}
.dock-left .fd-dock, .dock-right .fd-dock { left: -254px; top: 254px; transform: rotate(90deg); }
.dock-left .fd-nav, .dock-right .fd-nav { transform: rotate(-90deg); }
.fd-dock-items { position: absolute; left: 20px; top: 0; width: 560px; height: 72px; }
.fd-grip {
    position: absolute; left: 6px; top: 16px; width: 16px; height: 40px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: rgba(17,17,17,0.25); cursor: grab; touch-action: none;
}
.fd-grip:hover { color: rgba(17,17,17,0.6); background: rgba(234,234,234,0.7); }
.fd-dock-wrap.is-dragging { z-index: 60; }
.fd-dock-wrap.is-dragging .fd-dock { opacity: .9; box-shadow: 0 8px 24px rgba(0,0,0,0.18); cursor: grabbing; }
.fd-dock-wrap.is-dragging .fd-grip { cursor: grabbing; }
.fd-drop { position: fixed; inset: 0; z-index: 50; pointer-events: none; }
.fd-drop-slot {
    position: absolute; border-radius: 20px; border: 2px dashed rgba(17,17,17,0.2);
    background: rgba(255,255,255,0.45); transition: background-color .15s ease, border-color .15s ease;
}
.fd-drop-slot.is-on { border-color: #ff9400; background: rgba(255,148,0,0.16); }
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
    .fd-stage { width: auto !important; height: auto !important; padding: 12px 12px 110px; }
    .fd-panel { position: static; padding: 14px; gap: 14px; }
    .fd-main, .fd-stack { display: contents; }
    .fd-fit { position: relative; }
    .fd-main .fd-card.fd-ferie { width: 454px; } .fd-main .fd-card.fd-permessi { width: 464px; }
    .fd-main .fd-card.fd-act-ferie, .fd-main .fd-card.fd-act-perm { width: 358px; } .fd-main .fd-card.fd-presenze { width: 434px; }
    .fd-main .fd-card.fd-week { width: 449px; } .fd-main .fd-card.fd-comm { width: 435px; } .fd-main .fd-card.fd-docs { width: 387px; }
    .fd-main .fd-card.fd-reqs { width: 433px; }
    .fd-main .fd-card.fd-widget { height: 273px; } .fd-main .fd-card.fd-row2 { height: 118px; } .fd-main .fd-card.fd-row3 { height: 299px; }
    .fd-main .fd-card.fd-row3.fd-reqs { height: 420px; }
    .fd-dock-wrap { position: fixed; bottom: 14px; left: 0; right: 0; margin: 0 auto; z-index: 10; width: 560px; height: 72px; }
    .fd-dock { width: 560px; }
    .fd-dock-items { left: 0; }
    .fd-grip { display: none; }
}
</style>
</head>
<body>
<div class="fd-stage" id="fdStage">
    <div class="fd-panel" id="fdPanel">
        <div class="fd-main">

        <?php // ===== Ferie =====
        $__f = $fdBal['ferie']; ?>
        <div class="fd-fit fa-fer" data-w="454"><div class="fd-card fd-widget fd-ferie">
            <p class="fd-abs fd-h23" style="left:86px;top:20px;">Ferie <?= (int)$__year ?></p>
            <div class="fd-watermark"><img src="<?= $__img ?>/watermark.svg" alt=""></div>
            <?php if ($__f['total'] > 0): ?>
                <div class="fd-ring">
                    <img class="fd-ring-bg" src="<?= $__img ?>/bg-segment.png" width="188" height="188" alt="">
                    <svg width="188" height="188" viewBox="0 0 188 188">
                        <defs>
                            <linearGradient id="fdFerieGrad" x1="0" y1="0" x2="0" y2="188" gradientUnits="userSpaceOnUse">
                                <stop offset="0.120192" stop-color="white"/>
                                <stop offset="0.947115" stop-color="#FF9400"/>
                            </linearGradient>
                        </defs>
                        <circle cx="94" cy="94" r="<?= $fdR ?>" fill="none" stroke="url(#fdFerieGrad)" stroke-width="26.32" stroke-linecap="round"<?= $__f['pct'] > 0 ? '' : ' style="visibility:hidden"' ?>
                                class="fd-arc" data-pct="<?= round($__f['pct'], 4) ?>" data-circ="<?= round($fdCirc, 2) ?>"
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
        <div class="fd-fit fa-per" data-w="464"><div class="fd-card fd-widget fd-permessi">
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
                        <circle cx="94" cy="94" r="<?= $fdR ?>" fill="none" stroke="url(#fdPermGrad)" stroke-width="26.32" stroke-linecap="round"<?= $__p['pct'] > 0 ? '' : ' style="visibility:hidden"' ?>
                                class="fd-arc" data-pct="<?= round($__p['pct'], 4) ?>" data-circ="<?= round($fdCirc, 2) ?>"
                                stroke-dasharray="<?= round($fdCirc * $__p['pct'], 2) ?> <?= round($fdCirc, 2) ?>"
                                transform="rotate(-90 94 94)"/>
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

        <?php // Richiedi ferie e, sotto, Richiedi permesso: due box separati ?>
        <div class="fd-stack">
        <div class="fd-fit fa-rf" data-w="358"><a class="fd-card fd-row2 fd-act-ferie is-link" href="<?= $baseUrl ?>/employee/leave-requests.php?action=new&type=ferie">
            <p class="fd-abs fd-act-title">Richiedi ferie</p>
            <p class="fd-abs fd-act-sub">Crea una nuova richiesta</p>
            <span class="fd-act-btn" style="right:22px;top:28px;">
                <img class="bg" src="<?= $__img ?>/pulsante.svg" alt="">
                <svg class="ic" width="34" height="35" viewBox="0 0 34 35" fill="currentColor" aria-hidden="true"><path d="M19.1589 0V9.86759C19.1589 11.8611 18.5838 13.7296 17.5873 15.281C16.8663 16.4071 15.9266 17.3746 14.833 18.117C13.3262 19.143 11.5197 19.7352 9.57541 19.7352H0V15.281H14.3388C14.6143 15.281 14.833 15.0558 14.833 14.7722V0H19.1589Z"/><path d="M34 19.7273H19.6531C19.3776 19.7273 19.1589 19.9525 19.1589 20.2278V35H14.833V25.1407C14.833 23.1389 15.4081 21.2788 16.4046 19.7273C17.1256 18.6013 18.0653 17.6337 19.1589 16.8913C20.6657 15.8737 22.4722 15.2815 24.4165 15.2815H34C34 15.2815 33.919 19.6106 34 19.7357V19.7273Z"/></svg>
            </span>
        </a></div>

        <div class="fd-fit fa-rp" data-w="358"><a class="fd-card fd-row2 fd-act-perm is-link" href="<?= $baseUrl ?>/employee/leave-requests.php?action=new&type=permesso">
            <p class="fd-abs fd-act-title" style="left:30px;font-size:20px;">Richiedi permesso</p>
            <p class="fd-abs fd-act-sub" style="left:30px;top:52px;">Ore o intera giornata</p>
            <span class="fd-act-btn" style="right:16px;top:31px;">
                <img class="bg" src="<?= $__img ?>/pulsante.svg" alt="">
                <svg class="ic" width="34" height="35" viewBox="0 0 34 35" fill="currentColor" aria-hidden="true"><path d="M19.1589 0V9.86759C19.1589 11.8611 18.5838 13.7296 17.5873 15.281C16.8663 16.4071 15.9266 17.3746 14.833 18.117C13.3262 19.143 11.5197 19.7352 9.57541 19.7352H0V15.281H14.3388C14.6143 15.281 14.833 15.0558 14.833 14.7722V0H19.1589Z"/><path d="M34 19.7273H19.6531C19.3776 19.7273 19.1589 19.9525 19.1589 20.2278V35H14.833V25.1407C14.833 23.1389 15.4081 21.2788 16.4046 19.7273C17.1256 18.6013 18.0653 17.6337 19.1589 16.8913C20.6657 15.8737 22.4722 15.2815 24.4165 15.2815H34C34 15.2815 33.919 19.6106 34 19.7357V19.7273Z"/></svg>
            </span>
        </a></div>
        </div>

        <?php // ===== Presenze di oggi ===== ?>
        <div class="fd-fit fa-po" data-w="434"><div class="fd-card fd-row2 fd-presenze">
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
            <div class="fd-legend">
                <img class="fd-legend-dot" style="left:0;top:52px;" src="<?= $__img ?>/dot-disponibili.svg" alt="">
                <p class="fd-abs fd-legend-txt" style="left:22px;top:50px;"><?= (int)$fdAvailable ?> disp.</p>
                <img class="fd-legend-dot" style="left:0;top:83px;" src="<?= $__img ?>/dot-occupati.svg" alt="">
                <p class="fd-abs fd-legend-txt" style="left:22px;top:80px;"><?= (int)$fdAbsent ?> assent<?= $fdAbsent === 1 ? 'e' : 'i' ?></p>
            </div>
        </div></div>

        <?php // ===== Presenze della settimana: un riquadro per giorno con anteprima degli assenti, clic = dettaglio del giorno ===== ?>
        <div class="fd-fit fa-ps" data-w="449"><div class="fd-card fd-row2 fd-week">
            <p class="fd-abs" style="left:23px;top:8px;font-size:21px;color:#111;">Presenze della settimana</p>
            <div class="fd-week-days">
                <?php foreach ($fdWeek as $__di => $__wd):
                    $__tip = $__wd['full'] . ': ' . ($__wd['holiday'] ? $__wd['holiday'] : $__wd['present'] . ' presenti, ' . $__wd['absent'] . ' assent' . ($__wd['absent'] === 1 ? 'e' : 'i'));
                ?>
                    <button type="button" class="fd-wd<?= $__wd['today'] ? ' is-today' : '' ?><?= $__wd['holiday'] ? ' is-holiday' : '' ?>" data-day="<?= (int)$__di ?>" title="<?= htmlspecialchars($__tip) ?>" aria-haspopup="dialog" aria-controls="fdWeekModal">
                        <span class="fd-wd-l"><?= htmlspecialchars($__wd['label']) ?> <em><?= htmlspecialchars((string)(int)$__wd['dayNum']) ?></em></span>
                        <?php if ($__wd['holiday']): ?>
                            <span class="fd-wd-note">Festa</span>
                        <?php elseif (empty($__wd['people'])): ?>
                            <span class="fd-wd-note is-ok">Tutti</span>
                        <?php else: ?>
                            <span class="fd-wd-avs">
                                <?php foreach (array_slice($__wd['people'], 0, 3) as $__k => $__pp): ?>
                                    <span class="fd-wd-av" style="z-index:<?= 5 - $__k ?>;"><?php if ($__pp['photo']): ?><img src="<?= htmlspecialchars($__pp['photo']) ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars($__pp['initials']) ?><?php endif; ?></span>
                                <?php endforeach; ?>
                                <?php if (count($__wd['people']) > 3): ?><span class="fd-wd-av is-more">+<?= count($__wd['people']) - 3 ?></span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div></div>

        <?php // ===== Le tue ultime richieste ===== ?>
        <div class="fd-fit fa-req" data-w="433"><div class="fd-card fd-row3 fd-reqs">
            <p class="fd-abs fd-h21">Le tue ultime richieste</p>
            <?php if (empty($fdLeaves)): ?>
                <p class="fd-none">Nessuna richiesta inviata</p>
            <?php else: foreach ($fdLeaves as $__i => $__lr):
                $__st = $fdStatus[$__lr['status']] ?? ['#e6e6e6', strtoupper((string)$__lr['status'])];
                $__s = $fdDayMonth($__lr['start_date']);
                $__e = $fdDayMonth($__lr['end_date']);
                $__period = $__s === $__e ? $__s : $__s . ' – ' . $__e;
            ?>
                <a class="fd-req" style="top:<?= 60 + $__i * 58 ?>px;" href="<?= $baseUrl ?>/employee/leave-requests.php">
                    <span class="fd-req-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 5H5C3.89543 5 3 5.89543 3 7V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V7C21 5.89543 20.1046 5 19 5Z"/><path d="M7 3V7M17 3V7M3 10H21"/><path d="M9 16L11 18L15 14"/></svg></span>
                    <span class="fd-req-t"><?= htmlspecialchars($__typeLabel[$__lr['leave_type']] ?? ucfirst((string)$__lr['leave_type'])) ?></span>
                    <span class="fd-req-s"><?= htmlspecialchars($__period) ?> – creata <?= htmlspecialchars(emp_time_ago($__lr['created_at'])) ?></span>
                    <span class="fd-pill" style="background:<?= $__st[0] ?>;"><?= htmlspecialchars($__st[1]) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        <?php // ===== Documenti recenti ===== ?>
        <div class="fd-fit fa-doc" data-w="387"><div class="fd-card fd-row3 fd-docs">
            <p class="fd-abs fd-h21">Documenti recenti</p>
            <a class="fd-abs fd-more" href="<?= $baseUrl ?>/employee/documents.php"><?= 'Vedi tutti  →' ?></a>
            <?php if (empty($fdDocs)): ?>
                <p class="fd-none">Nessun documento disponibile</p>
            <?php else: foreach ($fdDocs as $__i => $__d): ?>
                <a class="fd-doc" style="top:<?= [61, 138, 214][$__i] ?>px;" href="<?= htmlspecialchars($__d['url']) ?>">
                    <span class="fd-doc-ic"><svg width="30" height="30" viewBox="0 0 30 30" fill="none" aria-hidden="true"><path d="M3.75 8.75C3.75 8.08696 4.01339 7.45107 4.48223 6.98223C4.95107 6.51339 5.58696 6.25 6.25 6.25H12.5L15 8.75H23.75C24.413 8.75 25.0489 9.01339 25.5178 9.48223C25.9866 9.95107 26.25 10.587 26.25 11.25V23.75C26.25 24.413 25.9866 25.0489 25.5178 25.5178C25.0489 25.9866 24.413 26.25 23.75 26.25H6.25C5.58696 26.25 4.95107 25.9866 4.48223 25.5178C4.01339 25.0489 3.75 24.413 3.75 23.75V8.75Z" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span class="fd-doc-t"><?= htmlspecialchars($__d['title']) ?></span>
                    <span class="fd-doc-d"><?= htmlspecialchars($fdDate($__d['date'])) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        <?php // ===== Comunicazioni ===== ?>
        <div class="fd-fit fa-com" data-w="435"><div class="fd-card fd-row3 fd-comm">
            <p class="fd-abs fd-h21">Comunicazioni</p>
            <?php if (empty($fdComms)): ?>
                <div class="fd-comm-box" style="top:93px;">
                    <div class="wm"><img src="<?= $__img ?>/watermark-small.svg" alt=""></div>
                    <p class="fd-comm-t">Nessuna comunicazione disponibile</p>
                    <p class="fd-comm-s">Qui troverai i nuovi aggiornamenti aziendali</p>
                </div>
            <?php else: foreach ($fdComms as $__i => $__c): ?>
                <a class="fd-doc" style="top:<?= [61, 138, 214][$__i] ?>px;" href="<?= $baseUrl ?>/employee/communications.php?id=<?= (int)$__c['id'] ?>">
                    <span class="fd-doc-ic"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 21H14M18 8C18 6.4087 17.3679 4.88258 16.2426 3.75736C15.1174 2.63214 13.5913 2 12 2C10.4087 2 8.88258 2.63214 7.75736 3.75736C6.63214 4.88258 6 6.4087 6 8C6 15 3 15 3 17H21C21 15 18 15 18 8Z"/></svg></span>
                    <span class="fd-doc-t"><?= htmlspecialchars($__c['title']) ?></span>
                    <span class="fd-doc-d"><?= htmlspecialchars($fdDate($__c['created_at'] ?? $__c['publish_date'] ?? null)) ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div></div>

        </div>

        <?php // ===== Menu dipendente (spostabile su un lato trascinandolo dalla maniglia) ===== ?>
        <div class="fd-dock-wrap" id="fdDockWrap">
        <nav class="fd-dock" aria-label="Menu">
            <span class="fd-grip" id="fdGrip" title="Trascina per spostare il menu" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/></svg>
            </span>
            <div class="fd-dock-items">
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
            </div>
        </nav>
        </div>
    </div>
</div>
<?php // Dettaglio presenze della settimana (fuori dallo stage per non essere scalato) ?>
<div class="fd-modal" id="fdWeekModal" role="dialog" aria-modal="true" aria-labelledby="fdWeekTitle" hidden>
    <div class="fd-modal-box">
        <div class="fd-modal-h">
            <div>
                <h2 id="fdWeekTitle">Presenze della settimana</h2>
                <p id="fdWeekSub">Settimana <?= htmlspecialchars($fdWeekRange) ?> · <?= count($fdPeople) ?> colleghi</p>
            </div>
            <button type="button" class="fd-modal-x" id="fdWeekClose" aria-label="Chiudi">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="fd-modal-body">
            <?php foreach ($fdWeek as $__mi => $__wd): ?>
                <section class="fd-md<?= $__wd['today'] ? ' is-today' : '' ?>" data-day="<?= (int)$__mi ?>" data-title="<?= htmlspecialchars($__wd['full']) ?>">
                    <div class="fd-md-h">
                        <strong><?= htmlspecialchars($__wd['full']) ?></strong>
                        <?php if ($__wd['today']): ?><span class="fd-md-today">Oggi</span><?php endif; ?>
                        <span class="fd-md-cnt"><?= $__wd['holiday'] ? 'Festivit&agrave;' : (int)$__wd['present'] . ' present' . ($__wd['present'] === 1 ? 'e' : 'i') . ' · ' . (int)$__wd['absent'] . ' assent' . ($__wd['absent'] === 1 ? 'e' : 'i') ?></span>
                    </div>
                    <?php if ($__wd['holiday']): ?>
                        <p class="fd-md-note"><?= htmlspecialchars($__wd['holiday']) ?></p>
                    <?php elseif (empty($__wd['people'])): ?>
                        <p class="fd-md-note">Tutti presenti</p>
                    <?php else: ?>
                        <ul class="fd-md-list">
                            <?php foreach ($__wd['people'] as $__pp): ?>
                                <li>
                                    <span class="fd-md-av"><?php if ($__pp['photo']): ?><img src="<?= htmlspecialchars($__pp['photo']) ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars($__pp['initials']) ?><?php endif; ?></span>
                                    <span class="fd-md-name"><?= htmlspecialchars($__pp['name']) ?></span>
                                    <span class="fd-md-type"><?= htmlspecialchars($__pp['type']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var stage = document.getElementById('fdStage');
    var fits = document.querySelectorAll('.fd-fit');
    var panel = document.getElementById('fdPanel');
    var dock = document.getElementById('fdDockWrap');
    var grip = document.getElementById('fdGrip');
    // Ingombro minimo del design (pannello + margini 24px) e limiti di scala:
    // sotto MIN_ZOOM i testi diventerebbero illeggibili e si lascia lo scroll.
    // Con il menu a lato la larghezza minima cresce e l'altezza minima cala (72 + 14 di spazio).
    var STAGE_W = 1408, STAGE_H = 876, DOCK_SPACE = 84, MIN_ZOOM = 0.75, MAX_ZOOM = 1.2;
    var SIDES = ['bottom', 'top', 'left', 'right'];
    var sideKey = 'fd_dock_side_<?= (int)$employee['id'] ?>';
    var side = 'bottom';
    try { var saved = localStorage.getItem(sideKey); if (SIDES.indexOf(saved) !== -1) side = saved; } catch (e) {}
    var lastW = 0, zoom = 1;
    function isVertical() { return side === 'left' || side === 'right'; }
    function layout() {
        var w = document.documentElement.clientWidth || window.innerWidth;
        lastW = w;
        SIDES.forEach(function (sd) { panel.classList.toggle('dock-' + sd, w >= 900 && sd === side); });
        if (w >= 900) {
            var STAGE_W = isVertical() ? 1408 + DOCK_SPACE : 1408;
            var STAGE_H = isVertical() ? 876 - DOCK_SPACE : 876;
            // Scala per stare sia in larghezza sia in altezza; sotto MIN_ZOOM i testi
            // diventerebbero illeggibili e si lascia lo scroll verticale.
            var h = window.innerHeight;
            var z = Math.min(MAX_ZOOM, w / STAGE_W, Math.max(h / STAGE_H, MIN_ZOOM));
            zoom = z;
            stage.style.zoom = String(z);
            stage.style.width = (w / z) + 'px';
            stage.style.height = Math.max(h / z, STAGE_H) + 'px';
            fits.forEach(function (el) { el.style.zoom = ''; });
            dock.style.zoom = '';
        } else {
            stage.style.zoom = '';
            stage.style.width = '';
            stage.style.height = '';
            var avail = w - 24 - 28;
            fits.forEach(function (el) {
                el.style.zoom = Math.min(1, avail / parseFloat(el.getAttribute('data-w')));
            });
            dock.style.zoom = Math.min(1, (w - 24) / 560);
            zoom = 1;
        }
    }
    layout();
    // La prima misura puo' includere la barra di scorrimento della pagina non ancora scalata
    if (document.documentElement.clientWidth !== lastW) layout();
    window.addEventListener('resize', layout);

    // Spostamento del menu: si prende dalla maniglia (o dal bordo), compaiono i quattro
    // posti possibili e al rilascio il menu va su quello piu' vicino. Il lato resta salvato.
    var drag = null, overlay = null;
    function slotRects() {
        var p = panel.getBoundingClientRect(), z = zoom;
        var hLen = 580 * z, th = 72 * z, vLen = Math.min(580 * z, p.height - 40 * z);
        return {
            bottom: { x: p.left + p.width / 2 - hLen / 2, y: p.bottom - (8 + 72) * z, w: hLen, h: th },
            top:    { x: p.left + p.width / 2 - hLen / 2, y: p.top + 8 * z, w: hLen, h: th },
            left:   { x: p.left + 38 * z, y: p.top + p.height / 2 - vLen / 2, w: th, h: vLen },
            right:  { x: p.right - (38 + 72) * z, y: p.top + p.height / 2 - vLen / 2, w: th, h: vLen }
        };
    }
    function nearest(x, y, rects) {
        var best = side, bestD = Infinity;
        SIDES.forEach(function (sd) {
            var r = rects[sd], d = Math.hypot(x - (r.x + r.w / 2), y - (r.y + r.h / 2));
            if (d < bestD) { bestD = d; best = sd; }
        });
        return best;
    }
    function showOverlay(rects) {
        overlay = document.createElement('div');
        overlay.className = 'fd-drop';
        SIDES.forEach(function (sd) {
            var r = rects[sd], el = document.createElement('div');
            el.className = 'fd-drop-slot';
            el.setAttribute('data-side', sd);
            el.style.cssText = 'left:' + r.x + 'px;top:' + r.y + 'px;width:' + r.w + 'px;height:' + r.h + 'px;';
            overlay.appendChild(el);
        });
        document.body.appendChild(overlay);
    }
    dock.addEventListener('pointerdown', function (e) {
        if (window.innerWidth < 900 || e.button !== 0 || e.target.closest('.fd-nav')) return;
        e.preventDefault();
        dock.setPointerCapture(e.pointerId);
        drag = { x: e.clientX, y: e.clientY, moving: false, zone: side };
    });
    dock.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
        if (!drag.moving) {
            if (Math.hypot(dx, dy) < 5) return;
            drag.moving = true;
            drag.rects = slotRects();
            showOverlay(drag.rects);
            dock.classList.add('is-dragging');
        }
        dock.style.transform = 'translate(' + (dx / zoom) + 'px,' + (dy / zoom) + 'px)';
        drag.zone = nearest(e.clientX, e.clientY, drag.rects);
        overlay.querySelectorAll('.fd-drop-slot').forEach(function (el) {
            el.classList.toggle('is-on', el.getAttribute('data-side') === drag.zone);
        });
    });
    function endDrag() {
        if (!drag) return;
        if (drag.moving && drag.zone !== side) {
            side = drag.zone;
            try { localStorage.setItem(sideKey, side); } catch (e) {}
            layout();
        }
        dock.style.transform = '';
        dock.classList.remove('is-dragging');
        if (overlay) { overlay.remove(); overlay = null; }
        drag = null;
    }
    // Modal presenze della settimana
    var weekModal = document.getElementById('fdWeekModal');
    var weekClose = document.getElementById('fdWeekClose');
    var weekTitle = document.getElementById('fdWeekTitle');
    var weekBtn = null;
    function openWeek(btn) {
        var day = btn.getAttribute('data-day');
        weekModal.querySelectorAll('.fd-md').forEach(function (sec) {
            var on = sec.getAttribute('data-day') === day;
            sec.hidden = !on;
            if (on) weekTitle.textContent = sec.getAttribute('data-title');
        });
        weekBtn = btn;
        weekModal.hidden = false;
        weekClose.focus();
    }
    function closeWeek() { weekModal.hidden = true; if (weekBtn) weekBtn.focus(); }
    document.querySelectorAll('.fd-wd').forEach(function (b) { b.addEventListener('click', function () { openWeek(b); }); });
    weekClose.addEventListener('click', closeWeek);
    weekModal.addEventListener('click', function (e) { if (e.target === weekModal) closeWeek(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !weekModal.hidden) closeWeek(); });

    dock.addEventListener('pointerup', endDrag);
    dock.addEventListener('pointercancel', endDrag);

    // Anelli saldi: all'accesso si riempiono fino al 100% e poi tornano al valore reale
    var arcs = document.querySelectorAll('.fd-arc');
    // Nessun blocco su prefers-reduced-motion: con le animazioni di Windows disattivate
    // il browser lo segnala e le animazioni (richieste esplicitamente) sparivano del tutto.
    var reduce = false;
    // L'arco degli utilizzati e' disegnato a segmenti: il colore sfuma lungo il percorso,
    // bianco all'inizio (ore 12) e arancione alla fine dell'arco, qualunque sia la lunghezza.
    var NS = 'http://www.w3.org/2000/svg', CX = 94, CY = 94, R = 80.84, W = 26.32;
    var C0 = [255, 255, 255], C1 = [255, 148, 0];
    function mix(t) {
        return 'rgb(' + C0.map(function (v, i) { return Math.round(v + (C1[i] - v) * t); }).join(',') + ')';
    }
    function pt(a) { return [CX + R * Math.cos(a), CY + R * Math.sin(a)]; }
    function setArc(el, frac) {
        if (!el._g) {
            el._g = document.createElementNS(NS, 'g');
            el.parentNode.insertBefore(el._g, el.nextSibling);
            el.style.display = 'none';
        }
        var g = el._g;
        while (g.firstChild) g.removeChild(g.firstChild);
        if (frac <= 0.001) return;
        var start = -Math.PI / 2, total = 2 * Math.PI * Math.min(frac, 1);
        var n = Math.max(2, Math.ceil(frac * 120)), step = total / n, overlap = 0.006;
        for (var i = 0; i < n; i++) {
            var a0 = start + i * step, a1 = Math.min(start + (i + 1) * step + overlap, start + total);
            var p0 = pt(a0), p1 = pt(a1), path = document.createElementNS(NS, 'path');
            path.setAttribute('d', 'M' + p0[0].toFixed(2) + ' ' + p0[1].toFixed(2) + ' A' + R + ' ' + R + ' 0 0 1 ' + p1[0].toFixed(2) + ' ' + p1[1].toFixed(2));
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke-width', W);
            path.setAttribute('stroke', mix((i + 0.5) / n));
            g.appendChild(path);
        }
        // estremita' arrotondate: bianca all'inizio, arancione alla fine
        [[start, mix(0)], [start + total, mix(1)]].forEach(function (cap) {
            var q = pt(cap[0]), dot = document.createElementNS(NS, 'circle');
            dot.setAttribute('cx', q[0].toFixed(2)); dot.setAttribute('cy', q[1].toFixed(2));
            dot.setAttribute('r', W / 2); dot.setAttribute('fill', cap[1]);
            g.appendChild(dot);
        });
        // la fine resta sopra l'inizio quando l'arco e' quasi pieno
        g.insertBefore(g.lastChild.previousSibling, g.firstChild);
    }
    arcs.forEach(function (el) { setArc(el, parseFloat(el.getAttribute('data-pct')) || 0); });
    if (!reduce && window.requestAnimationFrame) {
        var FILL = 1100, BACK = 900, PAUSE = 150, t0 = null;
        var easeInOut = function (t) { return t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
        var easeOut = function (t) { return 1 - Math.pow(1 - t, 3); };
        // I valori reali restano quelli renderizzati dal server finche' il primo frame
        // non parte davvero: se l'animazione non viene eseguita (scheda in background,
        // rAF sospeso) gli anelli non restano azzerati.
        var step = function (ts) {
            if (t0 === null) t0 = ts;
            var t = ts - t0;
            arcs.forEach(function (el) {
                var pct = parseFloat(el.getAttribute('data-pct')) || 0;
                if (t < FILL) setArc(el, easeInOut(t / FILL));
                else if (t < FILL + PAUSE) setArc(el, 1);
                else setArc(el, 1 - (1 - pct) * easeOut(Math.min(1, (t - FILL - PAUSE) / BACK)));
            });
            if (t < FILL + PAUSE + BACK) requestAnimationFrame(step);
        };
        var start = function () { requestAnimationFrame(step); };
        if (document.visibilityState === 'hidden') {
            var onVis = function () {
                if (document.visibilityState !== 'visible') return;
                document.removeEventListener('visibilitychange', onVis);
                start();
            };
            document.addEventListener('visibilitychange', onVis);
        } else {
            start();
        }
    } else {
        arcs.forEach(function (el) { setArc(el, parseFloat(el.getAttribute('data-pct')) || 0); });
    }
})();
</script>
</body>
</html>
