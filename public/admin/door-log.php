<?php
/**
 * Log accessi porta NFC.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

// Modulo riservato: solo l'admin globale (nessun tenant) può vedere il log porta.
if (!Tenant::isCurrentUserTrueGlobalAdmin()) {
    header('Location: ' . PUBLIC_URL . '/admin/'); exit;
}

$companyId = Tenant::currentCompanyId();

$filterEsito = $_GET['esito'] ?? 'all';
$filterDate  = $_GET['date']  ?? '';

$where  = ['l.company_id = ?'];
$params = [$companyId];

if ($filterEsito === 'granted')  { $where[] = 'l.granted = 1'; }
if ($filterEsito === 'denied')   { $where[] = 'l.granted = 0'; }
if ($filterDate !== '') {
    $where[] = 'DATE(l.created_at) = ?';
    $params[] = $filterDate;
}

$sql = "SELECT l.*, CONCAT(e.first_name, ' ', e.last_name) AS employee_name
        FROM door_access_log l
        LEFT JOIN employees e ON e.id = l.employee_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at DESC
        LIMIT 200";
$rows = Database::fetchAll($sql, $params);

$reasonLabels = [
    'ok'                 => 'Accesso autorizzato',
    'unknown_uid'        => 'UID sconosciuto',
    'employee_inactive'  => 'Dipendente disattivato',
    'invalid_key'        => 'API key non valida',
    'module_disabled'    => 'Modulo disabilitato',
    'invalid_uid'        => 'UID non valido',
];

$pageTitle = 'Log apertura porta';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_config-tabs.php';
?>

<div class="admin-page">
    <form method="GET" class="cfg-card" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap; max-width:760px;">
        <div class="cfg-fg" style="margin:0;">
            <label>Esito</label>
            <select name="esito">
                <option value="all"     <?= $filterEsito === 'all'     ? 'selected' : '' ?>>Tutti</option>
                <option value="granted" <?= $filterEsito === 'granted' ? 'selected' : '' ?>>Autorizzati</option>
                <option value="denied"  <?= $filterEsito === 'denied'  ? 'selected' : '' ?>>Negati</option>
            </select>
        </div>
        <div class="cfg-fg" style="margin:0;">
            <label>Data</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="cfg-btn cfg-btn-primary">Filtra</button>
            <a href="<?= PUBLIC_URL ?>/admin/door-log.php" class="cfg-btn cfg-btn-ghost">Reset</a>
        </div>
    </form>

    <div class="cfg-card" style="padding: 0; overflow-x: auto;">
        <?php if (empty($rows)): ?>
            <div style="padding: 32px; text-align: center; color: #94a3b8;">
                Nessun accesso registrato con i filtri selezionati.
            </div>
        <?php else: ?>
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #475569;">Data/ora</th>
                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #475569;">Dipendente</th>
                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #475569;">UID</th>
                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #475569;">Esito</th>
                    <th style="text-align: left; padding: 12px 16px; font-weight: 600; color: #475569;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $isOk = (int) $r['granted'] === 1;
                        $reason = $r['reason'] ?? '';
                        $reasonLabel = $reasonLabels[$reason] ?? $reason;
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 16px; font-family: monospace; color: #475569;">
                            <?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?>
                        </td>
                        <td style="padding: 10px 16px;">
                            <?= htmlspecialchars(trim($r['employee_name']) ?: '—') ?>
                        </td>
                        <td style="padding: 10px 16px; font-family: monospace; font-size: 12px; color: #64748b;">
                            <?= htmlspecialchars($r['nfc_uid']) ?>
                        </td>
                        <td style="padding: 10px 16px;">
                            <?php if ($isOk): ?>
                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 11px; font-weight: 600;">
                                    ● Autorizzato
                                </span>
                            <?php else: ?>
                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 600;" title="<?= htmlspecialchars($reasonLabel) ?>">
                                    ● <?= htmlspecialchars($reasonLabel) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 16px; font-family: monospace; font-size: 11px; color: #94a3b8;">
                            <?= htmlspecialchars($r['ip_address'] ?? '') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
