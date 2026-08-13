<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Organizations';
require_admin_access($pdo);

$search = trim($_GET['search'] ?? '');
$projectType = trim($_GET['project_type'] ?? '');
$organizationType = trim($_GET['organization_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ['deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(organization_name LIKE ? OR organization_code LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($projectType !== '') {
    $where[] = 'project_type = ?';
    $params[] = $projectType;
}

if ($organizationType !== '') {
    $where[] = 'organization_type = ?';
    $params[] = $organizationType;
}

if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter === 'active' ? 1 : 0;
}

$sql = 'SELECT * FROM organizations WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($organizations);
$offset = ($page - 1) * $perPage;
$paginated = array_slice($organizations, $offset, $perPage);
$totalPages = max(1, (int)ceil($totalItems / $perPage));

$counts = get_organization_counts($pdo);
$recent = $pdo->query("SELECT * FROM organizations WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

function get_organization_status_badge($status) {
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
    <title>Organizations · ID Card Generator</title>

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
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card .stat-label {
            font-size: 0.813rem;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
        }

        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--neutral-800);
        }

        .stat-card .stat-number.text-primary {
            color: var(--primary);
        }

        .stat-card .stat-number.text-success {
            color: var(--success);
        }

        .stat-card .stat-number.text-secondary {
            color: var(--neutral-600);
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

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.813rem;
        }

        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .btn.w-100 {
            width: 100%;
            justify-content: center;
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

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin-bottom: 1rem;
        }

        .border-0 {
            border: none;
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
            padding: 0.75rem 1rem;
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
            padding: 0.75rem 1rem;
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

        .table .text-center {
            text-align: center;
        }

        /* Logo Thumb */
        .logo-thumb {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-lg);
            object-fit: cover;
            border: 1px solid var(--neutral-200);
            background: var(--neutral-100);
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
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
            padding: 0.75rem 0;
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

        .list-group-item .justify-content-between {
            justify-content: space-between;
        }

        .list-group-item .align-items-center {
            align-items: center;
        }

        .badge.bg-light {
            background: var(--neutral-100);
            color: var(--neutral-600);
        }

        /* Pagination */
        .pagination-custom {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 0.25rem;
            padding-top: 1rem;
            list-style: none;
        }

        .pagination-custom .page-item {
            display: inline-block;
        }

        .pagination-custom .page-link {
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

        .pagination-custom .page-link:hover:not(.active) {
            background: var(--neutral-100);
            border-color: var(--neutral-400);
            transform: translateY(-2px);
        }

        .pagination-custom .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Row and Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-lg-8 {
            flex: 0 0 66.666%;
            max-width: 66.666%;
            padding: 0 0.75rem;
        }

        .col-lg-4 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
            padding: 0 0.75rem;
        }

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 0.75rem;
        }

        .col-md-2 {
            flex: 0 0 16.666%;
            max-width: 16.666%;
            padding: 0 0.75rem;
        }

        .col-md-1 {
            flex: 0 0 8.333%;
            max-width: 8.333%;
            padding: 0 0.75rem;
        }

        .g-2 {
            margin: -0.5rem;
        }

        .g-2 > [class*="col-"] {
            padding: 0.5rem;
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
        .mt-4 {
            margin-top: 1.5rem;
        }
        .me-1 {
            margin-right: 0.25rem;
        }
        .me-2 {
            margin-right: 0.5rem;
        }

        .fw-semibold {
            font-weight: 600;
        }
        .fw-bold {
            font-weight: 700;
        }
        .text-muted {
            color: var(--neutral-500);
        }
        .text-primary {
            color: var(--primary);
        }
        .text-success {
            color: var(--success);
        }
        .text-secondary {
            color: var(--neutral-600);
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
        .justify-content-end {
            justify-content: flex-end;
        }
        .align-items-center {
            align-items: center;
        }
        .align-items-md-center {
            align-items: center;
        }

        /* Form Controls */
        .form-control,
        .form-select {
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: white;
            color: var(--neutral-800);
            width: 100%;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-right: 2rem;
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

        .modal-header .btn-close {
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

        .text-muted.small {
            color: var(--neutral-500);
            font-size: 0.813rem;
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

            .col-lg-8,
            .col-lg-4 {
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

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-card .stat-number {
                font-size: 1.25rem;
            }

            .col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .card-body {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-header form {
                width: 100%;
            }

            .page-header form .row {
                flex-direction: column;
            }

            .page-header form .col-md-3,
            .page-header form .col-md-2,
            .page-header form .col-md-1 {
                flex: 0 0 100%;
                max-width: 100%;
                padding: 0.25rem 0;
            }

            .table thead th,
            .table tbody td {
                padding: 0.5rem 0.5rem;
                font-size: 0.75rem;
            }

            .logo-thumb {
                width: 32px;
                height: 32px;
            }

            .pagination-custom {
                justify-content: center;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
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

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .stat-card {
                padding: 0.75rem 1rem;
            }

            .stat-card .stat-number {
                font-size: 1.1rem;
            }

            .col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .card-body {
                padding: 0.75rem;
            }

            .table {
                font-size: 0.75rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.4rem 0.4rem;
                font-size: 0.688rem;
            }

            .logo-thumb {
                width: 28px;
                height: 28px;
            }

            .btn-group-sm .btn {
                padding: 0.15rem 0.3rem;
                font-size: 0.625rem;
            }

            .status-badge {
                font-size: 0.625rem;
                padding: 0.15rem 0.4rem;
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
            .btn,
            .btn-group,
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

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }

            .table thead th {
                background: #f5f5f5 !important;
            }

            .breadcrumb-container {
                display: none;
            }

            .status-badge {
                border: 1px solid #ddd;
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
            <div class="content-area">
                <!-- Breadcrumb -->
                <div class="breadcrumb-container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Organizations</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($_SESSION['organization_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['organization_message']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['organization_message']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['organization_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($_SESSION['organization_error']) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                    <?php unset($_SESSION['organization_error']); ?>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Organizations</div>
                        <div class="stat-number text-primary"><?= $counts['total'] ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Active</div>
                        <div class="stat-number text-success"><?= $counts['active'] ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Inactive</div>
                        <div class="stat-number text-secondary"><?= $counts['inactive'] ?></div>
                    </div>
                    <div class="stat-card" style="cursor: pointer;" onclick="window.location.href='add.php'">
                        <div class="stat-label">Quick Action</div>
                        <div class="stat-number" style="font-size: 1rem; color: var(--primary);">Add Organization</div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Main Table -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3">
                                    <div>
                                        <h5 class="card-title mb-0"><i class="fas fa-building text-primary me-2"></i>Organization Directory</h5>
                                        <p class="text-muted small mb-0">Manage organizations, status, and branding details.</p>
                                    </div>
                                    <div>
                                        <form method="get" class="row g-2 align-items-end">
                                            <div class="col-md-3">
                                                <input type="text" name="search" class="form-control" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <select name="project_type" class="form-select">
                                                    <option value="">Project Type</option>
                                                    <option value="residence" <?= $projectType === 'residence' ? 'selected' : '' ?>>Residence</option>
                                                    <option value="corporate" <?= $projectType === 'corporate' ? 'selected' : '' ?>>Corporate</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <select name="organization_type" class="form-select">
                                                    <option value="">Organization Type</option>
                                                    <option value="company" <?= $organizationType === 'company' ? 'selected' : '' ?>>Company</option>
                                                    <option value="school" <?= $organizationType === 'school' ? 'selected' : '' ?>>School</option>
                                                    <option value="college" <?= $organizationType === 'college' ? 'selected' : '' ?>>College</option>
                                                    <option value="government" <?= $organizationType === 'government' ? 'selected' : '' ?>>Government</option>
                                                    <option value="hospital" <?= $organizationType === 'hospital' ? 'selected' : '' ?>>Hospital</option>
                                                    <option value="ngo" <?= $organizationType === 'ngo' ? 'selected' : '' ?>>NGO</option>
                                                    <option value="other" <?= $organizationType === 'other' ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <select name="status" class="form-select">
                                                    <option value="">Status</option>
                                                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                                <button type="submit" class="btn btn-outline-secondary w-100"><i class="fas fa-search"></i></button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Logo</th>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Type</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($paginated)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">
                                                        <i class="fas fa-building fa-2x mb-2 d-block text-secondary"></i>
                                                        No organizations found.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($paginated as $organization): ?>
                                                    <tr>
                                                        <td>
                                                            <img src="<?= htmlspecialchars(get_organization_logo_path($organization['logo'] ?? '')) ?>" class="logo-thumb" alt="Logo">
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($organization['organization_name']) ?></div>
                                                            <div class="small text-muted"><?= htmlspecialchars($organization['email'] ?? '') ?></div>
                                                        </td>
                                                        <td><?= htmlspecialchars($organization['organization_code'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars(ucfirst((string)($organization['organization_type'] ?? ''))) ?></td>
                                                        <td><?= get_organization_status_badge((int)($organization['status'] ?? 0)) ?></td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <a href="view.php?id=<?= (int)$organization['id'] ?>" class="btn btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                                                                <a href="edit.php?id=<?= (int)$organization['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                                                                <a href="status.php?id=<?= (int)$organization['id'] ?>&status=<?= (int)$organization['status'] === 1 ? 0 : 1 ?>" class="btn btn-outline-warning" title="Toggle Status"><i class="fas fa-toggle-on"></i></a>
                                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteOrganizationModal" data-id="<?= (int)$organization['id'] ?>" data-name="<?= htmlspecialchars($organization['organization_name'], ENT_QUOTES) ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($totalPages > 1): ?>
                                    <nav>
                                        <ul class="pagination-custom">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&project_type=<?= urlencode($projectType) ?>&organization_type=<?= urlencode($organizationType) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity Sidebar -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-clock text-primary me-2"></i>Recently Added</h5>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent as $item): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                            <div>
                                                <div class="fw-semibold text-dark"><?= htmlspecialchars($item['organization_name']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($item['organization_code'] ?? '') ?></div>
                                            </div>
                                            <span class="badge bg-light text-dark"><?= date('M d', strtotime($item['created_at'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Modal Confirmation for Organization Delete -->
    <div class="modal fade" id="deleteOrganizationModal" tabindex="-1" aria-labelledby="deleteOrganizationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteOrganizationModalLabel"><i class="fas fa-trash-alt text-danger me-2"></i>Delete Organization</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="delete.php" id="deleteOrganizationForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="id" id="deleteOrganizationId" value="">
                        <p>Are you sure you want to delete <strong id="deleteOrganizationName" class="text-danger"></strong>?</p>
                        <p class="text-muted small">This will soft-delete the organization and prevent it from appearing in active listings.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i>Delete</button>
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

        // Delete Organization Modal
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteOrganizationModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const orgId = button.getAttribute('data-id');
                    const orgName = button.getAttribute('data-name');

                    document.getElementById('deleteOrganizationId').value = orgId;
                    document.getElementById('deleteOrganizationName').textContent = orgName;
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
                document.querySelector('input[name="search"]')?.focus();
            }

            // Ctrl/Cmd + N to add new organization
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add.php';
            }
        });

        // Auto-submit on filter change
        document.querySelectorAll('select[name="project_type"], select[name="organization_type"], select[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .page-link, .form-control, .form-select, .list-group-item').forEach(el => {
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