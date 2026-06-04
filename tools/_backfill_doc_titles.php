<?php
require_once dirname(__DIR__) . '/config/config.php';
$labels = ['Documento di riconoscimento','Codice fiscale','Permesso di soggiorno','Modello C2'];
$updated = 0;
foreach ($labels as $lbl) {
    $rows = Database::fetchAll(
        "SELECT id, original_name FROM documents WHERE type = 'other' AND original_name LIKE ?",
        [$lbl . ' - %']
    );
    foreach ($rows as $r) {
        $orig = preg_replace('/^' . preg_quote($lbl, '/') . ' - /', '', $r['original_name']);
        Database::update('documents', ['title' => $lbl, 'original_name' => $orig], 'id = ?', [$r['id']]);
        $updated++;
        echo "  #{$r['id']}: {$lbl} <- {$orig}\n";
    }
}
echo "{$updated} documenti aggiornati\n";
