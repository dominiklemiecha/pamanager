<?php
/**
 * Registra un clic dell'utenza HR (ruolo admin) su pulsanti e link.
 * Chiamato da assets/js/click-log.js via navigator.sendBeacon (FormData).
 * Report: /superadmin/clic.php
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
$__u = Auth::getUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$__u || ($__u['role'] ?? '') !== 'admin') {
    http_response_code(204);
    exit;
}
if (!CSRF::verify()) {
    http_response_code(403);
    exit;
}

// Limite per sessione: niente raffiche (max 30 clic al minuto)
$now = time();
$win = $_SESSION['ui_click_win'] ?? ['t' => $now, 'n' => 0];
if ($now - $win['t'] >= 60) $win = ['t' => $now, 'n' => 0];
$win['n']++;
$_SESSION['ui_click_win'] = $win;
if ($win['n'] > 30) { http_response_code(204); exit; }

$clean = function (string $key, int $max): string {
    $v = (string) ($_POST[$key] ?? '');
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    return mb_substr($v, 0, $max);
};

$label = $clean('label', 100);
$page  = $clean('page', 120);
if ($label === '' || $page === '') { http_response_code(204); exit; }

$area = $clean('area', 40);
$kind = $clean('kind', 20);
$target = $clean('target', 160);

try {
    $companyId = Tenant::currentCompanyId();
    Database::insert('ui_click_logs', [
        'company_id' => $companyId > 0 ? $companyId : null,
        'user_id'    => (int) $__u['id'],
        'page'       => $page,
        'area'       => $area !== '' ? $area : 'Pagina',
        'kind'       => $kind !== '' ? $kind : 'button',
        'label'      => $label,
        'target'     => $target !== '' ? $target : null,
    ]);
    // Pulizia occasionale: si tengono 12 mesi
    if (random_int(1, 500) === 1) {
        Database::execute("DELETE FROM ui_click_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    }
} catch (Throwable $e) {
    error_log('ui-click: ' . $e->getMessage());
}
http_response_code(204);
