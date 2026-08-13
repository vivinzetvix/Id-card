<?php
/**
 * Users Management Module - View User Profile Page
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'View User Profile';
$currentUser = require_user_module_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$user = get_user_by_id($pdo, $id);

if (!$user) {
    $_SESSION['user_error'] = 'User not found.';
    header('Location: index.php');
    exit();
}

// Scope check for Organization Admin
if (!is_super_admin($currentUser) && (int)($user['organization_id'] ?? 0) !== (int)($currentUser['organization_id'] ?? 0)) {
    $_SESSION['user_error'] = 'Access Denied. You cannot view users outside your organization.';
    header('Location: index.php');
    exit();
}

$isSuperTarget = is_target_super_admin($pdo, $id);

// CSRF token helper
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function is_super_admin($user) {
    return (int)($user['role_id'] ?? 0) === 1 || strtolower($user['role'] ?? '') === 'super_admin';
}

function is_target_super_admin($pdo, $id) {
    $stmt = $pdo->prepare("SELECT role_id, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return (int)($row['role_id'] ?? 0) === 1 || strtolower($row['role'] ?? '') === 'super_admin';
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
    <title>View User Profile · ID Card Generator</title>

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
        .p-4 {
            padding: 1.5rem;
        }
        .ps-0 {
            padding-left: 0;
        }
        .px-3 {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
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
        .fs-6 {
            font-size: 1rem;
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
        .justify-content-center {
            justify-content: center;
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

        .btn-secondary {
            background: var(--neutral-200);
            color: var(--neutral-700);
        }

        .btn-secondary:hover {
            background: var(--neutral-300);
            color: var(--neutral-800);
            transform: translateY(-2px);
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

        .badge-custom.bg-light {
            background: var(--neutral-100);
            color: var(--neutral-600);
            border-color: var(--neutral-200);
        }

        .badge-custom.bg-info {
            background: var(--info-soft);
            color: var(--info);
            border-color: rgba(59, 130, 246, 0.25);
        }

        .badge-custom.px-3 {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        .badge-custom.py-2 {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        /* User Avatar Large */
        .user-avatar-lg {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--neutral-200);
            background: var(--neutral-100);
            transition: all 0.2s;
        }

        .user-avatar-lg:hover {
            border-color: var(--primary);
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
            padding: 0.75rem 0;
        }

        .table th {
            font-weight: 500;
            color: var(--neutral-500);
            text-align: left;
            width: 150px;
            font-size: 0.875rem;
        }

        .table td {
            color: var(--neutral-700);
            font-size: 0.875rem;
        }

        .fst-italic {
            font-style: italic;
        }

        /* Row and Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-lg-4 {
            flex: 0 0 33.333%;
            max-width: 33.333%;
            padding: 0 0.75rem;
        }

        .col-lg-8 {
            flex: 0 0 66.666%;
            max-width: 66.666%;
            padding: 0 0.75rem;
        }

        .col-md-5 {
            flex: 0 0 41.666%;
            max-width: 41.666%;
            padding: 0 0.75rem;
        }

        .col-md-7 {
            flex: 0 0 58.333%;
            max-width: 58.333%;
            padding: 0 0.75rem;
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

        .text-center {
            text-align: center;
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

        .modal-header.bg-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);
            color: var(--neutral-900);
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

        .form-label {
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }

        .form-label .text-danger {
            color: var(--danger);
        }

        .form-control {
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: white;
            color: var(--neutral-800);
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .input-group {
            display: flex;
            align-items: stretch;
            width: 100%;
        }

        .input-group .form-control {
            border-radius: var(--radius-lg) 0 0 var(--radius-lg);
        }

        .input-group .btn {
            border-radius: 0 var(--radius-lg) var(--radius-lg) 0;
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

            .col-lg-4,
            .col-lg-8 {
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

            .col-md-5,
            .col-md-7 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .user-avatar-lg {
                width: 100px;
                height: 100px;
            }

            .table th {
                width: 120px;
                font-size: 0.813rem;
            }

            .table td {
                font-size: 0.813rem;
            }

            .table-borderless td,
            .table-borderless th {
                padding: 0.5rem 0;
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

            .card.p-4 {
                padding: 1rem !important;
            }

            .user-avatar-lg {
                width: 80px;
                height: 80px;
            }

            .table th {
                width: 100px;
                font-size: 0.75rem;
            }

            .table td {
                font-size: 0.75rem;
            }

            .table-borderless td,
            .table-borderless th {
                padding: 0.4rem 0;
            }

            .badge-custom.px-3.py-2 {
                padding: 0.3rem 0.6rem;
                font-size: 0.688rem;
            }

            .status-badge {
                font-size: 0.688rem;
                padding: 0.2rem 0.5rem;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .input-group {
                flex-direction: column;
            }

            .input-group .form-control {
                border-radius: var(--radius-lg);
                margin-bottom: 0.5rem;
            }

            .input-group .btn {
                border-radius: var(--radius-lg);
                width: 100%;
                justify-content: center;
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

            .card-header {
                background: #f5f5f5 !important;
            }

            .breadcrumb-container {
                display: none;
            }

            .status-badge {
                border: 1px solid #ddd;
            }

            .user-avatar-lg {
                border: 2px solid #ddd;
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
                            <li class="breadcrumb-item"><a href="index.php">Users</a></li>
                            <li class="breadcrumb-item active" aria-current="page">View Profile</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Messages -->
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

                <!-- Header Actions -->
                <div class="page-head">
                    <div>
                        <h3><i class="fas fa-user-circle me-2"></i><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h3>
                        <div class="text-muted">User ID: #<?= (int)$user['id'] ?> &bull; Account Status: <?= get_user_status_badge((int)$user['status']) ?></div>
                    </div>
                    <div class="btn-group">
                        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Users</a>
                        <a href="edit.php?id=<?= (int)$user['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit User</a>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"><i class="fas fa-key me-1"></i>Reset Password</button>
                    </div>
                </div>

                <div class="row g-4 justify-content-center">
                    <!-- Profile Card -->
                    <div class="col-lg-4 col-md-5">
                        <div class="card text-center p-4">
                            <div class="d-flex justify-content-center mb-3">
                                <img src="<?= htmlspecialchars(get_user_avatar_path($user['avatar'] ?? '')) ?>" class="user-avatar-lg" alt="Avatar">
                            </div>
                            <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h4>
                            <p class="text-muted mb-2">@<?= htmlspecialchars($user['username']) ?></p>
                            <div class="mb-3">
                                <span class="badge-custom bg-info px-3 py-2 fs-6">
                                    <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($user['role_name'] ?: ucfirst($user['role'] ?? 'User')) ?>
                                </span>
                            </div>
                            <div class="text-muted small">
                                <i class="fas fa-building text-primary me-1"></i><?= htmlspecialchars($user['organization_name'] ?: 'System Global') ?>
                            </div>
                        </div>
                    </div>

                    <!-- Details Card -->
                    <div class="col-lg-8 col-md-7">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="fas fa-id-card text-primary me-2"></i>Account Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th class="text-muted ps-0">Full Name</th>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($user['full_name'] ?: 'Not specified') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Username</th>
                                        <td class="fw-semibold text-primary">@<?= htmlspecialchars($user['username']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Email Address</th>
                                        <td><?= htmlspecialchars($user['email'] ?: 'No email registered') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Mobile Number</th>
                                        <td><?= htmlspecialchars($user['mobile'] ?: 'No mobile registered') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Organization</th>
                                        <td>
                                            <span class="badge-custom bg-light px-3 py-2">
                                                <i class="fas fa-building me-1 text-primary"></i><?= htmlspecialchars($user['organization_name'] ?: 'System Global') ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Role Assigned</th>
                                        <td>
                                            <span class="badge-custom bg-info px-3 py-2">
                                                <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($user['role_name'] ?: ucfirst($user['role'] ?? 'User')) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Account Status</th>
                                        <td><?= get_user_status_badge((int)$user['status']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Created Date</th>
                                        <td><?= date('F j, Y g:i A', strtotime($user['created_at'] ?? 'now')) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Last Updated</th>
                                        <td><?= date('F j, Y g:i A', strtotime($user['updated_at'] ?? 'now')) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted ps-0">Last Login</th>
                                        <td>
                                            <?= !empty($user['last_login']) ? date('F j, Y g:i A', strtotime($user['last_login'])) : '<span class="fst-italic text-secondary">Never logged in</span>' ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
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
                        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <p>Reset password for user: <strong class="text-primary">@<?= htmlspecialchars($user['username']) ?></strong></p>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="new_password" id="new_password" class="form-control" placeholder="Enter new password..." required>
                                <button type="button" class="btn btn-outline-secondary" id="btnGeneratePassword" title="Generate Secure Password"><i class="fas fa-random me-1"></i>Auto Generate</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
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

        // Generate Password
        document.getElementById('btnGeneratePassword')?.addEventListener('click', function() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('new_password').value = password;
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
                window.location.href = 'edit.php?id=<?= (int)$user['id'] ?>';
            }

            // Backspace to go back
            if (e.key === 'Backspace' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                window.location.href = 'index.php';
            }
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .badge-custom, .card').forEach(el => {
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