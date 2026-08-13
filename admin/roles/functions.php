<?php
/**
 * Role Management Module - Helper Functions
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

if (!function_exists('require_admin_access')) {
    function require_admin_access(PDO $pdo): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
            header('Location: ../../index.php');
            exit();
        }

        $userName = $_SESSION['username'];
        $stmt = $pdo->prepare("SELECT role, role_id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$userName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            header('Location: ../../index.php');
            exit();
        }

        $roleString = strtolower((string)($row['role'] ?? ''));
        $roleId = (int)($row['role_id'] ?? 0);

        // Check legacy string role or role_id 1 (Super Admin)
        if (!in_array($roleString, ['admin', 'super_admin', 'organization_admin'], true) && $roleId !== 1) {
            header('Location: ../../dashboard.php');
            exit();
        }
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(PDO $pdo): ?int
    {
        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        $username = $_SESSION['username'] ?? '';
        if ($username === '') {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string
    {
        if (!empty($_SESSION['csrf_token'])) {
            return $_SESSION['csrf_token'];
        }

        if (!empty($_SESSION['roles_csrf_token'])) {
            $_SESSION['csrf_token'] = $_SESSION['roles_csrf_token'];
            return $_SESSION['csrf_token'];
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['roles_csrf_token'] = $_SESSION['csrf_token'];

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token(string $token): bool
    {
        $storedToken = $_SESSION['csrf_token'] ?? $_SESSION['roles_csrf_token'] ?? '';
        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($storedToken, $token);
    }
}

if (!function_exists('log_role_activity')) {
    function log_role_activity(PDO $pdo, string $action, string $actionType, string $details): void
    {
        $userId = get_current_user_id($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $actionType, $details, $ip, $ua]);
        } catch (Throwable $e) {
            // Audit table optional fallback
        }
    }
}

if (!function_exists('get_all_roles')) {
    function get_all_roles(PDO $pdo, string $search = '', string $statusFilter = '', int $page = 1, int $perPage = 10, string $sort = 'id', string $order = 'DESC'): array
    {
        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(role_name LIKE ? OR description LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($statusFilter !== '') {
            $where[] = 'status = ?';
            $params[] = $statusFilter === 'active' ? 1 : 0;
        }

        $allowedSorts = ['id', 'role_name', 'status', 'created_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $whereSql = implode(' AND ', $where);

        // Count query
        $countSql = "SELECT COUNT(*) FROM roles WHERE {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        // Data query
        $dataSql = "SELECT * FROM roles WHERE {$whereSql} ORDER BY {$sort} {$order} LIMIT {$perPage} OFFSET {$offset}";
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($params);
        $roles = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        // Augment roles with user count and permission count
        foreach ($roles as &$role) {
            $role['user_count'] = get_role_assigned_users_count($pdo, (int)$role['id'], $role['role_name']);
            $role['perm_count'] = count(get_role_permission_ids($pdo, (int)$role['id']));
        }

        return [
            'roles' => $roles,
            'total' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }
}

if (!function_exists('get_role_by_id')) {
    function get_role_by_id(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('role_name_exists')) {
    function role_name_exists(PDO $pdo, string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE LOWER(role_name) = LOWER(?) AND id != ?");
            $stmt->execute([trim($name), $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE LOWER(role_name) = LOWER(?)");
            $stmt->execute([trim($name)]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('create_role')) {
    function create_role(PDO $pdo, string $roleName, string $description, int $status = 1): ?int
    {
        $stmt = $pdo->prepare("INSERT INTO roles (role_name, description, status) VALUES (?, ?, ?)");
        if ($stmt->execute([trim($roleName), trim($description), $status])) {
            $newId = (int)$pdo->lastInsertId();
            log_role_activity($pdo, 'Role Created', 'roles', "Created role: '{$roleName}' (ID: {$newId})");
            return $newId;
        }
        return null;
    }
}

if (!function_exists('update_role')) {
    function update_role(PDO $pdo, int $id, string $roleName, string $description, int $status): bool
    {
        $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, description = ?, status = ? WHERE id = ?");
        $res = $stmt->execute([trim($roleName), trim($description), $status, $id]);
        if ($res) {
            log_role_activity($pdo, 'Role Updated', 'roles', "Updated role ID {$id}: '{$roleName}'");
        }
        return $res;
    }
}

if (!function_exists('get_role_assigned_users_count')) {
    function get_role_assigned_users_count(PDO $pdo, int $roleId, string $roleName): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? OR LOWER(role) = LOWER(?) OR LOWER(role) = LOWER(?)");
        $slug = strtolower(str_replace(' ', '_', $roleName));
        $stmt->execute([$roleId, $roleName, $slug]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('delete_role')) {
    function delete_role(PDO $pdo, int $id): array
    {
        $role = get_role_by_id($pdo, $id);
        if (!$role) {
            return ['success' => false, 'message' => 'Role not found.'];
        }

        $userCount = get_role_assigned_users_count($pdo, $id, $role['role_name']);
        if ($userCount > 0) {
            return [
                'success' => false,
                'message' => "Cannot delete role '{$role['role_name']}'. It is currently assigned to {$userCount} user(s)."
            ];
        }

        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        if ($stmt->execute([$id])) {
            log_role_activity($pdo, 'Role Deleted', 'roles', "Deleted role: '{$role['role_name']}' (ID: {$id})");
            return ['success' => true, 'message' => "Role '{$role['role_name']}' has been deleted successfully."];
        }

        return ['success' => false, 'message' => 'Failed to delete role.'];
    }
}

if (!function_exists('toggle_role_status')) {
    function toggle_role_status(PDO $pdo, int $id): bool
    {
        $role = get_role_by_id($pdo, $id);
        if (!$role) {
            return false;
        }

        $newStatus = (int)$role['status'] === 1 ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE roles SET status = ? WHERE id = ?");
        $res = $stmt->execute([$newStatus, $id]);

        if ($res) {
            $statusText = $newStatus === 1 ? 'Activated' : 'Deactivated';
            log_role_activity($pdo, 'Role Status Toggled', 'roles', "{$statusText} role '{$role['role_name']}' (ID: {$id})");
        }

        return $res;
    }
}

if (!function_exists('get_role_status_badge')) {
    function get_role_status_badge(int $status): string
    {
        return $status === 1
            ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>'
            : '<span class="badge bg-secondary"><i class="fas fa-minus-circle me-1"></i>Inactive</span>';
    }
}

if (!function_exists('get_all_permissions_grouped_by_module')) {
    function get_all_permissions_grouped_by_module(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT * FROM permissions ORDER BY module_name ASC, id ASC");
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module_name'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }

        return $grouped;
    }
}

if (!function_exists('get_role_permission_ids')) {
    function get_role_permission_ids(PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}

if (!function_exists('get_role_permissions_detailed')) {
    function get_role_permissions_detailed(PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare("
            SELECT p.* 
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.module_name ASC, p.id ASC
        ");
        $stmt->execute([$roleId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($permissions as $perm) {
            $grouped[$perm['module_name']][] = $perm;
        }

        return $grouped;
    }
}

if (!function_exists('save_role_permissions')) {
    function save_role_permissions(PDO $pdo, int $roleId, array $permissionIds): bool
    {
        $role = get_role_by_id($pdo, $roleId);
        if (!$role) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            // Remove existing
            $del = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $del->execute([$roleId]);

            // Insert new
            if (!empty($permissionIds)) {
                $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissionIds as $pId) {
                    $ins->execute([$roleId, (int)$pId]);
                }
            }

            $pdo->commit();
            log_role_activity($pdo, 'Role Permissions Updated', 'roles', "Updated permissions for role '{$role['role_name']}' (ID: {$roleId}). Count: " . count($permissionIds));
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
