<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';

$message = '';
$error = '';

// Apply configured timezone/date format
$system_settings = load_system_settings($pdo);
$timezone = get_system_setting($system_settings, 'timezone', 'Asia/Kolkata');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Asia/Kolkata';
}
date_default_timezone_set($timezone);

$date_format = get_system_setting($system_settings, 'date_format', 'd/m/Y');
if (!in_array($date_format, ['d/m/Y', 'm/d/Y', 'Y-m-d'], true)) {
    $date_format = 'd/m/Y';
}

// Get current date
$today = date('Y-m-d');
$next_30_days = date('Y-m-d', strtotime('+30 days'));
$current_month = date('m');
$current_year = date('Y');
$expiry_sql_date = "COALESCE(DATE(expiry_date), STR_TO_DATE(expiry_date, '%Y-%m-%d'), STR_TO_DATE(expiry_date, '%d/%m/%Y'), STR_TO_DATE(expiry_date, '%m/%d/%Y'))";

// Fetch statistics
$stats = [];

// Total members
$stats['total_members'] = (int)$pdo->query("SELECT COUNT(*) FROM id_members")->fetchColumn();

// Active members (expiry date >= today)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE {$expiry_sql_date} >= ?");
$stmt->execute([$today]);
$stats['active_members'] = (int)$stmt->fetchColumn();

// Expiring soon (within 30 days)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE {$expiry_sql_date} BETWEEN ? AND ?");
$stmt->execute([$today, $next_30_days]);
$stats['expiring_soon'] = (int)$stmt->fetchColumn();

// Expired members
$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE {$expiry_sql_date} < ?");
$stmt->execute([$today]);
$stats['expired_members'] = (int)$stmt->fetchColumn();

// New members this month
$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE MONTH(joined_date) = ? AND YEAR(joined_date) = ?");
$stmt->execute([$current_month, $current_year]);
$stats['new_this_month'] = (int)$stmt->fetchColumn();

// Total templates
$stats['total_templates'] = (int)$pdo->query("SELECT COUNT(*) FROM card_templates")->fetchColumn();

// Total downloads
$stats['total_downloads'] = (int)($pdo->query("SELECT SUM(downloads) FROM card_templates")->fetchColumn() ?? 0);

// Get total organizations
$stats['total_organizations'] = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL")->fetchColumn();

// Get total users
$stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();

// Get recent members (last 5 added)
$recent_members = $pdo->query("SELECT * FROM id_members ORDER BY id DESC LIMIT 5")->fetchAll();

// Get expiring soon members (next 30 days)
$expiring_stmt = $pdo->prepare("SELECT *, {$expiry_sql_date} AS normalized_expiry_date FROM id_members WHERE {$expiry_sql_date} BETWEEN ? AND ? ORDER BY {$expiry_sql_date} ASC LIMIT 5");
$expiring_stmt->execute([$today, $next_30_days]);
$expiring_members = $expiring_stmt->fetchAll();

// Get class distribution
$class_distribution = $pdo->query("SELECT class, COUNT(*) as count FROM id_members GROUP BY class ORDER BY count DESC LIMIT 10")->fetchAll();

// Get monthly trend
$trend_period = isset($_GET['period']) ? (int)$_GET['period'] : 6;
if (!in_array($trend_period, [6, 12], true)) {
    $trend_period = 6;
}

