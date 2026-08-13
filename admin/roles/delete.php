<?php
/**
 * Role Management Module - Delete Role Handler
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

// Check admin access
require_admin_access($pdo);

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['id'])) {
    $_SESSION['role_error'] = 'Invalid request method.';
    header('Location: index.php');
    exit();
}

// Get role ID
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));

if ($id <= 0) {
    $_SESSION['role_error'] = 'Invalid role ID provided.';
    header('Location: index.php');
    exit();
}

// Validate CSRF token
$token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');

if (!validate_csrf_token($token)) {
    $_SESSION['role_error'] = 'Security validation failed. Please try again.';
    header('Location: index.php');
    exit();
}

// Check if role exists and get details for logging
try {
    $role = get_role_by_id($pdo, $id);
    if (!$role) {
        $_SESSION['role_error'] = 'Role not found or already deleted.';
        header('Location: index.php');
        exit();
    }

    // Check if role has users assigned
    $userCount = get_role_assigned_users_count($pdo, $id, $role['role_name']);
    if ($userCount > 0) {
        $_SESSION['role_error'] = 'Cannot delete role "' . htmlspecialchars($role['role_name']) . '" because it is assigned to ' . $userCount . ' user(s). Please reassign or remove users first.';
        header('Location: index.php');
        exit();
    }

    // Prevent deletion of system-critical roles
    $protectedRoles = ['Super Admin', 'Organization Admin', 'Registrar'];
    if (in_array($role['role_name'], $protectedRoles, true)) {
        $_SESSION['role_error'] = 'Cannot delete protected system role "' . htmlspecialchars($role['role_name']) . '".';
        header('Location: index.php');
        exit();
    }

} catch (PDOException $e) {
    $_SESSION['role_error'] = 'Database error: Unable to verify role details.';
    header('Location: index.php');
    exit();
}

// Perform deletion
try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Delete role permissions first (foreign key cascade should handle this, but safe to do manually)
    $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$id]);
    
    // Delete the role
    $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
    $stmt->execute([$id]);
    
    // Log the deletion in audit log
    $userId = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? 'Unknown';
    $roleName = $role['role_name'];
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Check if audit_log table exists
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'audit_log'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                'Role Deleted',
                'roles',
                'Deleted role: \'' . $roleName . '\' (ID: ' . $id . ')',
                $ipAddress,
                $userAgent
            ]);
        }
    } catch (PDOException $e) {
        // Audit log failure shouldn't stop the deletion
        error_log('Failed to log role deletion: ' . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    $_SESSION['role_message'] = 'Role "' . htmlspecialchars($roleName) . '" has been successfully deleted.';
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    // Check if it's a foreign key constraint error
    if (strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
        $_SESSION['role_error'] = 'Cannot delete this role because it is still referenced by other records. Please remove all associations first.';
    } else {
        $_SESSION['role_error'] = 'Database error: ' . $e->getMessage();
    }
}

// Redirect back to role list
header('Location: index.php');
exit();
?>