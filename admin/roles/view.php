<?php
/**
 * Role Management Module - View Role Page
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'View Role Details';
require_admin_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$role = get_role_by_id($pdo, $id);

if (!$role) {
    $_SESSION['role_error'] = 'Role not found.';
    header('Location: index.php');
    exit();
}

$userCount = get_role_assigned_users_count($pdo, $id, $role['role_name']);
$assignedPermissions = get_role_permissions_detailed($pdo, $id);

// Fetch assigned users list (up to 20)
$stmt = $pdo->prepare("SELECT id, username, full_name, email, created_at FROM users WHERE role_id = ? OR LOWER(role) = LOWER(?) OR LOWER(role) = LOWER(?) ORDER BY id DESC LIMIT 20");
$slug = strtolower(str_replace(' ', '_', $role['role_name']));
$stmt->execute([$id, $role['role_name'], $slug]);
$assignedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSRF token helper
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>View Role Details · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&family=Roboto:wght@400;500;700&family=Lato:wght@400;700&family=Montserrat:wght@500;600;700&family=Playfair+Display:wght@600;700&family=Libre+Barcode+128&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

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
            background: none;
            border: none;
            padding: 0.5rem;
            border-radius: var(--radius-md);
            transition: background 0.2s;
        }

        .menu-toggle:hover {
            background: var(--neutral-100);
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

        /* ----- Content Area ----- */
        .content-area {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Breadcrumb */
        .breadcrumb-container {
            background: transparent;
            padding: 0 0 1.5rem 0;
        }

        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 0;
            font-size: 0.875rem;
        }

        .breadcrumb-item a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .breadcrumb-item a:hover {
            color: var(--accent);
        }

        .breadcrumb-item.active {
            color: var(--neutral-600);
        }

        .breadcrumb-item + .breadcrumb-item::before {
            content: "›";
            color: var(--neutral-400);
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
            border: none;
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .alert-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .alert i {
            font-size: 1.25rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert .btn-close {
            filter: brightness(0) invert(1);
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
            padding: 0.25rem;
        }

        .alert .btn-close:hover {
            opacity: 1;
        }

        /* Page Header */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .page-head h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neutral-800);
            margin: 0;
        }

        .page-head h3 i {
            color: var(--primary);
        }

        .page-head .text-muted {
            color: var(--neutral-500);
            font-size: 0.9375rem;
        }

        .page-head .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            background: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
        }

        .card-header .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin: 0;
        }

        .card-header .card-title i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.25rem 1.5rem;
        }

        .border-0 {
            border: none;
        }
        .shadow-sm {
            box-shadow: var(--shadow-sm);
        }
        .mb-0 {
            margin-bottom: 0;
        }
        .mb-1 {
            margin-bottom: 0.25rem;
        }
        .mb-2 {
            margin-bottom: 0.5rem;
        }
        .mb-3 {
            margin-bottom: 1rem;
        }
        .mb-4 {
            margin-bottom: 1.5rem;
        }
        .mt-2 {
            margin-top: 0.5rem;
        }
        .mt-3 {
            margin-top: 1rem;
        }
        .me-1 {
            margin-right: 0.25rem;
        }
        .me-2 {
            margin-right: 0.5rem;
        }
        .me-3 {
            margin-right: 1rem;
        }
        .py-3 {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        .py-4 {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }
        .py-5 {
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        .px-3 {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        .ps-0 {
            padding-left: 0;
        }
        .p-0 {
            padding: 0;
        }
        .p-3 {
            padding: 0.75rem;
        }

        .fw-bold {
            font-weight: 700;
        }
        .fw-semibold {
            font-weight: 600;
        }
        .text-primary {
            color: var(--primary);
        }
        .text-success {
            color: var(--success);
        }
        .text-warning {
            color: var(--warning);
        }
        .text-muted {
            color: var(--neutral-500);
        }
        .text-dark {
            color: var(--neutral-800);
        }
        .text-secondary {
            color: var(--neutral-600);
        }
        .small {
            font-size: 0.813rem;
        }

        .d-flex {
            display: flex;
        }
        .flex-column {
            flex-direction: column;
        }
        .flex-md-row {
            flex-direction: row;
        }
        .justify-content-between {
            justify-content: space-between;
        }
        .align-items-center {
            align-items: center;
        }
        .gap-2 {
            gap: 0.5rem;
        }
        .gap-3 {
            gap: 1rem;
        }
        .gap-4 {
            gap: 1.5rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: 0.9375rem;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);
            color: var(--neutral-900);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: var(--neutral-900);
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }

        .btn-outline-secondary:hover {
            background: var(--neutral-100);
            border-color: var(--neutral-400);
            transform: translateY(-2px);
            color: var(--neutral-800);
        }

        .btn-outline-primary {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-warning {
            background: transparent;
            border: 1px solid var(--warning);
            color: var(--warning);
        }

        .btn-outline-warning:hover {
            background: var(--warning);
            color: var(--neutral-900);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.813rem;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-badge.active {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-badge.inactive {
            background: var(--neutral-200);
            color: var(--neutral-600);
        }

        .status-badge i {
            font-size: 0.625rem;
        }

        /* Badge */
        .badge-custom {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            background: var(--neutral-100);
            color: var(--neutral-700);
            border: 1px solid var(--neutral-200);
        }

        .badge-custom.rounded-pill {
            border-radius: 9999px;
        }

        .badge-custom.bg-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .badge-custom.bg-light {
            background: var(--neutral-100);
            color: var(--neutral-600);
            border-color: var(--neutral-200);
        }

        /* Table */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .table-borderless td,
        .table-borderless th {
            border: none;
            padding: 0.5rem 0;
        }

        .table th {
            font-weight: 500;
            color: var(--neutral-500);
            text-align: left;
            width: 130px;
        }

        .table td {
            color: var(--neutral-700);
        }

        /* List Group */
        .list-group {
            display: flex;
            flex-direction: column;
            padding-left: 0;
            margin-bottom: 0;
            border-radius: 0;
        }

        .list-group-flush .list-group-item {
            border-left: 0;
            border-right: 0;
            border-radius: 0;
        }

        .list-group-item {
            position: relative;
            display: block;
            padding: 0.75rem 1.25rem;
            background-color: white;
            border: 1px solid var(--neutral-200);
        }

        .list-group-item:first-child {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }

        .list-group-item:last-child {
            border-bottom-right-radius: 0;
            border-bottom-left-radius: 0;
        }

        .list-group-item .d-flex {
            display: flex;
        }

        .list-group-item .align-items-center {
            align-items: center;
        }

        .list-group-item .justify-content-between {
            justify-content: space-between;
        }

        .rounded-circle {
            border-radius: 50%;
        }

        .bg-primary.bg-opacity-10 {
            background-color: rgba(10, 26, 47, 0.1);
        }

        /* Permission Badges */
        .permission-badge-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.5rem;
        }

        .perm-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.6rem;
            background: white;
            border: 1px solid var(--neutral-200);
            border-radius: 9999px;
            font-size: 0.75rem;
            color: var(--neutral-700);
            transition: all 0.2s;
        }

        .perm-badge:hover {
            background: var(--neutral-50);
            border-color: var(--primary-soft);
        }

        .perm-badge i {
            font-size: 0.625rem;
            color: var(--success);
        }

        .border {
            border: 1px solid var(--neutral-200);
        }
        .rounded {
            border-radius: var(--radius-lg);
        }
        .bg-light {
            background: var(--neutral-100);
        }
        .border-bottom {
            border-bottom: 1px solid var(--neutral-200);
        }

        /* Row and Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-lg-5 {
            flex: 0 0 41.666%;
            max-width: 41.666%;
            padding: 0 0.75rem;
        }

        .col-lg-7 {
            flex: 0 0 58.333%;
            max-width: 58.333%;
            padding: 0 0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 0.75rem;
        }

        .g-3 {
            margin: -0.75rem;
        }

        .g-3 > [class*="col-"] {
            padding: 0.75rem;
        }

        .g-4 {
            margin: -1rem;
        }

        .g-4 > [class*="col-"] {
            padding: 1rem;
        }

        /* Container */
        .container-fluid {
            padding: 0 2rem;
            width: 100%;
        }

        .py-4 {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                z-index: 1000;
                width: 280px;
                background: var(--primary);
                transition: transform 0.3s ease;
                overflow-y: auto;
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

            .col-lg-5,
            .col-lg-7 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }

            .container-fluid {
                padding: 0 1rem;
            }

            .top-header {
                padding: 0.75rem 1rem;
            }

            .user-info {
                display: none;
            }

            .page-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-head .btn-group {
                width: 100%;
            }

            .page-head .btn-group .btn {
                flex: 1;
                justify-content: center;
            }

            .card-body {
                padding: 1rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .list-group-item {
                padding: 0.625rem 1rem;
            }

            .table th {
                width: 100px;
                font-size: 0.813rem;
            }

            .table td {
                font-size: 0.813rem;
            }

            .perm-badge {
                font-size: 0.688rem;
                padding: 0.2rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 0.75rem;
            }

            .container-fluid {
                padding: 0 0.75rem;
            }

            .page-head h3 {
                font-size: 1.25rem;
            }

            .page-head .btn-group {
                flex-direction: column;
            }

            .page-head .btn-group .btn {
                width: 100%;
                justify-content: center;
            }

            .card {
                border-radius: var(--radius-xl);
            }

            .card-body {
                padding: 0.75rem;
            }

            .table th {
                width: 80px;
                font-size: 0.75rem;
            }

            .table td {
                font-size: 0.75rem;
            }

            .list-group-item {
                padding: 0.5rem 0.75rem;
                font-size: 0.813rem;
            }

            .perm-badge {
                font-size: 0.625rem;
                padding: 0.15rem 0.4rem;
            }

            .rounded-circle.p-2 {
                width: 30px !important;
                height: 30px !important;
                font-size: 0.75rem !important;
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

        /* Print Styles */
        @media print {
            .sidebar,
            .top-header,
            .menu-toggle,
            .header-actions,
            .btn,
            .btn-group,
            .alert .btn-close {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 0;
            }

            .content-area {
                padding: 0.5rem;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }

            .card-header {
                background: #f5f5f5 !important;
            }

            .breadcrumb-container {
                display: none;
            }

            .status-badge {
                border: 1px solid #ddd;
            }

            .perm-badge {
                border: 1px solid #ddd;
                background: #fafafa;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <!-- Content Area -->
            <div class="content-area">
                <!-- Breadcrumb -->
                <div class="breadcrumb-container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Roles</a></li>
                            <li class="breadcrumb-item active" aria-current="page">View Role</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($_SESSION['role_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['role_message']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['role_message']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['role_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['role_error']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['role_error']); ?>
                <?php endif; ?>

                <!-- Header Actions -->
                <div class="page-head">
                    <div>
                        <h3><i class="fas fa-user-shield me-2"></i><?= htmlspecialchars($role['role_name']) ?></h3>
                        <div class="text-muted">Role ID: #<?= (int)$role['id'] ?> | Status: <?= get_role_status_badge((int)$role['status']) ?></div>
                    </div>
                    <div class="btn-group">
                        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
                        <a href="edit.php?id=<?= (int)$role['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit Role</a>
                        <a href="permissions.php?id=<?= (int)$role['id'] ?>" class="btn btn-warning"><i class="fas fa-key me-1"></i>Manage Permissions</a>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Side: Role Info & Assigned Users -->
                    <div class="col-lg-5">
                        <!-- Role Info Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-info-circle text-primary me-2"></i>Role Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th class="text-muted ps-0">Role Name</th>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($role['role_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Status</th>
                                        <td><?= get_role_status_badge((int)$role['status']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Description</th>
                                        <td><?= htmlspecialchars($role['description'] ?: 'No description available.') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Created At</th>
                                        <td><?= date('F j, Y g:i A', strtotime($role['created_at'] ?? 'now')) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Last Updated</th>
                                        <td><?= date('F j, Y g:i A', strtotime($role['updated_at'] ?? 'now')) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Assigned Users Card -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title"><i class="fas fa-users text-success me-2"></i>Assigned Users</h5>
                                <span class="badge-custom bg-primary rounded-pill px-3"><?= $userCount ?> Users</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($assignedUsers)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-user-slash fa-2x mb-2 text-secondary"></i>
                                        <p class="mb-0 small">No users are currently assigned to this role.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($assignedUsers as $u): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-2 me-3 fw-bold" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;">
                                                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                                                        <div class="small text-muted">@<?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['email'] ?: 'No email') ?>)</div>
                                                    </div>
                                                </div>
                                                <span class="badge-custom bg-light">User #<?= (int)$u['id'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Side: Granted Permissions -->
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title"><i class="fas fa-key text-warning me-2"></i>Assigned Module Permissions</h5>
                                <a href="permissions.php?id=<?= (int)$role['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-cog me-1"></i>Modify</a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($assignedPermissions)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-lock fa-3x mb-3 text-secondary"></i>
                                        <h5>No Permissions Granted</h5>
                                        <p class="small">This role currently has no permissions assigned to it.</p>
                                        <a href="permissions.php?id=<?= (int)$role['id'] ?>" class="btn btn-warning mt-2"><i class="fas fa-key me-1"></i>Configure Permissions Now</a>
                                    </div>
                                <?php else: ?>
                                    <div class="row g-3">
                                        <?php foreach ($assignedPermissions as $module => $perms): ?>
                                            <div class="col-md-6">
                                                <div class="border rounded p-3 bg-light">
                                                    <div class="fw-bold text-dark mb-2 pb-1 border-bottom d-flex align-items-center justify-content-between">
                                                        <span><i class="fas fa-folder-open text-primary me-2"></i><?= htmlspecialchars($module) ?></span>
                                                        <span class="badge-custom bg-light rounded-pill"><?= count($perms) ?></span>
                                                    </div>
                                                    <div class="permission-badge-list">
                                                        <?php foreach ($perms as $p): ?>
                                                            <span class="perm-badge" title="<?= htmlspecialchars($p['description'] ?? '') ?>">
                                                                <i class="fas fa-check-circle me-1 text-success"></i><?= htmlspecialchars($p['permission_name']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 1024) {
                if (sidebar && menuToggle && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close mobile menu
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                document.querySelector('.sidebar')?.classList.remove('active');
            }

            // Ctrl/Cmd + E to edit
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'edit.php?id=<?= (int)$role['id'] ?>';
            }

            // Ctrl/Cmd + P to manage permissions
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'permissions.php?id=<?= (int)$role['id'] ?>';
            }

            // Backspace to go back
            if (e.key === 'Backspace' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                window.location.href = 'index.php';
            }
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .list-group-item, .perm-badge').forEach(el => {
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