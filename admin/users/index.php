<?php
/**
 * Users Management Module - User Listing Page with Add/Edit Modals
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Users Management';
$currentUser = require_user_module_access($pdo);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit();
    }
    
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if ($action === 'add' || $action === 'edit') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $organizationId = (int)($_POST['organization_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $status = ((string)($_POST['status'] ?? '0') === '1') ? 1 : 0;
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');
        
        $errors = [];
        
        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if ($roleId <= 0) {
            $errors[] = 'Please select a role.';
        }
        
        if ($action === 'add') {
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }
            
            // Check if username already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = 'Username already exists.';
            }
            
            if (!empty($email)) {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = 'Email already exists.';
                }
            }
        } else {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $errors[] = 'Invalid user ID.';
            } else {
                // Check if username already exists (excluding current user)
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$username, $userId]);
                if ($stmt->fetch()) {
                    $errors[] = 'Username already exists.';
                }
                
                if (!empty($email)) {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL LIMIT 1');
                    $stmt->execute([$email, $userId]);
                    if ($stmt->fetch()) {
                        $errors[] = 'Email already exists.';
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit();
        }
        
        try {
            // Handle avatar upload
            $avatarPath = null;
            if (!empty($_FILES['avatar']['name'])) {
                $upload = upload_user_avatar($_FILES['avatar'], __DIR__ . '/assets/uploads/avatars');
                if (!$upload['success']) {
                    echo json_encode(['success' => false, 'message' => $upload['message']]);
                    exit();
                }
                $avatarPath = $upload['file'];
            }
            
            if ($action === 'add') {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, mobile, password, organization_id, role_id, status, avatar, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fullName, $username, $email, $mobile, $hashedPassword, $organizationId, $roleId, $status, $avatarPath, get_current_user_id($pdo)]);
                
                log_user_activity($pdo, 'Created user', 'user', 'Created user ' . $username, $pdo->lastInsertId());
                $response = ['success' => true, 'message' => 'User added successfully.', 'action' => 'add'];
            } else {
                $userId = (int)($_POST['user_id'] ?? 0);
                $updateFields = [];
                $params = [];
                
                $updateFields[] = 'full_name = ?';
                $params[] = $fullName;
                
                $updateFields[] = 'username = ?';
                $params[] = $username;
                
                $updateFields[] = 'email = ?';
                $params[] = $email;
                
                $updateFields[] = 'mobile = ?';
                $params[] = $mobile;
                
                $updateFields[] = 'organization_id = ?';
                $params[] = $organizationId;
                
                $updateFields[] = 'role_id = ?';
                $params[] = $roleId;
                
                $updateFields[] = 'status = ?';
                $params[] = $status;
                
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
                        exit();
                    }
                    if ($password !== $confirmPassword) {
                        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
                        exit();
                    }
                    $updateFields[] = 'password = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                
                if ($avatarPath !== null) {
                    $updateFields[] = 'avatar = ?';
                    $params[] = $avatarPath;
                }
                
                $params[] = $userId;
                
                $sql = 'UPDATE users SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                log_user_activity($pdo, 'Updated user', 'user', 'Updated user ' . $username, $userId);
                $response = ['success' => true, 'message' => 'User updated successfully.', 'action' => 'edit'];
            }
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        echo json_encode($response);
        exit();
    } elseif ($action === 'get_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                unset($user['password']);
                $response = ['success' => true, 'data' => $user];
            } else {
                $response = ['success' => false, 'message' => 'User not found.'];
            }
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        echo json_encode($response);
        exit();
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');
        if ($userId <= 0 || strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'Valid user ID and a password of at least 6 characters are required.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$userId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit();
            }
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            log_user_activity($pdo, 'Reset user password', 'user', 'Reset password for ' . $target['username'], $userId);
            $response = ['success' => true, 'message' => 'Password reset successfully.'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        echo json_encode($response);
        exit();
    } elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $currentStatus = ((string)($_POST['current_status'] ?? '0') === '1') ? 1 : 0;
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare('SELECT id, username, status, role_id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$userId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit();
            }
            if ((int)$target['id'] === (int)$currentUser['id']) {
                echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account.']);
                exit();
            }
            if (is_super_admin($target)) {
                echo json_encode(['success' => false, 'message' => 'Super Admin status cannot be changed.']);
                exit();
            }
            $newStatus = $currentStatus === 1 ? 0 : 1;
            $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $userId]);
            $label = $newStatus === 1 ? 'activated' : 'deactivated';
            log_user_activity($pdo, 'Changed user status', 'user', 'User ' . $target['username'] . ' ' . $label, $userId);
            $response = ['success' => true, 'message' => 'User ' . $label . ' successfully.', 'new_status' => $newStatus];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        echo json_encode($response);
        exit();
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare('SELECT id, username, role_id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$userId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit();
            }
            if ((int)$target['id'] === (int)$currentUser['id']) {
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
                exit();
            }
            if (is_super_admin($target)) {
                echo json_encode(['success' => false, 'message' => 'Super Admin cannot be deleted.']);
                exit();
            }
            $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?');
            $stmt->execute([$userId]);
            log_user_activity($pdo, 'Deleted user', 'user', 'Deleted user ' . $target['username'], $userId);
            $response = ['success' => true, 'message' => 'User deleted successfully.'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
        echo json_encode($response);
        exit();
    }
    
    echo json_encode($response);
    exit();
}

$search = trim($_GET['search'] ?? '');
$orgFilter = trim($_GET['org_id'] ?? '');
$roleFilter = trim($_GET['role_id'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$sort = trim($_GET['sort'] ?? 'id');
$order = trim($_GET['order'] ?? 'DESC');

$result = get_all_users($pdo, $currentUser, $search, $orgFilter, $roleFilter, $statusFilter, $page, $perPage, $sort, $order);
$users = $result['users'];
$totalUsers = $result['total'];
$totalPages = $result['total_pages'];
$currentPage = $result['page'];

$scopedOrgs = get_active_organizations_scoped($pdo, $currentUser);
$scopedRoles = get_active_roles_scoped($pdo, $currentUser);

// Stats
if (is_super_admin($currentUser)) {
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
    $inactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 0")->fetchColumn();
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
} else {
    $myOrgId = (int)($currentUser['organization_id'] ?? 0);
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 1 AND organization_id = {$myOrgId}")->fetchColumn();
    $inactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 0 AND organization_id = {$myOrgId}")->fetchColumn();
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND organization_id = {$myOrgId}")->fetchColumn();
}

// CSRF token helper
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Helper functions
function is_super_admin($user) {
    return (int)($user['role_id'] ?? 0) === 1 || strtolower($user['role'] ?? '') === 'super_admin';
}

function get_user_avatar_path($avatar) {
    $avatarFile = __DIR__ . '/assets/uploads/avatars/' . basename((string)$avatar);
    if (!empty($avatar) && file_exists($avatarFile)) {
        return 'assets/uploads/avatars/' . htmlspecialchars(basename($avatar));
    }
    return '../../images/avatars/default.png';
}

function get_user_status_badge($status) {
    if ($status == 1) {
        return '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
    }
    return '<span class="status-badge inactive"><i class="fas fa-minus-circle"></i>Inactive</span>';
}

function get_current_user_id($pdo) {
    return (int)($_SESSION['user_id'] ?? 0);
}

function log_user_activity($pdo, $action, $action_type, $details, $userId) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $action_type, $details, $ip, $userAgent]);
}

function upload_user_avatar($file, $targetDir) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'file' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Avatar upload failed.'];
    }
    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'message' => 'Avatar must be 2MB or smaller.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['success' => false, 'message' => 'Invalid avatar upload.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return ['success' => false, 'message' => 'Invalid image type. Use JPG, PNG, or WEBP.'];
    }
    $ext = $allowed[$mime];
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'Could not create upload directory.'];
    }
    $filename = 'avatar_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $targetDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['success' => false, 'message' => 'Failed to save avatar.'];
    }
    return ['success' => true, 'file' => $filename];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Users Management · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #d97706;
            --warning-soft: #fef5e0;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --info: #3b82f6;
            --info-soft: #dbeafe;
            --neutral-50: #f8fafc;
            --neutral-100: #f1f5f9;
            --neutral-200: #e2e8f0;
            --neutral-300: #cbd5e1;
            --neutral-400: #94a3b8;
            --neutral-500: #64748b;
            --neutral-600: #475569;
            --neutral-700: #334155;
            --neutral-800: #1e293b;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--neutral-50);
            color: var(--neutral-800);
            font-family: 'Inter', sans-serif;
        }

        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; }
        .dashboard-content { max-width: 1600px; margin: 0 auto; padding: 28px; }

        .breadcrumb-container { margin-bottom: 20px; }
        .breadcrumb { margin: 0; font-size: .86rem; }
        .breadcrumb a { text-decoration: none; color: var(--info); }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: 18px;
            padding: 18px;
            box-shadow: var(--shadow-sm);
            transition: .2s ease;
        }

        .stat-card.clickable { cursor: pointer; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-label { font-size: .7rem; color: var(--neutral-500); text-transform: uppercase; letter-spacing: .05em; }
        .stat-number { font-size: 1.7rem; font-weight: 700; }

        .main-card {
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .card-header-custom {
            padding: 20px;
            background: #fff;
            border-bottom: 1px solid var(--neutral-200);
        }

        .card-body-custom { padding: 20px; }
        .card-footer-custom {
            padding: 16px 20px;
            background: var(--neutral-50);
            border-top: 1px solid var(--neutral-200);
        }

        .quick-actions { gap: 8px; flex-wrap: wrap; }

        /* Responsive filter bar
           Desktop: keep all filters in one clean horizontal row.
           Tablet: allow wrapping.
           Mobile: stack controls vertically. */
        .filter-bar {
            width: 100%;
            margin-top: 12px;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            flex-wrap: nowrap;
        }

        .filter-form .filter-search {
            flex: 1 1 auto;
            min-width: 180px;
        }

        .filter-form .filter-select {
            flex: 0 1 180px;
            width: 180px;
            min-width: 150px;
        }

        .filter-form .filter-btn,
        .filter-form .filter-reset {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .filter-form .form-control,
        .filter-form .form-select {
            min-height: 40px;
            height: 40px;
            border-radius: 9px;
            font-size: .82rem;
            border: 1px solid var(--neutral-300);
        }

        @media (max-width: 1200px) {
            .filter-form {
                flex-wrap: wrap;
            }

            .filter-form .filter-search {
                flex: 1 1 100%;
                min-width: 0;
            }

            .filter-form .filter-select {
                flex: 1 1 180px;
                width: auto;
            }
        }

        .table-wrap {
            width: 100%;
            overflow: auto;
            border: 1px solid var(--neutral-200);
            border-radius: 12px;
        }

        .table {
            margin: 0;
            min-width: 700px;
            font-size: .8rem;
        }

        .table thead th {
            background: var(--neutral-50);
            color: var(--neutral-500);
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
            border-bottom: 2px solid var(--neutral-200);
            padding: 11px 9px;
            vertical-align: middle;
        }

        .table tbody td {
            padding: 10px 9px;
            border-bottom: 1px solid var(--neutral-100);
            vertical-align: middle;
        }

        .table tbody tr:hover td { background: #fbfdff; }

        .user-avatar-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--neutral-200);
            background: var(--neutral-100);
        }

        .user-name {
            font-weight: 700;
            color: var(--neutral-800);
            white-space: nowrap;
        }

        .muted { color: var(--neutral-500); }
        .small-text { font-size: .72rem; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .66rem;
            font-weight: 700;
            white-space: nowrap;
            background: #eef2f7;
            color: #64748b;
        }

        .status-badge.active { background: #dcfce7; color: #15803d; }
        .status-badge.inactive { background: #f1f5f9; color: #64748b; }

        .badge-custom {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 600;
            background: var(--info-soft);
            color: var(--info);
        }

        .badge-custom.bg-light {
            background: var(--neutral-100);
            color: var(--neutral-600);
        }

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; }
        .empty-state p { color: #64748b; }

        .pagination-custom {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
            flex-wrap: wrap;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .pagination-custom a {
            display: block;
            padding: 6px 10px;
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: 8px;
            text-decoration: none;
            color: var(--neutral-700);
            font-size: .78rem;
        }

        .pagination-custom .active a {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn-group-sm .btn { font-size: .75rem; padding: .3rem .5rem; }

        /* Modal Styles */
        .modal-content {
            border: 0;
            border-radius: 18px;
            box-shadow: var(--shadow-xl);
            max-height: 95vh;
        }

        .modal-header {
            border-bottom: 1px solid var(--neutral-200);
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border-radius: 18px 18px 0 0;
            padding: 1.25rem 1.5rem;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-header h5 {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--neutral-200);
            padding: 1rem 1.5rem;
        }

        .modal-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .modal-form-item.full { grid-column: 1 / -1; }

        .modal-form-item label {
            display: block;
            margin-bottom: 6px;
            font-size: .78rem;
            font-weight: 700;
            color: var(--neutral-700);
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .modal-form-item label .req { color: var(--danger); }

        .modal-form-item .form-control,
        .modal-form-item .form-select {
            min-height: 42px;
            border-radius: 9px;
            font-size: .86rem;
            border: 1px solid var(--neutral-300);
        }

        .modal-form-item .form-control:focus,
        .modal-form-item .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, .08);
        }

        .modal-form-item .form-control.is-invalid {
            border-color: var(--danger);
        }

        .form-hint {
            font-size: .72rem;
            color: var(--neutral-500);
            margin-top: 5px;
        }

        .avatar-upload-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1rem;
        }

        .avatar-preview-box {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--neutral-200);
            background: var(--neutral-100);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }

        .avatar-preview-box:hover {
            border-color: var(--primary);
        }

        .avatar-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.8rem;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 500;
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .avatar-upload-btn:hover {
            background: var(--primary);
            color: white;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: .6rem 1.1rem;
            border-radius: 9px;
            font-weight: 600;
            font-size: .86rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: .15s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            color: #fff;
        }

        .btn-outline-secondary {
            background: #fff;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-700);
        }

        .btn-outline-secondary:hover {
            background: var(--neutral-100);
        }

        .btn-outline-danger {
            background: transparent;
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: #fff;
        }

        .btn-outline-warning {
            background: transparent;
            border: 1px solid var(--warning);
            color: var(--warning);
        }

        .btn-outline-warning:hover {
            background: var(--warning);
            color: #fff;
        }

        .btn-outline-success {
            background: transparent;
            border: 1px solid var(--success);
            color: var(--success);
        }

        .btn-outline-success:hover {
            background: var(--success);
            color: #fff;
        }

        .btn-outline-info {
            background: transparent;
            border: 1px solid var(--info);
            color: var(--info);
        }

        .btn-outline-info:hover {
            background: var(--info);
            color: #fff;
        }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; }
            .dashboard-content { padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .modal-form-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr; }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .filter-form .filter-search,
            .filter-form .filter-select,
            .filter-form .filter-btn,
            .filter-form .filter-reset {
                width: 100%;
                min-width: 0;
                flex: 1 1 auto;
            }

            .modal-form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">

    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/../../includes/header.php'; ?>

        <div class="dashboard-content">

            <div class="breadcrumb-container">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="../../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
                        </li>
                        <li class="breadcrumb-item active">Users</li>
                    </ol>
                </nav>
            </div>

            <div id="alert-container"></div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-number text-primary"><?= $totalCount ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active</div>
                    <div class="stat-number text-success"><?= $activeCount ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Inactive</div>
                    <div class="stat-number" style="color:var(--neutral-500);"><?= $inactiveCount ?></div>
                </div>
                <div class="stat-card clickable" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <div class="stat-label">Quick Action</div>
                    <div class="stat-number" style="font-size:1rem;color:var(--primary);">
                        <i class="fas fa-user-plus me-1"></i>Add User
                    </div>
                </div>
            </div>

            <div class="main-card">
                <div class="card-header-custom">
                    <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                        <div>
                            <h5 class="mb-1 fw-bold">
                                <i class="fas fa-users text-primary me-2"></i>User Directory
                            </h5>
                            <div class="small muted">Manage system user accounts, roles, and permissions.</div>
                        </div>

                        <div class="quick-actions">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                <i class="fas fa-user-plus me-1"></i>Add User
                            </button>
                        </div>
                    </div>

                    <div class="filter-bar">
                        <form method="get" class="filter-form">
                            <input type="text" name="search" class="form-control filter-search" placeholder="Search users..."
                                   value="<?= htmlspecialchars($search) ?>">

                            <?php if (is_super_admin($currentUser)): ?>
                                <select name="org_id" class="form-select filter-select">
                                    <option value="">All Organizations</option>
                                    <?php foreach ($scopedOrgs as $org): ?>
                                        <option value="<?= (int)$org['id'] ?>" <?= $orgFilter === (string)$org['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($org['organization_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <select name="role_id" class="form-select filter-select">
                                <option value="">All Roles</option>
                                <?php foreach ($scopedRoles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= $roleFilter === (string)$r['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="status" class="form-select filter-select">
                                <option value="">All Statuses</option>
                                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i>Filter
                            </button>
                            <?php if ($search !== '' || $orgFilter !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                                <a href="index.php" class="btn btn-outline-secondary filter-reset">
                                    <i class="fas fa-rotate-left me-1"></i>Reset
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card-body-custom">
                    <div class="table-wrap">
                        <table class="table" id="usersTable">
                            <thead>
                                <tr>
                                    <th>Avatar</th>
                                    <th>Full Name / Username</th>
                                    <th>Organization</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fas fa-users"></i>
                                                <p>No users found matching your criteria.</p>
                                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                                    <i class="fas fa-user-plus me-1"></i>Add User
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php
                                        $isSuper = is_super_admin($u) || (int)$u['id'] === 1;
                                        $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                                        ?>
                                        <tr data-id="<?= (int)$u['id'] ?>">
                                            <td>
                                                <img src="<?= htmlspecialchars(get_user_avatar_path($u['avatar'] ?? '')) ?>"
                                                     class="user-avatar-thumb" alt="Avatar">
                                            </td>
                                            <td>
                                                <div class="user-name"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                                                <div class="small-text muted">@<?= htmlspecialchars($u['username']) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge-custom bg-light">
                                                    <i class="fas fa-building me-1 text-primary"></i>
                                                    <?= htmlspecialchars($u['organization_name'] ?: 'System Global') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-custom">
                                                    <i class="fas fa-user-shield me-1"></i>
                                                    <?= htmlspecialchars($u['role_name'] ?: ucfirst($u['role'] ?? 'User')) ?>
                                                </span>
                                            </td>
                                            <td class="status-cell"><?= get_user_status_badge((int)$u['status']) ?></td>
                                            <td class="small muted">
                                                <?= !empty($u['last_login']) ? date('M d, Y', strtotime($u['last_login'])) : 'Never' ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="view.php?id=<?= (int)$u['id'] ?>" class="btn btn-outline-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button class="btn btn-outline-secondary edit-btn" title="Edit"
                                                            data-id="<?= (int)$u['id'] ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning reset-password-btn" title="Reset Password"
                                                            data-id="<?= (int)$u['id'] ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php if (!$isSuper): ?>
                                                        <button class="btn btn-outline-<?= (int)$u['status'] === 1 ? 'dark' : 'success' ?> toggle-status-btn"
                                                                title="<?= (int)$u['status'] === 1 ? 'Deactivate' : 'Activate' ?>"
                                                                data-id="<?= (int)$u['id'] ?>" data-status="<?= (int)$u['status'] ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!$isSuper && !$isSelf): ?>
                                                        <button class="btn btn-outline-danger delete-btn" title="Delete"
                                                                data-id="<?= (int)$u['id'] ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="card-footer-custom">
                        <ul class="pagination-custom">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="<?= $i === $currentPage ? 'active' : '' ?>">
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&org_id=<?= urlencode($orgFilter) ?>&role_id=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <?php include __DIR__ . '/../../includes/footer.php'; ?>
    </main>
</div>

<!-- ============================================ -->
<!-- ADD USER MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="fas fa-user-plus"></i> Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addUserForm" enctype="multipart/form-data" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="ajax_action" value="add">

                    <div class="avatar-upload-container">
                        <div class="avatar-preview-box">
                            <img src="../../images/avatars/default.png" id="addAvatarPreview" alt="Avatar Preview">
                        </div>
                        <label for="addAvatar" class="avatar-upload-btn">
                            <i class="fas fa-camera me-1"></i>Upload Photo
                        </label>
                        <input type="file" name="avatar" id="addAvatar" class="d-none" accept=".jpg,.jpeg,.png,.webp">
                        <div class="form-hint">Allowed: JPG, PNG, WEBP (Max 2MB)</div>
                    </div>

                    <div class="modal-form-grid">
                        <div class="modal-form-item">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="full_name" required placeholder="e.g. John Doe">
                        </div>

                        <div class="modal-form-item">
                            <label>Username <span class="req">*</span></label>
                            <input type="text" class="form-control" name="username" required placeholder="e.g. johndoe">
                        </div>

                        <div class="modal-form-item">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email" placeholder="john@example.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Mobile</label>
                            <input type="text" class="form-control" name="mobile" placeholder="e.g. +1234567890">
                        </div>

                        <div class="modal-form-item">
                            <label>Password <span class="req">*</span></label>
                            <input type="password" class="form-control" name="password" required placeholder="Min 6 characters">
                            <div class="form-hint">Password must be at least 6 characters.</div>
                        </div>

                        <div class="modal-form-item">
                            <label>Confirm Password <span class="req">*</span></label>
                            <input type="password" class="form-control" name="confirm_password" required placeholder="Re-enter password">
                        </div>

                        <div class="modal-form-item">
                            <label>Organization</label>
                            <select name="organization_id" class="form-select" <?= !is_super_admin($currentUser) ? 'disabled' : '' ?>>
                                <option value="">System Default</option>
                                <?php foreach ($scopedOrgs as $org): ?>
                                    <option value="<?= (int)$org['id'] ?>" <?= ($currentUser['organization_id'] ?? 0) == $org['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($org['organization_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!is_super_admin($currentUser)): ?>
                                <input type="hidden" name="organization_id" value="<?= (int)($currentUser['organization_id'] ?? 0) ?>">
                            <?php endif; ?>
                        </div>

                        <div class="modal-form-item">
                            <label>Role <span class="req">*</span></label>
                            <select name="role_id" class="form-select" required>
                                <option value="">Select Role</option>
                                <?php foreach ($scopedRoles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="modal-form-item full">
                            <label>Status</label>
                            <select name="status" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            <div class="form-hint">Inactive users cannot log in.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- EDIT USER MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="fas fa-user-edit"></i> Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editUserForm" enctype="multipart/form-data" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="ajax_action" value="edit">
                    <input type="hidden" name="user_id" id="editUserId" value="">

                    <div class="avatar-upload-container">
                        <div class="avatar-preview-box">
                            <img src="../../images/avatars/default.png" id="editAvatarPreview" alt="Avatar Preview">
                        </div>
                        <label for="editAvatar" class="avatar-upload-btn">
                            <i class="fas fa-camera me-1"></i>Change Photo
                        </label>
                        <input type="file" name="avatar" id="editAvatar" class="d-none" accept=".jpg,.jpeg,.png,.webp">
                        <div class="form-hint">Leave empty to keep current. Allowed: JPG, PNG, WEBP (Max 2MB)</div>
                        <div id="editCurrentAvatar" class="mt-2"></div>
                    </div>

                    <div class="modal-form-grid">
                        <div class="modal-form-item">
                            <label>Full Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="full_name" id="editFullName" required placeholder="e.g. John Doe">
                        </div>

                        <div class="modal-form-item">
                            <label>Username <span class="req">*</span></label>
                            <input type="text" class="form-control" name="username" id="editUsername" required placeholder="e.g. johndoe">
                        </div>

                        <div class="modal-form-item">
                            <label>Email</label>
                            <input type="email" class="form-control" name="email" id="editEmail" placeholder="john@example.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Mobile</label>
                            <input type="text" class="form-control" name="mobile" id="editMobile" placeholder="e.g. +1234567890">
                        </div>

                        <div class="modal-form-item">
                            <label>New Password</label>
                            <input type="password" class="form-control" name="password" id="editPassword" placeholder="Leave empty to keep current">
                            <div class="form-hint">Leave empty to keep current password.</div>
                        </div>

                        <div class="modal-form-item">
                            <label>Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" id="editConfirmPassword" placeholder="Re-enter new password">
                        </div>

                        <div class="modal-form-item">
                            <label>Organization</label>
                            <select name="organization_id" class="form-select" id="editOrganization" <?= !is_super_admin($currentUser) ? 'disabled' : '' ?>>
                                <option value="">System Default</option>
                                <?php foreach ($scopedOrgs as $org): ?>
                                    <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['organization_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!is_super_admin($currentUser)): ?>
                                <input type="hidden" name="organization_id" value="<?= (int)($currentUser['organization_id'] ?? 0) ?>">
                            <?php endif; ?>
                        </div>

                        <div class="modal-form-item">
                            <label>Role <span class="req">*</span></label>
                            <select name="role_id" class="form-select" id="editRole" required>
                                <option value="">Select Role</option>
                                <?php foreach ($scopedRoles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="modal-form-item full">
                            <label>Status</label>
                            <select name="status" class="form-select" id="editStatus">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            <div class="form-hint">Inactive users cannot log in.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- RESET PASSWORD MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);">
                <h5><i class="fas fa-key"></i> Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="resetPasswordForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="ajax_action" value="reset_password">
                    <input type="hidden" name="user_id" id="resetUserId" value="">
                    <p>Reset password for: <strong id="resetUsername" class="text-primary"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="new_password" id="newPassword" class="form-control" placeholder="Enter new password..." required minlength="6">
                            <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="Generate Secure Password">
                                <i class="fas fa-random"></i> Generate
                            </button>
                        </div>
                        <div class="form-hint">Password must be at least 6 characters.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Save Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- DELETE CONFIRMATION MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);">
                <h5><i class="fas fa-user-slash"></i> Delete User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" id="deleteCsrfToken" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="user_id" id="deleteUserId" value="">
                <p>Are you sure you want to delete user <strong id="deleteUsername" class="text-danger"></strong>?</p>
                <p class="text-muted small mb-0">This will soft-delete the user account and prevent them from logging in.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash-alt me-1"></i>Delete User
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function() {
    'use strict';

    // ============================================
    // HELPER FUNCTIONS
    // ============================================

    function showAlert(message, type = 'success') {
        const container = document.getElementById('alert-container');
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        container.appendChild(alert);
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    }

    function ajaxRequest(formData, callback) {
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (callback) callback(data);
        })
        .catch(error => {
            showAlert('An error occurred: ' + error.message, 'danger');
        });
    }

    // ============================================
    // AVATAR PREVIEW
    // ============================================

    document.getElementById('addAvatar')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('addAvatarPreview').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    document.getElementById('editAvatar')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('editAvatarPreview').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // ============================================
    // ADD USER
    // ============================================

    const addForm = document.getElementById('addUserForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            submitBtn.disabled = true;

            ajaxRequest(formData, function(data) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;

                if (data.success) {
                    showAlert(data.message, 'success');
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addUserModal'));
                    modal.hide();
                    addForm.reset();
                    document.getElementById('addAvatarPreview').src = '../../images/avatars/default.png';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    }

    // ============================================
    // EDIT USER
    // ============================================

    const editModal = document.getElementById('editUserModal');
    let editModalInstance = null;

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');

            if (!editModalInstance) {
                editModalInstance = new bootstrap.Modal(editModal);
            }

            const form = document.getElementById('editUserForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
            submitBtn.disabled = true;

            const formData = new FormData();
            formData.append('ajax_action', 'get_user');
            formData.append('user_id', userId);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            ajaxRequest(formData, function(data) {
                if (data.success) {
                    const user = data.data;
                    document.getElementById('editUserId').value = user.id;
                    document.getElementById('editFullName').value = user.full_name || '';
                    document.getElementById('editUsername').value = user.username || '';
                    document.getElementById('editEmail').value = user.email || '';
                    document.getElementById('editMobile').value = user.mobile || '';
                    document.getElementById('editStatus').value = user.status || 1;

                    // Set organization
                    const orgSelect = document.getElementById('editOrganization');
                    if (orgSelect) {
                        orgSelect.value = user.organization_id || '';
                    }

                    // Set role
                    const roleSelect = document.getElementById('editRole');
                    if (roleSelect) {
                        roleSelect.value = user.role_id || '';
                    }

                    // Show current avatar
                    const avatarContainer = document.getElementById('editCurrentAvatar');
                    if (user.avatar) {
                        avatarContainer.innerHTML = `
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <img src="assets/uploads/avatars/${user.avatar}" 
                                     class="user-avatar-thumb" alt="Current Avatar"
                                     style="width:50px;height:50px;">
                                <span class="small muted">Current photo</span>
                            </div>
                        `;
                        document.getElementById('editAvatarPreview').src = 'assets/uploads/avatars/' + user.avatar;
                    } else {
                        avatarContainer.innerHTML = '';
                        document.getElementById('editAvatarPreview').src = '../../images/avatars/default.png';
                    }

                    submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Update User';
                    submitBtn.disabled = false;

                    editModalInstance.show();
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    });

    const editForm = document.getElementById('editUserForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
            submitBtn.disabled = true;

            ajaxRequest(formData, function(data) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;

                if (data.success) {
                    showAlert(data.message, 'success');
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editUserModal'));
                    modal.hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    }

    // ============================================
    // RESET PASSWORD
    // ============================================

    const resetModal = document.getElementById('resetPasswordModal');
    let resetModalInstance = null;

    document.querySelectorAll('.reset-password-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');

            if (!resetModalInstance) {
                resetModalInstance = new bootstrap.Modal(resetModal);
            }

            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').textContent = username;
            document.getElementById('newPassword').value = '';
            resetModalInstance.show();
        });
    });

    document.getElementById('generatePasswordBtn')?.addEventListener('click', function() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('newPassword').value = password;
    });

    document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
        submitBtn.disabled = true;

        ajaxRequest(formData, function(data) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;

            if (data.success) {
                showAlert(data.message, 'success');
                const modal = bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal'));
                modal.hide();
            } else {
                showAlert(data.message, 'danger');
            }
        });
    });

    // ============================================
    // DELETE USER
    // ============================================

    const deleteModal = document.getElementById('deleteUserModal');
    let deleteModalInstance = null;

    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');

            if (!deleteModalInstance) {
                deleteModalInstance = new bootstrap.Modal(deleteModal);
            }

            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            deleteModalInstance.show();
        });
    });

    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
        const userId = document.getElementById('deleteUserId').value;
        const token = document.getElementById('deleteCsrfToken').value;

        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
        this.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'delete');
        formData.append('user_id', userId);
        formData.append('csrf_token', token);

        ajaxRequest(formData, function(data) {
            document.getElementById('confirmDeleteBtn').innerHTML = '<i class="fas fa-trash-alt me-1"></i>Delete User';
            document.getElementById('confirmDeleteBtn').disabled = false;

            if (data.success) {
                showAlert(data.message, 'success');
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteUserModal'));
                modal.hide();
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showAlert(data.message, 'danger');
            }
        });
    });

    // ============================================
    // TOGGLE STATUS
    // ============================================

    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const currentStatus = this.getAttribute('data-status');
            const token = document.querySelector('input[name="csrf_token"]').value;
            const row = this.closest('tr');

            const formData = new FormData();
            formData.append('ajax_action', 'toggle_status');
            formData.append('user_id', userId);
            formData.append('current_status', currentStatus);
            formData.append('csrf_token', token);

            ajaxRequest(formData, function(data) {
                if (data.success) {
                    showAlert(data.message, 'success');
                    const statusCell = row.querySelector('.status-cell');
                    if (data.new_status == 1) {
                        statusCell.innerHTML = '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
                    } else {
                        statusCell.innerHTML = '<span class="status-badge inactive"><i class="fas fa-minus-circle"></i>Inactive</span>';
                    }
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    });

    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            document.querySelector('input[name="search"]')?.focus();
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('addUserModal'));
            modal.show();
        }
    });

    // ============================================
    // AUTO-GENERATE USERNAME FROM FULL NAME (Add Modal)
    // ============================================

    document.querySelector('#addUserModal input[name="full_name"]')?.addEventListener('blur', function() {
        const usernameField = document.querySelector('#addUserModal input[name="username"]');
        if (!usernameField.value.trim() && this.value.trim()) {
            const nameParts = this.value.trim().toLowerCase().split(' ');
            let suggestion = nameParts[0];
            if (nameParts.length > 1) {
                suggestion += nameParts[nameParts.length - 1].charAt(0);
            }
            suggestion = suggestion.replace(/[^a-z0-9]/g, '');
            usernameField.value = suggestion;
        }
    });

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);

    // ============================================
    // RESET MODAL ON HIDE
    // ============================================

    document.getElementById('addUserModal')?.addEventListener('hidden.bs.modal', function() {
        this.querySelector('form').reset();
        document.getElementById('addAvatarPreview').src = '../../images/avatars/default.png';
        this.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    });

})();
</script>
</body>
</html>