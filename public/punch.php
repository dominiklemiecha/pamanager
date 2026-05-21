<?php
/**
 * Endpoint timbratura NFC.
 * URL da scrivere su NTAG215: https://hr.connecteed.com/punch.php?c=SLUG
 *
 * Pagina minimal: mostra solo la conferma e tenta di chiudersi.
 */
require_once dirname(__DIR__) . '/config/config.php';
Auth::init();
setSecurityHeaders();

$companySlug = trim($_GET['c'] ?? '');

if (!Auth::isEmployeeLoggedIn()) {
    $return = '/punch.php' . ($companySlug !== '' ? '?c=' . urlencode($companySlug) : '');
    header('Location: ' . PUBLIC_URL . '/auth/login.php?return=' . urlencode($return));
    exit;
}

$employee = Auth::getEmployee();
$employeeId = (int) $employee['id'];

// Risolvi company del dipendente (anche se sessione non lo include)
$empCompId = (int) ($employee['company_id']
    ?? Database::fetchColumn("SELECT company_id FROM employees WHERE id = ?", [$employeeId])
    ?? 0);

$companyMismatch = false;
$companyName = '';

if ($companySlug !== '') {
    $slugLower = mb_strtolower($companySlug);
    // 1. Match diretto su slug DB
    $comp = Database::fetchOne("SELECT id, name, slug FROM companies WHERE LOWER(slug) = ?", [$slugLower]);
    // 2. Fallback: slug-ifica il nome dell'azienda del dipendente e compara
    if (!$comp && $empCompId) {
        $empComp = Database::fetchOne("SELECT id, name, slug FROM companies WHERE id = ?", [$empCompId]);
        if ($empComp) {
            $autoSlug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($empComp['name'], 'UTF-8'));
            $autoSlug = trim($autoSlug, '-');
            if ($autoSlug !== '' && $autoSlug === $slugLower) {
                // Persisti lo slug e accetta
                Database::update('companies', ['slug' => $autoSlug], 'id = ?', [(int) $empComp['id']]);
                $comp = ['id' => (int) $empComp['id'], 'name' => $empComp['name'], 'slug' => $autoSlug];
            }
        }
    }
    if (!$comp) {
        $companyMismatch = true;
        $companyName = $companySlug;
    } elseif ((int) $comp['id'] !== $empCompId) {
        $companyMismatch = true;
        $companyName = $comp['name'];
    } else {
        $companyName = $comp['name'];
    }
}

if ($companyMismatch) {
    $result = ['success' => false, 'error' => 'Carta non valida per la tua azienda.'];
} else {
    $result = AttendancePunch::record($employeeId, 'nfc');
}

$ok       = !empty($result['success']);
$cooldown = !empty($result['cooldown']);
$kind     = $result['kind'] ?? ($cooldown ? $result['last_kind'] : null);
$kindLbl  = $kind === 'in' ? 'Entrata' : ($kind === 'out' ? 'Uscita' : '');
$at       = $result['punch_at'] ?? ($cooldown ? $result['last_at'] : date('Y-m-d H:i:s'));
$timeFmt  = $at ? date('H:i', strtotime($at)) : '';
$err      = $result['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $ok ? "Timbrato $timeFmt" : 'Timbratura' ?></title>
<style>
:root {
    --green: #16a34a;
    --orange: #d97706;
    --red: #b91c1c;
    --navy: #0b3aa4;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: <?= $ok && $kind === 'in' ? '#dcfce7' : ($ok && $kind === 'out' ? '#fef3c7' : '#fee2e2') ?>;
    color: #0f172a;
    min-height: 100vh; min-height: 100dvh;
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
    text-align: center;
}
.box { max-width: 320px; width: 100%; }
.ic {
    width: 96px; height: 96px;
    border-radius: 50%;
    background: white;
    display: inline-flex; align-items: center; justify-content: center;
    margin: 0 auto 18px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.08);
    color: <?= $ok && $kind === 'in' ? 'var(--green)' : ($ok && $kind === 'out' ? 'var(--orange)' : 'var(--red)') ?>;
    animation: pop .4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.ic svg { width: 48px; height: 48px; }
@keyframes pop {
    0% { transform: scale(0.5); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
.kind {
    font-size: 18px; font-weight: 700;
    color: <?= $ok && $kind === 'in' ? 'var(--green)' : ($ok && $kind === 'out' ? 'var(--orange)' : 'var(--red)') ?>;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-bottom: 6px;
}
.time {
    font-size: 64px; font-weight: 700;
    color: #0f172a; letter-spacing: -0.03em;
    line-height: 1; margin: 6px 0 12px;
    font-variant-numeric: tabular-nums;
}
.who { font-size: 14px; color: #475569; }
.err-msg { font-size: 14px; color: var(--red); margin-top: 10px; }
.close-hint {
    margin-top: 28px;
    font-size: 12.5px; color: #6e7191;
    opacity: 0.7;
}
</style>
</head>
<body>
    <div class="box">
        <?php if ($ok): ?>
            <div class="ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="kind"><?= $kindLbl ?></div>
            <div class="time"><?= $timeFmt ?></div>
            <div class="who"><?= htmlspecialchars($result['employee'] ?? '') ?></div>
        <?php else: ?>
            <div class="ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div class="kind">Timbratura</div>
            <div class="err-msg"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <div class="close-hint">Puoi chiudere questa scheda</div>
    </div>
<script>
// Tenta di chiudere la scheda dopo 1.8s. Funziona su tab aperte da JS;
// le tab aperte direttamente da NFC su iOS non chiudono via JS — il messaggio
// "Puoi chiudere" guida l'utente.
setTimeout(function(){
    try {
        window.close();
        // Fallback: vibrazione + back se possibile
        if (navigator.vibrate) navigator.vibrate(80);
        history.back();
    } catch (e) {}
}, 1800);
</script>
</body>
</html>
