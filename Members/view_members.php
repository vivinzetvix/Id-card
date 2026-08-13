<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/member_helpers.php';

$page_title = 'Members Management';
require_login();
$authUser = get_auth_user($pdo) ?: [];
require_permission($pdo, 'Members', 'View');

$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);
$canCreate = has_permission($pdo, 'Members', 'Create');
$canEdit = has_permission($pdo, 'Members', 'Edit');
$canDelete = has_permission($pdo, 'Members', 'Delete');
$canPrint = has_permission($pdo, 'Members', 'Print');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['member_message'] ?? '';
$error = $_SESSION['member_error'] ?? '';
unset($_SESSION['member_message'], $_SESSION['member_error']);

// Filter parameters
$search = trim($_GET['search'] ?? '');

$statusFilter = trim($_GET['status'] ?? '');
$orgFilter = trim($_GET['org_id'] ?? '');
$templateFilter = trim($_GET['template_id'] ?? '');
$department = trim($_GET['department'] ?? '');
$class = trim($_GET['class'] ?? '');
$projectType = trim($_GET['project_type'] ?? '');
$showArchived = !empty($_GET['show_archived']);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$sort = trim($_GET['sort'] ?? 'id');
$order = strtoupper(trim($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

// Build WHERE clause
if ($showArchived) {
    $where = ['m.deleted_at IS NOT NULL'];
} else {
    $where = ['m.deleted_at IS NULL'];
}
$params = [];

// Search
if ($search !== '') {
    $where[] = '(m.name LIKE ? OR m.unique_id LIKE ? OR m.email LIKE ? OR m.emergency_contact LIKE ? OR m.company LIKE ? OR m.department LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}


// Status filter (based on expiry)
if ($statusFilter !== '') {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));
    if ($statusFilter === 'active') {
        $where[] = 'expiry_date >= ?';
        $params[] = $today;
    } elseif ($statusFilter === 'expiring') {
        $where[] = 'expiry_date BETWEEN ? AND ?';
        $params[] = $today;
        $params[] = $next30;
    } elseif ($statusFilter === 'expired') {
        $where[] = 'expiry_date < ?';
        $params[] = $today;
    }
}

// Organization filter
if ($isSuperAdmin && $orgFilter !== '') {
    $where[] = 'm.organization_id = ?';
    $params[] = (int)$orgFilter;
} elseif (!$isSuperAdmin && $userOrgId > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $userOrgId;
}

// Template filter
if ($templateFilter !== '') {
    $where[] = 'm.template_id = ?';
    $params[] = (int)$templateFilter;
}

// Project type filter (through organization)
if ($projectType !== '') {
    $where[] = 'o.project_type = ?';
    $params[] = $projectType;
}

// Department/Class filter
if ($department !== '') {
    $where[] = 'm.department = ?';
    $params[] = $department;
}
if ($class !== '') {
    $where[] = 'm.class = ?';
    $params[] = $class;
}

// Removed language filter — bilingual is template-field only (EN/AM)

// Get total count
$countSql = "SELECT COUNT(*) FROM id_members m 
             LEFT JOIN organizations o ON m.organization_id = o.id 
             WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalMembers = (int)$countStmt->fetchColumn();

// Get paginated results
$offset = ($page - 1) * $perPage;
$sql = "SELECT m.*, 
        o.organization_name,
        o.project_type,
        t.name as template_name,
        t.orientation as template_orientation,
        (SELECT COUNT(*) FROM generated_cards WHERE member_id = m.id) as card_count,
        (SELECT COUNT(*) FROM member_dynamic_values WHERE member_id = m.id) as field_count
        FROM id_members m
        LEFT JOIN organizations o ON m.organization_id = o.id
        LEFT JOIN card_templates t ON m.template_id = t.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.{$sort} {$order}
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = ceil($totalMembers / $perPage);

// Get statistics with project type support
$stats = get_member_statistics_advanced($pdo, $userOrgId, $isSuperAdmin);

// Get organizations for filter (if super admin)
$organizations = [];
if ($isSuperAdmin) {
    $organizations = $pdo->query("SELECT id, organization_name, project_type FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get templates with orientation
$templates = $pdo->query("SELECT id, name, orientation, primary_color, is_default FROM card_templates WHERE status = 1 AND deleted_at IS NULL ORDER BY is_default DESC, name")->fetchAll(PDO::FETCH_ASSOC);

// Get departments and classes for filters
$departments = $pdo->query("SELECT DISTINCT department FROM id_members WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$classes = $pdo->query("SELECT DISTINCT class FROM id_members WHERE class IS NOT NULL AND class != '' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);

// Member types

$projectTypes = ['residence', 'corporate'];

function members_page_url(int $pageNum): string {
    global $search, $memberType, $statusFilter, $orgFilter, $templateFilter, $department, $class, $projectType, $showArchived, $sort, $order;
    $params = array_filter([
        'page' => $pageNum > 1 ? $pageNum : null,
        'search' => $search !== '' ? $search : null,
        'member_type' => $memberType !== '' ? $memberType : null,
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'org_id' => $orgFilter !== '' ? $orgFilter : null,
        'template_id' => $templateFilter !== '' ? $templateFilter : null,
        'department' => $department !== '' ? $department : null,
        'class' => $class !== '' ? $class : null,
        'project_type' => $projectType !== '' ? $projectType : null,
        'show_archived' => $showArchived ? '1' : null,
        'sort' => $sort !== 'id' ? $sort : null,
        'order' => $order !== 'DESC' ? $order : null,
    ], static fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

function get_member_type_label($type) {
    return ucfirst((string)($type ?: 'member'));
}

function get_member_type_icon($type) {
    return 'fa-user';
}

function get_orientation_label($orientation) {
    return $orientation === 'landscape' ? 'Landscape' : 'Portrait';
}

function get_orientation_icon($orientation) {
    return $orientation === 'landscape' ? 'fa-arrows-alt-h' : 'fa-arrows-alt-v';
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function get_member_photo_path($photo) {
    if (!empty($photo) && file_exists(__DIR__ . '/../images/uploads/' . basename((string)$photo))) {
        return '../images/uploads/' . htmlspecialchars(basename($photo));
    }
    return '../images/uploads/default.png';
}

function get_days_remaining($expiry_date) {
    if (!$expiry_date) return 'N/A';
    try {
        $today = new DateTime();
        $expiry = new DateTime($expiry_date);
        $diff = $today->diff($expiry);
        if ($diff->invert) {
            return '<span class="text-danger">Expired</span>';
        }
        return $diff->days . ' days';
    } catch (Exception $e) {
        return 'N/A';
    }
}

function get_member_type_badge($type) {
    return '<span class="badge bg-secondary">' . get_member_type_label($type) . '</span>';
}

// Advanced statistics function
function get_member_statistics_advanced($pdo, $userOrgId = 0, $isSuperAdmin = false) {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));
    
    $where = 'WHERE deleted_at IS NULL';
    $params = [];
    
    if (!$isSuperAdmin && $userOrgId > 0) {
        $where .= ' AND organization_id = ?';
        $params[] = $userOrgId;
    }
    
    // Total
    $sql = "SELECT COUNT(*) FROM id_members $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Active
    $sql = "SELECT COUNT(*) FROM id_members $where AND expiry_date >= ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today]));
    $active = (int)$stmt->fetchColumn();
    
    // Expiring
    $sql = "SELECT COUNT(*) FROM id_members $where AND expiry_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today, $next30]));
    $expiring = (int)$stmt->fetchColumn();
    
    // Expired
    $sql = "SELECT COUNT(*) FROM id_members $where AND expiry_date < ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today]));
    $expired = (int)$stmt->fetchColumn();
    
    // By type
    $types = ['student', 'employee', 'staff', 'faculty', 'visitor', 'office'];
    $byType = [];
    foreach ($types as $type) {
        $sql = "SELECT COUNT(*) FROM id_members $where AND member_type = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$type]));
        $byType[$type] = (int)$stmt->fetchColumn();
    }
    
    // By project type
    $joinWhere = 'WHERE m.deleted_at IS NULL';
    $joinParams = [];
    if (!$isSuperAdmin && $userOrgId > 0) {
        $joinWhere .= ' AND m.organization_id = ?';
        $joinParams[] = $userOrgId;
    }
    $sql = "SELECT o.project_type, COUNT(*) as count 
            FROM id_members m 
            LEFT JOIN organizations o ON m.organization_id = o.id 
            $joinWhere 
            GROUP BY o.project_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($joinParams);
    $byProjectType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    return [
        'total' => $total,
        'active' => $active,
        'expiring' => $expiring,
        'expired' => $expired,
        'by_type' => $byType,
        'by_project_type' => $byProjectType
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Members Management · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
            --accent-soft: #fee2e2;
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

        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            margin: 0;
            padding: 0;
        }

        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 2rem; max-width: 1600px; margin: 0 auto; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            transition: all 0.3s ease;
            cursor: pointer;
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--neutral-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-card .stat-icon { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .text-primary { color: var(--primary); }
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        .text-info { color: var(--info); }

        /* Main Card */
        .main-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
        }
        .card-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }
        .card-body-custom { padding: 1.5rem; overflow-x: auto; }
        .card-footer-custom {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }

        /* Filter Form */
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .filter-form .input-group { min-width: 160px; }
        .filter-form select {
            min-width: 120px;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--neutral-300);
            font-size: 0.813rem;
            background: white;
        }
        .filter-form .btn { padding: 0.375rem 0.75rem; font-size: 0.813rem; border-radius: var(--radius-md); }

        /* Table */
        .table { width: 100%; border-collapse: collapse; font-size: 0.813rem; }
        .table thead th {
            text-align: left;
            padding: 0.75rem 0.5rem;
            font-weight: 600;
            color: var(--neutral-500);
            text-transform: uppercase;
            font-size: 0.688rem;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--neutral-200);
            white-space: nowrap;
        }
        .table tbody td {
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid var(--neutral-100);
            vertical-align: middle;
        }
        .table tbody tr:hover td { background: var(--neutral-50); }

        .member-photo-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--neutral-200);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.688rem;
            font-weight: 500;
            gap: 0.25rem;
        }
        .status-badge.active { background: var(--success-soft); color: var(--success); }
        .status-badge.expiring { background: var(--warning-soft); color: var(--warning); }
        .status-badge.expired { background: var(--danger-soft); color: var(--danger); }

        .badge-custom {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-md);
            font-size: 0.688rem;
            font-weight: 500;
        }
        .bg-light { background: var(--neutral-100); }

        /* Pagination */
        .pagination-controls {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .pagination-controls .page-item { list-style: none; }
        .pagination-controls .page-link {
            padding: 0.375rem 0.75rem;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-md);
            color: var(--neutral-700);
            text-decoration: none;
            transition: all 0.2s;
            background: white;
            font-size: 0.813rem;
        }
        .pagination-controls .page-link:hover { background: var(--neutral-100); }
        .pagination-controls .active .page-link { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination-controls .disabled .page-link { opacity: 0.5; pointer-events: none; }

        .pagination-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .pagination-info { font-size: 0.813rem; color: var(--neutral-500); }

        /* Alerts */
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

        /* Quick Actions */
        .quick-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .quick-actions .btn { font-size: 0.813rem; padding: 0.375rem 0.75rem; border-radius: var(--radius-md); }

        /* Breadcrumb */
        .breadcrumb-container { margin-bottom: 1.5rem; }
        .breadcrumb {
            display: flex;
            gap: 0.5rem;
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 0.875rem;
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        /* Modal */
        .modal-content { border-radius: var(--radius-2xl); border: none; box-shadow: var(--shadow-xl); }
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--neutral-200); }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1.25rem 1.5rem; border-top: 1px solid var(--neutral-200); }

        .btn { border-radius: var(--radius-md); padding: 0.375rem 0.75rem; font-size: 0.875rem; }
        .btn-group-sm .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

        .empty-state { text-align: center; padding: 3rem 1rem; }
        .empty-state i { font-size: 3rem; color: var(--neutral-300); margin-bottom: 1rem; }
        .empty-state p { color: var(--neutral-500); margin-bottom: 1rem; }

        /* Orientation badge */
        .orientation-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.4rem;
            border-radius: var(--radius-sm);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .orientation-badge.landscape { background: #dbeafe; color: #1e40af; }
        .orientation-badge.portrait { background: #fce7f3; color: #9d174d; }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-form .input-group { min-width: 100%; }
            .filter-form select { min-width: 100%; }
            .pagination-custom { flex-direction: column; align-items: stretch; }
            .quick-actions { flex-direction: column; }
            .table { font-size: 0.688rem; }
            .table thead th, .table tbody td { padding: 0.5rem 0.25rem; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        .filter-form{
    display:block;
    margin-top:20px;
}

.filter-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(280px,1fr));
    gap:18px;
}

.filter-item label{
    display:block;
    margin-bottom:6px;
    font-size:13px;
    font-weight:600;
    color:#6b7280;
}

.filter-item .form-select,
.filter-item .form-control{
    width:100%;
    height:46px;
    border-radius:10px;
}

.filter-actions{
    display:flex;
    justify-content:flex-end;
    gap:12px;
    margin-top:20px;
}

@media(max-width:992px){
    .filter-grid{
        grid-template-columns:1fr;
    }

    .filter-actions{
        justify-content:stretch;
        flex-direction:column;
    }
}
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include __DIR__ . '/../includes/header.php'; ?>

            <!-- Content Area -->
            <div class="dashboard-content">
                <!-- Breadcrumb -->
                <div class="breadcrumb-container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Members</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($_SESSION['member_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content flex-1"><?= htmlspecialchars($_SESSION['member_message']) ?></div>
                        <button type="button" class="btn-close-custom" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['member_message']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['member_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content flex-1"><?= htmlspecialchars($_SESSION['member_error']) ?></div>
                        <button type="button" class="btn-close-custom" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['member_error']); ?>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card" onclick="window.location.href='view_members.php'">
                        <div class="stat-icon text-primary"><i class="fas fa-users"></i></div>
                        <div class="stat-label">Total Members</div>
                        <div class="stat-number text-primary"><?= number_format($stats['total'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='view_members.php?status=active'">
                        <div class="stat-icon text-success"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-label">Active</div>
                        <div class="stat-number text-success"><?= number_format($stats['active'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='view_members.php?status=expiring'">
                        <div class="stat-icon text-warning"><i class="fas fa-clock"></i></div>
                        <div class="stat-label">Expiring Soon</div>
                        <div class="stat-number text-warning"><?= number_format($stats['expiring'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='view_members.php?status=expired'">
                        <div class="stat-icon text-danger"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="stat-label">Expired</div>
                        <div class="stat-number text-danger"><?= number_format($stats['expired'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='add_member.php'">
                        <div class="stat-icon text-info"><i class="fas fa-user-plus"></i></div>
                        <div class="stat-label">Quick Action</div>
                        <div class="stat-number" style="font-size:1rem;color:var(--primary);">+ Add Member</div>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5 style="font-weight:600;color:var(--neutral-800);margin:0;">
                                    <i class="fas fa-user-friends text-primary me-2"></i>Member Directory
                                </h5>
                                <p style="color:var(--neutral-500);font-size:0.813rem;margin:0;">
                                    Manage ID card holders, member records, and card generation.
                                    <?php if ($projectType): ?>
                                        <span class="badge bg-primary ms-2"><?= ucfirst($projectType) ?> Project</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="quick-actions">
                                <a href="add_member.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-user-plus me-1"></i>Add Member
                                </a>
                                <a href="bulk_upload.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-upload me-1"></i>Bulk Upload
                                </a>
                                <a href="../generate_id_card.php" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-id-card me-1"></i>Generate ID
                                </a>
                                <button onclick="window.print()" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                            </div>
                        </div>

                        <!-- Filter Form -->
                   <form method="get" class="filter-form mt-3">

    <!-- Search -->
    <div class="search-box mb-4">
        <div class="input-group">
            <span class="input-group-text">
                <i class="fas fa-search text-muted"></i>
            </span>

            <input
                type="text"
                name="search"
                class="form-control"
                placeholder="Search members..."
                value="<?= htmlspecialchars($search) ?>">
        </div>
    </div>

    <div class="filter-grid">

   

        <!-- Status -->
        <div class="filter-item">

            <label>Status</label>

            <select
                name="status"
                class="form-select">

                <option value="">All Status</option>

                <option
                    value="active"
                    <?= $statusFilter == 'active' ? 'selected' : '' ?>>
                    Active
                </option>

                <option
                    value="expiring"
                    <?= $statusFilter == 'expiring' ? 'selected' : '' ?>>
                    Expiring Soon
                </option>

                <option
                    value="expired"
                    <?= $statusFilter == 'expired' ? 'selected' : '' ?>>
                    Expired
                </option>

            </select>

        </div>

        <!-- Project -->

        <div class="filter-item">

            <label>Project</label>

            <select
                name="project_type"
                class="form-select">

                <option value="">All Projects</option>

                <?php foreach ($projectTypes as $pt): ?>

                    <option
                        value="<?= $pt ?>"
                        <?= $projectType == $pt ? 'selected' : '' ?>>

                        <?= ucfirst($pt) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <!-- Organization -->

        <?php if ($isSuperAdmin): ?>

        <div class="filter-item">

            <label>Organization</label>

            <select
                name="org_id"
                class="form-select">

                <option value="">All Organizations</option>

                <?php foreach ($organizations as $org): ?>

                    <option
                        value="<?= $org['id'] ?>"
                        <?= $orgFilter == $org['id'] ? 'selected' : '' ?>>

                        <?= htmlspecialchars($org['organization_name']) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <?php endif; ?>

        <!-- Template -->

        <div class="filter-item">

            <label>Template</label>

            <select
                name="template_id"
                class="form-select">

                <option value="">All Templates</option>

                <?php foreach ($templates as $tpl): ?>

                    <option
                        value="<?= $tpl['id'] ?>"
                        <?= $templateFilter == $tpl['id'] ? 'selected' : '' ?>>

                        <?= htmlspecialchars($tpl['name']) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>



        <!-- Class -->


        <!-- Members -->

        <div class="filter-item">

            <label>Members</label>

            <select
                name="show_archived"
                class="form-select">

                <option
                    value="0"
                    <?= !$showArchived ? 'selected' : '' ?>>
                    Active Members
                </option>

                <option
                    value="1"
                    <?= $showArchived ? 'selected' : '' ?>>
                    Archived Members
                </option>

            </select>

        </div>

    </div>

    <div class="filter-actions">

        <a
            href="view_members.php"
            class="btn btn-outline-secondary">

            <i class="fas fa-rotate-left me-1"></i>

            Reset

        </a>

        <button
            type="submit"
            class="btn btn-primary">

            <i class="fas fa-filter me-1"></i>

            Apply Filters

        </button>

    </div>

</form>
                    </div>

                    <div class="card-body-custom">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if (!$showArchived && ($canDelete || $canEdit || $canPrint)): ?>
                        <form method="post" action="bulk_actions.php" id="bulkMemberForm" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <?php if ($canDelete): ?>
                            <button type="submit" name="bulk_action" value="archive" class="btn btn-warning btn-sm" onclick="return confirm('Archive selected members?')">
                                <i class="fas fa-archive me-1"></i>Archive Selected
                            </button>
                            <?php endif; ?>
                            <?php if ($canPrint): ?>
                            <button type="submit" name="bulk_action" value="print" class="btn btn-info btn-sm">
                                <i class="fas fa-print me-1"></i>Print Selected
                            </button>
                            <?php endif; ?>
                            <span class="text-muted small" id="selectedCount">0 selected</span>
                        </form>
                        <?php elseif ($showArchived && $canEdit): ?>
                        <form method="post" action="bulk_actions.php" id="bulkMemberForm" class="mb-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="show_archived" value="1">
                            <button type="submit" name="bulk_action" value="restore" class="btn btn-success btn-sm">
                                <i class="fas fa-undo me-1"></i>Restore Selected
                            </button>
                        </form>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:36px;"><input type="checkbox" id="selectAllMembers" title="Select all"></th>
                                        <th style="width:45px;">Photo</th>
                                        <th>Name / ID</th>
                                        <th>Kind</th>
                                        <th>Organization</th>
                                        <th>Dept/Class</th>
                                        <th>Template</th>
                                        <th>Orientation</th>
                                        <th>Status</th>
                                        <th>Expiry</th>
                                        <th style="text-align:right;width:200px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="11">
                                                <div class="empty-state">
                                                    <i class="fas fa-user-slash"></i>
                                                    <p>No members found matching your criteria.</p>
                                                    <a href="add_member.php" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-user-plus"></i> Add First Member
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($members as $member): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="member-select" name="member_ids[]" value="<?= (int)$member['id'] ?>" form="bulkMemberForm">
                                                </td>
                                                <td>
                                                    <img src="<?= htmlspecialchars(get_member_photo_path($member['photo'] ?? '')) ?>" 
                                                         class="member-photo-thumb" alt="Photo">
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($member['name']) ?></div>
                                                    <div class="small text-muted">ID: <?= htmlspecialchars($member['unique_id']) ?></div>
                                                    <?php if (!empty($member['email'])): ?>
                                                        <div class="small text-muted"><?= htmlspecialchars($member['email']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= get_member_type_badge($member['member_type']) ?></td>
                                                <td>
                                                    <?php if (!empty($member['organization_name'])): ?>
                                                        <span class="badge-custom bg-light">
                                                            <i class="fas fa-building text-primary me-1"></i>
                                                            <?= htmlspecialchars($member['organization_name']) ?>
                                                        </span>
                                                        <?php if (!empty($member['project_type'])): ?>
                                                            <div class="small text-muted"><?= ucfirst($member['project_type']) ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($member['department'])): ?>
                                                        <div><?= htmlspecialchars($member['department']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($member['class'])): ?>
                                                        <div class="small text-muted">Class: <?= htmlspecialchars($member['class']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (empty($member['department']) && empty($member['class'])): ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($member['template_name'])): ?>
                                                        <span class="badge-custom bg-light">
                                                            <i class="fas fa-paint-brush text-primary me-1"></i>
                                                            <?= htmlspecialchars($member['template_name']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Default</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($member['template_orientation'])): ?>
                                                        <span class="orientation-badge <?= $member['template_orientation'] ?>">
                                                            <i class="fas <?= get_orientation_icon($member['template_orientation']) ?> me-1"></i>
                                                            <?= get_orientation_label($member['template_orientation']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= get_member_status_badge($member['expiry_date']) ?></td>
                                                <td>
                                                    <?php if ($member['expiry_date']): ?>
                                                        <div><?= date('M d, Y', strtotime($member['expiry_date'])) ?></div>
                                                        <div class="small text-muted"><?= get_days_remaining($member['expiry_date']) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align:right;">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <a href="view_member.php?id=<?= (int)$member['id'] ?>" class="btn btn-outline-info" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if (!$showArchived): ?>
                                                        <?php if ($canEdit): ?>
                                                        <a href="edit_member.php?id=<?= (int)$member['id'] ?>" class="btn btn-outline-secondary" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="renew_member.php?id=<?= (int)$member['id'] ?>" class="btn btn-outline-primary" title="Renew">
                                                            <i class="fas fa-redo"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        <a href="../generate_id_card.php?member_id=<?= (int)$member['id'] ?>" class="btn btn-outline-success" title="Generate ID Card">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <?php if ($canPrint): ?>
                                                        <a href="../card/print_id_card.php?id=<?= (int)$member['id'] ?>" class="btn btn-outline-warning" title="Print ID Card" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                        <?php if ($canDelete): ?>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteMemberModal" data-id="<?= (int)$member['id'] ?>" data-name="<?= htmlspecialchars($member['name'], ENT_QUOTES) ?>" title="Archive">
                                                            <i class="fas fa-archive"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php else: ?>
                                                        <?php if ($canEdit): ?>
                                                        <form method="post" action="restore_member.php" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                                                            <input type="hidden" name="show_archived" value="1">
                                                            <button type="submit" class="btn btn-outline-success" title="Restore"><i class="fas fa-undo"></i></button>
                                                        </form>
                                                        <?php endif; ?>
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer-custom">
                            <div class="pagination-custom">
                                <div class="pagination-info">
                                    Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total: <?= $totalMembers ?> members)
                                </div>
                                <nav aria-label="Member pagination">
                                    <ul class="pagination-controls">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(members_page_url($page - 1)) ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars(members_page_url($i)) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(members_page_url($page + 1)) ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Delete Member Modal -->
    <div class="modal fade" id="deleteMemberModal" tabindex="-1" aria-labelledby="deleteMemberModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteMemberModalLabel">
                        <i class="fas fa-archive me-2"></i>Archive Member
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="delete_member.php" id="deleteMemberForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="id" id="deleteMemberId" value="">
                        <p class="fs-6 mb-2">Archive member <strong id="deleteMemberName" class="text-danger"></strong>?</p>
                        <p class="text-muted small">The member will be hidden from active lists. Generated cards and history are preserved.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-archive me-1"></i>Archive Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Bulk selection
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAllMembers');
            const boxes = document.querySelectorAll('.member-select');
            const countEl = document.getElementById('selectedCount');
            function updateCount() {
                if (!countEl) return;
                const n = document.querySelectorAll('.member-select:checked').length;
                countEl.textContent = n + ' selected';
            }
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    boxes.forEach(cb => { cb.checked = selectAll.checked; });
                    updateCount();
                });
            }
            boxes.forEach(cb => cb.addEventListener('change', updateCount));
        });

        // Archive Member Modal
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteMemberModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const memberId = button.getAttribute('data-id');
                    const memberName = button.getAttribute('data-name');

                    document.getElementById('deleteMemberId').value = memberId;
                    document.getElementById('deleteMemberName').textContent = memberName;
                });
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

        // Auto-submit on filter change
        document.querySelectorAll('.filter-form select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]')?.focus();
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add_member.php';
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .page-link, .form-control, .form-select').forEach(el => {
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
