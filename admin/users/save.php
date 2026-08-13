<?php
/**
 * Users Management Module - Save User Request Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$currentUser = require_user_module_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrfToken)) {
    $_SESSION['user_error'] = 'Invalid CSRF token. Action aborted.';
    header('Location: add.php');
    exit();
}

$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$email = trim($_POST['email'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$organizationId = trim($_POST['organization_id'] ?? '');
$roleId = (int)($_POST['role_id'] ?? 0);
$status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

$errors = [];

// Full Name Validation
if ($fullName === '') {
    $errors['full_name'] = 'Full Name is required.';
}

// Username Validation
if ($username === '') {
    $errors['username'] = 'Username is required.';
} elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
    $errors['username'] = 'Username must be 3-50 characters (letters, numbers, underscores, dots, hyphens only).';
} elseif (is_username_taken($pdo, $username)) {
    $errors['username'] = "Username '{$username}' is already taken.";
}

// Password Validation
if (strlen($password) < 6) {
    $errors['password'] = 'Password must be at least 6 characters long.';
} elseif ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Password and Confirm Password do not match.';
}

// Email Validation
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
} elseif ($email !== '' && is_email_taken($pdo, $email)) {
    $errors['email'] = "Email address '{$email}' is already registered to another user.";
}

// Mobile Validation
if ($mobile !== '' && is_mobile_taken($pdo, $mobile)) {
    $errors['mobile'] = "Mobile number '{$mobile}' is already registered.";
}

// Role Scope Validation
if ($roleId <= 0) {
    $errors['role_id'] = 'Please select a valid role.';
} else {
    $scopedRoles = get_active_roles_scoped($pdo, $currentUser);
    $validRoleIds = array_column($scopedRoles, 'id');
    if (!in_array($roleId, $validRoleIds, true)) {
        $errors['role_id'] = 'You do not have permission to assign this role.';
    }
}

// Organization Scope Validation
if (!is_super_admin($currentUser)) {
    $organizationId = (string)($currentUser['organization_id'] ?? '');
}

// Avatar Upload Handling
$avatarName = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $uploadRes = upload_user_avatar($_FILES['avatar'], __DIR__ . '/assets/uploads/avatars');
    if ($uploadRes['success']) {
        $avatarName = $uploadRes['file'];
    } else {
        $errors['avatar'] = $uploadRes['message'];
    }
}

if (!empty($errors)) {
    $_SESSION['user_form_errors'] = $errors;
    $_SESSION['user_form_old'] = [
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'mobile' => $mobile,
        'organization_id' => $organizationId,
        'role_id' => (string)$roleId,
        'status' => (string)$status
    ];
    header('Location: add.php');
    exit();
}

$newUserId = create_user($pdo, [
    'full_name' => $fullName,
    'username' => $username,
    'password' => $password,
    'email' => $email,
    'mobile' => $mobile,
    'organization_id' => $organizationId,
    'role_id' => $roleId,
    'avatar' => $avatarName,
    'status' => $status
]);

if ($newUserId) {
    $_SESSION['user_message'] = "User account '{$username}' created successfully.";
    header('Location: index.php');
    exit();
} else {
    $_SESSION['user_error'] = 'Failed to create user account due to a database error.';
    header('Location: add.php');
    exit();
}
