<?php
/**
 * Endpoint upload + estrazione ZIP uploads (foto, certificati, documenti).
 *
 * USO (da locale):
 *   curl -F "file=@tools/pamanager_uploads_YYYY-MM-DD_HHMMSS.zip" \
 *        "https://hr.connecteed.com/import-uploads.php?token=<MIGRATION_TOKEN>"
 *
 * SICUREZZA: token-protected, da rimuovere dopo l'uso.
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

$expectedToken = getenv('MIGRATION_TOKEN') ?: '';
$providedToken = $_GET['token'] ?? '';
if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die("Access denied. Use ?token=<MIGRATION_TOKEN>\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo "Usage:\n  curl -F \"file=@uploads.zip\" \"" . ($_SERVER['REQUEST_SCHEME'] ?? 'https') . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . "?token=<TOKEN>\"\n";
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die("Upload error: " . $file['error'] . "\n");
}

echo "[1/3] File ricevuto: " . $file['name'] . " (" . round($file['size']/1024) . " KB)\n";

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die("ERR: PHP ext-zip non disponibile.\n");
}

$uploadsRoot = __DIR__ . '/uploads';
if (!is_dir($uploadsRoot)) {
    @mkdir($uploadsRoot, 0775, true);
}

// Pulizia volume (mantieni la dir, cancella il contenuto)
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadsRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($rii as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
echo "[2/3] Volume uploads svuotato\n";

// Estrazione
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    http_response_code(500);
    die("ERR: Impossibile aprire l'archivio.\n");
}

$count = $zip->numFiles;
$extracted = 0;
$targetParent = __DIR__; // lo zip ha radice "uploads/" → estrae in public/

for ($i = 0; $i < $count; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'];
    // Sicurezza: blocca path traversal
    if (strpos($name, '..') !== false || strpos($name, '\0') !== false) continue;
    if (!preg_match('#^uploads/#', $name)) continue;
    $zip->extractTo($targetParent, $name);
    $extracted++;
}
$zip->close();

// Permessi
@chgrp_recursive($uploadsRoot, 'www-data');
shell_exec("chown -R www-data:www-data " . escapeshellarg($uploadsRoot) . " 2>&1");
shell_exec("chmod -R 775 " . escapeshellarg($uploadsRoot) . " 2>&1");

echo "[3/3] Estratti $extracted file in $uploadsRoot\n";

// Sanity: conteggio file finali
$total = 0; $size = 0;
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsRoot, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile()) { $total++; $size += $f->getSize(); }
}
echo "\n=== Risultato ===\n";
echo "File totali nel volume: $total\n";
echo "Dimensione totale: " . round($size/1024/1024, 1) . " MB\n";
echo "\nImport uploads completato. Ricarica https://" . $_SERVER['HTTP_HOST'] . "/\n";

function chgrp_recursive(string $path, string $group): bool { return true; } // stub no-op
