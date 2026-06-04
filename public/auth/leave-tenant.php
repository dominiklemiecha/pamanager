<?php
/**
 * Endpoint POST: l'utente (accountant/consulente_lavoro) si rimuove da un'azienda.
 * Usato dal menu tenant-switcher quando il mandato e' finito e l'admin
 * non ha gia' tolto l'accesso.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
CSRF::verifyOrDie();

$user = Auth::getUser();
if (!$user || !in_array($user['role'] ?? '', ['accountant', 'consulente_lavoro'], true)) {
    http_response_code(403);
    exit('Operazione non consentita');
}

$companyId = (int) ($_POST['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    exit('Azienda non valida');
}

$res = Tenant::leaveCompany((int) $user['id'], $companyId);
$back = PUBLIC_URL . '/' . ($user['role'] === 'consulente_lavoro' ? 'consulente-lavoro' : 'accountant') . '/';
$flag = $res['success'] ? 'left' : ('err:' . urlencode($res['error'] ?? ''));
header('Location: ' . $back . '?tenant=' . $flag);
exit;
