<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Add Organization';
require_admin_access($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['organization_name'] ?? '');
        $code = trim($_POST['organization_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $organizationType = trim($_POST['organization_type'] ?? 'company');
        $projectType = strtolower(trim($_POST['project_type'] ?? 'corporate'));
        $status = isset($_POST['status']) ? 1 : 0;

        if ($name === '' || $code === '' || $email === '') {
            $error = 'Organization name, code, and email are required.';
        } elseif (!organization_project_type_is_valid($projectType)) {
            $error = 'Please select either Residence or Corporate as the organization category.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM organizations WHERE organization_code = ? OR email = ? LIMIT 1');
            $stmt->execute([$code, $email]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'An organization with the same code or email already exists.';
            } else {
                $logoName = null;
                if (!empty($_FILES['logo']['name'])) {
                    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
                    if (!$upload['success']) {
                        $error = $upload['message'];
                    } else {
                        $logoName = $upload['file'];
                    }
                }

                if ($error === '') {
                    $stmt = $pdo->prepare("INSERT INTO organizations (organization_name, organization_code, logo, address, phone, email, website, organization_type, project_type, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $code, $logoName, $address, $phone, $email, $website, $organizationType, $projectType, $status, get_current_user_id($pdo), get_current_user_id($pdo)]);

                    log_organization_activity($pdo, 'Created organization', 'organization', 'Created organization ' . $name, $pdo->lastInsertId());
                    $_SESSION['organization_message'] = 'Organization added successfully.';
                    header('Location: index.php');
                    exit();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Add Organization · ID Card Generator</title>

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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            padding: 1.25rem 1.5rem;
            border-bottom: none;
        }

        .card-header-custom h5 {
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header-custom h5 i {
            font-size: 1.25rem;
        }

        .card-body-custom {
            padding: 2rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--neutral-800);
            margin-bottom: 0.25rem;
        }

        .page-header .text-muted {
            color: var(--neutral-500);
            font-size: 0.9375rem;
        }

        /* Form */
        .form-label {
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }

        .form-label .text-danger {
            color: var(--danger);
        }

        .form-control,
        .form-select {
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

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .form-control.is-invalid {
            border-color: var(--danger);
        }

        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
        }

        .form-text {
            font-size: 0.813rem;
            color: var(--neutral-500);
            margin-top: 0.35rem;
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Form Check */
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-left: 0;
        }

        .form-check-input {
            width: 1.1rem;
            height: 1.1rem;
            margin: 0;
            cursor: pointer;
            border: 1.5px solid var(--neutral-300);
            border-radius: var(--radius-sm);
            appearance: none;
            transition: all 0.2s;
            position: relative;
            flex-shrink: 0;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-input:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .form-check-label {
            font-size: 0.9375rem;
            color: var(--neutral-700);
            cursor: pointer;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
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

        /* Utility */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 0.75rem;
        }

        .col-12 {
            flex: 0 0 100%;
            max-width: 100%;
            padding: 0 0.75rem;
        }

        .g-3 {
            margin: -0.75rem;
        }

        .g-3 > [class*="col-"] {
            padding: 0.75rem;
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
        .ms-2 {
            margin-left: 0.5rem;
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
        .text-danger {
            color: var(--danger);
        }
        .text-white {
            color: white;
        }
        .text-dark {
            color: var(--neutral-800);
        }
        .small {
            font-size: 0.813rem;
        }

        .border-0 {
            border: none;
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

        .container-fluid {
            padding: 0 2rem;
            width: 100%;
        }

        .py-4 {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }

        .mt-4 {
            margin-top: 1.5rem;
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

            .container-fluid {
                padding: 0 1rem;
            }

            .card-body-custom {
                padding: 1.25rem;
            }

            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .top-header {
                padding: 0.75rem 1rem;
            }

            .user-info {
                display: none;
            }

            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 1rem;
            }

            .d-flex.justify-content-between .btn {
                width: 100%;
                justify-content: center;
            }

            .form-control,
            .form-select {
                padding: 0.625rem 0.875rem;
                font-size: 0.875rem;
            }

            .page-header h4 {
                font-size: 1.1rem;
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

            .card-header-custom h5 {
                font-size: 1rem;
            }

            .btn {
                padding: 0.625rem 1rem;
                font-size: 0.875rem;
            }

            .mt-4.d-flex {
                flex-direction: column;
            }

            .mt-4.d-flex .btn {
                width: 100%;
                justify-content: center;
            }

            .form-check {
                padding-left: 0;
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

            .card-header-custom {
                background: #f5f5f5 !important;
                color: #000 !important;
            }

            .card-header-custom h5 {
                color: #000 !important;
            }

            .breadcrumb-container {
                display: none;
            }

            .form-control,
            .form-select {
                border: 1px solid #ddd;
                background: white !important;
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
                            <li class="breadcrumb-item active" aria-current="page">Add Organization</li>
                        </ol>
                    </nav>
                </div>

                <!-- Alert Errors -->
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Main Card -->
                <div class="card">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-building"></i> Add Organization</h5>
                    </div>
                    <div class="card-body-custom">
                        <div class="page-header">
                            <h4>Create New Organization</h4>
                            <p class="text-muted">Create a new organization profile with branding and contact details.</p>
                        </div>

                        <form method="post" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Organization Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="organization_name" required value="<?= htmlspecialchars($_POST['organization_name'] ?? '') ?>" placeholder="e.g. ABC International School">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Organization Code <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="organization_code" required value="<?= htmlspecialchars($_POST['organization_code'] ?? '') ?>" placeholder="e.g. ABC001">
                                    <div class="form-text">Unique identifier for the organization.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Logo</label>
                                    <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png,.webp">
                                    <div class="form-text">Allowed: JPG, PNG, WEBP (Max 2MB)</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="e.g. +1234567890">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="e.g. info@organization.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Website</label>
                                    <input type="url" class="form-control" name="website" value="<?= htmlspecialchars($_POST['website'] ?? '') ?>" placeholder="e.g. https://www.organization.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Organization Type</label>
                                    <select class="form-select" name="organization_type">
                                        <option value="company" <?= (($_POST['organization_type'] ?? 'company') === 'company') ? 'selected' : '' ?>>Company</option>
                                        <option value="school" <?= (($_POST['organization_type'] ?? '') === 'school') ? 'selected' : '' ?>>School</option>
                                        <option value="college" <?= (($_POST['organization_type'] ?? '') === 'college') ? 'selected' : '' ?>>College</option>
                                        <option value="government" <?= (($_POST['organization_type'] ?? '') === 'government') ? 'selected' : '' ?>>Government</option>
                                        <option value="hospital" <?= (($_POST['organization_type'] ?? '') === 'hospital') ? 'selected' : '' ?>>Hospital</option>
                                        <option value="ngo" <?= (($_POST['organization_type'] ?? '') === 'ngo') ? 'selected' : '' ?>>NGO</option>
                                        <option value="other" <?= (($_POST['organization_type'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Project Type</label>
                                    <select class="form-select" name="project_type">
                                        <option value="residence" <?= (($_POST['project_type'] ?? 'corporate') === 'residence') ? 'selected' : '' ?>>Residence</option>
                                        <option value="corporate" <?= (($_POST['project_type'] ?? 'corporate') === 'corporate') ? 'selected' : '' ?>>Corporate</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="3" placeholder="Enter full address..."><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                        <label class="form-check-label" for="status">Active</label>
                                    </div>
                                    <div class="form-text">Inactive organizations will not be available for selection.</div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex flex-column flex-md-row gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Organization
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
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

            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('form')?.submit();
            }

            // Ctrl/Cmd + Backspace to go back
            if ((e.ctrlKey || e.metaKey) && e.key === 'Backspace') {
                e.preventDefault();
                window.location.href = 'index.php';
            }
        });

        // Form validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const name = document.querySelector('input[name="organization_name"]');
            const code = document.querySelector('input[name="organization_code"]');
            const email = document.querySelector('input[name="email"]');

            if (!name.value.trim()) {
                e.preventDefault();
                name.classList.add('is-invalid');
                alert('Please enter the organization name.');
                name.focus();
                return false;
            }

            if (!code.value.trim()) {
                e.preventDefault();
                code.classList.add('is-invalid');
                alert('Please enter the organization code.');
                code.focus();
                return false;
            }

            if (!email.value.trim()) {
                e.preventDefault();
                email.classList.add('is-invalid');
                alert('Please enter the organization email.');
                email.focus();
                return false;
            }

            return true;
        });

        // Remove invalid class on input
        document.querySelectorAll('.form-control, .form-select').forEach(el => {
            el.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
            el.addEventListener('change', function() {
                if (this.value) {
                    this.classList.remove('is-invalid');
                }
            });
        });

        // Touch-friendly improvements
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control, .form-select').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Auto-focus on first field
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="organization_name"]')?.focus();
        });

        // Auto-generate code from name (optional)
        document.querySelector('input[name="organization_name"]')?.addEventListener('blur', function() {
            const codeField = document.querySelector('input[name="organization_code"]');
            if (!codeField.value.trim() && this.value.trim()) {
                const words = this.value.trim().toUpperCase().split(' ');
                let code = '';
                for (let i = 0; i < Math.min(words.length, 3); i++) {
                    code += words[i].charAt(0);
                }
                code += Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                codeField.value = code;
            }
        });
    </script>
</body>
</html>
