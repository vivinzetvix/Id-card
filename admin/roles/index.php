<?php
/**
 * Role Management Module - Role Listing Page
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Roles Management';
require_admin_access($pdo);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$sort = trim($_GET['sort'] ?? 'id');
$order = trim($_GET['order'] ?? 'DESC');

$result = get_all_roles($pdo, $search, $statusFilter, $page, $perPage, $sort, $order);
$roles = $result['roles'];
$totalRoles = $result['total'];
$totalPages = $result['total_pages'];
$currentPage = $result['page'];

// Global Stats
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE status = 1")->fetchColumn();
$inactiveCount = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE status = 0")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();

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
    <title>Roles Management · ID Card Generator</title>

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

        /* Page Header */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-head h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--neutral-800);
            letter-spacing: -0.02em;
        }

        .head-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--neutral-500);
            font-size: 0.95rem;
            flex-wrap: wrap;
        }

        /* Breadcrumb */
        .breadcrumb-container {
            background: transparent;
            padding: 0 0 1rem 0;
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

        .alert-close {
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
            background: none;
            border: none;
            color: inherit;
            font-size: 1.25rem;
        }

        .alert-close:hover {
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
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s;
            cursor: default;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.clickable {
            cursor: pointer;
        }

        .stat-card.clickable:hover {
            border-color: var(--primary);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .stat-icon.primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-icon.success {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-icon.warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .stat-icon.info {
            background: var(--info-soft);
            color: var(--info);
        }

        .stat-details h3 {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
        }

        .stat-details .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neutral-800);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }

        .btn-outline:hover {
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

        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }

        .btn-outline-secondary:hover {
            background: var(--neutral-200);
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
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-group-sm .btn {
            padding: 0.4rem 0.75rem;
            font-size: 0.813rem;
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
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
        }

        .card-header-custom h5 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin-bottom: 0.25rem;
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
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
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

        .align-items-md-center {
            align-items: center;
        }

        .gap-3 {
            gap: 1rem;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            padding: 0.75rem 0;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-group .input-group {
            max-width: 250px;
            display: flex;
            align-items: center;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-md);
            background: white;
            overflow: hidden;
            transition: all 0.2s;
        }

        .filter-group .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .filter-group .input-group-text {
            background: transparent;
            border: none;
            color: var(--neutral-500);
            font-size: 0.875rem;
            padding: 0.5rem 0 0.5rem 0.75rem;
        }

        .filter-group .form-control {
            border: none;
            padding: 0.5rem 0.75rem 0.5rem 0.25rem;
            font-size: 0.875rem;
            background: transparent;
            color: var(--neutral-800);
            outline: none;
            width: 100%;
        }

        .filter-group .form-control::placeholder {
            color: var(--neutral-400);
        }

        .filter-group .form-select {
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            background: white;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            color: var(--neutral-700);
            transition: all 0.2s;
        }

        .filter-group .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
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

        .fw-bold {
            font-weight: 700;
        }
        .fw-semibold {
            font-weight: 600;
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
        .text-center {
            text-align: center;
        }
        .text-end {
            text-align: right;
        }
        .small {
            font-size: 0.813rem;
        }
        .me-1 {
            margin-right: 0.25rem;
        }
        .me-2 {
            margin-right: 0.5rem;
        }
        .mt-3 {
            margin-top: 1rem;
        }
        .mb-0 {
            margin-bottom: 0;
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

        .badge-custom i {
            font-size: 0.75rem;
        }

        .badge-custom.text-primary i {
            color: var(--primary);
        }

        .badge-custom.text-warning i {
            color: var(--warning);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--neutral-300);
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: var(--neutral-500);
            margin-bottom: 0;
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
            font-size: 0.875rem;
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
            min-width: 36px;
            height: 36px;
            padding: 0 0.75rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-md);
            background: white;
            color: var(--neutral-600);
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.875rem;
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

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
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

        .text-danger {
            color: var(--danger);
        }
        .text-warning {
            color: var(--warning);
        }
        .fs-6 {
            font-size: 1rem;
        }
        .mb-2 {
            margin-bottom: 0.5rem;
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
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }

            .page-head h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-group .input-group {
                max-width: 100%;
            }

            .filter-group .form-select {
                width: 100%;
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

            .btn-group-sm .btn {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
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

            .card-header-custom .d-flex {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 480px) {
            .page-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .head-meta {
                width: 100%;
                flex-wrap: wrap;
            }

            .table-responsive {
                font-size: 0.75rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.5rem 0.5rem;
                font-size: 0.75rem;
            }

            .status-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }

            .badge-custom {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }

            .btn-group-sm .btn {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }

            .top-header {
                padding: 0.75rem 1rem;
            }

            .user-info {
                display: none;
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
            .filter-bar,
            .btn-group,
            .btn,
            .pagination-controls,
            .modal,
            .alert-close {
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

            .stats-grid {
                page-break-inside: avoid;
            }

            .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
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
                            <li class="breadcrumb-item active" aria-current="page">Roles</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($_SESSION['role_message'])): ?>
                    <div class="alert alert-success" id="successAlert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['role_message']) ?></div>
                        <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php unset($_SESSION['role_message']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['role_error'])): ?>
                    <div class="alert alert-danger" id="errorAlert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['role_error']) ?></div>
                        <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php unset($_SESSION['role_error']); ?>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-head">
                    <h1>Roles Management</h1>
                    <div class="head-meta">
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></span>
                        <a href="add.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add New Role
                        </a>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-details">
                            <h3>Total Roles</h3>
                            <div class="stat-number"><?= $totalCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>Active Roles</h3>
                            <div class="stat-number"><?= $activeCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-pause-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>Inactive Roles</h3>
                            <div class="stat-number"><?= $inactiveCount ?></div>
                        </div>
                    </div>

                    <div class="stat-card clickable" onclick="window.location.href='add.php'">
                        <div class="stat-icon info">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>Quick Action</h3>
                            <div class="stat-number" style="font-size: 1rem; color: var(--primary);">Create New Role</div>
                        </div>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5><i class="fas fa-shield-alt text-primary me-2"></i>System Roles</h5>
                                <p>Manage roles, set up module permissions, and control administrative access.</p>
                            </div>
                            <!-- Filters -->
                            <form method="get" class="filter-bar">
                                <div class="filter-group">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                                        <input type="text" name="search" class="form-control" placeholder="Search roles..." value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                </div>
                                <div class="filter-group">
                                    <select name="status" class="form-select">
                                        <option value="">All Statuses</option>
                                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                    <?php if ($search !== '' || $statusFilter !== ''): ?>
                                        <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                                            <i class="fas fa-redo"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="ps-4" style="width: 70px;">#</th>
                                        <th>Role Name</th>
                                        <th>Description</th>
                                        <th class="text-center">Assigned Users</th>
                                        <th class="text-center">Permissions</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4" style="width: 240px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($roles)): ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-user-shield"></i>
                                                    <p>No roles found matching your criteria.</p>
                                                    <a href="add.php" class="btn btn-primary btn-sm mt-3">
                                                        <i class="fas fa-plus"></i> Create First Role
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($roles as $role): ?>
                                            <tr>
                                                <td class="ps-4 fw-semibold text-muted">#<?= (int)$role['id'] ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($role['role_name']) ?></div>
                                                    <div class="small text-muted">Created: <?= date('M d, Y', strtotime($role['created_at'] ?? 'now')) ?></div>
                                                </td>
                                                <td>
                                                    <span class="text-secondary"><?= htmlspecialchars($role['description'] ?: 'No description provided.') ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge-custom">
                                                        <i class="fas fa-users text-primary"></i>
                                                        <?= (int)$role['user_count'] ?> Users
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge-custom">
                                                        <i class="fas fa-key text-warning"></i>
                                                        <?= (int)$role['perm_count'] ?> Active
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?= (int)$role['status'] === 1 ? 'active' : 'inactive' ?>">
                                                        <i class="fas <?= (int)$role['status'] === 1 ? 'fa-check-circle' : 'fa-minus-circle' ?>"></i>
                                                        <?= (int)$role['status'] === 1 ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <a href="view.php?id=<?= (int)$role['id'] ?>" class="btn btn-outline-info" title="View Role Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit.php?id=<?= (int)$role['id'] ?>" class="btn btn-outline-secondary" title="Edit Role">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="permissions.php?id=<?= (int)$role['id'] ?>" class="btn btn-outline-warning" title="Manage Permissions">
                                                            <i class="fas fa-key"></i>
                                                        </a>
                                                        <a href="status.php?id=<?= (int)$role['id'] ?>&csrf_token=<?= htmlspecialchars(generate_csrf_token()) ?>" class="btn btn-outline-<?= (int)$role['status'] === 1 ? 'dark' : 'success' ?>" title="<?= (int)$role['status'] === 1 ? 'Deactivate Role' : 'Activate Role' ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteRoleModal" data-id="<?= (int)$role['id'] ?>" data-name="<?= htmlspecialchars($role['role_name'], ENT_QUOTES) ?>" data-user-count="<?= (int)$role['user_count'] ?>" title="Delete Role">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
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
                                    Showing page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong> (Total: <?= $totalRoles ?> roles)
                                </div>
                                <nav aria-label="Role pagination">
                                    <ul class="pagination-controls">
                                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
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

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Modal Confirmation for Role Delete -->
    <div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-labelledby="deleteRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteRoleModalLabel"><i class="fas fa-trash-alt me-2"></i>Delete Role</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="delete.php" id="deleteRoleForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="id" id="deleteRoleId" value="">
                        <p class="fs-6 mb-2">Are you sure you want to delete role <strong id="deleteRoleName" class="text-danger"></strong>?</p>
                        <div id="deleteRoleWarning" class="text-muted small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i>Delete Role</button>
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

        // Delete Role Modal
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteRoleModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const roleId = button.getAttribute('data-id');
                    const roleName = button.getAttribute('data-name');
                    const userCount = button.getAttribute('data-user-count');

                    document.getElementById('deleteRoleId').value = roleId;
                    document.getElementById('deleteRoleName').textContent = roleName;
                    const warningDiv = document.getElementById('deleteRoleWarning');

                    if (userCount > 0) {
                        warningDiv.innerHTML = '<i class="fas fa-exclamation-triangle text-warning me-1"></i> <strong>Warning:</strong> This role is assigned to <strong>' + userCount + '</strong> users. Deleting this role may affect their permissions.';
                        warningDiv.style.color = 'var(--warning)';
                    } else {
                        warningDiv.innerHTML = '';
                        warningDiv.style.color = '';
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close mobile menu
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                document.querySelector('.sidebar')?.classList.remove('active');
            }

            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) searchInput.focus();
            }

            // Ctrl/Cmd + N to add new role
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add.php';
            }
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

        // Auto-submit on filter change
        document.querySelector('select[name="status"]')?.addEventListener('change', function() {
            this.closest('form').submit();
        });
    </script>
</body>
</html>