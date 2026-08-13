<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';

$page_title = 'My Profile';
$message = '';
$error = '';

// Get current user info
$username = $_SESSION['username'];
$userId = $_SESSION['user_id'] ?? 0;

// Fetch user details with role and organization
$stmt = $conn->prepare("
    SELECT u.*, 
           r.role_name,
           o.organization_name,
           o.organization_code,
           o.logo as org_logo
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN organizations o ON u.organization_id = o.id
    WHERE u.username = ?
");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error'] = 'User not found';
    header("Location: logout.php");
    exit();
}

// Get user statistics
$stats = [];

// The current member schema has no created_by column.
$stmt = $conn->prepare("SELECT COUNT(*) FROM id_members");
$stmt->execute();
$stats['total_members'] = $stmt->get_result()->fetch_row()[0] ?? 0;
$stmt->close();

// Generated cards also have no created_by column.
$stmt = $conn->prepare("SELECT COUNT(*) FROM generated_cards");
$stmt->execute();
$stats['total_cards'] = $stmt->get_result()->fetch_row()[0] ?? 0;
$stmt->close();

// Recent activity from audit_log
$recent_activity = [];
$audit_exists = $conn->query("SHOW TABLES LIKE 'audit_log'")->num_rows > 0;
if ($audit_exists) {
    $stmt = $conn->prepare("
        SELECT action, action_type, details, created_at 
        FROM audit_log 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $recent_activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get action types count
$action_types = [];
if ($audit_exists) {
    $stmt = $conn->prepare("SELECT action_type, COUNT(*) as count FROM audit_log WHERE user_id = ? GROUP BY action_type");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $action_types = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        
        // Validate email
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } else {
            // Check if email is taken by another user
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $userId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = "Email already in use by another account";
                }
                $stmt->close();
            }
            
            if (empty($error)) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, mobile = ? WHERE id = ?");
                $stmt->bind_param("sssi", $full_name, $email, $mobile, $userId);
                if ($stmt->execute()) {
                    $message = "Profile updated successfully!";
                    $_SESSION['full_name'] = $full_name;
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                    $user['mobile'] = $mobile;
                    
                    // Log activity
                    logAuditActivity($conn, $username, 'Updated profile', 'users', 'Profile updated');
                } else {
                    $error = "Failed to update profile: " . $conn->error;
                }
                $stmt->close();
            }
        }
    }
}

// Handle avatar upload
if (isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file = $_FILES['avatar'];
        $file_type = mime_content_type($file['tmp_name']);
        $file_size = $file['size'];
        $file_error = $file['error'];
        
        if ($file_error !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $error = $upload_errors[$file_error] ?? 'Unknown upload error';
        } elseif (!in_array($file_type, $allowed_types)) {
            $error = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP";
        } elseif ($file_size > $max_size) {
            $error = "File too large. Maximum size: 5MB";
        } else {
            // Create upload directory if not exists
            $upload_dir = __DIR__ . '/admin/users/assets/uploads/avatars/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Delete old avatar if exists
            if (!empty($user['avatar']) && file_exists($upload_dir . $user['avatar'])) {
                unlink($upload_dir . $user['avatar']);
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $target_path = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                // Update database
                $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->bind_param("si", $filename, $userId);
                if ($stmt->execute()) {
                    $message = "Avatar updated successfully!";
                    $user['avatar'] = $filename;
                    $_SESSION['avatar'] = $filename;
                    logAuditActivity($conn, $username, 'Updated avatar', 'users', 'Profile avatar updated');
                } else {
                    $error = "Failed to update avatar in database";
                    unlink($target_path);
                }
                $stmt->close();
            } else {
                $error = "Failed to upload avatar";
            }
        }
    }
}

