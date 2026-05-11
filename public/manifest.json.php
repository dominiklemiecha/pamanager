<?php
/**
 * PWA Manifest - Generato dinamicamente
 * PAManager - Comune
 */

require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

$baseUrl = PUBLIC_URL;

// Genera URL icone con cache-busting
$imagesPath = dirname(__FILE__) . '/assets/images';
$iconVersion = 4; // Incrementa per forzare refresh icone

function getIconUrl($baseUrl, $size, $imagesPath, $version) {
    $staticFile = $imagesPath . '/icon-' . $size . '.png';
    if (file_exists($staticFile)) {
        return $baseUrl . '/assets/images/icon-' . $size . '.png?v=' . $version;
    }
    return $baseUrl . '/assets/images/icon.php?size=' . $size . '&v=' . $version;
}

$manifest = [
    "name" => "PAManager - Comune",
    "short_name" => "PAManager",
    "description" => "Sistema gestionale per dipendenti comunali. Accedi ai tuoi documenti e comunicazioni.",
    "lang" => "it",
    "start_url" => $baseUrl . "/",
    "scope" => $baseUrl . "/",
    "display" => "standalone",
    "orientation" => "portrait-primary",
    "theme_color" => "#1a365d",
    "background_color" => "#ffffff",
    "icons" => [
        ["src" => getIconUrl($baseUrl, 72, $imagesPath, $iconVersion), "sizes" => "72x72", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 96, $imagesPath, $iconVersion), "sizes" => "96x96", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 128, $imagesPath, $iconVersion), "sizes" => "128x128", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 144, $imagesPath, $iconVersion), "sizes" => "144x144", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 152, $imagesPath, $iconVersion), "sizes" => "152x152", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 192, $imagesPath, $iconVersion), "sizes" => "192x192", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 384, $imagesPath, $iconVersion), "sizes" => "384x384", "type" => "image/png", "purpose" => "any maskable"],
        ["src" => getIconUrl($baseUrl, 512, $imagesPath, $iconVersion), "sizes" => "512x512", "type" => "image/png", "purpose" => "any maskable"]
    ],
    "categories" => ["business", "productivity"],
    "shortcuts" => [
        [
            "name" => "I Miei Documenti",
            "short_name" => "Documenti",
            "description" => "Visualizza i tuoi documenti",
            "url" => $baseUrl . "/employee/documents.php",
            "icons" => [["src" => $baseUrl . "/assets/images/icon-documents.png", "sizes" => "96x96"]]
        ],
        [
            "name" => "Comunicazioni",
            "short_name" => "Comunicazioni",
            "description" => "Leggi le comunicazioni",
            "url" => $baseUrl . "/employee/communications.php",
            "icons" => [["src" => $baseUrl . "/assets/images/icon-comms.png", "sizes" => "96x96"]]
        ]
    ],
    "screenshots" => [
        [
            "src" => $baseUrl . "/assets/images/screenshot-mobile.png",
            "sizes" => "375x812",
            "type" => "image/png",
            "form_factor" => "narrow",
            "label" => "Home page su mobile"
        ],
        [
            "src" => $baseUrl . "/assets/images/screenshot-desktop.png",
            "sizes" => "1280x720",
            "type" => "image/png",
            "form_factor" => "wide",
            "label" => "Dashboard su desktop"
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
