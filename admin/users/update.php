<?php
/**
 * Users Management Module - Update User Request Handler
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
$user = get_user_by_id($pdo, $id);

if (!$user) {
    $_SESSION['user_error'] = 'User not found.';
    header('Location: index.php');
    exit();
}

// Scope check
if (!is_super_admin($currentUser) && (int)($user['organization_id'] ?? 0) !== (int)($currentUser['organization_id'] ?? 0)) {
    $_SESSION['user_error'] = 'Access Denied. You cannot update users outside your organization.';
    header('Location: index.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrfToken)) {
    $_SESSION['user_error'] = 'Invalid CSRF token. Action aborted.';
    header("Location: edit.php?id={$id}");
    exit();
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$organizationId = trim($_POST['organization_id'] ?? '');
$roleId = (int)($_POST['role_id'] ?? 0);
$status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

$errors = [];

// Super Admin status protection
if (is_target_super_admin($pdo, $id)) {
    $status = 1;
}

if ($fullName === '') {
    $errors['full_name'] = 'Full Name is required.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
} elseif ($email !== '' && is_email_taken($pdo, $email, $id)) {
    $errors['email'] = "Email address '{$email}' is already registered to another user.";
}

if ($mobile !== '' && is_mobile_taken($pdo, $mobile, $id)) {
    $errors['mobile'] = "Mobile number '{$mobile}' is already registered to another user.";
}

// Role Scope Validation
if ($roleId <= 0) {
    $errors['role_id'] = 'Please select a valid role.';
} else {
    $scopedRoles = get_active_roles_scoped($pdo, $currentUser);
    $validRoleIds = array_column($scopedRoles, 'id');
    // If target user is already Super Admin and editor is Super Admin, allow keeping Super Admin role
    if (!in_array($roleId, $validRoleIds, true) && !(is_super_admin($currentUser) && $roleId === (int)$user['role_id'])) {
        $errors['role_id'] = 'You do not have permission to assign this role.';
    }
}

if (!is_super_admin($currentUser)) {
    $organizationId = (string)($currentUser['organization_id'] ?? '');
}

// Avatar upload
$newAvatarName = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $uploadRes = upload_user_avatar($_FILES['avatar'], __DIR__ . '/assets/uploads/avatars');
    if ($uploadRes['success']) {
        $newAvatarName = $uploadRes['file'];
        // Remove old avatar
        delete_avatar_file($user['avatar'] ?? null);
    } else {
        $errors['avatar'] = $uploadRes['message'];
    }
}

if (!empty($errors)) {
    $_SESSION['user_form_errors'] = $errors;
    $_SESSION['user_form_old'] = [
        'full_name' => $fullName,
        'email' => $email,
        'mobile' => $mobile,
        'organization_id' => $organizationId,
        'role_id' => (string)$roleId,
        'status' => (string)$status
    ];
    header("Location: edit.php?id={$id}");
    exit();
}

$updateData = [
    'username' => $user['username'],
    'full_name' => $fullName,
    'email' => $email,
    'mobile' => $mobile,
    'organization_id' => $organizationId,
    'role_id' => $roleId,
    'status' => $status
];
if ($newAvatarName) {
    $updateData['avatar'] = $newAvatarName;
}

if (update_user($pdo, $id, $updateData)) {
    $_SESSION['user_message'] = "User '@{$user['username']}' updated successfully.";
    header('Location: index.php');
    exit();
} else {
    $_SESSION['user_error'] = 'Failed to update user due to a database error.';
    header("Location: edit.php?id={$id}");
    exit();
}
