<?php
/**
 * Sessione per il widget assistenza Connecteed.
 *
 * POST dal caricatore del widget (fetch same-origin con i cookie di sessione).
 * Risponde { token } con la dichiarazione firmata dell'utente loggato.
 *
 * Niente CSRF token (il widget non lo conosce): l'endpoint non modifica nulla
 * e non emette header CORS, quindi un sito terzo puo' al massimo farlo
 * chiamare ma non leggerne la risposta. Per scrupolo si rifiutano comunque
 * le richieste che il browser marca come cross-site.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function assistenzaFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    assistenzaFail(405, 'Metodo non consentito');
}

$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    assistenzaFail(403, 'Origine non ammessa');
}

if (!ConnecteedWidget::configured()) {
    assistenzaFail(503, 'Assistenza non configurata');
}

Auth::init();
$identity = ConnecteedWidget::currentIdentity();
if ($identity === null || $identity['sub'] === '') {
    assistenzaFail(401, 'Autenticazione richiesta');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ctx = [
    'page' => mb_substr((string) ($input['pagina'] ?? ''), 0, 200),
    'path' => mb_substr((string) ($input['percorso'] ?? ''), 0, 200),
];
try {
    $company = Database::fetchOne('SELECT name FROM companies WHERE id = ?', [Tenant::currentCompanyId()]);
    if ($company) $ctx['company'] = $company['name'];
} catch (Throwable $e) { /* il contesto azienda e' facoltativo */ }

echo json_encode(['token' => ConnecteedWidget::signToken($identity, $ctx)]);
