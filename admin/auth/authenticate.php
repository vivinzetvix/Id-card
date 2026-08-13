<?php
/**
 * Authentication Processor
 * Handles login POST from index.php (root login form).
 * Action: POST → validate CSRF → check lockout → verify credentials → populate session → redirect.
 */

require_once __DIR__ . '/../../middleware/session.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../index.php');
    exit();
}

$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// ─── CSRF Validation ──────────────────────────────────────────────────────────

$csrfToken = $_POST['csrf_token'] ?? '';
if (!auth_validate_csrf_token($csrfToken)) {
    $_SESSION['auth_error'] = 'Invalid form submission. Please try again.';
    header('Location: ../../index.php');
    exit();
}

// ─── Input Validation ─────────────────────────────────────────────────────────

if ($username === '' || $password === '') {
    $_SESSION['auth_error'] = 'Username and password are required.';
    header('Location: ../../index.php');
    exit();
}

// ─── Lockout Check ────────────────────────────────────────────────────────────

if (auth_is_locked_out($pdo, $username, $ip)) {
    auth_record_login_history($pdo, null, $username, $ip, 'locked');
    $_SESSION['auth_error'] = 'Too many failed login attempts. Your account is temporarily locked for ' . AUTH_LOCKOUT_MINUTES . ' minutes. Please try again later.';
    header('Location: ../../index.php');
    exit();
}

// ─── Credential Verification ──────────────────────────────────────────────────

$user = auth_verify_credentials($pdo, $username, $password);

if (!$user) {
    // Log failed attempt
    auth_record_failed_attempt($pdo, $username, $ip);
    auth_record_login_history($pdo, null, $username, $ip, 'failed');
    auth_log_activity($pdo, null, 'Login Failed', 'auth', "Failed login attempt for username: '{$username}' from IP: {$ip}");

    $remainingAttempts = max(0, AUTH_MAX_ATTEMPTS - auth_get_failed_attempt_count($pdo, $username, $ip));

    if ($remainingAttempts === 0) {
        $_SESSION['auth_error'] = 'Too many failed attempts. Your account is now locked for ' . AUTH_LOCKOUT_MINUTES . ' minutes.';
    } else {
        $_SESSION['auth_error'] = 'Invalid username or password. ' . $remainingAttempts . ' attempt(s) remaining.';
    }

    header('Location: ../../index.php');
    exit();
}

// ─── Success Path ─────────────────────────────────────────────────────────────

// Clear failed attempts for this user/IP
auth_clear_failed_attempts($pdo, $username, $ip);

// Record successful login
$historyId = auth_record_login_history($pdo, (int)$user['id'], $username, $ip, 'success');
auth_update_last_login($pdo, (int)$user['id']);

// Populate session
auth_populate_session($user, $historyId);

// Audit log
auth_log_activity(
    $pdo,
    (int)$user['id'],
    'User Login',
    'auth',
    "Successful login: '{$username}' (Role: " . ($user['role_name'] ?? $user['role'] ?? 'N/A') . ") from IP: {$ip}"
);

// Redirect to dashboard
header('Location: ../../dashboard.php');
exit();
