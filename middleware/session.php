<?php
/**
 * Session Security Middleware
 * Initializes and validates session security on every request.
 * Include this FIRST before any other require in protected pages.
 */

// ─── Session Security Configuration ───────────────────────────────────────────

define('SESSION_INACTIVITY_TIMEOUT', 3600);   // 1 hour idle timeout (seconds)
define('SESSION_COOKIE_LIFETIME',    0);        // 0 = browser session
define('SESSION_COOKIE_SECURE',      false);    // Set true if using HTTPS
define('SESSION_COOKIE_HTTPONLY',    true);
define('SESSION_COOKIE_SAMESITE',    'Lax');

// ─── Start Session Securely ────────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_COOKIE_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => SESSION_COOKIE_SECURE,
        'httponly' => SESSION_COOKIE_HTTPONLY,
        'samesite' => SESSION_COOKIE_SAMESITE,
    ]);
    session_start();
}

// ─── Inactivity Timeout Check ─────────────────────────────────────────────────

if (!empty($_SESSION['logged_in'])) {
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_INACTIVITY_TIMEOUT) {
        // Session expired — destroy and redirect
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

        // Determine login page path dynamically
        $depth = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
        $loginPath = str_repeat('../', max(0, $depth - 1)) . 'index.php';
        header('Location: ' . $loginPath . '?timeout=1');
        exit();
    }

    // Refresh last activity timestamp
    $_SESSION['last_activity'] = time();
}

// ─── Session Fingerprint Validation ───────────────────────────────────────────
// Protects against session hijacking across different user agents

if (!empty($_SESSION['logged_in'])) {
    $currentFingerprint = md5(
        ($_SERVER['HTTP_USER_AGENT'] ?? '') .
        ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
    );

    if (empty($_SESSION['session_fingerprint'])) {
        $_SESSION['session_fingerprint'] = $currentFingerprint;
    } elseif (!hash_equals($_SESSION['session_fingerprint'], $currentFingerprint)) {
        // Fingerprint mismatch — possible session hijack, destroy and redirect
        session_destroy();
        $depth = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
        $loginPath = str_repeat('../', max(0, $depth - 1)) . 'index.php';
        header('Location: ' . $loginPath . '?security=1');
        exit();
    }
}
