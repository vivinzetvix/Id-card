<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['organization_error'] = 'Invalid security token.';
    header('Location: index.php');
    exit();
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['organization_error'] = 'Invalid organization selected.';
    header('Location: index.php');
    exit();
}

$usersCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE organization_id = ' . $id)->fetchColumn();
$membersCount = (int)$pdo->query('SELECT COUNT(*) FROM id_members WHERE organization_id = ' . $id)->fetchColumn();

if ($usersCount > 0 || $membersCount > 0) {
    $_SESSION['organization_error'] = 'This organization cannot be deleted because it has users or members assigned.';
    header('Location: index.php');
    exit();
}

$organization = $pdo->prepare('SELECT logo FROM organizations WHERE id = ? LIMIT 1');
$organization->execute([$id]);
$row = $organization->fetch(PDO::FETCH_ASSOC);

$pdo->prepare('UPDATE organizations SET deleted_by = ?, deleted_at = NOW() WHERE id = ?')->execute([get_current_user_id($pdo), $id]);
if (!empty($row['logo'])) {
    delete_logo_file($row['logo']);
}

log_organization_activity($pdo, 'Deleted organization', 'organization', 'Soft deleted organization ' . $id, $id);
$_SESSION['organization_message'] = 'Organization deleted successfully.';
header('Location: index.php');
exit();
