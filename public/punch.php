<?php
/**
 * Endpoint timbratura NFC.
 * URL da scrivere su carta NTAG215: https://hr.connecteed.com/punch.php
 *
 * Flusso:
 *  - Se il dipendente è loggato: registra timbratura toggle (in/out) e mostra conferma
 *  - Se NON è loggato: redirect a login con return=/punch.php
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::init();
setSecurityHeaders();

// Eventuale slug azienda (per URL tenant-specifica scritta sulla carta).
// Se mancante o "any", funziona generico in base alla sessione del dipendente.
$companySlug = trim($_GET['c'] ?? '');

if (!Auth::isEmployeeLoggedIn()) {
    $return = '/punch.php' . ($companySlug !== '' ? '?c=' . urlencode($companySlug) : '');
    header('Location: ' . PUBLIC_URL . '/auth/login.php?return=' . urlencode($return));
    exit;
}

$employee = Auth::getEmployee();
$employeeId = (int) $employee['id'];

// Se passata una slug azienda, verifica che il dipendente appartenga a quella tenant
$companyMismatch = false;
$companyName = '';
if ($companySlug !== '') {
    $slugLower = mb_strtolower($companySlug);
    $comp = Database::fetchOne("SELECT id, name, slug FROM companies WHERE LOWER(slug) = ?", [$slugLower]);
    if (!$comp) {
        // Fallback: lo slug potrebbe non essere ancora stato salvato sul DB. Verifica
        // se il nome dell'azienda del dipendente, slug-ificato, corrisponde al param.
        $empComp = Database::fetchOne(
            "SELECT id, name, slug FROM companies WHERE id = ?",
            [(int) $employee['company_id']]
        );
        if ($empComp) {
            $autoSlug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($empComp['name'], 'UTF-8'));
            $autoSlug = trim($autoSlug, '-');
            if ($autoSlug !== '' && $autoSlug === $slugLower) {
                Database::update('companies', ['slug' => $autoSlug], 'id = ?', [(int) $empComp['id']]);
                $comp = ['id' => (int) $empComp['id'], 'name' => $empComp['name'], 'slug' => $autoSlug];
            }
        }
    }
    if (!$comp) {
        $companyMismatch = true;
        $companyName = $companySlug;
    } else {
        $companyName = $comp['name'];
        if ((int) $comp['id'] !== (int) $employee['company_id']) {
            $companyMismatch = true;
        }
    }
}

if ($companyMismatch) {
    $result = [
        'success' => false,
        'error'   => 'Questa carta non appartiene alla tua azienda (' . htmlspecialchars($companyName) . '). Usa la carta corretta o contatta l\'amministratore.',
    ];
} else {
    $result = AttendancePunch::record($employeeId, 'nfc');
}
$summary = AttendancePunch::todaySummary($employeeId);
$totalH = floor($summary['total_seconds'] / 3600);
$totalM = floor(($summary['total_seconds'] % 3600) / 60);

$ok       = !empty($result['success']);
$cooldown = !empty($result['cooldown']);
$kind     = $result['kind'] ?? ($cooldown ? $result['last_kind'] : null);
$kindLbl  = $kind === 'in' ? 'Entrata' : ($kind === 'out' ? 'Uscita' : 'Timbratura');
$at       = $result['punch_at'] ?? ($cooldown ? $result['last_at'] : date('Y-m-d H:i:s'));
$timeFmt  = $at ? date('H:i:s', strtotime($at)) : '';
$dateFmt  = $at ? date('d/m/Y', strtotime($at)) : '';
$err      = $result['error'] ?? '';
$baseUrl  = PUBLIC_URL;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $ok ? "Timbrata $kindLbl alle $timeFmt" : 'Timbratura' ?> · ConnecteedHR</title>
<link rel="stylesheet" href="https://rsms.me/inter/inter.css">
<style>
:root {
    --navy: #0b3aa4;
    --navy-dark: #082b7b;
    --green: #16a34a;
    --orange: #d97706;
    --red: #b91c1c;
    --muted: #6e7191;
    --line: #e6e8f0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: linear-gradient(135deg, #f8fafc, #eef2ff);
    min-height: 100vh; min-height: 100dvh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    color: #0f172a;
}
.card {
    background: white;
    border-radius: 20px;
    padding: 32px 28px;
    max-width: 420px; width: 100%;
    box-shadow: 0 24px 64px rgba(15,23,42,0.12);
    text-align: center;
}
.status-ic {
    width: 96px; height: 96px;
    margin: 0 auto 18px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(22,163,74,0.10); color: var(--green);
}
.status-ic.is-out { background: rgba(217,119,6,0.10); color: var(--orange); }
.status-ic.is-err { background: rgba(185,28,28,0.10); color: var(--red); }
.status-ic svg { width: 48px; height: 48px; }

.kind-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    background: rgba(22,163,74,0.10); color: var(--green);
    margin-bottom: 14px;
}
.kind-badge.is-out { background: rgba(217,119,6,0.10); color: var(--orange); }

h1 {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 22px; font-weight: 700;
    color: var(--navy);
    margin-bottom: 6px;
    letter-spacing: -0.02em;
}
.time {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 44px; font-weight: 700;
    color: #0f172a; letter-spacing: -0.03em;
    line-height: 1.1;
    margin: 14px 0 6px;
}
.date { color: var(--muted); font-size: 13px; }
.employee {
    margin-top: 14px;
    font-size: 14px; color: #0f172a;
    font-weight: 600;
}

.summary {
    margin-top: 24px; padding-top: 20px;
    border-top: 1px solid var(--line);
    text-align: left;
}
.summary h3 {
    font-size: 11px; font-weight: 700; color: var(--navy);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 12px;
}
.summary-stats {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    margin-bottom: 14px;
}
.stat {
    background: #f8fafc; border-radius: 10px;
    padding: 10px 12px;
}
.stat .l { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; }
.stat .v {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 18px; font-weight: 700; color: #0f172a;
    margin-top: 2px; font-variant-numeric: tabular-nums;
}
.punches { display: flex; flex-direction: column; gap: 6px; }
.punch-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px;
    background: #f8fafc; border-radius: 8px;
    font-size: 12.5px;
}
.punch-row .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.punch-row .dot.in  { background: var(--green); }
.punch-row .dot.out { background: var(--orange); }
.punch-row .k { font-weight: 600; color: #0f172a; width: 60px; text-transform: uppercase; font-size: 10.5px; letter-spacing: 0.04em; }
.punch-row .t { color: var(--muted); font-variant-numeric: tabular-nums; margin-left: auto; }

.actions { margin-top: 22px; display: flex; gap: 8px; }
.btn {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 11px 18px;
    border: 1px solid var(--line);
    background: white; color: #475569;
    border-radius: 10px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    text-decoration: none;
    cursor: pointer;
}
.btn-primary { background: var(--navy); color: white; border-color: var(--navy); }
.btn-primary:hover { background: var(--navy-dark); }
.btn:hover { border-color: var(--navy); color: var(--navy); }

.err { color: var(--red); font-size: 13.5px; margin-bottom: 14px; }
</style>
</head>
<body>
<div class="card">
    <?php if ($ok): ?>
        <div class="status-ic <?= $kind === 'out' ? 'is-out' : '' ?>">
            <?php if ($kind === 'in'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <?php endif; ?>
        </div>
        <span class="kind-badge <?= $kind === 'out' ? 'is-out' : '' ?>"><?= $kindLbl ?> registrata</span>
        <div class="time"><?= $timeFmt ?></div>
        <div class="date"><?= $dateFmt ?></div>
        <div class="employee"><?= htmlspecialchars($result['employee']) ?></div>

        <div class="summary">
            <h3>Oggi</h3>
            <div class="summary-stats">
                <div class="stat"><div class="l">Ore lavorate</div><div class="v"><?= sprintf('%dh %02d', $totalH, $totalM) ?></div></div>
                <div class="stat"><div class="l">Stato</div><div class="v" style="color: <?= $summary['is_open'] ? 'var(--green)' : 'var(--muted)' ?>;"><?= $summary['is_open'] ? 'IN sede' : 'Fuori' ?></div></div>
            </div>
            <?php if (!empty($summary['punches'])): ?>
                <div class="punches">
                    <?php foreach ($summary['punches'] as $p): ?>
                        <div class="punch-row">
                            <span class="dot <?= $p['kind'] ?>"></span>
                            <span class="k"><?= $p['kind'] === 'in' ? 'Entrata' : 'Uscita' ?></span>
                            <span class="t"><?= date('H:i', strtotime($p['punch_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="status-ic <?= $cooldown ? 'is-out' : 'is-err' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h1>Timbratura non registrata</h1>
        <div class="err"><?= htmlspecialchars($err) ?></div>
        <?php if ($cooldown && !empty($result['remaining'])): ?>
            <p style="color:var(--muted); font-size:13px;">Riprova fra <?= (int) $result['remaining'] ?> secondi.</p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="actions">
        <a class="btn" href="<?= htmlspecialchars($baseUrl) ?>/employee/">Home</a>
        <a class="btn btn-primary" href="<?= htmlspecialchars($baseUrl) ?>/punch.php">Riprova timbratura</a>
    </div>
</div>
</body>
</html>
