<?php
/**
 * Endpoint pubblico per migrazione database
 * PAManager - Comune
 *
 * USO: http://localhost/gestionalepa/public/migrate.php?token=pamanager_migrate_2024
 */

// Verifica token di sicurezza
$expectedToken = getenv('MIGRATION_TOKEN') ?: 'pamanager_migrate_2024';
$providedToken = $_GET['token'] ?? '';

if ($providedToken !== $expectedToken) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Accesso Negato</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h1>403 - Accesso Negato</h1><p>Token mancante o non valido.</p><p>Uso: ?token=xxx</p></body></html>');
}

// Autorizza l'accesso allo script di migrazione
define('MIGRATION_AUTHORIZED', true);

// Includi lo script di migrazione principale
require_once __DIR__ . '/../database/migrate.php';
