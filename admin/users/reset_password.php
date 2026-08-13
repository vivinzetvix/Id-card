<?php
/**
 * Users Management Module - Reset Password Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$currentUser = require_user_module_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$id = (int)($_POST['id'] ?? 0);
$token = $_POST['csrf_token'] ?? '';
$newPassword = $_POST['new_password'] ?? '';

if (!validate_csrf_token($token)) {
    $_SESSION['user_error'] = 'Invalid CSRF token. Action aborted.';
    header('Location: index.php');
    exit();
}

$user = get_user_by_id($pdo, $id);
if (!$user) {
    $_SESSION['user_error'] = 'User not found.';
    header('Location: index.php');
    exit();
}

// Scope check for Organization Admin
if (!is_super_admin($currentUser) && (int)($user['organization_id'] ?? 0) !== (int)($currentUser['organization_id'] ?? 0)) {
    $_SESSION['user_error'] = 'Access Denied. You cannot reset passwords for users outside your organization.';
    header('Location: index.php');
    exit();
}

if (strlen($newPassword) < 6) {
    $_SESSION['user_error'] = 'Password must be at least 6 characters long.';
    header('Location: index.php');
    exit();
}

if (reset_user_password($pdo, $id, $newPassword)) {
    $_SESSION['user_message'] = "Password for user '{$user['username']}' has been reset successfully.";
} else {
    $_SESSION['user_error'] = 'Failed to reset password due to a database error.';
}

header('Location: index.php');
exit();
