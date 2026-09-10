<?php
/**
 * Endpoint pubblico per migrazione database
 * PAManager - Comune
 *
 * USO: https://<host>/migrate.php?token=<MIGRATION_TOKEN>
 */

// Verifica token di sicurezza (nessun default: senza MIGRATION_TOKEN l'endpoint e' chiuso)
$expectedToken = (string) (getenv('MIGRATION_TOKEN') ?: '');
$providedToken = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Accesso Negato</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h1>403 - Accesso Negato</h1><p>Token mancante o non valido.</p><p>Uso: ?token=xxx</p></body></html>');
}

// Autorizza l'accesso allo script di migrazione
define('MIGRATION_AUTHORIZED', true);

// Includi lo script di migrazione principale
require_once __DIR__ . '/../database/migrate.php';
