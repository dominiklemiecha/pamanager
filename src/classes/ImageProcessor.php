<?php
/**
 * ImageProcessor - resize e conversione immagini in WebP usando GD.
 *
 * Pensato per foto profilo: riduce drasticamente dimensione (200KB -> 20KB
 * tipico) e standardizza il formato. Genera multiple varianti se richiesto.
 */

class ImageProcessor
{
    /**
     * Verifica se GD e webp sono disponibili.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('gd')) return false;
        $info = gd_info();
        return !empty($info['WebP Support']);
    }

    /**
     * Resize e converte un'immagine in WebP.
     *
     * @param string $srcPath  path file sorgente (qualsiasi formato GD-supportato)
     * @param string $destPath path file destinazione (verra' creato in formato WebP)
     * @param int    $maxSize  lato massimo (in px) - resize proporzionale; 0 = no resize
     * @param int    $quality  0-100, qualita' WebP (default 82, ottimo bilanciamento)
     * @return array ['success'=>bool, 'width'=>int, 'height'=>int, 'size'=>int, 'error'=>string|null]
     */
    public static function toWebp(string $srcPath, string $destPath, int $maxSize = 256, int $quality = 82): array
    {
        if (!self::isAvailable()) {
            return ['success' => false, 'error' => 'GD/WebP non disponibile'];
        }
        if (!file_exists($srcPath)) {
            return ['success' => false, 'error' => 'File sorgente non trovato'];
        }

        $info = @getimagesize($srcPath);
        if (!$info) {
            return ['success' => false, 'error' => 'Immagine non valida'];
        }

        $origW = $info[0];
        $origH = $info[1];
        $mime  = $info['mime'] ?? '';

        // Carica immagine in base al tipo
        $src = self::loadImage($srcPath, $mime);
        if (!$src) {
            return ['success' => false, 'error' => 'Impossibile decodificare: ' . $mime];
        }

        // Calcola nuove dimensioni mantenendo proporzioni
        if ($maxSize > 0 && ($origW > $maxSize || $origH > $maxSize)) {
            $ratio = min($maxSize / $origW, $maxSize / $origH);
            $newW = (int) round($origW * $ratio);
            $newH = (int) round($origH * $ratio);
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        // Resample
        $dst = imagecreatetruecolor($newW, $newH);
        // Preserva trasparenza
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        // Salva come WebP
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $ok = imagewebp($dst, $destPath, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) {
            return ['success' => false, 'error' => 'Scrittura WebP fallita'];
        }

        return [
            'success' => true,
            'width'   => $newW,
            'height'  => $newH,
            'size'    => filesize($destPath),
            'error'   => null,
        ];
    }

    private static function loadImage(string $path, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($path);
                if ($img) {
                    // Rispetta orientamento EXIF (foto da smartphone)
                    self::applyExifOrientation($img, $path);
                }
                return $img;
            case 'image/png':
                return @imagecreatefrompng($path);
            case 'image/gif':
                return @imagecreatefromgif($path);
            case 'image/webp':
                return @imagecreatefromwebp($path);
            default:
                return false;
        }
    }

    /**
     * Ruota l'immagine in base all'orientamento EXIF (importante per JPEG da iPhone).
     */
    private static function applyExifOrientation(&$img, string $path): void
    {
        if (!function_exists('exif_read_data')) return;
        try {
            $exif = @exif_read_data($path);
            if (!$exif || empty($exif['Orientation'])) return;
            switch ((int)$exif['Orientation']) {
                case 3: $img = imagerotate($img, 180, 0); break;
                case 6: $img = imagerotate($img, -90, 0); break;
                case 8: $img = imagerotate($img, 90, 0); break;
            }
        } catch (Throwable $e) {
            // Ignora errori EXIF
        }
    }
}
