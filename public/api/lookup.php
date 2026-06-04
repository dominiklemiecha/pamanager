<?php
/**
 * API lookup: CF -> dati di nascita, indirizzo/CAP -> città/provincia/CAP.
 * Usato dai form di assunzione e creazione dipendente.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();

header('Content-Type: application/json; charset=utf-8');

// Solo utenti autenticati (admin/accountant/consulente)
$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'cf') {
    $cf = trim((string)($_GET['cf'] ?? ''));
    if (strlen($cf) !== 16) {
        echo json_encode(['error' => 'CF deve essere 16 caratteri']);
        exit;
    }
    $r = FiscalCodeDecoder::decode($cf);
    if (!$r) {
        echo json_encode(['error' => 'Codice fiscale non valido']);
        exit;
    }
    echo json_encode($r);
    exit;
}

if ($action === 'address') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 4) {
        echo json_encode(['error' => 'Indirizzo troppo corto']);
        exit;
    }
    // Nominatim (OpenStreetMap) — gratuito, richiede User-Agent
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $q,
        'format' => 'jsonv2',
        'countrycodes' => 'it',
        'addressdetails' => 1,
        'limit' => 1,
        'accept-language' => 'it',
    ]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => "User-Agent: PAManager/1.0 (info@connecteed.com)\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { echo json_encode(['error' => 'Servizio non disponibile']); exit; }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data[0])) {
        echo json_encode(['error' => 'Indirizzo non trovato']);
        exit;
    }
    $hit = $data[0];
    $a = $hit['address'] ?? [];
    $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['municipality'] ?? '';
    $province = $a['county'] ?? $a['state_district'] ?? '';
    // Sigla provincia: se "Provincia di Milano" -> Milano; altrimenti vuoto. Nominatim italiano usa nome esteso.
    $province = preg_replace('/^Provincia\s+di\s+/i', '', $province);
    $cap = $a['postcode'] ?? '';
    $road = $a['road'] ?? '';
    $houseNum = $a['house_number'] ?? '';
    $streetFull = trim($road . ($houseNum ? ', ' . $houseNum : ''));
    echo json_encode([
        'street'   => $streetFull,
        'cap'      => $cap,
        'city'     => $city,
        'province' => $province,
        'display'  => $hit['display_name'] ?? '',
    ]);
    exit;
}

echo json_encode(['error' => 'Azione non riconosciuta']);
