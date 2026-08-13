<?php
/**
 * Users Management Module - Status Toggle Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$currentUser = require_user_module_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$token = $_GET['csrf_token'] ?? ($_POST['csrf_token'] ?? '');

if (!validate_csrf_token($token)) {
    $_SESSION['user_error'] = 'Invalid request security token.';
    header('Location: index.php');
    exit();
}

$user = get_user_by_id($pdo, $id);
if (!$user) {
    $_SESSION['user_error'] = 'User not found.';
    header('Location: index.php');
    exit();
}

// Organization Admin Scope check
if (!is_super_admin($currentUser) && (int)($user['organization_id'] ?? 0) !== (int)($currentUser['organization_id'] ?? 0)) {
    $_SESSION['user_error'] = 'Access Denied. You cannot modify users outside your organization.';
    header('Location: index.php');
    exit();
}

// Super Admin Protection
if (is_target_super_admin($pdo, $id)) {
    $_SESSION['user_error'] = 'Super Admin accounts cannot be deactivated.';
    header('Location: index.php');
    exit();
}

if (toggle_user_status($pdo, $id)) {
    $newStatus = (int)$user['status'] === 1 ? 'deactivated' : 'activated';
    $_SESSION['user_message'] = "User account '{$user['username']}' has been {$newStatus}.";
} else {
    $_SESSION['user_error'] = 'Failed to change user account status.';
}

header('Location: index.php');
exit();
