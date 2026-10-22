<?php
/**
 * Manifest della web app "Cora" (vista dipendente ridisegnata).
 * Le icone arrivano dal pacchetto favicon di Cora (assets/images/cora-app).
 */

require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

$baseUrl = PUBLIC_URL;
$icons = $baseUrl . '/assets/images/cora-app/';
$v = 1; // Incrementa per forzare il refresh delle icone

echo json_encode([
    'name'             => 'Cora',
    'short_name'       => 'Cora',
    'description'      => 'Documenti, ferie, permessi e avvisi della tua azienda.',
    'lang'             => 'it',
    'id'               => $baseUrl . '/employee/',
    'start_url'        => $baseUrl . '/employee/',
    'scope'            => $baseUrl . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'theme_color'      => '#f6f8f7',
    'background_color' => '#f6f8f7',
    'icons' => [
        ['src' => $icons . 'icon-192.png?v=' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icons . 'icon-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icons . 'icon-maskable-192.png?v=' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icons . 'icon-maskable-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'categories' => ['business', 'productivity'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
