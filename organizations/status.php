<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$status = (int)($_GET['status'] ?? 1);

if ($id > 0) {
    $stmt = $pdo->prepare('UPDATE organizations SET status = ?, updated_by = ? WHERE id = ?');
    $stmt->execute([$status, get_current_user_id($pdo), $id]);
    log_organization_activity($pdo, 'Updated organization status', 'organization', 'Set organization status to ' . $status, $id);
    $_SESSION['organization_message'] = 'Organization status updated.';
}

header('Location: index.php');
exit();
