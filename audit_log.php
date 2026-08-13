<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware/auth.php';

$page_title = 'Audit Log';
require_organization_admin($pdo);

// Get current user info
$currentUser = $_SESSION['user'] ?? [];
$userOrgId = $_SESSION['organization_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === 'Super Admin' || $userRole === 'super_admin' || $userRole === 'admin');

// Filter parameters
$search = trim($_GET['search'] ?? '');
$actionType = trim($_GET['action_type'] ?? '');
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$sort = trim($_GET['sort'] ?? 'created_at');
$order = trim($_GET['order'] ?? 'DESC');
$exportFormat = $_GET['export'] ?? '';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if (strtotime($dateFrom) > strtotime($dateTo)) {
    $temp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $temp;
}

// Build WHERE clause
$where = ['1=1'];
$params = [];

// Search
if ($search !== '') {
    $where[] = '(a.action LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR a.ip_address LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Action type filter
if ($actionType !== '') {
    $where[] = 'a.action_type = ?';
    $params[] = $actionType;
}

// User filter
if ($userId > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $userId;
}

// Date range
if ($dateFrom && $dateTo) {
    $where[] = 'DATE(a.created_at) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
}

// Organization filter (for non-super admin)
if (!$isSuperAdmin && $userOrgId > 0) {
    $where[] = '(u.organization_id = ? OR u.organization_id IS NULL)';
    $params[] = $userOrgId;
}

// Get total count
$countSql = "SELECT COUNT(*) FROM audit_log a 
             LEFT JOIN users u ON a.user_id = u.id 
             WHERE " . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();

// Get paginated results
$offset = ($page - 1) * $perPage;
$sql = "SELECT a.*, 
        u.username, 
        u.full_name,
        u.role,
        u.organization_id,
        o.organization_name
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN organizations o ON u.organization_id = o.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.{$sort} {$order}
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = ceil($totalLogs / $perPage);

// Get statistics
$stats = get_audit_statistics($pdo);

// Get action types for filter
$actionTypes = $pdo->query("SELECT DISTINCT action_type FROM audit_log ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);

// Get users for filter
$users = $pdo->query("SELECT id, username, full_name FROM users WHERE deleted_at IS NULL ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function get_audit_statistics($pdo) {
    $stats = [];
    
    // Total logs
    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    
    // Today's logs
    $stats['today'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    
    // This week
    $stats['week'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE YEARWEEK(created_at) = YEARWEEK(CURDATE())")->fetchColumn();
    
    // This month
    $stats['month'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
    
    // By action type
    $stats['by_type'] = $pdo->query("SELECT action_type, COUNT(*) as count FROM audit_log GROUP BY action_type ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    return $stats;
}

function get_action_type_label($type) {
    $labels = [
        'auth' => 'Authentication',
        'users' => 'User Management',
        'roles' => 'Role Management',
        'members' => 'Member Management',
        'templates' => 'Template Management',
        'organizations' => 'Organization Management',
        'cards' => 'ID Card Generation',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'export' => 'Export/Import',
        'print' => 'Printing',
        'bulk' => 'Bulk Operations',
        'login' => 'Login Activity',
        'logout' => 'Logout'
    ];
    return $labels[$type] ?? ucfirst($type);
}

function get_action_type_badge($type) {
    $colors = [
        'auth' => 'info',
        'users' => 'primary',
        'roles' => 'secondary',
        'members' => 'success',
        'templates' => 'warning',
        'organizations' => 'dark',
        'cards' => 'info',
        'reports' => 'primary',
        'settings' => 'secondary',
        'export' => 'success',
        'print' => 'warning',
        'bulk' => 'danger',
        'login' => 'success',
        'logout' => 'danger'
    ];
    $color = $colors[$type] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . get_action_type_label($type) . '</span>';
}

function get_action_icon($action) {
    $icons = [
        'Created' => 'fa-plus-circle',
        'Updated' => 'fa-edit',
        'Deleted' => 'fa-trash',
        'Login' => 'fa-sign-in-alt',
        'Logout' => 'fa-sign-out-alt',
        'Failed' => 'fa-times-circle',
        'Generated' => 'fa-id-card',
        'Downloaded' => 'fa-download',
        'Printed' => 'fa-print',
        'Uploaded' => 'fa-upload',
        'Exported' => 'fa-file-export',
        'Imported' => 'fa-file-import'
    ];
    foreach ($icons as $key => $icon) {
        if (stripos($action, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-circle';
}

function get_action_color($action) {
    if (stripos($action, 'Created') !== false || stripos($action, 'Generated') !== false) return 'success';
    if (stripos($action, 'Updated') !== false || stripos($action, 'Edited') !== false) return 'info';
    if (stripos($action, 'Deleted') !== false || stripos($action, 'Removed') !== false) return 'danger';
    if (stripos($action, 'Login') !== false || stripos($action, 'Logged in') !== false) return 'success';
    if (stripos($action, 'Logout') !== false || stripos($action, 'Logged out') !== false) return 'danger';
    if (stripos($action, 'Failed') !== false || stripos($action, 'Error') !== false) return 'danger';
    if (stripos($action, 'Downloaded') !== false || stripos($action, 'Exported') !== false) return 'info';
    if (stripos($action, 'Printed') !== false) return 'warning';
    if (stripos($action, 'Uploaded') !== false || stripos($action, 'Imported') !== false) return 'success';
    return 'secondary';
}

function get_truncated_details($details, $length = 80) {
    if (strlen($details) <= $length) return htmlspecialchars($details);
    return htmlspecialchars(substr($details, 0, $length)) . '...';
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Handle export
if ($exportFormat && in_array($exportFormat, ['csv', 'excel'])) {
    set_time_limit(300);
    
    $exportSql = "SELECT a.id, a.action, a.action_type, a.details, a.ip_address, 
                  u.username, u.full_name, a.created_at
                  FROM audit_log a
                  LEFT JOIN users u ON a.user_id = u.id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY a.created_at DESC";
    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($params);
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'audit_log_' . date('Y-m-d_His');
    
    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'User', 'Full Name', 'Action', 'Type', 'Details', 'IP Address', 'Date/Time']);
        
        foreach ($exportData as $row) {
            fputcsv($output, [
                $row['id'],
                $row['username'] ?? 'System',
                $row['full_name'] ?? '',
                $row['action'],
                $row['action_type'],
                $row['details'],
                $row['ip_address'],
                $row['created_at']
            ]);
        }
        fclose($output);
        exit();
    }
    
    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>User</th><th>Full Name</th><th>Action</th><th>Type</th><th>Details</th><th>IP Address</th><th>Date/Time</th></tr>";
        
        foreach ($exportData as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username'] ?? 'System') . "</td>";
            echo "<td>" . htmlspecialchars($row['full_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['action']) . "</td>";
            echo "<td>" . htmlspecialchars($row['action_type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['details']) . "</td>";
            echo "<td>" . htmlspecialchars($row['ip_address']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Audit Log · ID Card Generator</title>

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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
            text-align: center;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .stat-card .stat-number { font-size: 1.75rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.7rem; font-weight: 500; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.05em; }
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
        .filter-form select, .filter-form input[type="date"] {
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--neutral-300);
            font-size: 0.813rem;
            background: white;
            min-width: 130px;
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

        /* Status Badges */
        .badge-custom {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: var(--radius-md);
            font-size: 0.688rem;
            font-weight: 500;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }
        .action-icon.success { background: var(--success-soft); color: var(--success); }
        .action-icon.danger { background: var(--danger-soft); color: var(--danger); }
        .action-icon.info { background: var(--info-soft); color: var(--info); }
        .action-icon.warning { background: var(--warning-soft); color: var(--warning); }
        .action-icon.secondary { background: var(--neutral-100); color: var(--neutral-500); }

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

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .export-buttons .btn { font-size: 0.813rem; padding: 0.375rem 0.75rem; }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-form .input-group { min-width: 100%; }
            .filter-form select, .filter-form input[type="date"] { min-width: 100%; }
            .pagination-custom { flex-direction: column; align-items: stretch; }
            .table { font-size: 0.688rem; }
            .table thead th, .table tbody td { padding: 0.5rem 0.25rem; }
            .export-buttons { justify-content: center; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

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

        /* Details tooltip */
        .details-tooltip {
            cursor: pointer;
            color: var(--primary);
            text-decoration: underline dotted;
        }
        .details-tooltip:hover { color: var(--accent); }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include __DIR__ . '/includes/header.php'; ?>

            <!-- Content Area -->
            <div class="dashboard-content">
                <!-- Breadcrumb -->
                <div class="breadcrumb-container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Audit Log</li>
                        </ol>
                    </nav>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon text-primary"><i class="fas fa-history"></i></div>
                        <div class="stat-label">Total Entries</div>
                        <div class="stat-number text-primary"><?= number_format($stats['total'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-success"><i class="fas fa-calendar-day"></i></div>
                        <div class="stat-label">Today</div>
                        <div class="stat-number text-success"><?= number_format($stats['today'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-warning"><i class="fas fa-calendar-week"></i></div>
                        <div class="stat-label">This Week</div>
                        <div class="stat-number text-warning"><?= number_format($stats['week'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-info"><i class="fas fa-calendar-alt"></i></div>
                        <div class="stat-label">This Month</div>
                        <div class="stat-number text-info"><?= number_format($stats['month'] ?? 0) ?></div>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5 style="font-weight:600;color:var(--neutral-800);margin:0;">
                                    <i class="fas fa-clipboard-list text-primary me-2"></i>Audit Log
                                </h5>
                                <p style="color:var(--neutral-500);font-size:0.813rem;margin:0;">
                                    Track all system activities and user actions
                                </p>
                            </div>
                            <div class="export-buttons">
                                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-file-csv me-1"></i>CSV
                                </a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-file-excel me-1"></i>Excel
                                </a>
                                <button onclick="window.print()" class="btn btn-info btn-sm text-white">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                                <button onclick="clearFilters()" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-undo me-1"></i>Reset
                                </button>
                            </div>
                        </div>

                        <!-- Filter Form -->
                        <form method="get" class="filter-form mt-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>">
                            </div>

                            <select name="action_type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($actionTypes as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= $actionType === $type ? 'selected' : '' ?>>
                                        <?= get_action_type_label($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="user_id" class="form-select">
                                <option value="0">All Users</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= (int)$user['id'] ?>" <?= $userId === (int)$user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username'] . ($user['full_name'] ? ' (' . $user['full_name'] . ')' : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">

                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-filter me-1"></i>Filter
                            </button>
                        </form>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">#</th>
                                        <th style="width:40px;"></th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Type</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                        <th style="width:150px;">Date/Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    <i class="fas fa-history"></i>
                                                    <p>No audit log entries found matching your criteria.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td class="text-muted">#<?= (int)$log['id'] ?></td>
                                                <td>
                                                    <span class="action-icon <?= get_action_color($log['action']) ?>">
                                                        <i class="fas <?= get_action_icon($log['action']) ?>"></i>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($log['username'])): ?>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($log['username']) ?></div>
                                                        <?php if (!empty($log['full_name'])): ?>
                                                            <div class="small text-muted"><?= htmlspecialchars($log['full_name']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($log['organization_name'])): ?>
                                                            <div class="small text-muted">
                                                                <i class="fas fa-building me-1"></i><?= htmlspecialchars($log['organization_name']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($log['action']) ?></span>
                                                </td>
                                                <td><?= get_action_type_badge($log['action_type']) ?></td>
                                                <td>
                                                    <?php if (!empty($log['details'])): ?>
                                                        <span class="details-tooltip" title="<?= htmlspecialchars($log['details']) ?>" data-bs-toggle="tooltip">
                                                            <?= get_truncated_details($log['details']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($log['ip_address'])): ?>
                                                        <code class="small"><?= htmlspecialchars($log['ip_address']) ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($log['created_at'])): ?>
                                                        <div><?= date('M d, Y', strtotime($log['created_at'])) ?></div>
                                                        <div class="small text-muted"><?= date('g:i A', strtotime($log['created_at'])) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
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
                                    Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> (Total: <?= $totalLogs ?> entries)
                                </div>
                                <nav aria-label="Audit log pagination">
                                    <ul class="pagination-controls">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&action_type=<?= urlencode($actionType) ?>&user_id=<?= urlencode($userId) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php 
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&action_type=<?= urlencode($actionType) ?>&user_id=<?= urlencode($userId) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&action_type=<?= urlencode($actionType) ?>&user_id=<?= urlencode($userId) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">
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

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Clear filters
        function clearFilters() {
            window.location.href = 'audit_log.php';
        }

        // Auto-submit on filter change
        document.querySelectorAll('.filter-form select, .filter-form input[type="date"]').forEach(function(element) {
            element.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Search input with Enter key
        document.querySelector('.filter-form input[name="search"]')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('.filter-form input[name="search"]')?.focus();
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }

            if (e.key === 'Escape') {
                document.querySelector('.filter-form input[name="search"]')?.blur();
            }
        });

        // Print optimization
        window.addEventListener('beforeprint', function() {
            document.querySelectorAll('.details-tooltip').forEach(function(el) {
                el.style.textDecoration = 'none';
            });
        });

        window.addEventListener('afterprint', function() {
            document.querySelectorAll('.details-tooltip').forEach(function(el) {
                el.style.textDecoration = 'underline dotted';
            });
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .page-link, .form-control, .form-select').forEach(function(el) {
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
