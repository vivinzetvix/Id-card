<?php
/**
 * Authentication Middleware
 * Provides role-based authentication guards.
 * Requires session.php and config.php to be loaded before this file.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Helper: Resolve Login Page Path ──────────────────────────────────────────

if (!function_exists('auth_login_url')) {
    function auth_login_url(): string
    {
        $depth = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
        return str_repeat('../', max(0, $depth - 1)) . 'index.php';
    }
}

// ─── Helper: Resolve Dashboard Path ───────────────────────────────────────────

if (!function_exists('auth_dashboard_url')) {
    function auth_dashboard_url(): string
    {
        $depth = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
        return str_repeat('../', max(0, $depth - 1)) . 'dashboard.php';
    }
}

// ─── require_login() ──────────────────────────────────────────────────────────
// Redirect to login if session is not active. Must be called at the top of
// every protected page.

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
            header('Location: ' . auth_login_url());
            exit();
        }
    }
}

// ─── get_auth_user() ──────────────────────────────────────────────────────────
// Returns the full user row from the database for the currently logged-in user.
// Caches the result in $_SESSION['_auth_user'] for the request lifetime.

if (!function_exists('get_auth_user')) {
    function get_auth_user(PDO $pdo): ?array
    {
        if (!empty($_SESSION['_auth_user'])) {
            return $_SESSION['_auth_user'];
        }

        if (empty($_SESSION['username'])) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name, r.status AS role_status, o.organization_name, o.status AS org_status
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.username = ? AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $_SESSION['_auth_user'] = $user;
        }

        return $user ?: null;
    }
}

// ─── require_super_admin() ────────────────────────────────────────────────────

if (!function_exists('require_super_admin')) {
    function require_super_admin(PDO $pdo): array
    {
        require_login();
        $user = get_auth_user($pdo);

        if (!$user || !auth_is_super_admin($user)) {
            header('Location: ' . auth_dashboard_url());
            exit();
        }

        return $user;
    }
}

// ─── require_organization_admin() ────────────────────────────────────────────

if (!function_exists('require_organization_admin')) {
    function require_organization_admin(PDO $pdo): array
    {
        require_login();
        $user = get_auth_user($pdo);

        if (!$user || (!auth_is_super_admin($user) && !auth_is_org_admin($user))) {
            header('Location: ' . auth_dashboard_url());
            exit();
        }

        return $user;
    }
}

// ─── require_registrar() ──────────────────────────────────────────────────────

if (!function_exists('require_registrar')) {
    function require_registrar(PDO $pdo): array
    {
        require_login();
        $user = get_auth_user($pdo);

        if (!$user) {
            header('Location: ' . auth_login_url());
            exit();
        }

        return $user;
    }
}

// ─── Role Detection Helpers ───────────────────────────────────────────────────

if (!function_exists('auth_is_super_admin')) {
    function auth_is_super_admin(array $user): bool
    {
        $roleId   = (int)($user['role_id'] ?? 0);
        $roleSlug = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return $roleId === 1
            || in_array($roleSlug, ['admin', 'super_admin', 'super admin'], true)
            || in_array($roleName, ['super admin', 'super_admin'], true);
    }
}

if (!function_exists('auth_is_org_admin')) {
    function auth_is_org_admin(array $user): bool
    {
        if (auth_is_super_admin($user)) {
            return false;
        }
        $roleSlug = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return in_array($roleSlug, ['organization_admin', 'organization admin'], true)
            || in_array($roleName, ['organization admin', 'organization_admin'], true);
    }
}

if (!function_exists('auth_is_registrar')) {
    function auth_is_registrar(array $user): bool
    {
        $roleSlug = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return in_array($roleSlug, ['registrar'], true)
            || in_array($roleName, ['registrar'], true);
    }
}

// ─── get_auth_role_label() ────────────────────────────────────────────────────

if (!function_exists('get_auth_role_label')) {
    function get_auth_role_label(array $user): string
    {
        if (!empty($user['role_name'])) {
            // UI display only: Organization Admin → Admin
            if (strcasecmp((string)$user['role_name'], 'Organization Admin') === 0) {
                return 'Admin';
            }
            return $user['role_name'];
        }
        if (!empty($user['role'])) {
            return ucwords(str_replace('_', ' ', $user['role']));
        }
        return 'User';
    }
}

// ─── Organization ownership helpers (Phase 3 Module 1) ───────────────────────

if (!function_exists('user_can_access_organization')) {
    /**
     * Super Admin may access any org. Others may only access their own org_id.
     */
    function user_can_access_organization(array $user, $resourceOrgId): bool
    {
        if (auth_is_super_admin($user)) {
            return true;
        }
        $userOrg = (int)($user['organization_id'] ?? 0);
        $resourceOrg = (int)($resourceOrgId ?? 0);
        return $userOrg > 0 && $resourceOrg > 0 && $userOrg === $resourceOrg;
    }
}

if (!function_exists('fetch_member_for_user')) {
    /**
     * Load a non-deleted member and enforce organization ownership.
     * Returns null when missing or cross-org access is attempted.
     */
    function fetch_member_for_user(PDO $pdo, array $user, int $memberId, bool $includeArchived = false): ?array
    {
        if ($memberId <= 0) {
            return null;
        }
        $deletedClause = $includeArchived ? '' : ' AND m.deleted_at IS NULL';
        $stmt = $pdo->prepare(
            'SELECT m.*, o.organization_name, o.logo AS org_logo, o.project_type,
                    o.address AS org_address, o.phone AS org_phone, o.email AS org_email,
                    o.website AS org_website
             FROM id_members m
             LEFT JOIN organizations o ON o.id = m.organization_id
             WHERE m.id = ?' . $deletedClause . '
             LIMIT 1'
        );
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return null;
        }
        if (!user_can_access_organization($user, $member['organization_id'] ?? null)) {
            return null;
        }
        return $member;
    }
}

if (!function_exists('template_usable_for_organization')) {
    /**
     * Template must be active and either owned by the organization or global (NULL/0).
     */
    function template_usable_for_organization(PDO $pdo, int $templateId, $organizationId): bool
    {
        if ($templateId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT organization_id, status, deleted_at
             FROM card_templates
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl || (int)$tpl['status'] !== 1 || !empty($tpl['deleted_at'])) {
            return false;
        }
        $tplOrg = (int)($tpl['organization_id'] ?? 0);
        $orgId = (int)($organizationId ?? 0);
        // Global templates (NULL/0) are usable by any org; otherwise orgs must match.
        return $tplOrg === 0 || ($orgId > 0 && $tplOrg === $orgId);
    }
}
