<?php
/**
 * Role Management Module - Save Permissions Request Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$roleId = (int)($_POST['role_id'] ?? 0);
$role = get_role_by_id($pdo, $roleId);

if (!$role) {
    $_SESSION['role_error'] = 'Role not found.';
    header('Location: index.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrfToken)) {
    $_SESSION['role_error'] = 'Invalid CSRF token. Action aborted.';
    header("Location: permissions.php?id={$roleId}");
    exit();
}

$permissionIds = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];

if (save_role_permissions($pdo, $roleId, $permissionIds)) {
    $_SESSION['role_message'] = "Permissions for role '{$role['role_name']}' updated successfully. (" . count($permissionIds) . " permissions assigned)";
} else {
    $_SESSION['role_error'] = "Failed to update permissions for role '{$role['role_name']}'.";
}

header("Location: permissions.php?id={$roleId}");
exit();