$monthly_trend = [];
$loop_start = $trend_period - 1;
for ($i = $loop_start; $i >= 0; $i--) {
    $month = date('m', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $month_name = date('M', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE MONTH(joined_date) = ? AND YEAR(joined_date) = ?");
    $stmt->execute([$month, $year]);
    $count = (int)$stmt->fetchColumn();
    
    $monthly_trend[] = [
        'month' => $month_name,
        'count' => $count
    ];
}

// Get recent activities (from audit_log if table exists)
$recent_activities = [];
$audit_exists = $pdo->query("SHOW TABLES LIKE 'audit_log'")->rowCount() > 0;
if ($audit_exists) {
    $recent_activities = $pdo->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 10")->fetchAll();
}

// Get upcoming expirations (next 7 days)
$next_week = date('Y-m-d', strtotime('+7 days'));
$upcoming_expiring = $pdo->prepare("SELECT * FROM id_members WHERE {$expiry_sql_date} BETWEEN ? AND ? ORDER BY {$expiry_sql_date} ASC");
$upcoming_expiring->execute([$today, $next_week]);
$urgent_expirations = $upcoming_expiring->fetchAll();

// Calculate renewal rate
$total_expired = $stats['expired_members'];
$total_ever = $stats['total_members'] + $total_expired;
$renewal_rate = $total_ever > 0 ? round(($stats['active_members'] / $total_ever) * 100) : 0;

// Get organization stats
$org_stats = $pdo->query("SELECT organization_type, COUNT(*) as count FROM organizations WHERE deleted_at IS NULL GROUP BY organization_type")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js for graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

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
            --neutral-900: #111827;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,0.25);
            
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-3xl: 2rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            line-height: 1.5;
        }

        /* ===== LAYOUT ===== */
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ----- Main Content ----- */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: var(--neutral-50);
        }

        /* ----- Top Header ----- */
        .top-header {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--neutral-200);
            position: sticky;
            top: 0;
            z-index: 40;
            box-shadow: var(--shadow-sm);
        }

        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            color: var(--neutral-600);
            cursor: pointer;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--neutral-800);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--neutral-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--neutral-600);
            text-decoration: none;
            transition: all 0.2s;
        }

        .notification-btn:hover {
            background: var(--neutral-200);
            color: var(--neutral-800);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--accent);
            color: white;
            font-size: 0.75rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .user-menu:hover {
            background: var(--neutral-200);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-info {
            line-height: 1.4;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--neutral-500);
        }

        /* ----- Dashboard Content ----- */
        .dashboard-content {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Welcome Section */
        .welcome-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .welcome-text h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--neutral-800);
            margin-bottom: 0.5rem;
        }

        .welcome-text p {
            color: var(--neutral-500);
            font-size: 1rem;
        }

        .date-badge {
            background: white;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-xl);
            border: 1px solid var(--neutral-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-sm);
        }

        .date-badge i {
            color: var(--accent);
            font-size: 1.25rem;
        }

        .date-badge span {
            font-weight: 500;
            color: var(--neutral-700);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary-soft), #d4e0f0);
            color: var(--primary);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, var(--success-soft), #c8f0dc);
            color: var(--success);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, var(--warning-soft), #ffe6b8);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: linear-gradient(135deg, var(--danger-soft), #ffd6d6);
            color: var(--danger);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, var(--info-soft), #c8e0ff);
            color: var(--info);
        }

        .stat-icon.accent {
            background: linear-gradient(135deg, var(--accent-soft), #ffd6d6);
            color: var(--accent);
        }

        .stat-content {
            flex: 1;
            min-width: 0;
        }

        .stat-content h3 {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--neutral-800);
            line-height: 1.2;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-top: 0.25rem;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .chart-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-700);
        }

        .chart-header h3 i {
            color: var(--accent);
        }

        .chart-header select {
            padding: 0.5rem 2rem 0.5rem 1rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            background: white;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Tables Grid */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .table-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .table-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-700);
        }

        .table-header h3 i {
            color: var(--accent);
        }

        .table-header a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s;
        }

        .table-header a:hover {
            color: var(--accent);
            gap: 0.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            background: var(--neutral-100);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--neutral-200);
            color: var(--neutral-600);
            font-size: 0.875rem;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: var(--neutral-50);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-expiring {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .status-expired {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .student-photo-thumb {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--neutral-200);
        }

        .student-photo-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Activity Timeline */
        .timeline {
            margin-top: 1rem;
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
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
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
            min-width: 0;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 0.25rem;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: var(--neutral-500);
        }

        .timeline-desc {
            font-size: 0.875rem;
            color: var(--neutral-600);
            margin-top: 0.25rem;
            word-wrap: break-word;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }

        .quick-action {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--neutral-200);
            transition: all 0.3s ease;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .quick-action i {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-lg);
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .quick-action:hover i {
            background: var(--primary);
            color: white;
        }

        .quick-action-content h4 {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .quick-action-content p {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin: 0;
        }

        /* Alert Messages */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .alert-error {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .alert i {
            font-size: 1.25rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert-close {
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .charts-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .welcome-text h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .tables-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr 1fr;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }

            .stat-number {
                font-size: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-badge {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .timeline-item {
                flex-wrap: wrap;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="dashboard-wrapper">
        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include 'includes/header.php'; ?>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="welcome-text">
                        <h1>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
                        <p>Here's what's happening with your ID card system today.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?= date('l, F j, Y') ?></span>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card" onclick="window.location.href='view_members.php'">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Total Members</h3>
                            <div class="stat-number"><?= number_format($stats['total_members']) ?></div>
                            <div class="stat-trend">
                                <span class="trend-up"><i class="fas fa-arrow-up"></i> <?= $stats['new_this_month'] ?> this month</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='view_members.php?status=active'">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Active Cards</h3>
                            <div class="stat-number"><?= number_format($stats['active_members']) ?></div>
                            <div class="stat-trend">
                                <span><?= $stats['total_members'] > 0 ? round(($stats['active_members'] / $stats['total_members']) * 100) : 0 ?>% of total</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='view_members.php?status=expiring'">
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Expiring Soon</h3>
                            <div class="stat-number"><?= number_format($stats['expiring_soon']) ?></div>
                            <div class="stat-trend">
                                <span class="trend-down"><i class="fas fa-exclamation-triangle"></i> Need attention</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='view_members.php?status=expired'">
                        <div class="stat-icon danger">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Expired</h3>
                            <div class="stat-number"><?= number_format($stats['expired_members']) ?></div>
                            <div class="stat-trend">
                                <span>Requires renewal</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='templates.php'">
                        <div class="stat-icon info">
                            <i class="fas fa-paint-brush"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Templates</h3>
                            <div class="stat-number"><?= number_format($stats['total_templates']) ?></div>
                            <div class="stat-trend">
                                <span><i class="fas fa-download"></i> <?= number_format($stats['total_downloads']) ?> downloads</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='organizations/index.php'">
                        <div class="stat-icon accent">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Organizations</h3>
                            <div class="stat-number"><?= number_format($stats['total_organizations']) ?></div>
                            <div class="stat-trend">
                                <span>Multiple org support</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='admin/users/index.php'">
                        <div class="stat-icon primary">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Total Users</h3>
                            <div class="stat-number"><?= number_format($stats['total_users']) ?></div>
                            <div class="stat-trend">
                                <span>Roles & permissions</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card" onclick="window.location.href='reports.php'">
                        <div class="stat-icon info">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Renewal Rate</h3>
                            <div class="stat-number"><?= $renewal_rate ?>%</div>
                            <div class="stat-trend">
                                <span class="trend-up"><i class="fas fa-arrow-up"></i> +5% vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="charts-row">
                    <!-- Monthly Trend Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3>
                                <i class="fas fa-chart-line"></i>
                                Member Growth Trend
                            </h3>
                            <select id="trendPeriod">
                                <option value="6" <?= $trend_period == 6 ? 'selected' : '' ?>>Last 6 months</option>
                                <option value="12" <?= $trend_period == 12 ? 'selected' : '' ?>>Last 12 months</option>
                            </select>
                        </div>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <!-- Class Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3>
                                <i class="fas fa-chart-pie"></i>
                                Group Distribution
                            </h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="classChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tables Grid -->
                <div class="tables-grid">
                    <!-- Recent Members -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3>
                                <i class="fas fa-user-graduate"></i>
                                Recently Added Members
                            </h3>
                            <a href="view_members.php">
                                View All <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Photo</th>
                                        <th>Name</th>
                                        <th>Group</th>
                                        <th>Unique ID</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_members)): ?>
                                        <?php foreach ($recent_members as $student): 
                                            $expiry_date = $student['expiry_date'];
                                            $status = $expiry_date < $today ? 'expired' : ($expiry_date <= $next_30_days ? 'expiring' : 'active');
                                            $status_text = $status == 'active' ? 'Active' : ($status == 'expiring' ? 'Expiring' : 'Expired');
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="student-photo-thumb">
                                                        <?php if (!empty($student['photo']) && file_exists('images/uploads/' . $student['photo'])): ?>
                                                            <img src="images/uploads/<?= htmlspecialchars($student['photo']) ?>" alt="">
                                                        <?php else: ?>
                                                            <img src="images/uploads/default.png" alt="">
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($student['name']) ?></td>
                                                <td><?= htmlspecialchars($student['class'] ?? ($student['department'] ?? ($student['company'] ?? '—'))) ?></td>
                                                <td><?= htmlspecialchars($student['unique_id']) ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= $status ?>">
                                                        <?= $status_text ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center;">No members found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Expiring Soon -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3>
                                <i class="fas fa-clock"></i>
                                Expiring Soon (Next 30 Days)
                            </h3>
                            <a href="view_members.php?status=expiring">
                                View All <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Group</th>
                                        <th>Unique ID</th>
                                        <th>Expiry Date</th>
                                        <th>Days Left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($expiring_members)): ?>
                                        <?php foreach ($expiring_members as $student): 
                                            $expiry_raw = $student['normalized_expiry_date'] ?? $student['expiry_date'];
                                            $expiry_ts = strtotime((string)$expiry_raw);
                                            $days_left = $expiry_ts !== false ? (int)floor(($expiry_ts - strtotime($today)) / (60 * 60 * 24)) : 0;
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($student['name']) ?></td>
                                                <td><?= htmlspecialchars($student['class'] ?? ($student['department'] ?? ($student['company'] ?? '—'))) ?></td>
                                                <td><?= htmlspecialchars($student['unique_id']) ?></td>
                                                <td><?= $expiry_ts !== false ? date($date_format, $expiry_ts) : htmlspecialchars((string)$student['expiry_date']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $days_left <= 7 ? 'status-expiring' : 'status-active' ?>">
                                                        <?= $days_left ?> days
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center;">No expiring cards</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3>
                                <i class="fas fa-history"></i>
                                Recent Activity
                            </h3>
                            <a href="audit_log.php">
                                View All <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="timeline">
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <?php
                                            $icon = 'fa-circle';
                                            if ($activity['action_type'] == 'add') $icon = 'fa-user-plus';
                                            elseif ($activity['action_type'] == 'edit') $icon = 'fa-edit';
                                            elseif ($activity['action_type'] == 'delete') $icon = 'fa-trash';
                                            elseif ($activity['action_type'] == 'download') $icon = 'fa-download';
                                            ?>
                                            <i class="fas <?= $icon ?>"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?= htmlspecialchars($activity['action']) ?></div>
                                            <div class="timeline-time"><?= date('M j, Y g:i A', strtotime($activity['created_at'])) ?></div>
                                            <div class="timeline-desc"><?= htmlspecialchars($activity['details']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: var(--neutral-500);">
                                    <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                    <p>No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="add_member.php" class="quick-action">
                        <i class="fas fa-user-plus"></i>
                        <div class="quick-action-content">
                            <h4>Add Member</h4>
                            <p>Create new member record</p>
                        </div>
                    </a>
                    
                    <a href="generate_id_card.php" class="quick-action">
                        <i class="fas fa-id-card"></i>
                        <div class="quick-action-content">
                            <h4>Generate ID Card</h4>
                            <p>Create ID card for member</p>
                        </div>
                    </a>
                    
                    <a href="bulk_upload.php" class="quick-action">
                        <i class="fas fa-upload"></i>
                        <div class="quick-action-content">
                            <h4>Bulk Upload</h4>
                            <p>Import multiple members</p>
                        </div>
                    </a>
                    
                    <a href="templates.php" class="quick-action">
                        <i class="fas fa-paint-brush"></i>
                        <div class="quick-action-content">
                            <h4>Manage Templates</h4>
                            <p>Customize card designs</p>
                        </div>
                    </a>
                    
                    <a href="organizations/index.php" class="quick-action">
                        <i class="fas fa-building"></i>
                        <div class="quick-action-content">
                            <h4>Organizations</h4>
                            <p>Manage multiple organizations</p>
                        </div>
                    </a>
                    
                    <a href="admin/users/index.php" class="quick-action">
                        <i class="fas fa-users-cog"></i>
                        <div class="quick-action-content">
                            <h4>User Management</h4>
                            <p>Manage users & roles</p>
                        </div>
                    </a>
                    
                    <a href="reports.php" class="quick-action">
                        <i class="fas fa-file-alt"></i>
                        <div class="quick-action-content">
                            <h4>Reports</h4>
                            <p>View analytics & reports</p>
                        </div>
                    </a>
                    
                    <a href="settings.php" class="quick-action">
                        <i class="fas fa-cog"></i>
                        <div class="quick-action-content">
                            <h4>Settings</h4>
                            <p>Configure system</p>
                        </div>
                    </a>
                </div>
            </div>
            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // Mobile menu toggle - Fix to work with sidebar.php
        document.addEventListener('DOMContentLoaded', function() {
            // Find menu toggle - could be in header or sidebar
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                });

                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(event) {
                    if (window.innerWidth <= 1024) {
                        if (sidebar && menuToggle && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                            sidebar.classList.remove('active');
                        }
                    }
                });
            }
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Trend Chart
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            const trendData = <?= json_encode(array_column($monthly_trend, 'count')) ?>;
            const trendPeriod = <?= $trend_period ?>;
            
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($monthly_trend, 'month')) ?>,
                    datasets: [{
                        label: 'New Members',
                        data: trendData,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: trendPeriod === 12 ? 4 : 5,
                        pointHoverRadius: trendPeriod === 12 ? 6 : 7,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1f2937',
                            bodyColor: '#4b5563',
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f3f4f6'
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: trendPeriod === 12 ? 45 : 0,
                                minRotation: trendPeriod === 12 ? 45 : 0
                            }
                        }
                    }
                }
            });

            // Class Distribution Chart
            const classData = <?php 
                $class_labels = [];
                $class_counts = [];
                foreach ($class_distribution as $row) {
                    $class_labels[] = $row['class'];
                    $class_counts[] = $row['count'];
                }
                echo json_encode(['labels' => $class_labels, 'counts' => $class_counts]);
            ?>;
            
            if (classData.labels.length > 0) {
                const classCtx = document.getElementById('classChart').getContext('2d');
                new Chart(classCtx, {
                    type: 'doughnut',
                    data: {
                        labels: classData.labels,
                        datasets: [{
                            data: classData.counts,
                            backgroundColor: [
                                '#2563eb',
                                '#0e9f6e',
                                '#f4b740',
                                '#dc2626',
                                '#8b5cf6',
                                '#ec4899',
                                '#14b8a6',
                                '#f97316'
                            ],
                            borderWidth: 0,
                            hoverOffset: 10
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    padding: 15,
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        },
                        cutout: '65%'
                    }
                });
            }
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Handle period change for trend chart
        document.getElementById('trendPeriod')?.addEventListener('change', function() {
            window.location.href = `dashboard.php?period=${this.value}`;
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                // Focus search if implemented
            }
            
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>