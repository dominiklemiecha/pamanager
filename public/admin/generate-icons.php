<?php
/**
 * Generatore Icone PWA
 * PAManager - Comune
 *
 * Genera le icone necessarie per la PWA usando GD
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$imagesDir = dirname(__DIR__) . '/assets/images';

// Crea directory se non esiste
if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0755, true);
}

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$generated = [];
$errors = [];

// Forza rigenerazione se richiesto
$forceRegenerate = isset($_GET['regenerate']) && $_GET['regenerate'] === '1';

if ($forceRegenerate) {
    // Elimina tutte le icone esistenti
    foreach ($sizes as $size) {
        $filename = $imagesDir . '/icon-' . $size . '.png';
        if (file_exists($filename)) {
            unlink($filename);
        }
    }
}

// Funzione per convertire coordinate SVG in coordinate immagine
function svgToImg($x, $y, $scale, $offsetX, $offsetY) {
    return [
        (int)($x * $scale + $offsetX),
        (int)($y * $scale + $offsetY)
    ];
}

// Genera icone con il logo PAManager (cubo 3D)
foreach ($sizes as $size) {
    $filename = $imagesDir . '/icon-' . $size . '.png';

    if (file_exists($filename)) {
        $generated[] = "icon-{$size}.png - gia esistente";
        continue;
    }

    // Crea immagine
    $img = imagecreatetruecolor($size, $size);

    // Abilita alpha blending
    imagesavealpha($img, true);
    imagealphablending($img, true);

    // Colori
    $bgColor = imagecolorallocate($img, 26, 54, 93); // #1a365d
    $white = imagecolorallocate($img, 255, 255, 255);

    // Riempi sfondo
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $bgColor);

    // Calcola scala e offset per centrare l'icona
    $padding = $size * 0.15;
    $iconSize = $size - ($padding * 2);
    $scale = $iconSize / 24; // SVG originale è 24x24
    $offsetX = $padding;
    $offsetY = $padding;

    // Disegna il logo (cubo 3D)
    // Forma esterna
    $outerPoints = [];
    list($x, $y) = svgToImg(12, 2, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;
    list($x, $y) = svgToImg(2, 7, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;
    list($x, $y) = svgToImg(2, 17, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;
    list($x, $y) = svgToImg(12, 22, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;
    list($x, $y) = svgToImg(22, 17, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;
    list($x, $y) = svgToImg(22, 7, $scale, $offsetX, $offsetY);
    $outerPoints[] = $x; $outerPoints[] = $y;

    imagefilledpolygon($img, $outerPoints, $white);

    // Parte interna (buco 3D)
    $innerPoints = [];
    list($x, $y) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;
    list($x, $y) = svgToImg(5, 9, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;
    list($x, $y) = svgToImg(5, 17, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;
    list($x, $y) = svgToImg(12, 20.5, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;
    list($x, $y) = svgToImg(19, 17, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;
    list($x, $y) = svgToImg(19, 9, $scale, $offsetX, $offsetY);
    $innerPoints[] = $x; $innerPoints[] = $y;

    imagefilledpolygon($img, $innerPoints, $bgColor);

    // Linee interne per effetto 3D
    $lineThickness = max(1, (int)($size * 0.02));
    imagesetthickness($img, $lineThickness);

    list($x1, $y1) = svgToImg(12, 7, $scale, $offsetX, $offsetY);
    list($x2, $y2) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
    imageline($img, $x1, $y1, $x2, $y2, $white);

    list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
    list($x2, $y2) = svgToImg(5, 9, $scale, $offsetX, $offsetY);
    imageline($img, $x1, $y1, $x2, $y2, $white);

    list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
    list($x2, $y2) = svgToImg(19, 9, $scale, $offsetX, $offsetY);
    imageline($img, $x1, $y1, $x2, $y2, $white);

    list($x1, $y1) = svgToImg(12, 12.5, $scale, $offsetX, $offsetY);
    list($x2, $y2) = svgToImg(12, 20.5, $scale, $offsetX, $offsetY);
    imageline($img, $x1, $y1, $x2, $y2, $white);

    // Salva
    if (imagepng($img, $filename)) {
        $generated[] = "icon-{$size}.png - creata";
    } else {
        $errors[] = "icon-{$size}.png - errore creazione";
    }

    imagedestroy($img);
}

$pageTitle = 'Genera Icone PWA';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="dashboard">
    <div class="dashboard-card">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                </svg>
                Generazione Icone PWA
            </h3>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom: 1rem;">
                    <strong>Errori:</strong><br>
                    <?php foreach ($errors as $err): ?>
                        <?= htmlspecialchars($err) ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-success" style="margin-bottom: 1rem;">
                <strong>Risultato:</strong><br>
                <?php foreach ($generated as $gen): ?>
                    <?= htmlspecialchars($gen) ?><br>
                <?php endforeach; ?>
            </div>

            <h4 style="margin-bottom: 1rem;">Anteprima Icone</h4>
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
                <?php foreach ($sizes as $size): ?>
                    <?php $iconPath = PUBLIC_URL . '/assets/images/icon-' . $size . '.png'; ?>
                    <div style="text-align: center;">
                        <img src="<?= $iconPath ?>?v=<?= time() ?>"
                             alt="Icon <?= $size ?>px"
                             style="width: <?= min($size, 128) ?>px; height: <?= min($size, 128) ?>px; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="font-size: 0.75rem; color: #718096; margin-top: 0.25rem;">
                            <?= $size ?>x<?= $size ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 1.5rem;">
                <a href="?regenerate=1" class="btn btn-primary" onclick="return confirm('Rigenerare tutte le icone?')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
                    </svg>
                    Rigenera Tutte le Icone
                </a>
            </div>

            <div class="alert alert-info" style="margin-top: 1.5rem;">
                <strong>Nota:</strong> Le icone usano il logo PAManager (cubo 3D).
                Clicca "Rigenera" se vedi ancora il vecchio design.
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
