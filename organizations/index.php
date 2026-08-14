<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

$page_title = 'Organizations';
require_admin_access($pdo);

// Handle AJAX requests for organization operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit();
    }
    
    $action = $_POST['ajax_action'];
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['organization_name'] ?? '');
        $code = trim($_POST['organization_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $projectType = strtolower(trim($_POST['project_type'] ?? 'corporate'));
        $status = isset($_POST['status']) ? 1 : 0;
        
        if ($name === '' || $code === '' || $email === '') {
            echo json_encode(['success' => false, 'message' => 'Organization name, code, and email are required.']);
            exit();
        }
        
        if (!organization_project_type_is_valid($projectType)) {
            echo json_encode(['success' => false, 'message' => 'Please select either Residence or Corporate as the organization category.']);
            exit();
        }
        
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare('SELECT id FROM organizations WHERE organization_code = ? OR email = ? LIMIT 1');
                $stmt->execute([$code, $email]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode(['success' => false, 'message' => 'An organization with the same code or email already exists.']);
                    exit();
                }
                
                $logoName = null;
                if (!empty($_FILES['logo']['name'])) {
                    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
                    if (!$upload['success']) {
                        echo json_encode(['success' => false, 'message' => $upload['message']]);
                        exit();
                    }
                    $logoName = $upload['file'];
                }
                
                $stmt = $pdo->prepare("INSERT INTO organizations (organization_name, organization_code, logo, address, phone, email, website, project_type, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $code, $logoName, $address, $phone, $email, $website, $projectType, $status, get_current_user_id($pdo), get_current_user_id($pdo)]);
                
                log_organization_activity($pdo, 'Created organization', 'organization', 'Created organization ' . $name, $pdo->lastInsertId());
                $response = ['success' => true, 'message' => 'Organization added successfully.', 'action' => 'add'];
            } else {
                // Edit
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Invalid organization ID.']);
                    exit();
                }
                
                $stmt = $pdo->prepare('SELECT id FROM organizations WHERE (organization_code = ? OR email = ?) AND id != ? LIMIT 1');
                $stmt->execute([$code, $email, $id]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode(['success' => false, 'message' => 'Another organization with the same code or email already exists.']);
                    exit();
                }
                
                $logoName = null;
                if (!empty($_FILES['logo']['name'])) {
                    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
                    if (!$upload['success']) {
                        echo json_encode(['success' => false, 'message' => $upload['message']]);
                        exit();
                    }
                    $logoName = $upload['file'];
                }
                
                if ($logoName !== null) {
                    $stmt = $pdo->prepare("UPDATE organizations SET organization_name = ?, organization_code = ?, logo = ?, address = ?, phone = ?, email = ?, website = ?, project_type = ?, status = ?, updated_by = ? WHERE id = ?");
                    $stmt->execute([$name, $code, $logoName, $address, $phone, $email, $website, $projectType, $status, get_current_user_id($pdo), $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE organizations SET organization_name = ?, organization_code = ?, address = ?, phone = ?, email = ?, website = ?, project_type = ?, status = ?, updated_by = ? WHERE id = ?");
                    $stmt->execute([$name, $code, $address, $phone, $email, $website, $projectType, $status, get_current_user_id($pdo), $id]);
                }
                
                log_organization_activity($pdo, 'Updated organization', 'organization', 'Updated organization ' . $name, $id);
                $response = ['success' => true, 'message' => 'Organization updated successfully.', 'action' => 'edit'];
            }
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid organization ID.']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare('UPDATE organizations SET deleted_at = NOW() WHERE id = ?');
            $stmt->execute([$id]);
            log_organization_activity($pdo, 'Deleted organization', 'organization', 'Deleted organization ID: ' . $id, $id);
            $response = ['success' => true, 'message' => 'Organization deleted successfully.'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid organization ID.']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare('SELECT status FROM organizations WHERE id = ?');
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            $newStatus = $current['status'] == 1 ? 0 : 1;
            
            $stmt = $pdo->prepare('UPDATE organizations SET status = ?, updated_by = ? WHERE id = ?');
            $stmt->execute([$newStatus, get_current_user_id($pdo), $id]);
            log_organization_activity($pdo, 'Toggled status', 'organization', 'Toggled status for organization ID: ' . $id, $id);
            $response = ['success' => true, 'message' => 'Status updated successfully.', 'new_status' => $newStatus];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    } elseif ($action === 'get_organization') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid organization ID.']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$id]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($org) {
                $response = ['success' => true, 'data' => $org];
            } else {
                $response = ['success' => false, 'message' => 'Organization not found.'];
            }
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    echo json_encode($response);
    exit();
}

// Get filters and pagination
$search = trim($_GET['search'] ?? '');
$projectType = trim($_GET['project_type'] ?? '');
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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_organization_status_badge($status) {
    if ($status == 1) {
        return '<span class="status-badge active"><i class="fas fa-check-circle"></i> Active</span>';
    }
    return '<span class="status-badge inactive"><i class="fas fa-minus-circle"></i> Inactive</span>';
}

function organizations_page_url(array $filters, int $pageNum = 1): string
{
    $params = $filters;
    $params['page'] = $pageNum > 1 ? $pageNum : null;
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

$filterState = [
    'search'       => $search,
    'project_type' => $projectType,
    'status'       => $statusFilter,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Organizations · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary:#0a1a2f;
            --primary-light:#1e3a5f;
            --success:#0e9f6e;
            --warning:#d97706;
            --danger:#dc2626;
            --info:#2563eb;
            --neutral-50:#f8fafc;
            --neutral-100:#f1f5f9;
            --neutral-200:#e2e8f0;
            --neutral-300:#cbd5e1;
            --neutral-500:#64748b;
            --neutral-700:#334155;
            --neutral-800:#1e293b;
            --shadow-sm:0 1px 3px rgba(0,0,0,.05);
            --shadow-md:0 4px 10px rgba(15,23,42,.07);
            --shadow-lg:0 12px 30px rgba(15,23,42,.10);
            --radius:12px;
        }

        * { box-sizing:border-box; }

        body {
            margin:0;
            background:var(--neutral-50);
            color:var(--neutral-800);
            font-family:'Inter',sans-serif;
        }

        .dashboard-wrapper { display:flex; min-height:100vh; }
        .main-content { flex:1; margin-left:280px; min-height:100vh; }
        .dashboard-content { max-width:1800px; margin:0 auto; padding:28px; }

        .breadcrumb-container { margin-bottom:20px; }
        .breadcrumb { margin:0; font-size:.86rem; }
        .breadcrumb a { text-decoration:none; color:var(--info); }

        .alert {
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 16px;
            border-radius:10px;
            margin-bottom:16px;
        }

        .stats-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(150px,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card {
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:18px;
            padding:18px;
            box-shadow:var(--shadow-sm);
            transition:.2s ease;
        }

        .stat-card.clickable { cursor:pointer; }
        .stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
        .stat-label { font-size:.7rem; color:var(--neutral-500); text-transform:uppercase; letter-spacing:.05em; }
        .stat-number { font-size:1.7rem; font-weight:700; }

        .layout-grid {
            display:grid;
            grid-template-columns:2.2fr 1fr;
            gap:18px;
            align-items:start;
        }

        .main-card {
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:18px;
            overflow:hidden;
            box-shadow:var(--shadow-md);
        }

        .card-header-custom {
            padding:20px;
            background:#fff;
            border-bottom:1px solid var(--neutral-200);
        }

        .card-body-custom { padding:20px; }
        .card-footer-custom {
            padding:16px 20px;
            background:var(--neutral-50);
            border-top:1px solid var(--neutral-200);
        }

        .quick-actions { gap:8px; flex-wrap:wrap; }

        .advanced-box {
            margin-top:18px;
            padding:18px;
            border:1px solid var(--neutral-200);
            border-radius:14px;
            background:var(--neutral-50);
        }

        .filter-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(150px,1fr));
            gap:12px;
        }

        .filter-item label {
            display:block;
            margin-bottom:5px;
            font-size:.72rem;
            font-weight:700;
            color:var(--neutral-500);
            text-transform:uppercase;
        }

        .filter-item .form-control,
        .filter-item .form-select {
            min-height:42px;
            border-radius:9px;
            font-size:.82rem;
        }

        .filter-actions {
            display:flex;
            justify-content:flex-end;
            gap:8px;
            margin-top:14px;
        }

        .table-wrap {
            width:100%;
            overflow:auto;
            border:1px solid var(--neutral-200);
            border-radius:12px;
        }

        .table {
            margin:0;
            min-width:600px;
            font-size:.8rem;
        }

        .table thead th {
            background:var(--neutral-50);
            color:var(--neutral-500);
            font-size:.68rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            white-space:nowrap;
            border-bottom:2px solid var(--neutral-200);
            padding:11px 9px;
            vertical-align:middle;
        }

        .table tbody td {
            padding:10px 9px;
            border-bottom:1px solid var(--neutral-100);
            vertical-align:middle;
        }

        .table tbody tr:hover td { background:#fbfdff; }

        .logo-thumb {
            width:42px;
            height:42px;
            object-fit:cover;
            border-radius:10px;
            border:1px solid var(--neutral-200);
            background:var(--neutral-100);
        }

        .org-name {
            font-weight:700;
            color:var(--neutral-800);
            white-space:nowrap;
        }

        .muted { color:var(--neutral-500); }
        .small-text { font-size:.72rem; }

        .status-badge {
            display:inline-flex;
            align-items:center;
            gap:4px;
            padding:4px 8px;
            border-radius:999px;
            font-size:.66rem;
            font-weight:700;
            white-space:nowrap;
            background:#eef2f7;
            color:#64748b;
        }

        .status-badge.active { background:#dcfce7; color:#15803d; }
        .status-badge.inactive { background:#f1f5f9; color:#64748b; }

        .empty-state { padding:60px 20px; text-align:center; }
        .empty-state i { font-size:3rem; color:#cbd5e1; margin-bottom:12px; }
        .empty-state p { color:#64748b; }

        .pagination-custom {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:5px;
            flex-wrap:wrap;
            list-style:none;
            padding:0;
            margin:0;
        }

        .pagination-custom a {
            display:block;
            padding:6px 10px;
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:8px;
            text-decoration:none;
            color:var(--neutral-700);
            font-size:.78rem;
        }

        .pagination-custom .active a {
            background:var(--primary);
            color:#fff;
            border-color:var(--primary);
        }

        .recent-list {
            display:flex;
            flex-direction:column;
            gap:0;
        }

        .recent-item {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:12px 0;
            border-bottom:1px solid var(--neutral-100);
        }

        .recent-item:last-child { border-bottom:0; }

        .recent-item .name {
            font-weight:700;
            font-size:.82rem;
            color:var(--neutral-800);
        }

        .recent-item .code {
            font-size:.7rem;
            color:var(--neutral-500);
        }

        .recent-item .date-chip {
            font-size:.68rem;
            padding:3px 8px;
            border-radius:999px;
            background:var(--neutral-100);
            color:var(--neutral-700);
            white-space:nowrap;
        }

        .btn-group-sm .btn { font-size:.75rem; padding:.3rem .5rem; }

        /* Modal Styles */
        .modal-content { 
            border:0; 
            border-radius:18px; 
            box-shadow:var(--shadow-lg);
            max-height: 90vh;
        }
        .modal-header { border-bottom:1px solid var(--neutral-200); }
        .modal-footer { border-top:1px solid var(--neutral-200); }
        
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .modal-form-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(220px,1fr));
            gap:16px;
        }
        
        .modal-form-item.full { grid-column:1 / -1; }
        
        .modal-form-item label {
            display:block;
            margin-bottom:6px;
            font-size:.78rem;
            font-weight:700;
            color:var(--neutral-700);
            text-transform:uppercase;
            letter-spacing:.03em;
        }
        
        .modal-form-item label .req { color:var(--danger); }
        
        .modal-form-item .form-control,
        .modal-form-item .form-select {
            min-height:42px;
            border-radius:9px;
            font-size:.86rem;
            border:1px solid var(--neutral-300);
        }
        
        .modal-form-item .form-control:focus,
        .modal-form-item .form-select:focus {
            outline:none;
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(10,26,47,.08);
        }
        
        .modal-form-item textarea.form-control {
            min-height:90px;
            resize:vertical;
        }
        
        .form-hint {
            font-size:.72rem;
            color:var(--neutral-500);
            margin-top:5px;
        }
        
        .form-check-row {
            display:flex;
            align-items:center;
            gap:8px;
            min-height:42px;
        }

        @media(max-width:1200px) {
            .layout-grid { grid-template-columns:1fr; }
            .filter-grid { grid-template-columns:repeat(3,minmax(150px,1fr)); }
        }

        @media(max-width:992px) {
            .main-content { margin-left:0; }
            .dashboard-content { padding:16px; }
            .stats-grid { grid-template-columns:repeat(2,1fr); }
            .filter-grid { grid-template-columns:repeat(2,minmax(150px,1fr)); }
            .modal-form-grid { grid-template-columns:1fr; }
        }

        @media(max-width:600px) {
            .stats-grid { grid-template-columns:1fr; }
            .filter-grid { grid-template-columns:1fr; }
            .filter-actions { flex-direction:column; }
            .filter-actions .btn { width:100%; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/../includes/header.php'; ?>

        <div class="dashboard-content">

            <div class="breadcrumb-container">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
                        </li>
                        <li class="breadcrumb-item active">Organizations</li>
                    </ol>
                </nav>
            </div>

            <div id="alert-container"></div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Organizations</div>
                    <div class="stat-number text-primary"><?= (int)$counts['total'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active</div>
                    <div class="stat-number text-success"><?= (int)$counts['active'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Inactive</div>
                    <div class="stat-number" style="color:var(--neutral-500);"><?= (int)$counts['inactive'] ?></div>
                </div>
                <div class="stat-card clickable" data-bs-toggle="modal" data-bs-target="#addOrganizationModal">
                    <div class="stat-label">Quick Action</div>
                    <div class="stat-number" style="font-size:1rem;color:var(--primary);">
                        <i class="fas fa-plus-circle me-1"></i>Add Organization
                    </div>
                </div>
            </div>

            <div class="layout-grid">

                <div class="main-card">

                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                            <div>
                                <h5 class="mb-1 fw-bold">
                                    <i class="fas fa-building text-primary me-2"></i>Organization Directory
                                </h5>
                                <div class="small muted">
                                    Manage organizations, status, and branding details.
                                </div>
                            </div>

                            <div class="quick-actions">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrganizationModal">
                                    <i class="fas fa-plus me-1"></i>Add Organization
                                </button>
                            </div>
                        </div>

                        <div class="advanced-box">
                            <form method="get" id="filterForm">
                                <div class="filter-grid">
                                    <div class="filter-item" style="grid-column:span 2;">
                                        <label>Search</label>
                                        <input type="text" name="search" class="form-control"
                                               value="<?= h($search) ?>"
                                               placeholder="Name, Code, Phone, Email">
                                    </div>

                                    <div class="filter-item">
                                        <label>Project Type</label>
                                        <select name="project_type" class="form-select">
                                            <option value="">All Projects</option>
                                            <option value="residence" <?= $projectType === 'residence' ? 'selected' : '' ?>>Residence</option>
                                            <option value="corporate" <?= $projectType === 'corporate' ? 'selected' : '' ?>>Corporate</option>
                                        </select>
                                    </div>

                                    <div class="filter-item">
                                        <label>Status</label>
                                        <select name="status" class="form-select">
                                            <option value="">All Status</option>
                                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="filter-actions">
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-rotate-left me-1"></i>Reset
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i>Apply Filters
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-wrap">
                            <table class="table" id="organizationsTable">
                                <thead>
                                    <tr>
                                        <th>Logo</th>
                                        <th>Name</th>
                                        <th>Code</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paginated)): ?>
                                        <tr>
                                            <td colspan="5">
                                                <div class="empty-state">
                                                    <i class="fas fa-building"></i>
                                                    <p>No organizations found.</p>
                                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addOrganizationModal">
                                                        <i class="fas fa-plus me-1"></i>Add Organization
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($paginated as $organization): ?>
                                            <tr data-id="<?= (int)$organization['id'] ?>">
                                                <td>
                                                    <img src="<?= h(get_organization_logo_path($organization['logo'] ?? '')) ?>"
                                                         class="logo-thumb" alt="Logo">
                                                </td>
                                                <td>
                                                    <div class="org-name"><?= h($organization['organization_name']) ?></div>
                                                    <div class="small-text muted"><?= h($organization['email'] ?? '') ?></div>
                                                </td>
                                                <td><?= h($organization['organization_code'] ?? '') ?></td>
                                                <td class="status-cell"><?= get_organization_status_badge((int)($organization['status'] ?? 0)) ?></td>
                                                <td style="text-align:right;">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="view.php?id=<?= (int)$organization['id'] ?>" class="btn btn-outline-primary" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button class="btn btn-outline-secondary edit-btn" title="Edit" data-id="<?= (int)$organization['id'] ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-outline-warning toggle-status-btn" title="Toggle Status" data-id="<?= (int)$organization['id'] ?>" data-status="<?= (int)$organization['status'] ?>">
                                                            <i class="fas fa-toggle-on"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger delete-btn" title="Delete" data-id="<?= (int)$organization['id'] ?>" data-name="<?= h($organization['organization_name']) ?>">
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

                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer-custom">
                            <ul class="pagination-custom">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="<?= $i === $page ? 'active' : '' ?>">
                                        <a href="<?= h(organizations_page_url($filterState, $i)) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="main-card">
                    <div class="card-header-custom">
                        <h5 class="mb-0 fw-bold">
                            <i class="fas fa-clock text-primary me-2"></i>Recently Added
                        </h5>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($recent)): ?>
                            <div class="small muted">No organizations added yet.</div>
                        <?php else: ?>
                            <div class="recent-list">
                                <?php foreach ($recent as $item): ?>
                                    <div class="recent-item">
                                        <div>
                                            <div class="name"><?= h($item['organization_name']) ?></div>
                                            <div class="code"><?= h($item['organization_code'] ?? '') ?></div>
                                        </div>
                                        <span class="date-chip"><?= h(date('M d', strtotime($item['created_at']))) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </main>
</div>

<!-- ============================================ -->
<!-- ADD ORGANIZATION MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="addOrganizationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-building text-primary me-2"></i>Add Organization
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addOrganizationForm" enctype="multipart/form-data" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                    <input type="hidden" name="ajax_action" value="add">
                    
                    <div class="modal-form-grid">
                        <div class="modal-form-item">
                            <label>Organization Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="organization_name" required placeholder="e.g. ABC International School">
                        </div>

                        <div class="modal-form-item">
                            <label>Organization Code <span class="req">*</span></label>
                            <input type="text" class="form-control" name="organization_code" required placeholder="e.g. ABC001">
                            <div class="form-hint">Unique identifier for the organization.</div>
                        </div>

                        <div class="modal-form-item">
                            <label>Logo</label>
                            <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png,.webp">
                            <div class="form-hint">Allowed: JPG, PNG, WEBP (Max 2MB)</div>
                        </div>

                        <div class="modal-form-item">
                            <label>Phone</label>
                            <input type="text" class="form-control" name="phone" placeholder="e.g. +1234567890">
                        </div>

                        <div class="modal-form-item">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" class="form-control" name="email" required placeholder="e.g. info@organization.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Website</label>
                            <input type="url" class="form-control" name="website" placeholder="e.g. https://www.organization.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Project Type</label>
                            <select class="form-select" name="project_type">
                                <option value="corporate">Corporate</option>
                                <option value="residence">Residence</option>
                            </select>
                        </div>

                        <div class="modal-form-item">
                            <label>Status</label>
                            <div class="form-check-row">
                                <input class="form-check-input" type="checkbox" name="status" id="addStatus" checked>
                                <label class="form-check-label" for="addStatus" style="text-transform:none;font-weight:500;">Active</label>
                            </div>
                            <div class="form-hint">Inactive organizations will not be available for selection.</div>
                        </div>

                        <div class="modal-form-item full">
                            <label>Address</label>
                            <textarea class="form-control" name="address" rows="3" placeholder="Enter full address..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Organization
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- EDIT ORGANIZATION MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="editOrganizationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-edit text-primary me-2"></i>Edit Organization
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editOrganizationForm" enctype="multipart/form-data" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                    <input type="hidden" name="ajax_action" value="edit">
                    <input type="hidden" name="id" id="editOrganizationId" value="">
                    
                    <div class="modal-form-grid">
                        <div class="modal-form-item">
                            <label>Organization Name <span class="req">*</span></label>
                            <input type="text" class="form-control" name="organization_name" id="editOrgName" required placeholder="e.g. ABC International School">
                        </div>

                        <div class="modal-form-item">
                            <label>Organization Code <span class="req">*</span></label>
                            <input type="text" class="form-control" name="organization_code" id="editOrgCode" required placeholder="e.g. ABC001">
                            <div class="form-hint">Unique identifier for the organization.</div>
                        </div>

                        <div class="modal-form-item">
                            <label>Logo</label>
                            <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png,.webp">
                            <div class="form-hint">Leave empty to keep current logo. Allowed: JPG, PNG, WEBP (Max 2MB)</div>
                            <div id="editCurrentLogo" class="mt-2"></div>
                        </div>

                        <div class="modal-form-item">
                            <label>Phone</label>
                            <input type="text" class="form-control" name="phone" id="editPhone" placeholder="e.g. +1234567890">
                        </div>

                        <div class="modal-form-item">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" class="form-control" name="email" id="editEmail" required placeholder="e.g. info@organization.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Website</label>
                            <input type="url" class="form-control" name="website" id="editWebsite" placeholder="e.g. https://www.organization.com">
                        </div>

                        <div class="modal-form-item">
                            <label>Project Type</label>
                            <select class="form-select" name="project_type" id="editProjectType">
                                <option value="corporate">Corporate</option>
                                <option value="residence">Residence</option>
                            </select>
                        </div>

                        <div class="modal-form-item">
                            <label>Status</label>
                            <div class="form-check-row">
                                <input class="form-check-input" type="checkbox" name="status" id="editStatus">
                                <label class="form-check-label" for="editStatus" style="text-transform:none;font-weight:500;">Active</label>
                            </div>
                            <div class="form-hint">Inactive organizations will not be available for selection.</div>
                        </div>

                        <div class="modal-form-item full">
                            <label>Address</label>
                            <textarea class="form-control" name="address" id="editAddress" rows="3" placeholder="Enter full address..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Organization
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- DELETE CONFIRMATION MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="deleteOrganizationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-trash-alt text-danger me-2"></i>Delete Organization
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" id="deleteCsrfToken" value="<?= h(generate_csrf_token()) ?>">
                <input type="hidden" name="id" id="deleteOrganizationId" value="">
                <p>Are you sure you want to delete <strong id="deleteOrganizationName" class="text-danger"></strong>?</p>
                <p class="text-muted small mb-0">This will soft-delete the organization and prevent it from appearing in active listings.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash-alt me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function() {
    'use strict';

    // Helper function to show alerts
    function showAlert(message, type = 'success') {
        const container = document.getElementById('alert-container');
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        container.appendChild(alert);
        
        // Auto dismiss after 5 seconds
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    }

    // Helper function to make AJAX requests
    function ajaxRequest(formData, callback) {
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (callback) callback(data);
        })
        .catch(error => {
            showAlert('An error occurred: ' + error.message, 'danger');
        });
    }

    // ============================================
    // ADD ORGANIZATION
    // ============================================
    const addForm = document.getElementById('addOrganizationForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            submitBtn.disabled = true;
            
            ajaxRequest(formData, function(data) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                if (data.success) {
                    showAlert(data.message, 'success');
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addOrganizationModal'));
                    modal.hide();
                    addForm.reset();
                    // Refresh page to show new data
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    }

    // ============================================
    // EDIT ORGANIZATION
    // ============================================
    const editModal = document.getElementById('editOrganizationModal');
    let editModalInstance = null;
    
    // Open edit modal with data
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            
            if (!editModalInstance) {
                editModalInstance = new bootstrap.Modal(editModal);
            }
            
            // Show loading state
            const form = document.getElementById('editOrganizationForm');
            form.querySelector('button[type="submit"]').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
            form.querySelector('button[type="submit"]').disabled = true;
            
            // Fetch organization data
            const formData = new FormData();
            formData.append('ajax_action', 'get_organization');
            formData.append('id', id);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            ajaxRequest(formData, function(data) {
                if (data.success) {
                    const org = data.data;
                    document.getElementById('editOrganizationId').value = org.id;
                    document.getElementById('editOrgName').value = org.organization_name;
                    document.getElementById('editOrgCode').value = org.organization_code;
                    document.getElementById('editPhone').value = org.phone || '';
                    document.getElementById('editEmail').value = org.email;
                    document.getElementById('editWebsite').value = org.website || '';
                    document.getElementById('editAddress').value = org.address || '';
                    document.getElementById('editProjectType').value = org.project_type || 'corporate';
                    document.getElementById('editStatus').checked = org.status == 1;
                    
                    // Show current logo
                    const logoContainer = document.getElementById('editCurrentLogo');
if (org.logo) {
    const logoPath = 'assets/uploads/logo/' + encodeURIComponent(org.logo);

    logoContainer.innerHTML = `
        <div class="d-flex align-items-center gap-2">
            <img src="${logoPath}?v=${Date.now()}"
                 class="logo-thumb"
                 alt="Current Logo"
                 style="width:60px;height:60px;object-fit:contain;background:#f8fafc;"
                 onerror="this.style.display='none';">
            <span class="small muted">Current logo</span>
        </div>
    `;
} else {
    logoContainer.innerHTML = '';
}
                    
                    form.querySelector('button[type="submit"]').innerHTML = '<i class="fas fa-save me-1"></i>Update Organization';
                    form.querySelector('button[type="submit"]').disabled = false;
                    
                    editModalInstance.show();
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    });
    
    // Submit edit form
    const editForm = document.getElementById('editOrganizationForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
            submitBtn.disabled = true;
            
            ajaxRequest(formData, function(data) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                if (data.success) {
                    showAlert(data.message, 'success');
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editOrganizationModal'));
                    modal.hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    }

    // ============================================
    // DELETE ORGANIZATION
    // ============================================
    const deleteModal = document.getElementById('deleteOrganizationModal');
    let deleteModalInstance = null;
    let deleteId = null;
    
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            
            deleteId = id;
            document.getElementById('deleteOrganizationId').value = id;
            document.getElementById('deleteOrganizationName').textContent = name;
            
            if (!deleteModalInstance) {
                deleteModalInstance = new bootstrap.Modal(deleteModal);
            }
            deleteModalInstance.show();
        });
    });
    
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        const id = document.getElementById('deleteOrganizationId').value;
        const token = document.getElementById('deleteCsrfToken').value;
        
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
        this.disabled = true;
        
        const formData = new FormData();
        formData.append('ajax_action', 'delete');
        formData.append('id', id);
        formData.append('csrf_token', token);
        
        ajaxRequest(formData, function(data) {
            document.getElementById('confirmDeleteBtn').innerHTML = '<i class="fas fa-trash-alt me-1"></i>Delete';
            document.getElementById('confirmDeleteBtn').disabled = false;
            
            if (data.success) {
                showAlert(data.message, 'success');
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteOrganizationModal'));
                modal.hide();
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showAlert(data.message, 'danger');
            }
        });
    });

    // ============================================
    // TOGGLE STATUS
    // ============================================
    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const token = document.querySelector('input[name="csrf_token"]').value;
            const row = this.closest('tr');
            
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_status');
            formData.append('id', id);
            formData.append('csrf_token', token);
            
            ajaxRequest(formData, function(data) {
                if (data.success) {
                    showAlert(data.message, 'success');
                    // Update status badge in the row
                    const statusCell = row.querySelector('.status-cell');
                    if (data.new_status == 1) {
                        statusCell.innerHTML = '<span class="status-badge active"><i class="fas fa-check-circle"></i> Active</span>';
                    } else {
                        statusCell.innerHTML = '<span class="status-badge inactive"><i class="fas fa-minus-circle"></i> Inactive</span>';
                    }
                    // Update stats
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });
    });

    // ============================================
    // FILTER AUTO-SUBMIT
    // ============================================
    document.querySelectorAll('select[name="project_type"], select[name="status"]').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            document.querySelector('input[name="search"]')?.focus();
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('addOrganizationModal'));
            modal.show();
        }
    });

    // ============================================
    // AUTO-GENERATE CODE FROM NAME (Add Modal)
    // ============================================
    document.querySelector('#addOrganizationModal input[name="organization_name"]')?.addEventListener('blur', function() {
        const codeField = document.querySelector('#addOrganizationModal input[name="organization_code"]');
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

    // ============================================
    // ESCAPE HTML HELPER
    // ============================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);

    // ============================================
    // RESET MODAL ON HIDE
    // ============================================
    document.getElementById('addOrganizationModal')?.addEventListener('hidden.bs.modal', function() {
        this.querySelector('form').reset();
        this.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    });

})();
</script>
</body>
</html>