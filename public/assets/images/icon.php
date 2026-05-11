<?php
/**
 * Dynamic PWA Icon Generator
 * PAManager - Comune
 *
 * Genera icone PNG con il logo PAManager (cubo 3D)
 * URL: /assets/images/icon.php?size=192
 */

$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;

// Valida dimensione
$validSizes = [72, 96, 128, 144, 152, 192, 384, 512];
if (!in_array($size, $validSizes)) {
    $size = 192;
}

// Percorso icona statica
$staticIcon = __DIR__ . '/icon-' . $size . '.png';

// Se esiste già, servila
if (file_exists($staticIcon)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000');
    readfile($staticIcon);
    exit;
}

// Altrimenti genera al volo
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

// Crea immagine con anti-aliasing
$img = imagecreatetruecolor($size, $size);

// Abilita alpha blending
imagesavealpha($img, true);
imagealphablending($img, true);

// Colori
$bgColor = imagecolorallocate($img, 26, 54, 93); // #1a365d
$white = imagecolorallocate($img, 255, 255, 255);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);

// Riempi sfondo con colore primario
imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $bgColor);

// Calcola scala e offset per centrare l'icona
$padding = $size * 0.15;
$iconSize = $size - ($padding * 2);
$scale = $iconSize / 24; // SVG originale è 24x24
$offsetX = $padding;
$offsetY = $padding;

// Funzione per convertire coordinate SVG in coordinate immagine
function svgToImg($x, $y, $scale, $offsetX, $offsetY) {
    return [
        (int)($x * $scale + $offsetX),
        (int)($y * $scale + $offsetY)
    ];
}

// Disegna il logo (basato sul path SVG: M12 2L2 7v10l10 5 10-5V7L12 2z)
// Forma esterna (esagono/busta 3D)
$outerPoints = [];

// Punto alto (12, 2)
list($x, $y) = svgToImg(12, 2, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Punto sinistra alto (2, 7)
list($x, $y) = svgToImg(2, 7, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Punto sinistra basso (2, 17)
list($x, $y) = svgToImg(2, 17, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Punto basso (12, 22)
list($x, $y) = svgToImg(12, 22, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Punto destra basso (22, 17)
list($x, $y) = svgToImg(22, 17, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Punto destra alto (22, 7)
list($x, $y) = svgToImg(22, 7, $scale, $offsetX, $offsetY);
$outerPoints[] = $x;
$outerPoints[] = $y;

// Disegna forma esterna piena
imagefilledpolygon($img, $outerPoints, $white);

// Disegna la parte interna (il "buco" che crea l'effetto 3D)
// Path: M12 20.5l-7-3.5V9l7 3.5 7-3.5v8l-7 3.5z
$innerPoints = [];

// Punto alto centro (12, 12.5) - dove le linee si incontrano
list($x, $y) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Punto sinistra (5, 9)
list($x, $y) = svgToImg(5, 9, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Punto sinistra basso (5, 17)
list($x, $y) = svgToImg(5, 17, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Punto basso (12, 20.5)
list($x, $y) = svgToImg(12, 20.5, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Punto destra basso (19, 17)
list($x, $y) = svgToImg(19, 17, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Punto destra (19, 9)
list($x, $y) = svgToImg(19, 9, $scale, $offsetX, $offsetY);
$innerPoints[] = $x;
$innerPoints[] = $y;

// Disegna parte interna con colore di sfondo per creare il "buco"
imagefilledpolygon($img, $innerPoints, $bgColor);

// Aggiungi le linee di divisione interne per l'effetto 3D
$lineColor = $white;
$lineThickness = max(1, (int)($size * 0.02));

// Linea centrale verticale (dal punto alto al punto basso interno)
list($x1, $y1) = svgToImg(12, 7, $scale, $offsetX, $offsetY);
list($x2, $y2) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
imagesetthickness($img, $lineThickness);
imageline($img, $x1, $y1, $x2, $y2, $white);

// Linea sinistra (dal centro verso sinistra)
list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
list($x2, $y2) = svgToImg(5, 9, $scale, $offsetX, $offsetY);
imageline($img, $x1, $y1, $x2, $y2, $white);

// Linea destra (dal centro verso destra)
list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
list($x2, $y2) = svgToImg(19, 9, $scale, $offsetX, $offsetY);
imageline($img, $x1, $y1, $x2, $y2, $white);

// Linea verticale centrale interna (dal centro al basso)
list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
list($x2, $y2) = svgToImg(12, 20.5, $scale, $offsetX, $offsetY);
imageline($img, $x1, $y1, $x2, $y2, $white);

// Salva anche su disco per cache futura (se possibile)
@imagepng($img, $staticIcon);

// Output
imagepng($img);
imagedestroy($img);
