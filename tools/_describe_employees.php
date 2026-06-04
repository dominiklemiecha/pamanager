<?php
require __DIR__ . '/../config/config.php';
foreach (Database::fetchAll('DESCRIBE employees') as $c) {
    echo str_pad($c['Field'], 32) . ' | ' . $c['Type'] . "\n";
}
