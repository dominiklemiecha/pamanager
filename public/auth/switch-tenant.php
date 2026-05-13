<?php
/**
 * Endpoint per cambiare azienda corrente.
 * Accessibile a admin, accountant, consulente_lavoro (vedi Tenant::switchCompany).
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
if (!$user) {
    header('Location: ' . PUBLIC_URL . '/auth/login.php');
    exit;
}

$companyId = (int)($_POST['id'] ?? 0);
if ($companyId > 0) {
    Tenant::switchCompany($companyId);
}

// Redirect alla pagina di provenienza (relativa allo stesso host) o all'area utente
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
    header('Location: ' . $ref);
    exit;
}

$fallback = match($user['role']) {
    'admin'             => PUBLIC_URL . '/admin/',
    'admin_reparto'     => PUBLIC_URL . '/admin-reparto/',
    'consulente_lavoro' => PUBLIC_URL . '/consulente-lavoro/',
    'accountant'        => PUBLIC_URL . '/accountant/',
    default             => PUBLIC_URL . '/',
};
header('Location: ' . $fallback);
exit;
