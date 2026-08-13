<?php
/**
 * Users Management Module - User Listing Page
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Users Management';
$currentUser = require_user_module_access($pdo);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Users Management · ID Card Generator</title>

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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-card .stat-icon.success {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-card .stat-icon.warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .stat-card .stat-icon.info {
            background: var(--info-soft);
            color: var(--info);
        }

        .stat-card .stat-details .stat-label {
            font-size: 0.813rem;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
        }

        .stat-card .stat-details .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neutral-800);
        }

        .stat-card .stat-details .stat-number.text-success {
            color: var(--success);
        }

        .stat-card .stat-details .stat-number.text-secondary {
            color: var(--neutral-600);
        }

        /* Main Card */
        .main-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
        }

        .card-header-custom {
            background: white;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
        }

        .card-header-custom h5 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin-bottom: 0.25rem;
        }

        .card-header-custom h5 i {
            color: var(--primary);
        }

        .card-header-custom p {
            color: var(--neutral-500);
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        .card-body-custom {
            padding: 0;
        }

        .card-footer-custom {
            background: white;
            padding: 0.75rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
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

        .btn-outline-info {
            background: transparent;
            border: 1px solid var(--info);
            color: var(--info);
        }

        .btn-outline-info:hover {
            background: var(--info);
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

        .btn-outline-danger {
            background: transparent;
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-dark {
            background: transparent;
            border: 1px solid var(--neutral-800);
            color: var(--neutral-800);
        }

        .btn-outline-dark:hover {
            background: var(--neutral-800);
            color: white;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.813rem;
        }

        .btn-group-sm .btn {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .btn.w-100 {
            width: 100%;
        }
        .py-3 {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }
        .shadow-sm {
            box-shadow: var(--shadow-sm);
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
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            background: var(--neutral-100);
            color: var(--neutral-700);
            border: 1px solid var(--neutral-200);
        }

        .badge-custom i {
            font-size: 0.75rem;
        }

        .badge-custom.bg-info {
            background: var(--info-soft);
            color: var(--info);
            border-color: rgba(59, 130, 246, 0.25);
        }

        .badge-custom.bg-light {
            background: var(--neutral-100);
            color: var(--neutral-600);
            border-color: var(--neutral-200);
        }

        /* User Avatar */
        .user-avatar-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--neutral-200);
            background: var(--neutral-100);
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .table thead th {
            text-align: left;
            padding: 0.875rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            background: var(--neutral-100);
            border-bottom: 1px solid var(--neutral-200);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--neutral-200);
            color: var(--neutral-600);
            font-size: 0.875rem;
            vertical-align: middle;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover td {
            background: var(--neutral-50);
        }

        .table .ps-4 {
            padding-left: 1.5rem;
        }
        .table .pe-4 {
            padding-right: 1.5rem;
        }
        .table .text-end {
            text-align: right;
        }
        .table .text-center {
            text-align: center;
        }

        .fw-bold {
            font-weight: 700;
        }
        .text-dark {
            color: var(--neutral-800);
        }
        .text-muted {
            color: var(--neutral-500);
        }
        .text-secondary {
            color: var(--neutral-600);
        }
        .small {
            font-size: 0.813rem;
        }
        .fst-italic {
            font-style: italic;
        }

        /* Filter Form */
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-form .input-group {
            max-width: 200px;
        }

        .filter-form .input-group .input-group-text {
            background: var(--neutral-100);
            border: 1px solid var(--neutral-300);
            border-right: none;
            color: var(--neutral-500);
            font-size: 0.813rem;
            padding: 0.4rem 0.6rem;
        }

        .filter-form .input-group .form-control {
            border: 1px solid var(--neutral-300);
            border-left: none;
            padding: 0.4rem 0.6rem;
            font-size: 0.813rem;
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            background: white;
            color: var(--neutral-800);
            transition: all 0.2s;
        }

        .filter-form .input-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .filter-form .form-select-sm {
            padding: 0.4rem 2rem 0.4rem 0.6rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-md);
            font-size: 0.813rem;
            background: white;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            color: var(--neutral-700);
            width: auto;
            min-width: 130px;
        }

        .filter-form .form-select-sm:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        /* Pagination */
        .pagination-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .pagination-info {
            color: var(--neutral-500);
            font-size: 0.813rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .pagination-controls .page-item {
            display: inline-block;
        }

        .pagination-controls .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 0.6rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-md);
            background: white;
            color: var(--neutral-600);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.813rem;
        }

        .pagination-controls .page-link:hover:not(.active):not(.disabled) {
            background: var(--neutral-100);
            border-color: var(--neutral-400);
            transform: translateY(-2px);
        }

        .pagination-controls .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .pagination-controls .page-item.disabled .page-link {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Modal */
        .modal-content {
            border-radius: var(--radius-2xl);
            border: none;
            box-shadow: var(--shadow-2xl);
            overflow: hidden;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
        }

        .modal-header.bg-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .modal-header.bg-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);
            color: var(--neutral-900);
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-header.bg-warning .btn-close {
            filter: none;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .modal-footer .btn {
            min-width: 80px;
            justify-content: center;
        }

        .text-danger {
            color: var(--danger);
        }
        .text-primary {
            color: var(--primary);
        }
        .fs-6 {
            font-size: 1rem;
        }
        .mb-0 {
            margin-bottom: 0;
        }
        .mb-2 {
            margin-bottom: 0.5rem;
        }
        .mb-3 {
            margin-bottom: 1rem;
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
        .mt-1 {
            margin-top: 0.25rem;
        }
        .mt-3 {
            margin-top: 1rem;
        }
        .mt-4 {
            margin-top: 1.5rem;
        }
        .my-4 {
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .gap-2 {
            gap: 0.5rem;
        }
        .gap-3 {
            gap: 1rem;
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
        .flex-wrap {
            flex-wrap: wrap;
        }
        .justify-content-between {
            justify-content: space-between;
        }
        .justify-content-center {
            justify-content: center;
        }
        .align-items-center {
            align-items: center;
        }
        .align-items-md-center {
            align-items: center;
        }

        /* Row & Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 0.75rem;
        }

        .g-3 {
            margin: -0.75rem;
        }

        .g-3 > [class*="col-"] {
            padding: 0.75rem;
        }

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

            .col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
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

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-card .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }

            .stat-card .stat-details .stat-number {
                font-size: 1.25rem;
            }

            .card-header-custom {
                padding: 1rem;
            }

            .card-footer-custom {
                padding: 0.75rem 1rem;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
            }

            .filter-form .input-group {
                max-width: 100%;
            }

            .filter-form .form-select-sm {
                width: 100%;
                min-width: unset;
            }

            .table thead th,
            .table tbody td {
                padding: 0.625rem 0.75rem;
                font-size: 0.813rem;
            }

            .table .ps-4 {
                padding-left: 0.75rem;
            }
            .table .pe-4 {
                padding-right: 0.75rem;
            }

            .pagination-custom {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .pagination-controls {
                justify-content: center;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 0.75rem;
            }

            .container-fluid {
                padding: 0 0.75rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .stat-card {
                padding: 0.75rem 1rem;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }

            .stat-card .stat-details .stat-number {
                font-size: 1.1rem;
            }

            .table {
                font-size: 0.75rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.5rem 0.5rem;
                font-size: 0.75rem;
            }

            .user-avatar-thumb {
                width: 32px;
                height: 32px;
            }

            .status-badge {
                font-size: 0.625rem;
                padding: 0.2rem 0.5rem;
            }

            .badge-custom {
                font-size: 0.625rem;
                padding: 0.2rem 0.5rem;
            }

            .btn-group-sm .btn {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }

            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.813rem;
            }

            .page-head h1 {
                font-size: 1.25rem;
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
            .filter-form,
            .btn,
            .btn-group,
            .pagination-controls,
            .modal,
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

            .main-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .table thead th {
                background: #f5f5f5 !important;
            }

            .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
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
                            <li class="breadcrumb-item text-muted">Administration</li>
                            <li class="breadcrumb-item active" aria-current="page">Users</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Notifications -->
                <?php if (!empty($_SESSION['user_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['user_message']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['user_message']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['user_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['user_error']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['user_error']); ?>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-label">Total Users</div>
                            <div class="stat-number"><?= $totalCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-label">Active Users</div>
                            <div class="stat-number text-success"><?= $activeCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-label">Inactive Users</div>
                            <div class="stat-number text-secondary"><?= $inactiveCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card" style="cursor: pointer;" onclick="window.location.href='add.php'">
                        <div class="stat-icon info">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-label">Quick Action</div>
                            <div class="stat-number" style="font-size: 1rem; color: var(--primary);">Add New User</div>
                        </div>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5><i class="fas fa-user-friends me-2"></i>User Directory</h5>
                                <p>Manage system user accounts, assigned roles, and organizational permissions.</p>
                            </div>
                            <!-- Filters Form -->
                            <form method="get" class="filter-form">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>">
                                </div>

                                <?php if (is_super_admin($currentUser)): ?>
                                    <select name="org_id" class="form-select-sm">
                                        <option value="">All Organizations</option>
                                        <?php foreach ($scopedOrgs as $org): ?>
                                            <option value="<?= (int)$org['id'] ?>" <?= $orgFilter === (string)$org['id'] ? 'selected' : '' ?>><?= htmlspecialchars($org['organization_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>

                                <select name="role_id" class="form-select-sm">
                                    <option value="">All Roles</option>
                                    <?php foreach ($scopedRoles as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>" <?= $roleFilter === (string)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="status" class="form-select-sm">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>

                                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                                <?php if ($search !== '' || $orgFilter !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                                    <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="fas fa-redo"></i></a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="ps-4" style="width: 60px;">Photo</th>
                                        <th>Full Name / Username</th>
                                        <th>Organization</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th class="text-end pe-4" style="width: 250px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="fas fa-user-slash fa-3x mb-3 text-secondary"></i>
                                                <p class="mb-0">No users found matching your criteria.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $u): ?>
                                            <?php
                                            $isSuper = is_super_admin($u) || (int)$u['id'] === 1;
                                            $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                                            ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <img src="<?= htmlspecialchars(get_user_avatar_path($u['avatar'] ?? '')) ?>" class="user-avatar-thumb" alt="Avatar">
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                                                    <div class="small text-muted">@<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['email'] ?: 'No email') ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge-custom bg-light">
                                                        <i class="fas fa-building me-1 text-primary"></i><?= htmlspecialchars($u['organization_name'] ?: 'System Global') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge-custom bg-info">
                                                        <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($u['role_name'] ?: ucfirst($u['role'] ?? 'User')) ?>
                                                    </span>
                                                </td>
                                                <td><?= get_user_status_badge((int)$u['status']) ?></td>
                                                <td class="small text-muted">
                                                    <?= !empty($u['last_login']) ? date('M d, Y g:i A', strtotime($u['last_login'])) : '<span class="fst-italic text-secondary">Never</span>' ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <a href="view.php?id=<?= (int)$u['id'] ?>" class="btn btn-outline-info" title="View Profile">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-outline-secondary" title="Edit User">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-id="<?= (int)$u['id'] ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>" title="Reset Password">
                                                            <i class="fas fa-key"></i>
                                                        </button>

                                                        <?php if (!$isSuper): ?>
                                                            <a href="status.php?id=<?= (int)$u['id'] ?>&csrf_token=<?= htmlspecialchars(generate_csrf_token()) ?>" class="btn btn-outline-<?= (int)$u['status'] === 1 ? 'dark' : 'success' ?>" title="<?= (int)$u['status'] === 1 ? 'Deactivate User' : 'Activate User' ?>">
                                                                <i class="fas fa-power-off"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-outline-secondary" disabled title="Super Admin cannot be deactivated"><i class="fas fa-lock"></i></button>
                                                        <?php endif; ?>

                                                        <?php if (!$isSuper && !$isSelf): ?>
                                                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal" data-id="<?= (int)$u['id'] ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>" title="Delete User">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-outline-secondary" disabled title="Protected Account"><i class="fas fa-trash"></i></button>
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

                    <!-- Footer Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer-custom">
                            <div class="pagination-custom">
                                <div class="pagination-info">
                                    Showing page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong> (Total: <?= $totalUsers ?> users)
                                </div>
                                <nav aria-label="User pagination">
                                    <ul class="pagination-controls">
                                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&search=<?= urlencode($search) ?>&org_id=<?= urlencode($orgFilter) ?>&role_id=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><i class="fas fa-chevron-left"></i></a>
                                        </li>
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&org_id=<?= urlencode($orgFilter) ?>&role_id=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&search=<?= urlencode($search) ?>&org_id=<?= urlencode($orgFilter) ?>&role_id=<?= urlencode($roleFilter) ?>&status=<?= urlencode($statusFilter) ?>"><i class="fas fa-chevron-right"></i></a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Modal Confirmation for User Delete -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteUserModalLabel"><i class="fas fa-user-slash me-2"></i>Delete User Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="delete.php" id="deleteUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="id" id="deleteUserId" value="">
                        <p class="fs-6 mb-2">Are you sure you want to soft-delete user <strong id="deleteUsername" class="text-danger"></strong>?</p>
                        <div id="deleteUserWarning" class="text-muted small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i>Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Reset Password -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="resetPasswordModalLabel"><i class="fas fa-key me-2"></i>Reset User Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="reset_password.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="resetUserId">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <p>Reset password for user: <strong id="resetUsername" class="text-primary"></strong></p>
                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="new_password" id="new_password" class="form-control" placeholder="Enter new password..." required>
                                <button type="button" class="btn btn-outline-secondary" id="btnGeneratePassword" title="Generate Secure Password"><i class="fas fa-random me-1"></i>Auto Generate</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Save Password</button>
                    </div>
                </form>
            </div>
        </div>
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

        // Delete User Modal
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteUserModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-id');
                    const username = button.getAttribute('data-username');

                    document.getElementById('deleteUserId').value = userId;
                    document.getElementById('deleteUsername').textContent = username;
                });
            }

            // Reset Password Modal
            const resetModal = document.getElementById('resetPasswordModal');
            if (resetModal) {
                resetModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-id');
                    const username = button.getAttribute('data-username');

                    document.getElementById('resetUserId').value = userId;
                    document.getElementById('resetUsername').textContent = username;
                });
            }

            // Generate Password
            document.getElementById('btnGeneratePassword')?.addEventListener('click', function() {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
                let password = '';
                for (let i = 0; i < 12; i++) {
                    password += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('new_password').value = password;
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

            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]')?.focus();
            }

            // Ctrl/Cmd + N to add new user
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add.php';
            }
        });

        // Auto-submit on filter change
        document.querySelectorAll('.filter-form select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Touch-friendly improvements
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