// Handle avatar removal
if (isset($_POST['remove_avatar'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        if (!empty($user['avatar'])) {
            $upload_dir = __DIR__ . '/admin/users/assets/uploads/avatars/';
            if (file_exists($upload_dir . $user['avatar'])) {
                unlink($upload_dir . $user['avatar']);
            }
            
            $stmt = $conn->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
            $stmt->bind_param("i", $userId);
            if ($stmt->execute()) {
                $message = "Avatar removed successfully!";
                $user['avatar'] = null;
                $_SESSION['avatar'] = null;
                logAuditActivity($conn, $username, 'Removed avatar', 'users', 'Profile avatar removed');
            } else {
                $error = "Failed to remove avatar";
            }
            $stmt->close();
        }
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate
        if (empty($current_password)) {
            $error = "Current password is required";
        } elseif (empty($new_password)) {
            $error = "New password is required";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters";
        } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $error = "New password must include uppercase, lowercase, and number";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($row && password_verify($current_password, $row['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $userId);
                if ($stmt->execute()) {
                    $message = "Password changed successfully!";
                    logAuditActivity($conn, $username, 'Changed password', 'users', 'Password changed');
                } else {
                    $error = "Failed to change password: " . $conn->error;
                }
                $stmt->close();
            } else {
                $error = "Current password is incorrect";
            }
        }
    }
}

// Helper function
function logAuditActivity($conn, $username, $action, $action_type, $details) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssss", $user_id, $action, $action_type, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

function getRoleColor($role) {
    $colors = [
        'Super Admin' => 'danger',
        'admin' => 'danger',
        'Organization Admin' => 'warning',
        'Registrar' => 'primary',
        'editor' => 'info',
        'viewer' => 'secondary'
    ];
    return $colors[$role] ?? 'secondary';
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Use the same avatar storage used by the user-management module.
$avatar_url = 'images/avatars/default.png';
if (!empty($user['avatar']) && file_exists(__DIR__ . '/admin/users/assets/uploads/avatars/' . basename($user['avatar']))) {
    $avatar_url = 'admin/users/assets/uploads/avatars/' . rawurlencode(basename($user['avatar']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Profile · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #f4b740;
            --warning-soft: #fef5e0;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --info: #3b82f6;
            --info-soft: #dbeafe;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-400: #9ca3af;
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            line-height: 1.5;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: var(--neutral-50);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        .content-area {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            gap: 0.5rem;
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
            font-size: 0.875rem;
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease;
        }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert .btn-close-custom {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.5;
            padding: 0 0.25rem;
        }
        .alert .btn-close-custom:hover { opacity: 1; }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Profile Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: var(--radius-2xl);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            padding: 2rem 1.5rem;
            text-align: center;
            color: white;
        }

        .profile-avatar-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 1rem;
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
            box-shadow: var(--shadow-lg);
            background: var(--neutral-200);
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: var(--success);
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .avatar-upload-btn:hover {
            transform: scale(1.1);
            background: #0d8b5e;
        }

        .profile-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .profile-role-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 9999px;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .profile-username {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .profile-body {
            padding: 1.5rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--neutral-200);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 36px;
            height: 36px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1rem;
            flex-shrink: 0;
        }

        .info-content {
            flex: 1;
            min-width: 0;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-bottom: 0.15rem;
        }

        .info-value {
            font-weight: 500;
            color: var(--neutral-700);
            word-break: break-word;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 0.75rem;
            text-align: center;
        }

        .stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.688rem;
            color: var(--neutral-500);
        }

        /* Settings Card */
        .settings-card {
            background: white;
            border-radius: var(--radius-2xl);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .settings-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }

        .settings-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-700);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .settings-header h2 i {
            color: var(--primary);
        }

        .settings-body {
            padding: 1.5rem;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--neutral-700);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-control {
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .form-control:read-only {
            background: var(--neutral-100);
            cursor: not-allowed;
        }

        .input-hint {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-top: 0.15rem;
        }

        /* Password Section */
        .password-section {
            background: var(--neutral-100);
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            margin-top: 1.5rem;
        }

        .password-section h3 {
            font-size: 0.938rem;
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .password-section h3 i {
            color: var(--warning);
        }

        /* Avatar Section */
        .avatar-section {
            background: var(--neutral-100);
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            margin-top: 1.5rem;
        }

        .avatar-section h3 {
            font-size: 0.938rem;
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .avatar-section h3 i {
            color: var(--info);
        }

        .current-avatar {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .avatar-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
            background: var(--neutral-200);
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-success:hover {
            background: #0d8b5e;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }
        .btn-warning:hover {
            background: #e0a832;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }
        .btn-outline-secondary:hover {
            background: var(--neutral-100);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.813rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--neutral-200);
            flex-wrap: wrap;
        }

        @media (max-width: 480px) {
            .form-actions {
                flex-direction: column;
            }
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Timeline */
        .timeline {
            margin-top: 0.5rem;
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--neutral-200);
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .timeline-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
            min-width: 0;
        }

        .timeline-title {
            font-weight: 500;
            color: var(--neutral-700);
            font-size: 0.875rem;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: var(--neutral-500);
        }

        .timeline-desc {
            font-size: 0.813rem;
            color: var(--neutral-600);
            margin-top: 0.15rem;
        }

        /* Loading */
        .loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Print */
        @media print {
            .sidebar, .top-header, .no-print {
                display: none !important;
            }
            .main-content { margin-left: 0 !important; }
            .profile-card, .settings-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include 'includes/header.php'; ?>

            <!-- Content Area -->
            <div class="content-area">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">My Profile</li>
                    </ol>
                </nav>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($message) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Profile Layout -->
                <div class="profile-layout">
                    <!-- Left: Profile Card -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar-wrapper">
                                <img src="<?= htmlspecialchars($avatar_url) ?>" class="profile-avatar" alt="Avatar" id="profileAvatar">
                                <form method="POST" enctype="multipart/form-data" id="avatarForm" class="no-print">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="this.form.submit()">
                                    <button type="button" class="avatar-upload-btn" onclick="document.getElementById('avatarInput').click()" title="Upload Avatar">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                    <input type="hidden" name="upload_avatar" value="1">
                                </form>
                            </div>
                            <div class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></div>
                            <div class="profile-role-badge">
                                <?= htmlspecialchars($user['role_name'] ?? $user['role'] ?? 'User') ?>
                            </div>
                            <div class="profile-username">@<?= htmlspecialchars($user['username']) ?></div>
                        </div>

                        <div class="profile-body">
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-envelope"></i></div>
                                <div class="info-content">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?= htmlspecialchars($user['email'] ?? 'Not provided') ?></div>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-phone"></i></div>
                                <div class="info-content">
                                    <div class="info-label">Mobile</div>
                                    <div class="info-value"><?= htmlspecialchars($user['mobile'] ?? 'Not provided') ?></div>
                                </div>
                            </div>
                            <?php if (!empty($user['organization_name'])): ?>
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-building"></i></div>
                                <div class="info-content">
                                    <div class="info-label">Organization</div>
                                    <div class="info-value"><?= htmlspecialchars($user['organization_name']) ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-calendar-alt"></i></div>
                                <div class="info-content">
                                    <div class="info-label">Joined</div>
                                    <div class="info-value"><?= date('M d, Y', strtotime($user['created_at'] ?? 'now')) ?></div>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-icon"><i class="fas fa-clock"></i></div>
                                <div class="info-content">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value">
                                        <?= !empty($user['last_login']) ? date('M d, Y g:i A', strtotime($user['last_login'])) : 'First login' ?>
                                    </div>
                                </div>
                            </div>

                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-number"><?= $stats['total_members'] ?></div>
                                    <div class="stat-label">Members</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= $stats['total_cards'] ?></div>
                                    <div class="stat-label">ID Cards</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= count($recent_activity) ?></div>
                                    <div class="stat-label">Activities</div>
                                </div>
                            </div>

                            <?php if (!empty($action_types)): ?>
                            <div style="margin-top:0.75rem;font-size:0.75rem;color:var(--neutral-500);">
                                <strong>Top Actions:</strong>
                                <?php 
                                $topActions = array_slice($action_types, 0, 3);
                                $actionLabels = [
                                    'auth' => 'Auth',
                                    'users' => 'Users',
                                    'members' => 'Members',
                                    'roles' => 'Roles',
                                    'templates' => 'Templates',
                                    'organizations' => 'Orgs',
                                    'cards' => 'Cards'
                                ];
                                foreach ($topActions as $action):
                                    $label = $actionLabels[$action['action_type']] ?? $action['action_type'];
                                ?>
                                    <span class="badge bg-light text-dark ms-1"><?= ucfirst($label) ?> (<?= $action['count'] ?>)</span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: Settings -->
                    <div class="settings-card">
                        <div class="settings-header">
                            <h2><i class="fas fa-user-edit"></i> Edit Profile</h2>
                        </div>
                        <div class="settings-body">
                            <form method="POST" id="profileForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="update_profile" value="1">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                                        <div class="input-hint">Username cannot be changed</div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Enter full name">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your@email.com">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Mobile</label>
                                        <input type="tel" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile'] ?? '') ?>" placeholder="Enter mobile number">
                                    </div>
                                    <?php if (!empty($user['role_name'])): ?>
                                    <div class="form-group">
                                        <label class="form-label">Role</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['role_name']) ?>" readonly>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($user['organization_name'])): ?>
                                    <div class="form-group">
                                        <label class="form-label">Organization</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['organization_name']) ?>" readonly>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Password Section -->
                                <div class="password-section">
                                    <h3><i class="fas fa-lock"></i> Change Password</h3>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Current Password <span class="required">*</span></label>
                                            <input type="password" name="current_password" class="form-control" placeholder="Enter current password">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">New Password <span class="required">*</span></label>
                                            <input type="password" name="new_password" class="form-control" placeholder="Min 8 characters">
                                            <div class="input-hint">Must include uppercase, lowercase, and number</div>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Confirm Password <span class="required">*</span></label>
                                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password">
                                        </div>
                                    </div>
                                </div>

                                <!-- Avatar Section -->
                                <div class="avatar-section">
                                    <h3><i class="fas fa-camera"></i> Profile Avatar</h3>
                                    <div class="current-avatar">
                                        <div class="avatar-preview">
                                            <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar Preview" id="avatarPreview">
                                        </div>
                                        <div class="avatar-actions">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('avatarInput').click()">
                                                <i class="fas fa-upload"></i> Upload
                                            </button>
                                            <?php if (!empty($user['avatar'])): ?>
                                                <button type="submit" name="remove_avatar" value="1" class="btn btn-danger btn-sm" onclick="return confirm('Remove your profile avatar?')">
                                                    <i class="fas fa-trash"></i> Remove
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="saveBtn">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <?php if (!empty($recent_activity)): ?>
                    <div class="settings-card" style="grid-column: 1 / -1;">
                        <div class="settings-header">
                            <h2><i class="fas fa-history"></i> Recent Activity</h2>
                            <a href="audit_log.php" class="btn btn-sm btn-outline-secondary">
                                View All <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="settings-body">
                            <div class="timeline">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <?php
                                            $icon = 'fa-circle';
                                            if ($activity['action_type'] == 'auth') $icon = 'fa-sign-in-alt';
                                            elseif ($activity['action_type'] == 'users') $icon = 'fa-user';
                                            elseif ($activity['action_type'] == 'members') $icon = 'fa-user-plus';
                                            elseif ($activity['action_type'] == 'roles') $icon = 'fa-user-shield';
                                            elseif ($activity['action_type'] == 'templates') $icon = 'fa-paint-brush';
                                            elseif ($activity['action_type'] == 'organizations') $icon = 'fa-building';
                                            elseif ($activity['action_type'] == 'cards') $icon = 'fa-id-card';
                                            ?>
                                            <i class="fas <?= $icon ?>"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?= htmlspecialchars($activity['action']) ?></div>
                                            <div class="timeline-time"><?= date('M d, Y g:i A', strtotime($activity['created_at'])) ?></div>
                                            <?php if (!empty($activity['details'])): ?>
                                                <div class="timeline-desc"><?= htmlspecialchars($activity['details']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // Avatar upload preview
        document.getElementById('avatarInput')?.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    document.getElementById('profileAvatar').src = event.target.result;
                    document.getElementById('avatarPreview').src = event.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Form validation
        document.getElementById('profileForm')?.addEventListener('submit', function(e) {
            const currentPass = document.querySelector('input[name="current_password"]').value;
            const newPass = document.querySelector('input[name="new_password"]').value;
            const confirmPass = document.querySelector('input[name="confirm_password"]').value;
            
            // If any password field is filled, validate all
            if (currentPass || newPass || confirmPass) {
                if (!currentPass) {
                    e.preventDefault();
                    alert('Please enter your current password');
                    return;
                }
                if (!newPass) {
                    e.preventDefault();
                    alert('Please enter a new password');
                    return;
                }
                if (newPass.length < 8) {
                    e.preventDefault();
                    alert('New password must be at least 8 characters long');
                    return;
                }
                if (!/[A-Z]/.test(newPass) || !/[a-z]/.test(newPass) || !/[0-9]/.test(newPass)) {
                    e.preventDefault();
                    alert('New password must include uppercase, lowercase, and number');
                    return;
                }
                if (newPass !== confirmPass) {
                    e.preventDefault();
                    alert('New passwords do not match');
                    return;
                }
            }
            
            // Show loading state
            const btn = document.getElementById('saveBtn');
            btn.innerHTML = '<span class="loading"></span> Saving...';
            btn.disabled = true;
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('saveBtn')?.click();
            }
            if (e.key === 'Escape') {
                document.querySelector('.sidebar')?.classList.remove('active');
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }
    </script>
</body>
</html>
