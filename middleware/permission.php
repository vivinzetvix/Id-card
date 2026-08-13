<?php
/**
 * Permission Middleware
 * Provides granular permission checks using role_permissions table.
 * Requires auth.php and config.php to be loaded before this file.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Permission Cache ─────────────────────────────────────────────────────────
// Permissions are loaded once per request and cached in $_SESSION['_perm_cache'].

if (!function_exists('load_user_permissions')) {
    function load_user_permissions(PDO $pdo, array $user): void
    {
        if (isset($_SESSION['_perm_cache'])) {
            return; // Already loaded this request
        }

        // Super Admin has all permissions — bypass DB check
        if (function_exists('auth_is_super_admin') && auth_is_super_admin($user)) {
            $_SESSION['_perm_cache'] = ['*' => true];
            return;
        }

        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId === 0) {
            $_SESSION['_perm_cache'] = [];
            return;
        }

        $stmt = $pdo->prepare("
            SELECT p.module_name, p.permission_name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cache = [];
        foreach ($rows as $row) {
            $key = strtolower($row['module_name']) . ':' . strtolower($row['permission_name']);
            $cache[$key] = true;
        }
        $_SESSION['_perm_cache'] = $cache;
    }
}

// ─── has_permission() ─────────────────────────────────────────────────────────
// Returns true if the current user has the given module + action permission.
// Super Admin always returns true.

if (!function_exists('has_permission')) {
    function has_permission(PDO $pdo, string $module, string $action): bool
    {
        $user = function_exists('get_auth_user') ? get_auth_user($pdo) : null;

        if (!$user) {
            return false;
        }

        // Load permissions if not yet loaded
        load_user_permissions($pdo, $user);

        // Super Admin wildcard
        if (!empty($_SESSION['_perm_cache']['*'])) {
            return true;
        }

        $key = strtolower($module) . ':' . strtolower($action);
        return !empty($_SESSION['_perm_cache'][$key]);
    }
}

// ─── require_permission() ─────────────────────────────────────────────────────
// Guards a page/action. Redirects to access_denied.php if permission is missing.

if (!function_exists('require_permission')) {
    function require_permission(PDO $pdo, string $module, string $action): void
    {
        if (!has_permission($pdo, $module, $action)) {
            // Determine access_denied.php path
            $depth  = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
            $prefix = str_repeat('../', max(0, $depth - 1));
            header('Location: ' . $prefix . 'admin/auth/access_denied.php');
            exit();
        }
    }
}

// ─── flush_permission_cache() ────────────────────────────────────────────────
// Call this after changing a user's role or role permissions so the cache
// is rebuilt on the next request.

if (!function_exists('flush_permission_cache')) {
    function flush_permission_cache(): void
    {
        unset($_SESSION['_perm_cache'], $_SESSION['_auth_user']);
    }
}
