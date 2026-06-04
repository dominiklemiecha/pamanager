<?php
require __DIR__ . '/../config/config.php';
foreach (Database::fetchAll("SELECT id, hire_request_id, category, file_path FROM hire_request_files WHERE category IN ('signed_contract','signature_image','contract')") as $r) {
    $fs = HireRequest::fileFsPath($r);
    $ok = is_file($fs) ? 'OK' : 'MISSING';
    echo str_pad($r['id'], 4) . ' | hr=' . str_pad($r['hire_request_id'], 4) . ' | ' . str_pad($r['category'], 18) . ' | ' . $ok . ' | ' . $fs . PHP_EOL;
}
