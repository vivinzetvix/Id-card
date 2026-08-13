<?php
/**
 * Auth Module - Helper Functions
 * Authentication, lockout, login history, browser/OS detection.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

// ─── Constants ────────────────────────────────────────────────────────────────

define('AUTH_MAX_ATTEMPTS',    5);     // Max failed attempts before lockout
define('AUTH_LOCKOUT_MINUTES', 15);    // Lockout duration in minutes
define('AUTH_CSRF_TOKEN_KEY',  'auth_csrf_token');

// ─── CSRF Token ───────────────────────────────────────────────────────────────

if (!function_exists('auth_generate_csrf_token')) {
    function auth_generate_csrf_token(): string
    {
        if (empty($_SESSION[AUTH_CSRF_TOKEN_KEY])) {
            $_SESSION[AUTH_CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[AUTH_CSRF_TOKEN_KEY];
    }
}

if (!function_exists('auth_validate_csrf_token')) {
    function auth_validate_csrf_token(string $token): bool
    {
        $stored = $_SESSION[AUTH_CSRF_TOKEN_KEY] ?? '';
        if (!$stored) {
            return false;
        }
        // Regenerate after validation to prevent replay attacks
        unset($_SESSION[AUTH_CSRF_TOKEN_KEY]);
        return hash_equals($stored, $token);
    }
}

// ─── Lockout Management ───────────────────────────────────────────────────────

if (!function_exists('auth_get_failed_attempt_count')) {
    function auth_get_failed_attempt_count(PDO $pdo, string $username, string $ip): int
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . AUTH_LOCKOUT_MINUTES . ' minutes'));
        $stmt  = $pdo->prepare("
            SELECT COUNT(*) FROM failed_logins
            WHERE (username = ? OR ip_address = ?) AND attempt_time >= ?
        ");
        $stmt->execute([trim($username), $ip, $since]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('auth_is_locked_out')) {
    function auth_is_locked_out(PDO $pdo, string $username, string $ip): bool
    {
        try {
            return auth_get_failed_attempt_count($pdo, $username, $ip) >= AUTH_MAX_ATTEMPTS;
        } catch (Throwable $e) {
            return false; // Fail open if table doesn't exist yet
        }
    }
}

if (!function_exists('auth_record_failed_attempt')) {
    function auth_record_failed_attempt(PDO $pdo, string $username, string $ip): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO failed_logins (username, ip_address) VALUES (?, ?)");
            $stmt->execute([trim($username), $ip]);
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}

if (!function_exists('auth_clear_failed_attempts')) {
    function auth_clear_failed_attempts(PDO $pdo, string $username, string $ip): void
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM failed_logins WHERE username = ? OR ip_address = ?");
            $stmt->execute([trim($username), $ip]);
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}

// ─── User Agent Parsing ───────────────────────────────────────────────────────

if (!function_exists('auth_parse_browser')) {
    function auth_parse_browser(string $ua): string
    {
        if (str_contains($ua, 'Edg/'))       return 'Microsoft Edge';
        if (str_contains($ua, 'Chrome'))     return 'Google Chrome';
        if (str_contains($ua, 'Firefox'))    return 'Mozilla Firefox';
        if (str_contains($ua, 'Safari'))     return 'Apple Safari';
        if (str_contains($ua, 'Opera'))      return 'Opera';
        if (str_contains($ua, 'MSIE') || str_contains($ua, 'Trident')) return 'Internet Explorer';
        return 'Unknown Browser';
    }
}

if (!function_exists('auth_parse_os')) {
    function auth_parse_os(string $ua): string
    {
        if (str_contains($ua, 'Windows NT 10.0')) return 'Windows 10/11';
        if (str_contains($ua, 'Windows NT 6.3'))  return 'Windows 8.1';
        if (str_contains($ua, 'Windows NT 6.1'))  return 'Windows 7';
        if (str_contains($ua, 'Windows'))         return 'Windows';
        if (str_contains($ua, 'Mac OS X'))        return 'macOS';
        if (str_contains($ua, 'Android'))         return 'Android';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS';
        if (str_contains($ua, 'Linux'))           return 'Linux';
        return 'Unknown OS';
    }
}

// ─── Login History ────────────────────────────────────────────────────────────

if (!function_exists('auth_record_login_history')) {
    /**
     * Inserts a login_history record. Returns the insert ID.
     */
    function auth_record_login_history(
        PDO    $pdo,
        ?int   $userId,
        string $username,
        string $ip,
        string $status = 'success'
    ): int {
        $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = auth_parse_browser($ua);
        $os      = auth_parse_os($ua);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO login_history (user_id, username, ip_address, user_agent, browser, os, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $username, $ip, $ua, $browser, $os, $status]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('auth_record_logout')) {
    /**
     * Stamps the logout_time on the most recent login_history row for this session.
     */
    function auth_record_logout(PDO $pdo, int $historyId): void
    {
        if ($historyId <= 0) {
            return;
        }
        try {
            $stmt = $pdo->prepare("UPDATE login_history SET logout_time = NOW() WHERE id = ?");
            $stmt->execute([$historyId]);
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}

if (!function_exists('auth_update_last_login')) {
    function auth_update_last_login(PDO $pdo, int $userId): void
    {
        try {
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Throwable $e) {
            // Non-fatal — column might not exist yet
        }
    }
}

// ─── Credential Verification ──────────────────────────────────────────────────

if (!function_exists('auth_verify_credentials')) {
    /**
     * Returns the user array on success, null on failure.
     * Checks: username exists, password_verify(), user active,
     * role active, organization active.
     */
    function auth_verify_credentials(PDO $pdo, string $username, string $password): ?array
    {
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name, r.status AS role_status, o.organization_name, o.status AS org_status
            FROM users u
            LEFT JOIN roles        r ON u.role_id        = r.id
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.username = ? AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        // Password check
        if (!password_verify($password, $user['password'])) {
            return null;
        }

        // User must be active
        if ((int)$user['status'] !== 1) {
            return null;
        }

        // Role must be active (if role is assigned)
        if (!empty($user['role_id']) && (int)($user['role_status'] ?? 1) !== 1) {
            return null;
        }

        // Organization must be active (if assigned)
        if (!empty($user['organization_id']) && (int)($user['org_status'] ?? 1) !== 1) {
            return null;
        }

        return $user;
    }
}

// ─── Session Population ───────────────────────────────────────────────────────

if (!function_exists('auth_populate_session')) {
    /**
     * After successful login: regenerate session ID, populate session variables.
     */
    function auth_populate_session(array $user, int $historyId = 0): void
    {
        // Prevent session fixation
        session_regenerate_id(true);

        $_SESSION['logged_in']          = true;
        $_SESSION['user_id']            = (int)$user['id'];
        $_SESSION['username']           = $user['username'];
        $_SESSION['full_name']          = $user['full_name'] ?? $user['username'];
        $_SESSION['role_id']            = (int)($user['role_id'] ?? 0);
        $_SESSION['role']               = $user['role'] ?? '';
        $_SESSION['role_name']          = $user['role_name'] ?? '';
        $_SESSION['organization_id']    = !empty($user['organization_id']) ? (int)$user['organization_id'] : null;
        $_SESSION['organization_name']  = $user['organization_name'] ?? '';
        $_SESSION['avatar']             = $user['avatar'] ?? null;
        $_SESSION['login_history_id']   = $historyId;
        $_SESSION['last_activity']      = time();
        $_SESSION['session_fingerprint'] = md5(
            ($_SERVER['HTTP_USER_AGENT'] ?? '') .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
        );

        // Clear permission cache so fresh permissions are loaded
        unset($_SESSION['_perm_cache'], $_SESSION['_auth_user']);
    }
}

// ─── Password Reset Token ─────────────────────────────────────────────────────

if (!function_exists('auth_create_reset_token')) {
    function auth_create_reset_token(PDO $pdo, int $userId): string
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Invalidate existing tokens for this user
        $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ?")->execute([$userId]);

        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tokens (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $token, $expires]);

        return $token;
    }
}

if (!function_exists('auth_verify_reset_token')) {
    function auth_verify_reset_token(PDO $pdo, string $token): ?array
    {
        $stmt = $pdo->prepare("
            SELECT prt.*, u.username, u.full_name, u.email
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token = ?
              AND prt.used = 0
              AND prt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('auth_consume_reset_token')) {
    function auth_consume_reset_token(PDO $pdo, int $tokenId, string $newPassword): bool
    {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        // Get user_id from token record
        $stmt = $pdo->prepare("SELECT user_id FROM password_reset_tokens WHERE id = ?");
        $stmt->execute([$tokenId]);
        $userId = (int)($stmt->fetchColumn() ?: 0);

        if (!$userId) {
            return false;
        }

        // Update password
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $userId]);
        // Mark token as used
        $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?")->execute([$tokenId]);

        return true;
    }
}

// ─── Audit Log ────────────────────────────────────────────────────────────────

if (!function_exists('auth_log_activity')) {
    function auth_log_activity(PDO $pdo, ?int $userId, string $action, string $actionType, string $details): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $action, $actionType, $details, $ip, $ua]);
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}
