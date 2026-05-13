<?php
/**
 * Migra foto profilo esistenti a WebP 256x256.
 * Endpoint una-tantum protetto da token.
 *
 * Uso: https://<host>/migrate-photos-webp.php?token=pamanager_migrate_2024
 *
 * Da eliminare dopo l'esecuzione.
 */

require_once dirname(__DIR__) . '/config/config.php';

$expectedToken = getenv('MIGRATION_TOKEN') ?: 'pamanager_migrate_2024';
if (($_GET['token'] ?? '') !== $expectedToken) {
    http_response_code(403);
    exit('Token non valido');
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migrazione foto profilo a WebP ===\n";
echo "Inizio: " . date('Y-m-d H:i:s') . "\n\n";

if (!class_exists('ImageProcessor') || !ImageProcessor::isAvailable()) {
    exit("[ERRORE] ImageProcessor/GD WebP non disponibili.\n");
}

$rows = Database::fetchAll(
    "SELECT id, photo_path, first_name, last_name FROM employees
     WHERE photo_path IS NOT NULL AND photo_path != ''
       AND photo_path NOT LIKE '%.webp'"
);

if (empty($rows)) {
    exit("Nessuna foto da migrare. Tutte gia' in WebP o nessuna foto caricata.\n");
}

echo "Trovate " . count($rows) . " foto da convertire.\n\n";

$ok = 0; $skip = 0; $err = 0;

foreach ($rows as $emp) {
    $relPath = ltrim($emp['photo_path'], '/');
    $srcAbs  = ROOT_PATH . '/public/' . $relPath;
    $name    = trim($emp['first_name'] . ' ' . $emp['last_name']);

    if (!is_file($srcAbs)) {
        echo "[SKIP #{$emp['id']}] {$name}: file non trovato ({$relPath})\n";
        // Pulisci DB se file orfano
        Database::update('employees', ['photo_path' => null], 'id = ?', [$emp['id']]);
        $skip++;
        continue;
    }

    $dir = dirname($srcAbs);
    $base = pathinfo($srcAbs, PATHINFO_FILENAME);
    $newAbs = $dir . '/' . $base . '.webp';
    $newRel = dirname($relPath) . '/' . $base . '.webp';

    $result = ImageProcessor::toWebp($srcAbs, $newAbs, 256, 82);
    if (!$result['success']) {
        echo "[ERR  #{$emp['id']}] {$name}: " . ($result['error'] ?? '?') . "\n";
        $err++;
        continue;
    }

    $origSize = filesize($srcAbs);
    $newSize  = $result['size'];
    $saved = $origSize > 0 ? round((1 - $newSize / $origSize) * 100) : 0;

    // Aggiorna DB
    try {
        Database::update('employees', ['photo_path' => $newRel], 'id = ?', [$emp['id']]);
        // Cancella originale solo dopo aggiornamento DB
        @unlink($srcAbs);
        echo sprintf("[OK   #%d] %s: %dKB -> %dKB (%d%% in meno)\n",
            $emp['id'], $name, round($origSize / 1024), round($newSize / 1024), $saved);
        $ok++;
    } catch (Throwable $e) {
        @unlink($newAbs);
        echo "[ERR  #{$emp['id']}] {$name}: DB update failed: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "\n=== Riepilogo ===\n";
echo "Convertite: {$ok}\n";
echo "Saltate:    {$skip}\n";
echo "Errori:     {$err}\n";
echo "Fine: " . date('Y-m-d H:i:s') . "\n";
