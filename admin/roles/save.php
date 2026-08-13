<?php
/**
 * Role Management Module - Save Role Request Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrfToken)) {
    $_SESSION['role_error'] = 'Invalid CSRF token. Please try submitting the form again.';
    header('Location: add.php');
    exit();
}

$roleName = trim($_POST['role_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

$errors = [];

if ($roleName === '') {
    $errors['role_name'] = 'Role Name is required.';
} elseif (strlen($roleName) < 2 || strlen($roleName) > 50) {
    $errors['role_name'] = 'Role Name must be between 2 and 50 characters.';
} elseif (role_name_exists($pdo, $roleName)) {
    $errors['role_name'] = "The role name '{$roleName}' already exists. Please choose a different name.";
}

if (!in_array($status, [0, 1], true)) {
    $status = 1;
}

if (!empty($errors)) {
    $_SESSION['role_form_errors'] = $errors;
    $_SESSION['role_form_old'] = [
        'role_name' => $roleName,
        'description' => $description,
        'status' => (string)$status
    ];
    header('Location: add.php');
    exit();
}

$newRoleId = create_role($pdo, $roleName, $description, $status);

if ($newRoleId) {
    $_SESSION['role_message'] = "Role '{$roleName}' created successfully. Assign permissions for this role below.";
    header("Location: permissions.php?id={$newRoleId}");
    exit();
} else {
    $_SESSION['role_error'] = 'Failed to create role due to a database error.';
    header('Location: add.php');
    exit();
}
