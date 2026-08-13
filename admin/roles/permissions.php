<?php
/**
 * Role Management Module - Permissions Assignment Page
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Role Permissions';
require_admin_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$role = get_role_by_id($pdo, $id);

if (!$role) {
    $_SESSION['role_error'] = 'Role not found.';
    header('Location: index.php');
    exit();
}

$groupedPermissions = get_all_permissions_grouped_by_module($pdo);
$assignedPermissionIds = get_role_permission_ids($pdo, $id);

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
    <title>Manage Permissions · ID Card Generator</title>

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
        .mb-4 {
            margin-bottom: 1.5rem;
        }
        .mt-4 {
            margin-top: 1.5rem;
        }
        .me-1 {
            margin-right: 0.25rem;
        }
        .me-2 {
            margin-right: 0.5rem;
        }
        .py-3 {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        .py-2 {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }
        .px-3 {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        .px-4 {
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }
        .px-5 {
            padding-left: 2rem;
            padding-right: 2rem;
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
        .text-warning {
            color: var(--warning);
        }
        .text-muted {
            color: var(--neutral-500);
        }
        .text-dark {
            color: var(--neutral-800);
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
        .align-items-md-center {
            align-items: center;
        }
        .gap-2 {
            gap: 0.5rem;
        }
        .gap-3 {
            gap: 1rem;
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

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.813rem;
        }

        .btn-sm.px-4 {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        /* Badge */
        .badge-custom {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.813rem;
            font-weight: 500;
            background: var(--neutral-100);
            color: var(--neutral-700);
            border: 1px solid var(--neutral-200);
        }

        .badge-custom .fw-bold {
            font-weight: 700;
        }

        /* Permission Tree Card */
        .permission-tree-card {
            background: white;
            border-radius: var(--radius-xl);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
            height: 100%;
        }

        .permission-tree-card:hover {
            border-color: var(--primary-soft);
            box-shadow: var(--shadow-sm);
        }

        .permission-module-header {
            padding: 0.875rem 1.25rem;
            background: var(--neutral-50);
            border-bottom: 1px solid var(--neutral-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .permission-module-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--neutral-700);
            margin: 0;
        }

        .permission-module-header .form-check {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .permission-module-header .form-check-input {
            width: 2rem;
            height: 1.1rem;
            margin: 0;
            cursor: pointer;
            border: 1px solid var(--neutral-300);
            border-radius: 9999px;
            background-color: var(--neutral-200);
            transition: all 0.2s;
            appearance: none;
            position: relative;
        }

        .permission-module-header .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .permission-module-header .form-check-input:checked::after {
            transform: translateX(0.9rem);
        }

        .permission-module-header .form-check-input::after {
            content: '';
            position: absolute;
            top: 1px;
            left: 1px;
            width: 0.85rem;
            height: 0.85rem;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s;
            transform: translateX(0);
        }

        .permission-module-header .form-check-label {
            font-size: 0.813rem;
            font-weight: 500;
            color: var(--neutral-600);
            cursor: pointer;
        }

        .permission-grid {
            padding: 0.75rem 1.25rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
        }

        .permission-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.5rem;
            border-radius: var(--radius-md);
            transition: background 0.2s;
        }

        .permission-item:hover {
            background: var(--neutral-50);
        }

        .permission-item .form-check-input {
            width: 1rem;
            height: 1rem;
            margin: 0;
            cursor: pointer;
            border: 1.5px solid var(--neutral-300);
            border-radius: var(--radius-sm);
            appearance: none;
            transition: all 0.2s;
            position: relative;
            flex-shrink: 0;
        }

        .permission-item .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .permission-item .form-check-input:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
        }

        .permission-item .form-check-label {
            font-size: 0.813rem;
            color: var(--neutral-700);
            cursor: pointer;
        }

        /* Row and Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-lg-6 {
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

            .col-lg-6 {
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

            .card-body {
                padding: 1rem;
            }

            .permission-grid {
                grid-template-columns: 1fr;
                padding: 0.5rem 1rem;
            }

            .permission-module-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .permission-module-header .form-check {
                width: 100%;
                justify-content: flex-start;
            }

            .d-flex.justify-content-between.align-items-center {
                flex-direction: column;
                gap: 1rem;
            }

            .d-flex.justify-content-between.align-items-center .btn {
                width: 100%;
                justify-content: center;
            }

            .btn-group {
                flex-wrap: wrap;
            }

            .btn-group .btn {
                flex: 1;
                min-width: 80px;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 0.75rem;
            }

            .container-fluid {
                padding: 0 0.75rem;
            }

            .permission-tree-card {
                border-radius: var(--radius-lg);
            }

            .permission-module-header {
                padding: 0.625rem 1rem;
            }

            .permission-grid {
                padding: 0.5rem 0.75rem;
                gap: 0.25rem;
            }

            .permission-item {
                padding: 0.25rem 0.35rem;
            }

            .permission-item .form-check-label {
                font-size: 0.75rem;
            }

            .badge-custom {
                font-size: 0.75rem;
                padding: 0.35rem 0.75rem;
            }

            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.813rem;
            }

            .btn-sm {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
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
            }

            .permission-tree-card {
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }

            .permission-module-header {
                background: #f5f5f5 !important;
            }

            .breadcrumb-container {
                display: none;
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
                            <li class="breadcrumb-item active" aria-current="page">Manage Permissions</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Notifications -->
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

                <form action="save_permissions.php" method="post">
                    <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

                    <!-- Header Controls -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h4 class="mb-1 fw-bold"><i class="fas fa-key text-warning me-2"></i>Configure Permissions for: <span class="text-primary"><?= htmlspecialchars($role['role_name']) ?></span></h4>
                                <p class="text-muted small mb-0">Select the actions this role is permitted to perform across all system modules.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge-custom">
                                    <i class="fas fa-check-circle text-primary"></i>
                                    Selected: <span id="selectedPermCount" class="fw-bold text-primary">0</span>
                                </span>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectAllPerms">
                                    <i class="fas fa-check-double me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnDeselectAllPerms">
                                    <i class="fas fa-undo me-1"></i>Deselect All
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm">
                                    <i class="fas fa-save me-1"></i>Save Permissions
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions Matrix Grid -->
                    <div class="row g-3">
                        <?php foreach ($groupedPermissions as $moduleName => $permissions): ?>
                            <?php
                            $modulePermIds = array_column($permissions, 'id');
                            $moduleCheckedCount = count(array_intersect($modulePermIds, $assignedPermissionIds));
                            $allCheckedInModule = ($moduleCheckedCount === count($modulePermIds) && count($modulePermIds) > 0);
                            ?>
                            <div class="col-lg-6">
                                <div class="permission-tree-card">
                                    <div class="permission-module-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-layer-group text-primary"></i>
                                            <h6 class="permission-module-title"><?= htmlspecialchars($moduleName) ?></h6>
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input module-select-all" type="checkbox" id="module_<?= md5($moduleName) ?>" data-module="<?= htmlspecialchars($moduleName) ?>" <?= $allCheckedInModule ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold text-muted" for="module_<?= md5($moduleName) ?>">Select All</label>
                                        </div>
                                    </div>
                                    <div class="permission-grid">
                                        <?php foreach ($permissions as $perm): ?>
                                            <?php $isAssigned = in_array((int)$perm['id'], $assignedPermissionIds, true); ?>
                                            <div class="permission-item">
                                                <input type="checkbox" name="permissions[]" value="<?= (int)$perm['id'] ?>" id="perm_<?= (int)$perm['id'] ?>" class="form-check-input permission-checkbox" data-module="<?= htmlspecialchars($moduleName) ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                                <label for="perm_<?= (int)$perm['id'] ?>" class="form-check-label mb-0 small text-dark" style="cursor: pointer;" title="<?= htmlspecialchars($perm['description'] ?? '') ?>">
                                                    <?= htmlspecialchars($perm['permission_name']) ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Bottom Action Bar -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Roles
                            </a>
                            <button type="submit" class="btn btn-primary px-5 py-2 shadow-sm">
                                <i class="fas fa-save me-2"></i>Save Permissions
                            </button>
                        </div>
                    </div>
                </form>
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

        // Update selected count
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.permission-checkbox:checked');
            document.getElementById('selectedPermCount').textContent = checked.length;
        }

        // Initialize count
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();

            // Update count on checkbox change
            document.querySelectorAll('.permission-checkbox').forEach(cb => {
                cb.addEventListener('change', function() {
                    updateSelectedCount();
                    
                    // Update module select all state
                    const module = this.dataset.module;
                    const moduleCheckboxes = document.querySelectorAll(`.permission-checkbox[data-module="${module}"]`);
                    const moduleChecked = document.querySelectorAll(`.permission-checkbox[data-module="${module}"]:checked`);
                    const moduleSelectAll = document.querySelector(`.module-select-all[data-module="${module}"]`);
                    
                    if (moduleSelectAll) {
                        if (moduleCheckboxes.length === moduleChecked.length) {
                            moduleSelectAll.checked = true;
                        } else {
                            moduleSelectAll.checked = false;
                        }
                    }
                });
            });

            // Module select all
            document.querySelectorAll('.module-select-all').forEach(cb => {
                cb.addEventListener('change', function() {
                    const module = this.dataset.module;
                    const moduleCheckboxes = document.querySelectorAll(`.permission-checkbox[data-module="${module}"]`);
                    moduleCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateSelectedCount();
                });
            });

            // Select All button
            document.getElementById('btnSelectAllPerms')?.addEventListener('click', function() {
                document.querySelectorAll('.permission-checkbox').forEach(cb => {
                    cb.checked = true;
                });
                document.querySelectorAll('.module-select-all').forEach(cb => {
                    cb.checked = true;
                });
                updateSelectedCount();
            });

            // Deselect All button
            document.getElementById('btnDeselectAllPerms')?.addEventListener('click', function() {
                document.querySelectorAll('.permission-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll('.module-select-all').forEach(cb => {
                    cb.checked = false;
                });
                updateSelectedCount();
            });
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

            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('form')?.submit();
            }

            // Ctrl/Cmd + A to select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                document.getElementById('btnSelectAllPerms')?.click();
            }
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-check-input, .form-check-label').forEach(el => {
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