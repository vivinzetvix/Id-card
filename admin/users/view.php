<?php
/**
 * Users Management - User View / Profile
 * Follows the same conventions as organizations/view.php
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/permission.php';

$page_title = 'View User';
$currentUser = require_user_module_access($pdo);

$targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($targetId <= 0) {
    $_SESSION['user_error'] = 'Invalid user ID';
    header('Location: index.php');
    exit();
}

// Load user data
$stmt = $pdo->prepare(
    'SELECT u.*, r.role_name, r.description AS role_description,
            o.organization_name, o.organization_code, o.logo AS organization_logo
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     LEFT JOIN organizations o ON o.id = u.organization_id
     WHERE u.id = ? AND u.deleted_at IS NULL
     LIMIT 1'
);
$stmt->execute([$targetId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['user_error'] = 'User not found';
    header('Location: index.php');
    exit();
}

$authUser = get_auth_user($pdo);
$isSuperAdmin = auth_is_super_admin($authUser);
$canEdit = has_permission($pdo, 'Users', 'Edit');
$canDelete = has_permission($pdo, 'Users', 'Delete');

// Permission checks
if (!$isSuperAdmin && (int)($user['organization_id'] ?? 0) !== (int)($authUser['organization_id'] ?? 0)) {
    http_response_code(403);
    exit('Access denied.');
}

// Helper functions
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_user_status_badge($status) {
    if ($status == 1) {
        return '<span class="badge-pill badge-active"><i class="fas fa-check-circle"></i> Active</span>';
    }
    return '<span class="badge-pill badge-inactive"><i class="fas fa-minus-circle"></i> Inactive</span>';
}

function get_avatar_path($avatar): string
{
    $avatar = basename((string)$avatar);
    $file = __DIR__ . '/assets/uploads/avatars/' . $avatar;
    if ($avatar !== '' && is_file($file)) {
        return 'assets/uploads/avatars/' . rawurlencode($avatar);
    }
    return '../../images/avatars/default.png';
}

function get_role_badge($role_name) {
    $role = strtolower((string)$role_name);
    if ($role === 'super_admin' || $role === 'super_admin') {
        return '<span class="badge-pill badge-super"><i class="fas fa-crown"></i> Super Admin</span>';
    } elseif ($role === 'admin') {
        return '<span class="badge-pill badge-admin"><i class="fas fa-user-shield"></i> Admin</span>';
    } elseif ($role === 'manager') {
        return '<span class="badge-pill badge-manager"><i class="fas fa-user-tie"></i> Manager</span>';
    }
    return '<span class="badge-pill badge-user"><i class="fas fa-user"></i> ' . h(ucfirst($role)) . '</span>';
}

function time_ago($timestamp) {
    if (empty($timestamp)) return 'Never';
    
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just Now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}

// Get user statistics
$stats = [];

// Count members (if this user has a related organization)
if (!empty($user['organization_id'])) {
    try {
        $memberStmt = $pdo->prepare('SELECT COUNT(*) FROM id_members WHERE organization_id = ? AND deleted_at IS NULL');
        $memberStmt->execute([$user['organization_id']]);
        $stats['members'] = (int)$memberStmt->fetchColumn();
    } catch (Throwable $e) {
        $stats['members'] = 0;
    }

    // Count templates
    try {
        $templateStmt = $pdo->prepare('SELECT COUNT(*) FROM card_templates WHERE organization_id = ? AND deleted_at IS NULL AND status = 1');
        $templateStmt->execute([$user['organization_id']]);
        $stats['templates'] = (int)$templateStmt->fetchColumn();
    } catch (Throwable $e) {
        $stats['templates'] = 0;
    }

    // Count cards
    try {
        $cardStmt = $pdo->prepare('SELECT COUNT(*) FROM id_cards WHERE organization_id = ? AND deleted_at IS NULL');
        $cardStmt->execute([$user['organization_id']]);
        $stats['cards'] = (int)$cardStmt->fetchColumn();
    } catch (Throwable $e) {
        $stats['cards'] = 0;
    }
} else {
    $stats['members'] = 0;
    $stats['templates'] = 0;
    $stats['cards'] = 0;
}

// Get recent activity (last 5 audit logs)
$recentActivity = [];
try {
    $activityStmt = $pdo->prepare(
        'SELECT id, action, action_type, details, ip_address, created_at
         FROM audit_log
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );
    $activityStmt->execute([$targetId]);
    $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentActivity = [];
}

$isTargetSelf = (int)$user['id'] === (int)($authUser['id'] ?? 0);
$status = (int)($user['status'] ?? 0);
$displayName = trim((string)($user['full_name'] ?? '')) ?: (string)$user['username'];
$avatarUrl = get_avatar_path($user['avatar'] ?? null);
$roleName = $user['role_name'] ?? ucfirst((string)($user['role'] ?? 'User'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>View User · ID Card Generator</title>

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
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }
        * { box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--neutral-50); 
            color: var(--neutral-800); 
            margin: 0; 
        }
        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 1.5rem 2rem; max-width: 1600px; margin: 0 auto; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        @media (max-width: 768px) { .dashboard-content { padding: 1rem; } }

        .breadcrumb { 
            display: flex; 
            gap: 0.5rem; 
            list-style: none; 
            padding: 0; 
            margin: 0 0 1.25rem 0; 
            font-size: 0.875rem; 
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        .alert { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            padding: 0.75rem 1rem; 
            border-radius: var(--radius-lg); 
            margin-bottom: 1rem; 
        }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .btn-close-custom { 
            cursor: pointer; 
            background: none; 
            border: none; 
            font-size: 1.25rem; 
            color: inherit; 
            opacity: 0.5; 
            margin-left: auto;
        }
        .btn-close-custom:hover { opacity: 1; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .page-header h4 {
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .page-header .subtitle {
            color: var(--neutral-500);
            font-size: 0.875rem;
        }
        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.7rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-active { background: var(--success-soft); color: var(--success); }
        .badge-inactive { background: var(--neutral-200); color: var(--neutral-500); }
        .badge-super { background: #fef3c7; color: #d97706; }
        .badge-admin { background: #dbeafe; color: #1d4ed8; }
        .badge-manager { background: #e0e7ff; color: #4338ca; }
        .badge-user { background: var(--primary-soft); color: var(--primary); }

        .btn {
            border-radius: var(--radius-md);
            padding: 0.5rem 0.9rem;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-light); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #0d8b5e; color: #fff; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-warning:hover { background: #e0a832; color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #b91c1c; color: #fff; }
        .btn-outline-secondary { 
            background: transparent; 
            border: 1px solid var(--neutral-300); 
            color: var(--neutral-600); 
        }
        .btn-outline-secondary:hover { 
            background: var(--neutral-100); 
            color: var(--neutral-800);
        }

        .view-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 992px) {
            .view-layout { grid-template-columns: 1fr; }
        }

        .panel {
            background: #fff;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .panel h6 {
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid var(--neutral-200);
            font-size: 0.9rem;
        }
        .panel h6 i { 
            color: var(--primary); 
            margin-right: 0.4rem; 
        }

        .avatar-large {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--neutral-200);
            background: var(--neutral-100);
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem 1.5rem;
        }
        @media (max-width: 576px) {
            .user-info-grid { grid-template-columns: 1fr; }
        }
        .user-info-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--neutral-100);
        }
        .user-info-item .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--neutral-500);
            letter-spacing: 0.04em;
            display: block;
            margin-bottom: 0.15rem;
        }
        .user-info-item .value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--neutral-800);
        }
        .user-info-item .value a {
            color: var(--primary);
            text-decoration: none;
        }
        .user-info-item .value a:hover {
            text-decoration: underline;
        }

        .stat-mini-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
        @media (max-width: 576px) {
            .stat-mini-grid { grid-template-columns: 1fr 1fr; }
        }
        .stat-mini {
            text-align: center;
            padding: 0.85rem 0.5rem;
            border-radius: var(--radius-lg);
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
            transition: all 0.15s ease;
        }
        .stat-mini:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .stat-mini .num {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        .stat-mini .num.text-success { color: var(--success); }
        .stat-mini .num.text-info { color: var(--info); }
        .stat-mini .num.text-warning { color: var(--warning); }
        .stat-mini .lbl {
            font-size: 0.65rem;
            color: var(--neutral-500);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--neutral-100);
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-item .item-info {
            display: flex;
            flex-direction: column;
        }
        .activity-item .item-action {
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--neutral-800);
        }
        .activity-item .item-detail {
            font-size: 0.72rem;
            color: var(--neutral-500);
        }
        .activity-item .item-time {
            font-size: 0.7rem;
            color: var(--neutral-400);
            white-space: nowrap;
        }

        .empty-note {
            color: var(--neutral-500);
            font-size: 0.8rem;
            text-align: center;
            padding: 1rem 0;
        }

        .role-badge-display {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-lg);
            background: var(--primary-soft);
            border: 1px solid var(--primary-light);
            color: var(--primary);
            font-weight: 600;
            font-size: 0.85rem;
        }

        @media print {
            .sidebar, .top-header, .header-actions, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .panel { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <main class="main-content">
        <?php include __DIR__ . '/../../includes/header.php'; ?>
        <div class="dashboard-content">

            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="index.php">Users</a>
                    </li>
                    <li class="breadcrumb-item active"><?= h($displayName) ?></li>
                </ol>
            </nav>

            <!-- Alerts -->
            <?php if (!empty($_SESSION['user_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= h($_SESSION['user_message']) ?></div>
                    <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php unset($_SESSION['user_message']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['user_error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= h($_SESSION['user_error']) ?></div>
                    <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php unset($_SESSION['user_error']); ?>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h4>
                        <i class="fas fa-user-circle text-primary"></i>
                        <?= h($displayName) ?>
                        <?= get_user_status_badge($status) ?>
                        <?= get_role_badge($roleName) ?>
                    </h4>
                    <div class="subtitle">
                        <i class="fas fa-at"></i> <?= h($user['username']) ?>
                        <?php if (!empty($user['organization_name'])): ?>
                            &nbsp;·&nbsp; <i class="fas fa-building"></i> <?= h($user['organization_name']) ?>
                        <?php endif; ?>
                        &nbsp;·&nbsp; <i class="fas fa-calendar-alt"></i> Joined <?= !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'N/A' ?>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <?php if ($canEdit && ($isSuperAdmin || !$isTargetSelf)): ?>
                        <a href="edit.php?id=<?= (int)$user['id'] ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Main Layout -->
            <div class="view-layout">

                <!-- LEFT COLUMN -->
                <div>
                    <!-- User Avatar & Basic Info -->
                    <div class="panel">
                        <h6><i class="fas fa-info-circle"></i>User Details</h6>
                        <div class="text-center mb-4">
                            <img src="<?= h($avatarUrl) ?>"
                                 class="avatar-large" alt="User Avatar"
                                 onerror="this.src='../../images/avatars/default.png'">
                        </div>
                        <div class="user-info-grid">
                            <div class="user-info-item">
                                <span class="label">Full Name</span>
                                <span class="value"><?= h($user['full_name'] ?: 'Not provided') ?></span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Username</span>
                                <span class="value">@<?= h($user['username']) ?></span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Email</span>
                                <span class="value">
                                    <?php if (!empty($user['email'])): ?>
                                        <a href="mailto:<?= h($user['email']) ?>">
                                            <?= h($user['email']) ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Mobile</span>
                                <span class="value">
                                    <?php if (!empty($user['mobile'])): ?>
                                        <a href="tel:<?= h($user['mobile']) ?>">
                                            <?= h($user['mobile']) ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Organization</span>
                                <span class="value">
                                    <?php if (!empty($user['organization_name'])): ?>
                                        <a href="../organizations/view.php?id=<?= (int)$user['organization_id'] ?>">
                                            <?= h($user['organization_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">System Global</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Role</span>
                                <span class="value"><?= h($roleName) ?></span>
                            </div>
                            <div class="user-info-item" style="grid-column: 1 / -1;">
                                <span class="label">Role Description</span>
                                <span class="value"><?= h($user['role_description'] ?: 'System user account') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Meta Information -->
                    <div class="panel">
                        <h6><i class="fas fa-clock"></i>Meta Information</h6>
                        <div class="user-info-grid">
                            <div class="user-info-item">
                                <span class="label">User ID</span>
                                <span class="value">#<?= (int)$user['id'] ?></span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Status</span>
                                <span class="value"><?= $status === 1 ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Created At</span>
                                <span class="value">
                                    <?= !empty($user['created_at']) ? date('M j, Y g:i A', strtotime($user['created_at'])) : 'N/A' ?>
                                </span>
                            </div>
                            <div class="user-info-item">
                                <span class="label">Last Updated</span>
                                <span class="value">
                                    <?= !empty($user['updated_at']) ? date('M j, Y g:i A', strtotime($user['updated_at'])) : 'N/A' ?>
                                </span>
                            </div>
                            <div class="user-info-item" style="grid-column: 1 / -1;">
                                <span class="label">Last Login</span>
                                <span class="value <?= empty($user['last_login']) ? 'text-muted' : '' ?>">
                                    <?= !empty($user['last_login']) ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never logged in' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div>
                    <!-- Organization Statistics (if user belongs to an organization) -->
                    <?php if (!empty($user['organization_id'])): ?>
                    <div class="panel">
                        <h6><i class="fas fa-chart-bar"></i>Organization Statistics</h6>
                        <div class="stat-mini-grid">
                            <div class="stat-mini">
                                <div class="num"><?= (int)$stats['members'] ?></div>
                                <div class="lbl">Members</div>
                            </div>
                            <div class="stat-mini">
                                <div class="num text-info"><?= (int)$stats['templates'] ?></div>
                                <div class="lbl">Templates</div>
                            </div>
                            <div class="stat-mini">
                                <div class="num text-success"><?= (int)$stats['cards'] ?></div>
                                <div class="lbl">Cards Generated</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Activity -->
                    <div class="panel">
                        <h6><i class="fas fa-clock-rotate-left"></i>Recent Activity</h6>
                        <?php if (!empty($recentActivity)): ?>
                            <div class="activity-list">
                                <?php foreach ($recentActivity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="item-info">
                                            <span class="item-action"><?= h($activity['action'] ?: 'Activity') ?></span>
                                            <span class="item-detail"><?= h($activity['details'] ?: 'No additional details') ?></span>
                                            <?php if (!empty($activity['ip_address'])): ?>
                                                <span class="item-detail" style="font-size: 0.65rem; color: var(--neutral-400);">
                                                    <i class="fas fa-network-wired"></i> <?= h($activity['ip_address']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="item-time"><?= time_ago($activity['created_at']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="empty-note mb-0">No activity recorded for this user yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Role Information -->
                    <div class="panel">
                        <h6><i class="fas fa-user-shield"></i>Role Information</h6>
                        <div class="text-center py-3">
                            <div class="role-badge-display">
                                <i class="fas <?= strtolower($roleName) === 'super_admin' ? 'fa-crown' : (strtolower($roleName) === 'admin' ? 'fa-user-shield' : 'fa-user') ?>"></i>
                                <?= h($roleName) ?>
                            </div>
                            <p class="mt-3 mb-0 text-muted" style="font-size: 0.85rem;">
                                <?= h($user['role_description'] ?: 'This user has standard system access.') ?>
                            </p>
                            <?php if ($isTargetSelf): ?>
                                <p class="mt-2 text-muted" style="font-size: 0.75rem;">
                                    <i class="fas fa-info-circle"></i> This is your account
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function() {
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+Backspace or Ctrl+Left Arrow to go back
            if ((e.ctrlKey || e.metaKey) && (e.key === 'Backspace' || e.key === 'ArrowLeft')) {
                e.preventDefault();
                window.location.href = 'index.php';
            }
            // Ctrl+E to edit
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'e') {
                <?php if ($canEdit && ($isSuperAdmin || !$isTargetSelf)): ?>
                    e.preventDefault();
                    window.location.href = 'edit.php?id=<?= (int)$user['id'] ?>';
                <?php endif; ?>
            }
        });

        // Tooltips
        document.querySelectorAll('[title]').forEach(el => {
            el.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'position-absolute bg-dark text-white p-1 rounded small';
                tooltip.style.zIndex = '9999';
                tooltip.style.padding = '4px 8px';
                tooltip.style.fontSize = '0.75rem';
                tooltip.style.maxWidth = '200px';
                tooltip.textContent = this.getAttribute('title');
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - 30) + 'px';
                tooltip.style.left = (rect.left + rect.width/2 - tooltip.offsetWidth/2) + 'px';
                document.body.appendChild(tooltip);
                this._tooltip = tooltip;
            });
            el.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    this._tooltip = null;
                }
            });
        });
    })();
</script>
</body>
</html>