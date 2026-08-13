<?php
/**
 * Users Management Module - Helper Functions
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

if (!function_exists('get_logged_in_user')) {
    function get_logged_in_user(PDO $pdo): ?array
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name, o.organization_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.username = ? AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(array $user): bool
    {
        $roleId = (int)($user['role_id'] ?? 0);
        $roleStr = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return ($roleId === 1 || in_array($roleStr, ['admin', 'super_admin', 'super admin'], true) || in_array($roleName, ['super admin', 'super_admin'], true));
    }
}

if (!function_exists('is_org_admin')) {
    function is_org_admin(array $user): bool
    {
        if (is_super_admin($user)) {
            return false;
        }
        $roleStr = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return (in_array($roleStr, ['organization_admin', 'organization admin'], true) || in_array($roleName, ['organization admin', 'organization_admin'], true));
    }
}

if (!function_exists('is_registrar')) {
    function is_registrar(array $user): bool
    {
        $roleStr = strtolower((string)($user['role'] ?? ''));
        $roleName = strtolower((string)($user['role_name'] ?? ''));

        return (in_array($roleStr, ['registrar'], true) || in_array($roleName, ['registrar'], true));
    }
}

if (!function_exists('require_user_module_access')) {
    function require_user_module_access(PDO $pdo): array
    {
        $user = get_logged_in_user($pdo);

        if (!$user) {
            header('Location: ../../index.php');
            exit();
        }

        // Registrar cannot manage users
        if (is_registrar($user)) {
            $_SESSION['user_error'] = 'Access Denied. Registrars do not have permission to manage users.';
            header('Location: ../../dashboard.php');
            exit();
        }

        if (!is_super_admin($user) && !is_org_admin($user)) {
            $_SESSION['user_error'] = 'Access Denied. Insufficient privileges to access Users Management.';
            header('Location: ../../dashboard.php');
            exit();
        }

        return $user;
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

        if (!empty($_SESSION['users_csrf_token'])) {
            $_SESSION['csrf_token'] = $_SESSION['users_csrf_token'];
            return $_SESSION['csrf_token'];
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['users_csrf_token'] = $_SESSION['csrf_token'];

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token(string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $storedToken = $_SESSION['csrf_token'] ?? $_SESSION['users_csrf_token'] ?? '';
        return hash_equals($storedToken, $token);
    }
}

if (!function_exists('log_user_activity')) {
    function log_user_activity(PDO $pdo, string $action, string $actionType, string $details): void
    {
        $userId = get_current_user_id($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $actionType, $details, $ip, $ua]);
        } catch (Throwable $e) {
            // Optional audit log fallback
        }
    }
}

if (!function_exists('get_active_organizations_scoped')) {
    function get_active_organizations_scoped(PDO $pdo, array $currentUser): array
    {
        if (is_super_admin($currentUser)) {
            $stmt = $pdo->query("SELECT id, organization_name, organization_code FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Organization Admin: Only their assigned organization
        $orgId = (int)($currentUser['organization_id'] ?? 0);
        if ($orgId > 0) {
            $stmt = $pdo->prepare("SELECT id, organization_name, organization_code FROM organizations WHERE id = ? AND deleted_at IS NULL AND status = 1 LIMIT 1");
            $stmt->execute([$orgId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [];
    }
}

if (!function_exists('get_active_roles_scoped')) {
    function get_active_roles_scoped(PDO $pdo, array $currentUser): array
    {
        if (is_super_admin($currentUser)) {
            $stmt = $pdo->query("SELECT id, role_name, description FROM roles WHERE status = 1 ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Organization Admin: Cannot assign Super Admin role
        $stmt = $pdo->query("SELECT id, role_name, description FROM roles WHERE status = 1 AND LOWER(role_name) NOT IN ('super admin', 'super_admin') AND id != 1 ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('is_target_super_admin')) {
    function is_target_super_admin(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.role, u.role_id, r.role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        return is_super_admin($row) || (int)$row['id'] === 1 || strtolower((string)$row['username']) === 'admin';
    }
}

if (!function_exists('get_all_users')) {
    function get_all_users(PDO $pdo, array $currentUser, string $search = '', string $orgFilter = '', string $roleFilter = '', string $statusFilter = '', int $page = 1, int $perPage = 10, string $sort = 'id', string $order = 'DESC'): array
    {
        $where = ['u.deleted_at IS NULL'];
        $params = [];

        // Scope restrictions for Organization Admin
        if (!is_super_admin($currentUser)) {
            $where[] = 'u.organization_id = ?';
            $params[] = (int)($currentUser['organization_id'] ?? 0);
        } elseif ($orgFilter !== '') {
            $where[] = 'u.organization_id = ?';
            $params[] = (int)$orgFilter;
        }

        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        if ($roleFilter !== '') {
            $where[] = 'u.role_id = ?';
            $params[] = (int)$roleFilter;
        }

        if ($statusFilter !== '') {
            $where[] = 'u.status = ?';
            $params[] = $statusFilter === 'active' ? 1 : 0;
        }

        $allowedSorts = ['id', 'username', 'full_name', 'email', 'status', 'created_at', 'last_login'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $whereSql = implode(' AND ', $where);

        // Count query
        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        // Data query
        $dataSql = "
            SELECT u.*, o.organization_name, o.organization_code, r.role_name
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE {$whereSql}
            ORDER BY u.{$sort} {$order}
            LIMIT {$perPage} OFFSET {$offset}
        ";
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($params);
        $users = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'users' => $users,
            'total' => $totalItems,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }
}

if (!function_exists('get_user_by_id')) {
    function get_user_by_id(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare("
            SELECT u.*, o.organization_name, o.organization_code, r.role_name
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('is_username_taken')) {
    function is_username_taken(PDO $pdo, string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?) AND id != ? AND deleted_at IS NULL");
            $stmt->execute([trim($username), $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?) AND deleted_at IS NULL");
            $stmt->execute([trim($username)]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('is_email_taken')) {
    function is_email_taken(PDO $pdo, string $email, ?int $excludeId = null): bool
    {
        if (trim($email) === '') {
            return false;
        }
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND deleted_at IS NULL");
            $stmt->execute([trim($email), $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND deleted_at IS NULL");
            $stmt->execute([trim($email)]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('is_mobile_taken')) {
    function is_mobile_taken(PDO $pdo, string $mobile, ?int $excludeId = null): bool
    {
        if (trim($mobile) === '') {
            return false;
        }
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE mobile = ? AND id != ? AND deleted_at IS NULL");
            $stmt->execute([trim($mobile), $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE mobile = ? AND deleted_at IS NULL");
            $stmt->execute([trim($mobile)]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('get_user_avatar_path')) {
    function get_user_avatar_path(?string $avatarName): string
    {
        $avatarFile = __DIR__ . '/assets/uploads/avatars/' . basename((string)$avatarName);
        if (!empty($avatarName) && file_exists($avatarFile)) {
            return 'assets/uploads/avatars/' . htmlspecialchars(basename($avatarName));
        }

        return '../../images/avatars/default.png';
    }
}

if (!function_exists('upload_user_avatar')) {
    function upload_user_avatar(array $file, string $targetDir): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No avatar image was uploaded.'];
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, and WEBP image files are allowed.'];
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Avatar image file must be 2MB or smaller.'];
        }

        $dir = rtrim($targetDir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $newName = 'usr_' . uniqid('', true) . '.' . $ext;
        $destination = $dir . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'message' => 'The avatar image could not be saved to disk.'];
        }

        return ['success' => true, 'file' => $newName, 'path' => $destination];
    }
}

if (!function_exists('delete_avatar_file')) {
    function delete_avatar_file(?string $avatarName): void
    {
        if (empty($avatarName) || $avatarName === 'default.png') {
            return;
        }

        $file = __DIR__ . '/assets/uploads/avatars/' . basename($avatarName);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('create_user')) {
    function create_user(PDO $pdo, array $data): ?int
    {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        // Fetch role slug string from role_id
        $roleId = (int)$data['role_id'];
        $stmtRole = $pdo->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
        $stmtRole->execute([$roleId]);
        $roleName = $stmtRole->fetchColumn() ?: 'viewer';
        $roleSlug = strtolower(str_replace(' ', '_', $roleName));

        $stmt = $pdo->prepare("
            INSERT INTO users (organization_id, role_id, username, password, email, full_name, mobile, avatar, role, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $res = $stmt->execute([
            !empty($data['organization_id']) ? (int)$data['organization_id'] : null,
            $roleId,
            trim($data['username']),
            $hashedPassword,
            trim($data['email']) ?: null,
            trim($data['full_name']),
            trim($data['mobile']) ?: null,
            $data['avatar'] ?? null,
            $roleSlug,
            isset($data['status']) ? (int)$data['status'] : 1
        ]);

        if ($res) {
            $newId = (int)$pdo->lastInsertId();
            log_user_activity($pdo, 'User Created', 'users', "Created user: '{$data['username']}' (ID: {$newId}) with role '{$roleName}'");
            return $newId;
        }

        return null;
    }
}

if (!function_exists('update_user')) {
    function update_user(PDO $pdo, int $id, array $data): bool
    {
        $roleId = (int)$data['role_id'];
        $stmtRole = $pdo->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
        $stmtRole->execute([$roleId]);
        $roleName = $stmtRole->fetchColumn() ?: 'viewer';
        $roleSlug = strtolower(str_replace(' ', '_', $roleName));

        $sql = "
            UPDATE users SET
                organization_id = ?,
                role_id = ?,
                role = ?,
                full_name = ?,
                email = ?,
                mobile = ?,
                status = ?
        ";
        $params = [
            !empty($data['organization_id']) ? (int)$data['organization_id'] : null,
            $roleId,
            $roleSlug,
            trim($data['full_name']),
            trim($data['email']) ?: null,
            trim($data['mobile']) ?: null,
            (int)$data['status']
        ];

        if (!empty($data['avatar'])) {
            $sql .= ", avatar = ?";
            $params[] = $data['avatar'];
        }

        $sql .= " WHERE id = ? AND deleted_at IS NULL";
        $params[] = $id;

        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute($params);

        if ($res) {
            log_user_activity($pdo, 'User Updated', 'users', "Updated user ID {$id}: '{$data['username']}'");
        }

        return $res;
    }
}

if (!function_exists('reset_user_password')) {
    function reset_user_password(PDO $pdo, int $id, string $newPassword): bool
    {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND deleted_at IS NULL");
        $res = $stmt->execute([$hashed, $id]);

        if ($res) {
            $user = get_user_by_id($pdo, $id);
            log_user_activity($pdo, 'Password Reset', 'users', "Reset password for user ID {$id} ('" . ($user['username'] ?? '') . "')");
        }

        return $res;
    }
}

if (!function_exists('toggle_user_status')) {
    function toggle_user_status(PDO $pdo, int $id): bool
    {
        if (is_target_super_admin($pdo, $id)) {
            return false; // Never deactivate Super Admin
        }

        $user = get_user_by_id($pdo, $id);
        if (!$user) {
            return false;
        }

        $newStatus = (int)$user['status'] === 1 ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND deleted_at IS NULL");
        $res = $stmt->execute([$newStatus, $id]);

        if ($res) {
            $statusText = $newStatus === 1 ? 'Activated' : 'Deactivated';
            log_user_activity($pdo, 'User Status Toggled', 'users', "{$statusText} user '{$user['username']}' (ID: {$id})");
        }

        return $res;
    }
}

if (!function_exists('delete_user')) {
    function delete_user(PDO $pdo, int $id, array $currentUser): array
    {
        if ((int)$currentUser['id'] === $id) {
            return ['success' => false, 'message' => 'You cannot delete your own logged-in account.'];
        }

        if (is_target_super_admin($pdo, $id)) {
            return ['success' => false, 'message' => 'Super Admin accounts cannot be deleted.'];
        }

        $user = get_user_by_id($pdo, $id);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found or already deleted.'];
        }

        // Soft delete
        $stmt = $pdo->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$id])) {
            delete_avatar_file($user['avatar'] ?? null);
            log_user_activity($pdo, 'User Deleted', 'users', "Soft-deleted user '{$user['username']}' (ID: {$id})");
            return ['success' => true, 'message' => "User '{$user['username']}' has been deleted successfully."];
        }

        return ['success' => false, 'message' => 'Failed to delete user.'];
    }
}

if (!function_exists('get_user_status_badge')) {
    function get_user_status_badge(int $status): string
    {
        return $status === 1
            ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>'
            : '<span class="badge bg-secondary"><i class="fas fa-minus-circle me-1"></i>Inactive</span>';
    }
}
