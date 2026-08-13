<?php
/**
 * Role Management Module - Status Toggle Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$token = $_GET['csrf_token'] ?? ($_POST['csrf_token'] ?? '');

if (!validate_csrf_token($token)) {
    $_SESSION['role_error'] = 'Invalid request security token.';
    header('Location: index.php');
    exit();
}

$role = get_role_by_id($pdo, $id);
if (!$role) {
    $_SESSION['role_error'] = 'Role not found.';
    header('Location: index.php');
    exit();
}

if (toggle_role_status($pdo, $id)) {
    $newStatus = (int)$role['status'] === 1 ? 'deactivated' : 'activated';
    $_SESSION['role_message'] = "Role '{$role['role_name']}' has been {$newStatus}.";
} else {
    $_SESSION['role_error'] = 'Failed to change role status.';
}

header('Location: index.php');
exit();
