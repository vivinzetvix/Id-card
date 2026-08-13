<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'View Organization';
require_admin_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$organization = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$organization) {
    $_SESSION['organization_error'] = 'Organization not found.';
    header('Location: index.php');
    exit();
}

$usersCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE organization_id = ' . (int)$id)->fetchColumn();
$membersCount = (int)$pdo->query('SELECT COUNT(*) FROM id_members WHERE organization_id = ' . (int)$id)->fetchColumn();
$templatesCount = (int)$pdo->query('SELECT COUNT(*) FROM card_templates WHERE organization_id = ' . (int)$id)->fetchColumn();
$generatedCardsCount = (int)$pdo->query('SELECT COUNT(*) FROM generated_cards WHERE organization_id = ' . (int)$id)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>View Organization · ID Card Generator</title>

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

        /* Card */
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

        .card-header-custom {
            background: white;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
        }

        .card-header-custom h5 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header-custom h5 i {
            color: var(--primary);
        }

        .card-body-custom {
            padding: 2rem;
        }

        .border-0 {
            border: none;
        }

        /* Logo */
        .org-logo {
            max-height: 200px;
            max-width: 100%;
            border-radius: var(--radius-xl);
            border: 2px solid var(--neutral-200);
            padding: 0.5rem;
            background: var(--neutral-50);
            object-fit: contain;
            transition: all 0.2s;
        }

        .org-logo:hover {
            border-color: var(--primary);
        }

        /* Info Box */
        .info-box {
            padding: 1rem 1.25rem;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-lg);
            background: var(--neutral-50);
            transition: all 0.2s;
            height: 100%;
        }

        .info-box:hover {
            border-color: var(--primary-soft);
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .info-box .info-label {
            font-size: 0.75rem;
            color: var(--neutral-500);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .info-box .info-value {
            font-weight: 600;
            color: var(--neutral-800);
            font-size: 0.9375rem;
            word-break: break-word;
        }

        .info-box .info-value .text-muted {
            color: var(--neutral-500);
            font-weight: 400;
        }

        /* Stat Box */
        .stat-box {
            padding: 1.25rem;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-lg);
            background: var(--neutral-50);
            text-align: center;
            transition: all 0.2s;
            height: 100%;
        }

        .stat-box:hover {
            transform: translateY(-2px);
            border-color: var(--primary-soft);
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .stat-box .stat-label {
            font-size: 0.75rem;
            color: var(--neutral-500);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .stat-box .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--neutral-800);
        }

        .stat-box .stat-number.text-primary {
            color: var(--primary);
        }
        .stat-box .stat-number.text-success {
            color: var(--success);
        }
        .stat-box .stat-number.text-warning {
            color: var(--warning);
        }
        .stat-box .stat-number.text-info {
            color: var(--info);
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.813rem;
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

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.813rem;
        }

        /* Utility */
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

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
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
        .mt-1 {
            margin-top: 0.25rem;
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
        .text-warning {
            color: var(--warning);
        }
        .text-info {
            color: var(--info);
        }
        .text-dark {
            color: var(--neutral-800);
        }
        .small {
            font-size: 0.813rem;
        }
        .text-center {
            text-align: center;
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

        .container-fluid {
            padding: 0 2rem;
            width: 100%;
        }

        .py-4 {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }

        .rounded {
            border-radius: var(--radius-lg);
        }
        .border {
            border: 1px solid var(--neutral-200);
        }
        .p-2 {
            padding: 0.5rem;
        }
        .p-3 {
            padding: 0.75rem;
        }

        .img-fluid {
            max-width: 100%;
            height: auto;
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

            .org-logo {
                max-height: 150px;
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

            .card-body-custom {
                padding: 1.25rem;
            }

            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .info-box {
                padding: 0.75rem 1rem;
            }

            .stat-box {
                padding: 1rem;
            }

            .stat-box .stat-number {
                font-size: 1.5rem;
            }

            .org-logo {
                max-height: 120px;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 0.75rem;
            }

            .container-fluid {
                padding: 0 0.75rem;
            }

            .card-body-custom {
                padding: 1rem;
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

            .col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .info-box {
                padding: 0.625rem 0.875rem;
            }

            .info-box .info-value {
                font-size: 0.875rem;
            }

            .stat-box {
                padding: 0.75rem;
            }

            .stat-box .stat-number {
                font-size: 1.25rem;
            }

            .org-logo {
                max-height: 100px;
            }

            .card {
                border-radius: var(--radius-xl);
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

            .card-header-custom {
                background: #f5f5f5 !important;
            }

            .breadcrumb-container {
                display: none;
            }

            .info-box,
            .stat-box {
                border: 1px solid #ddd;
                background: white !important;
            }

            .org-logo {
                border: 1px solid #ddd;
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
                            <li class="breadcrumb-item"><a href="index.php">Organizations</a></li>
                            <li class="breadcrumb-item active" aria-current="page">View Organization</li>
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

                <!-- Header Actions -->
                <div class="page-head">
                    <div>
                        <h3><i class="fas fa-building me-2"></i><?= htmlspecialchars($organization['organization_name']) ?></h3>
                        <div class="text-muted">Organization ID: #<?= (int)$organization['id'] ?> &bull; Code: <?= htmlspecialchars($organization['organization_code'] ?? '') ?></div>
                    </div>
                    <div class="btn-group">
                        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
                        <a href="edit.php?id=<?= (int)$organization['id'] ?>" class="btn btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit</a>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="card">
                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Logo Column -->
                            <div class="col-lg-4 text-center">
                                <img src="<?= htmlspecialchars(get_organization_logo_path($organization['logo'] ?? '')) ?>" class="org-logo" alt="Organization logo">
                                <div class="mt-3">
                                    <h4 class="mb-1 text-dark"><?= htmlspecialchars($organization['organization_name']) ?></h4>
                                    <p class="text-muted mb-0">Code: <?= htmlspecialchars($organization['organization_code'] ?? '') ?></p>
                                    <div class="mt-2">
                                        <span class="status-badge <?= ((int)($organization['status'] ?? 0) === 1) ? 'active' : 'inactive' ?>">
                                            <i class="fas <?= ((int)($organization['status'] ?? 0) === 1) ? 'fa-check-circle' : 'fa-minus-circle' ?>"></i>
                                            <?= ((int)($organization['status'] ?? 0) === 1) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Details Column -->
                            <div class="col-lg-8">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-map-marker-alt me-1"></i>Address</div>
                                            <div class="info-value"><?= htmlspecialchars($organization['address'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-phone me-1"></i>Phone</div>
                                            <div class="info-value"><?= htmlspecialchars($organization['phone'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-envelope me-1"></i>Email</div>
                                            <div class="info-value"><?= htmlspecialchars($organization['email'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-globe me-1"></i>Website</div>
                                            <div class="info-value">
                                                <?php if (!empty($organization['website'])): ?>
                                                    <a href="<?= htmlspecialchars($organization['website']) ?>" target="_blank" class="text-primary text-decoration-none">
                                                        <?= htmlspecialchars($organization['website']) ?> <i class="fas fa-external-link-alt small"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-tag me-1"></i>Organization Type</div>
                                            <div class="info-value"><?= htmlspecialchars(ucfirst((string)($organization['organization_type'] ?? 'N/A'))) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-project-diagram me-1"></i>Project Type</div>
                                            <div class="info-value"><?= htmlspecialchars(ucfirst((string)($organization['project_type'] ?? 'N/A'))) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-calendar-plus me-1"></i>Created</div>
                                            <div class="info-value"><?= date('F j, Y g:i A', strtotime($organization['created_at'] ?? 'now')) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-box">
                                            <div class="info-label"><i class="fas fa-calendar-check me-1"></i>Last Updated</div>
                                            <div class="info-value"><?= date('F j, Y g:i A', strtotime($organization['updated_at'] ?? 'now')) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stats Row -->
                        <div class="row g-3 mt-2">
                            <div class="col-md-3 col-6">
                                <div class="stat-box">
                                    <div class="stat-label"><i class="fas fa-users me-1"></i>Total Users</div>
                                    <div class="stat-number text-primary"><?= $usersCount ?></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-box">
                                    <div class="stat-label"><i class="fas fa-id-card me-1"></i>Total Members</div>
                                    <div class="stat-number text-success"><?= $membersCount ?></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-box">
                                    <div class="stat-label"><i class="fas fa-paint-brush me-1"></i>Total Templates</div>
                                    <div class="stat-number text-warning"><?= $templatesCount ?></div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-box">
                                    <div class="stat-label"><i class="fas fa-credit-card me-1"></i>Generated Cards</div>
                                    <div class="stat-number text-info"><?= $generatedCardsCount ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
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
                window.location.href = 'edit.php?id=<?= (int)$organization['id'] ?>';
            }

            // Backspace to go back
            if (e.key === 'Backspace' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                window.location.href = 'index.php';
            }
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .info-box, .stat-box, .status-badge').forEach(el => {
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