<?php
/**
 * Report dei clic dell'utenza HR (ruolo admin) su pulsanti e link, tutti i tenant.
 * Dati raccolti da assets/js/click-log.js -> api/ui-click.php (tabella ui_click_logs).
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
Auth::init();
setSecurityHeaders();
if (!SuperAdmin::configured()) { http_response_code(503); die('Superadmin non configurato.'); }
SuperAdmin::requireAuth();

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [1, 7, 30, 90, 365], true)) $days = 30;
$companyId = (int) ($_GET['company'] ?? 0);
$userId = (int) ($_GET['user'] ?? 0);

$where = ['l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
$params = [$days];
if ($companyId > 0) { $where[] = 'l.company_id = ?'; $params[] = $companyId; }
if ($userId > 0)    { $where[] = 'l.user_id = ?';    $params[] = $userId; }
$w = implode(' AND ', $where);

// Nome pagina leggibile: /admin/employees.php -> employees, /admin/ -> dashboard
$pageName = function (string $p): string {
    $b = basename(rtrim($p, '/'), '.php');
    if ($b === '' || $b === 'index' || $b === 'admin') return 'dashboard';
    return $b;
};

if (($_GET['export'] ?? '') === 'csv') {
    $rows = Database::fetchAll(
        "SELECT l.created_at, c.name AS company, u.name AS user_name, u.username, l.page, l.area, l.kind, l.label, l.target
         FROM ui_click_logs l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN companies c ON c.id = l.company_id
         WHERE $w ORDER BY l.created_at DESC LIMIT 50000",
        $params
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clic-hr-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Azienda', 'Utente', 'Username', 'Pagina', 'Zona', 'Tipo', 'Pulsante', 'Destinazione'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], $r['company'], $r['user_name'], $r['username'], $pageName($r['page']), $r['area'], $r['kind'], $r['label'], $r['target']], ';');
    }
    exit;
}

$totals = Database::fetchOne(
    "SELECT COUNT(*) AS clicks, COUNT(DISTINCT l.user_id) AS users, COUNT(DISTINCT l.page) AS pages
     FROM ui_click_logs l WHERE $w",
    $params
) ?? ['clicks' => 0, 'users' => 0, 'pages' => 0];

$topButtons = Database::fetchAll(
    "SELECT l.label, l.area, l.page, l.kind, COUNT(*) AS n, COUNT(DISTINCT l.user_id) AS users, MAX(l.created_at) AS last_at
     FROM ui_click_logs l WHERE $w
     GROUP BY l.label, l.area, l.page, l.kind
     ORDER BY n DESC LIMIT 50",
    $params
);

$topPages = Database::fetchAll(
    "SELECT l.page, COUNT(*) AS n, COUNT(DISTINCT l.user_id) AS users
     FROM ui_click_logs l WHERE $w
     GROUP BY l.page ORDER BY n DESC LIMIT 20",
    $params
);

$perUser = Database::fetchAll(
    "SELECT l.user_id, u.name AS user_name, u.username, c.name AS company, COUNT(*) AS n, MAX(l.created_at) AS last_at
     FROM ui_click_logs l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN companies c ON c.id = l.company_id
     WHERE $w
     GROUP BY l.user_id, u.name, u.username, c.name
     ORDER BY n DESC LIMIT 50",
    $params
);

$recent = Database::fetchAll(
    "SELECT l.created_at, l.page, l.area, l.label, l.target, u.name AS user_name, u.username, c.name AS company
     FROM ui_click_logs l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN companies c ON c.id = l.company_id
     WHERE $w ORDER BY l.created_at DESC LIMIT 100",
    $params
);

$companies = Database::fetchAll("SELECT id, name FROM companies ORDER BY name");
$hrUsers = Database::fetchAll("SELECT id, name, username FROM users WHERE role = 'admin' ORDER BY name");
$maxN = max(1, (int) ($topButtons[0]['n'] ?? 1));

$qs = function (array $over) use ($days, $companyId, $userId): string {
    return '?' . http_build_query(array_merge(['days' => $days, 'company' => $companyId ?: null, 'user' => $userId ?: null], $over));
};
$h = fn($v) => htmlspecialchars((string) $v);
$dt = fn($v) => $v ? date('d/m/Y H:i', strtotime($v)) : '';
?>
<!DOCTYPE html>
<html lang="it"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Superadmin — Clic utenza HR</title>
<style>
*{box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;}
.wrap{max-width:1200px;margin:0 auto;}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
h1{margin:0;font-size:20px;color:#fff;}
.nav{display:flex;gap:16px;}
.nav a{color:#94a3b8;text-decoration:none;font-size:13px;}
.nav a:hover{color:#fff;}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px;margin-bottom:20px;}
.card h2{margin:0 0 4px;font-size:15px;color:#fff;}
.card .sub{margin:0 0 14px;font-size:12px;color:#94a3b8;}
.filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
label{display:block;font-size:11px;color:#94a3b8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;}
select{padding:9px 10px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:14px;min-width:160px;}
button.primary,a.btn{background:#0b3aa4;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block;}
a.btn.ghost{background:transparent;border:1px solid #334155;color:#cbd5e1;}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.kpi{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;}
.kpi b{display:block;font-size:24px;color:#fff;}
.kpi span{font-size:12px;color:#94a3b8;}
.scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:10px;background:#0f172a;color:#94a3b8;font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.04em;border-bottom:1px solid #334155;white-space:nowrap;}
td{padding:10px;border-bottom:1px solid #334155;color:#e2e8f0;vertical-align:top;}
td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.bar{height:6px;border-radius:3px;background:#0b3aa4;margin-top:6px;min-width:2px;}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;background:#0f172a;border:1px solid #334155;color:#cbd5e1;white-space:nowrap;}
.tiny{font-size:11px;color:#94a3b8;}
.empty{color:#94a3b8;font-size:13px;padding:8px 0;}
.two{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media (max-width:900px){.two{grid-template-columns:1fr;}body{padding:16px;}}
</style></head><body>
<div class="wrap">
<header>
    <h1>Clic utenza HR</h1>
    <div class="nav"><a href="index.php">Tenant</a><a href="logout.php">Logout</a></div>
</header>

<div class="card">
    <form method="GET" class="filters">
        <div>
            <label for="f-days">Periodo</label>
            <select id="f-days" name="days">
                <?php foreach ([1 => 'Ultime 24 ore', 7 => 'Ultimi 7 giorni', 30 => 'Ultimi 30 giorni', 90 => 'Ultimi 90 giorni', 365 => 'Ultimo anno'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-company">Azienda</label>
            <select id="f-company" name="company">
                <option value="0">Tutte</option>
                <?php foreach ($companies as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $companyId === (int) $c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-user">Utente HR</label>
            <select id="f-user" name="user">
                <option value="0">Tutti</option>
                <?php foreach ($hrUsers as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= $h($u['name'] ?: $u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="primary" type="submit">Filtra</button>
        <a class="btn ghost" href="<?= $h($qs(['export' => 'csv'])) ?>">Scarica CSV</a>
    </form>
</div>

<div class="kpis">
    <div class="kpi"><b><?= number_format((int) $totals['clicks'], 0, ',', '.') ?></b><span>Clic registrati</span></div>
    <div class="kpi"><b><?= (int) $totals['users'] ?></b><span>Utenti HR attivi</span></div>
    <div class="kpi"><b><?= (int) $totals['pages'] ?></b><span>Pagine usate</span></div>
</div>

<div class="card">
    <h2>Pulsanti più premuti</h2>
    <p class="sub">Stesso pulsante su pagine diverse conta separato. I link dentro le righe delle tabelle sono registrati per destinazione, senza nomi di persone.</p>
    <?php if (empty($topButtons)): ?>
        <p class="empty">Nessun clic nel periodo.</p>
    <?php else: ?>
    <div class="scroll"><table>
        <thead><tr><th>#</th><th>Pulsante</th><th>Pagina</th><th>Zona</th><th>Clic</th><th>Utenti</th><th>Ultimo</th></tr></thead>
        <tbody>
        <?php foreach ($topButtons as $i => $b): ?>
            <tr>
                <td class="tiny"><?= $i + 1 ?></td>
                <td style="min-width:220px;">
                    <?= $h($b['label']) ?> <span class="tiny"><?= $b['kind'] === 'link' ? 'link' : 'pulsante' ?></span>
                    <div class="bar" style="width:<?= round((int) $b['n'] / $maxN * 100, 1) ?>%"></div>
                </td>
                <td><?= $h($pageName($b['page'])) ?></td>
                <td><span class="tag"><?= $h($b['area']) ?></span></td>
                <td class="num"><?= (int) $b['n'] ?></td>
                <td class="num"><?= (int) $b['users'] ?></td>
                <td class="tiny" style="white-space:nowrap;"><?= $dt($b['last_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="two">
    <div class="card">
        <h2>Pagine con più clic</h2>
        <p class="sub">Dove l'utenza HR lavora di più.</p>
        <?php if (empty($topPages)): ?><p class="empty">Nessun dato.</p><?php else: ?>
        <div class="scroll"><table>
            <thead><tr><th>Pagina</th><th>Clic</th><th>Utenti</th></tr></thead>
            <tbody>
            <?php foreach ($topPages as $p): ?>
                <tr><td><?= $h($pageName($p['page'])) ?> <span class="tiny"><?= $h($p['page']) ?></span></td><td class="num"><?= (int) $p['n'] ?></td><td class="num"><?= (int) $p['users'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Per utente</h2>
        <p class="sub">Clic per utente HR; clicca il nome per filtrare.</p>
        <?php if (empty($perUser)): ?><p class="empty">Nessun dato.</p><?php else: ?>
        <div class="scroll"><table>
            <thead><tr><th>Utente</th><th>Azienda</th><th>Clic</th><th>Ultimo</th></tr></thead>
            <tbody>
            <?php foreach ($perUser as $u): ?>
                <tr>
                    <td><a style="color:#93c5fd;text-decoration:none;" href="<?= $h($qs(['user' => (int) $u['user_id']])) ?>"><?= $h($u['user_name'] ?: ($u['username'] ?: '#' . $u['user_id'])) ?></a></td>
                    <td class="tiny"><?= $h($u['company'] ?: '—') ?></td>
                    <td class="num"><?= (int) $u['n'] ?></td>
                    <td class="tiny" style="white-space:nowrap;"><?= $dt($u['last_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Ultimi clic</h2>
    <p class="sub">Gli ultimi 100 nel filtro. Il CSV contiene tutto il periodo.</p>
    <?php if (empty($recent)): ?><p class="empty">Nessun clic nel periodo.</p><?php else: ?>
    <div class="scroll"><table>
        <thead><tr><th>Quando</th><th>Utente</th><th>Azienda</th><th>Pagina</th><th>Pulsante</th><th>Destinazione</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td class="tiny" style="white-space:nowrap;"><?= $dt($r['created_at']) ?></td>
                <td><?= $h($r['user_name'] ?: $r['username']) ?></td>
                <td class="tiny"><?= $h($r['company'] ?: '—') ?></td>
                <td><?= $h($pageName($r['page'])) ?></td>
                <td><?= $h($r['label']) ?> <span class="tag"><?= $h($r['area']) ?></span></td>
                <td class="tiny"><?= $h($r['target'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
</div>
</body></html>
