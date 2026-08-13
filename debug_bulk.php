<?php
require_once __DIR__ . '/config.php';

// Check id_members table columns
$stmt = $pdo->query('DESCRIBE id_members');
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== id_members columns ===\n";
foreach ($cols as $col) {
    echo $col['Field'] . ' | ' . $col['Type'] . "\n";
}

// Check images/uploads directory
$uploadDir = __DIR__ . '/images/uploads/';
echo "\n=== Upload dir exists: " . (is_dir($uploadDir) ? 'YES' : 'NO') . " ===\n";
echo "Writable: " . (is_writable($uploadDir) ? 'YES' : 'NO') . "\n";

// Check bulk_temp dir
$bulkDir = $uploadDir . 'bulk_temp/';
echo "\n=== bulk_temp dir exists: " . (is_dir($bulkDir) ? 'YES' : 'NO') . " ===\n";

// Check a sample member
$stmt = $pdo->query('SELECT id, unique_id, photo FROM id_members ORDER BY id DESC LIMIT 5');
echo "\n=== Last 5 members (photo column) ===\n";
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    echo "ID:{$m['id']} | {$m['unique_id']} | photo=" . var_export($m['photo'], true) . "\n";
}
