<?php
/**
 * Logout Handler
 * Stamps logout time in login_history, destroys the session, redirects to login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

// Stamp logout time in login_history
if (!empty($_SESSION['login_history_id'])) {
    auth_record_logout($pdo, (int)$_SESSION['login_history_id']);
}

// Audit log the logout
$userId   = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';

if ($userId) {
    auth_log_activity($pdo, (int)$userId, 'User Logout', 'auth', "User '{$username}' logged out from IP: {$ip}");
}

// Determine depth-appropriate login path
$depth     = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
$loginPath = str_repeat('../', max(0, $depth - 1)) . 'index.php';

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: ' . $loginPath . '?logged_out=1');
exit();
