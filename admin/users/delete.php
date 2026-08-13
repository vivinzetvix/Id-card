<?php
/**
 * Users Management Module - Delete User Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$currentUser = require_user_module_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');

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
    $_SESSION['user_error'] = 'Access Denied. You cannot delete users outside your organization.';
    header('Location: index.php');
    exit();
}

$result = delete_user($pdo, $id, $currentUser);

if ($result['success']) {
    $_SESSION['user_message'] = $result['message'];
} else {
    $_SESSION['user_error'] = $result['message'];
}

header('Location: index.php');
exit();
