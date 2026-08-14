<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

$page_title = 'Bulk Print';

require_login();
$authUser = get_auth_user($pdo);

if (!$authUser) {
    $_SESSION['member_error'] = 'Authentication required.';
    header('Location: ../members/view_members.php');
    exit();
}

require_permission($pdo, 'Members', 'Print');

$isSuperAdmin  = auth_is_super_admin($authUser);
$userOrgId     = (int) ($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Member types (was missing before -> undefined variable warning)
|--------------------------------------------------------------------------
*/
$memberTypes = ['student', 'employee', 'staff', 'faculty', 'visitor', 'office'];

/*
|--------------------------------------------------------------------------
| Pre-selected IDs (e.g. coming from bulk_upload_members.php "print" action)
|--------------------------------------------------------------------------
*/
$preselectedIds = [];
if (!empty($_GET['ids'])) {
    $preselectedIds = array_values(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))));
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
| Bulk Print uses only fields that exist in the member-entry workflow.
| Template-specific fields are exposed only when their template is selected.
|--------------------------------------------------------------------------
*/
$search       = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$orgFilter    = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$templateFilter = isset($_GET['template_id']) ? (int) $_GET['template_id'] : 0;

/*
| Standard member fields supported by Add Member / Bulk Upload.
| Custom template fields are intentionally not guessed here because their
| storage schema is outside the supplied files.
*/
$filterFieldDefinitions = [
    'name' => [
        'label' => 'Name',
        'icon'  => 'fa-user',
        'type'  => 'text',
    ],
    'unique_id' => [
        'label' => 'Unique ID',
        'icon'  => 'fa-id-card',
        'type'  => 'text',
    ],
    'email' => [
        'label' => 'Email',
        'icon'  => 'fa-envelope',
        'type'  => 'text',
    ],
    'emergency_contact' => [
        'label' => 'Emergency Contact',
        'icon'  => 'fa-phone',
        'type'  => 'text',
    ],
    'dob' => [
        'label' => 'Date of Birth',
        'icon'  => 'fa-cake-candles',
        'type'  => 'date_range',
    ],
    'guardian_name' => [
        'label' => 'Guardian Name',
        'icon'  => 'fa-user-shield',
        'type'  => 'text',
    ],
    'class' => [
        'label' => 'Class / Grade',
        'icon'  => 'fa-school',
        'type'  => 'text',
    ],
    'department' => [
        'label' => 'Department',
        'icon'  => 'fa-building',
        'type'  => 'text',
    ],
    'designation' => [
        'label' => 'Designation',
        'icon'  => 'fa-briefcase',
        'type'  => 'text',
    ],
    'company' => [
        'label' => 'Company',
        'icon'  => 'fa-building-circle-check',
        'type'  => 'text',
    ],
    'purpose' => [
        'label' => 'Purpose',
        'icon'  => 'fa-bullseye',
        'type'  => 'text',
    ],
    'address' => [
        'label' => 'Address',
        'icon'  => 'fa-location-dot',
        'type'  => 'text',
    ],
    'joined_date' => [
        'label' => 'Joined Date',
        'icon'  => 'fa-calendar-plus',
        'type'  => 'date_range',
    ],
    'expiry_date' => [
        'label' => 'Expiry Date',
        'icon'  => 'fa-calendar-xmark',
        'type'  => 'date_range',
    ],
    'photo' => [
        'label' => 'Member Photo',
        'icon'  => 'fa-image',
        'type'  => 'exists',
    ],
    'signature' => [
        'label' => 'Signature',
        'icon'  => 'fa-signature',
        'type'  => 'exists',
    ],
];

$filterValues = [];
foreach (array_keys($filterFieldDefinitions) as $fieldKey) {
    $filterValues[$fieldKey] = trim((string) ($_GET[$fieldKey] ?? ''));
    $filterValues[$fieldKey . '_from'] = trim((string) ($_GET[$fieldKey . '_from'] ?? ''));
    $filterValues[$fieldKey . '_to'] = trim((string) ($_GET[$fieldKey . '_to'] ?? ''));
}

$filterFieldDefinitions['name']['value'] = $filterValues['name'];
$filterFieldDefinitions['unique_id']['value'] = $filterValues['unique_id'];
$filterFieldDefinitions['email']['value'] = $filterValues['email'];
$filterFieldDefinitions['emergency_contact']['value'] = $filterValues['emergency_contact'];
$filterFieldDefinitions['guardian_name']['value'] = $filterValues['guardian_name'];
$filterFieldDefinitions['class']['value'] = $filterValues['class'];
$filterFieldDefinitions['department']['value'] = $filterValues['department'];
$filterFieldDefinitions['designation']['value'] = $filterValues['designation'];
$filterFieldDefinitions['company']['value'] = $filterValues['company'];
$filterFieldDefinitions['purpose']['value'] = $filterValues['purpose'];
$filterFieldDefinitions['address']['value'] = $filterValues['address'];

$standardFieldKeys = array_keys($filterFieldDefinitions);

/*
|--------------------------------------------------------------------------
| Organizations + Templates
|--------------------------------------------------------------------------
*/
$organizations = [];
if ($isSuperAdmin) {
    $organizations = $pdo->query(
        "SELECT id, organization_name, project_type
         FROM organizations
         WHERE deleted_at IS NULL AND status = 1
         ORDER BY organization_name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

$templates = [];
$templateSql = "SELECT id, name, orientation, is_default, organization_id
                FROM card_templates
                WHERE status = 1 AND deleted_at IS NULL";
$templateParams = [];

if (!$isSuperAdmin && $userOrgId > 0) {
    $templateSql .= " AND organization_id = ?";
    $templateParams[] = $userOrgId;
} elseif ($isSuperAdmin && $orgFilter > 0) {
    $templateSql .= " AND organization_id = ?";
    $templateParams[] = $orgFilter;
}

$templateSql .= " ORDER BY is_default DESC, name";
$templateStmt = $pdo->prepare($templateSql);
$templateStmt->execute($templateParams);
$templates = $templateStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedTemplate = null;
$activeTemplateKeys = [];

if ($templateFilter > 0) {
    foreach ($templates as $tpl) {
        if ((int) $tpl['id'] === $templateFilter) {
            $selectedTemplate = $tpl;
            break;
        }
    }

    if ($selectedTemplate) {
        require_once __DIR__ . '/../template/template_mgmt_helpers.php';
        $activeTemplateKeys = array_map(
            static fn($key) => strtolower(trim((string) $key)),
            template_get_active_field_keys($pdo, $templateFilter)
        );
    } else {
        $templateFilter = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Decide which actual member fields to show in Advanced Filter.
| Core fields are always available; optional fields follow the selected
| template exactly like Add Member / Bulk Upload.
|--------------------------------------------------------------------------
*/
$visibleAdvancedFields = ['name', 'unique_id'];

if ($templateFilter > 0) {
    foreach ($standardFieldKeys as $fieldKey) {
        if (in_array($fieldKey, $activeTemplateKeys, true)) {
            $visibleAdvancedFields[] = $fieldKey;
        }
    }
}

$visibleAdvancedFields = array_values(array_unique($visibleAdvancedFields));

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/
$where  = ['m.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(m.name LIKE ? OR m.unique_id LIKE ? OR m.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$today = date('Y-m-d');

if ($statusFilter === 'active') {
    $where[] = '(m.expiry_date IS NULL OR m.expiry_date >= ?)';
    $params[] = $today;
} elseif ($statusFilter === 'expiring') {
    $where[] = 'm.expiry_date BETWEEN ? AND ?';
    $params[] = $today;
    $params[] = date('Y-m-d', strtotime('+30 days'));
} elseif ($statusFilter === 'expired') {
    $where[] = 'm.expiry_date IS NOT NULL AND m.expiry_date < ?';
    $params[] = $today;
}

if (!$isSuperAdmin && $userOrgId > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $userOrgId;
} elseif ($isSuperAdmin && $orgFilter > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $orgFilter;
}

if ($templateFilter > 0) {
    $where[] = 'm.template_id = ?';
    $params[] = $templateFilter;
}

/*
|--------------------------------------------------------------------------
| Advanced field conditions
|--------------------------------------------------------------------------
*/
$advancedTextFields = [
    'name', 'unique_id', 'email', 'emergency_contact', 'guardian_name',
    'class', 'department', 'designation', 'company', 'purpose', 'address'
];

foreach ($advancedTextFields as $fieldKey) {
    if (!in_array($fieldKey, $visibleAdvancedFields, true)) {
        continue;
    }

    $value = trim((string) ($filterValues[$fieldKey] ?? ''));
    if ($value !== '') {
        $where[] = "m.`{$fieldKey}` LIKE ?";
        $params[] = '%' . $value . '%';
    }
}

foreach (['dob', 'joined_date', 'expiry_date'] as $fieldKey) {
    if (!in_array($fieldKey, $visibleAdvancedFields, true)) {
        continue;
    }

    $from = $filterValues[$fieldKey . '_from'] ?? '';
    $to   = $filterValues[$fieldKey . '_to'] ?? '';

    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = "m.`{$fieldKey}` IS NOT NULL AND m.`{$fieldKey}` >= ?";
        $params[] = $from;
    }

    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = "m.`{$fieldKey}` IS NOT NULL AND m.`{$fieldKey}` <= ?";
        $params[] = $to;
    }
}

foreach (['photo', 'signature'] as $fieldKey) {
    if (!in_array($fieldKey, $visibleAdvancedFields, true)) {
        continue;
    }

    $existsValue = trim((string) ($filterValues[$fieldKey] ?? ''));
    if ($existsValue === 'yes') {
        $where[] = "m.`{$fieldKey}` IS NOT NULL AND m.`{$fieldKey}` <> ''";
    } elseif ($existsValue === 'no') {
        $where[] = "(m.`{$fieldKey}` IS NULL OR m.`{$fieldKey}` = '')";
    }
}

$sql = "SELECT m.id, m.name, m.unique_id, m.member_type, m.expiry_date,
               m.organization_id, m.template_id,
               o.organization_name, o.project_type
        FROM id_members m
        LEFT JOIN organizations o ON m.organization_id = o.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.organization_name, m.name
        LIMIT 2000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Group members by organization (client requirement: bulk print must
| keep organizations separated)
|--------------------------------------------------------------------------
*/
$groupedMembers = [];
foreach ($members as $m) {
    $orgKey = (int) ($m['organization_id'] ?? 0);
    if (!isset($groupedMembers[$orgKey])) {
        $groupedMembers[$orgKey] = [
            'organization_name' => $m['organization_name'] ?? 'Unassigned',
            'project_type'      => $m['project_type'] ?? null,
            'members'           => [],
        ];
    }
    $groupedMembers[$orgKey]['members'][] = $m;
}

$advancedFilterActive = ($templateFilter > 0);

foreach ($visibleAdvancedFields as $fieldKey) {
    if (($filterValues[$fieldKey] ?? '') !== '' ||
        ($filterValues[$fieldKey . '_from'] ?? '') !== '' ||
        ($filterValues[$fieldKey . '_to'] ?? '') !== '') {
        $advancedFilterActive = true;
        break;
    }
}

$totalMembers = count($members);
$totalOrgs    = count($groupedMembers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Bulk Print · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
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
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            margin: 0;
            padding: 0;
        }

        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 2rem; max-width: 1700px; margin: 0 auto; }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 1rem; }
        }

        .breadcrumb {
            display: flex;
            gap: 0.5rem;
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
            font-size: 0.875rem;
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        .main-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
        }

        .card-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--neutral-50);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .card-header-custom h5 {
            font-weight: 600;
            margin: 0;
            color: var(--neutral-800);
        }
        .card-header-custom h5 i { color: var(--primary); margin-right: 0.5rem; }

        .card-body-custom { padding: 1.5rem; }
        .card-footer-custom {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            font-size: 0.875rem;
            background: #fff;
            min-width: 150px;
        }
        .filter-bar .btn {
            padding: 0.5rem 0.8rem;
            font-size: 0.82rem;
        }

        .advanced-filter-toggle {
            background: #fff;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-700);
            font-weight: 600;
        }
        .advanced-filter-toggle:hover {
            background: var(--neutral-100);
            border-color: var(--neutral-400);
        }

        .advanced-filter-panel {
            display: none;
            margin-top: 0.9rem;
            padding: 1rem;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-xl);
            background: linear-gradient(180deg, #fbfcfe 0%, #f7f9fc 100%);
        }
        .advanced-filter-panel.show { display: block; }

        .advanced-filter-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.9rem;
            flex-wrap: wrap;
        }

        .advanced-filter-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            font-weight: 700;
            color: var(--neutral-800);
        }
        .advanced-filter-title i { color: var(--primary); }

        .advanced-filter-subtitle {
            color: var(--neutral-500);
            font-size: 0.75rem;
            margin-top: 0.15rem;
        }

        .advanced-filter-section {
            padding: 0.9rem;
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-lg);
        }
        .advanced-filter-section + .advanced-filter-section {
            margin-top: 0.75rem;
        }

        .advanced-section-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            margin-bottom: 0.75rem;
        }

        .advanced-field {
            position: relative;
        }
        .advanced-field .field-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--neutral-400);
            pointer-events: none;
            font-size: 0.78rem;
        }
        .advanced-field .form-control {
            padding-left: 2.15rem;
        }

        .advanced-field-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--neutral-600);
            margin-bottom: 0.3rem;
        }

        .date-range-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .advanced-filter-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 0.85rem;
            flex-wrap: wrap;
        }

        .active-filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: var(--info-soft);
            color: #1d4ed8;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .filter-empty-note {
            padding: 0.75rem 0.85rem;
            border-radius: var(--radius-lg);
            background: var(--neutral-100);
            color: var(--neutral-500);
            font-size: 0.78rem;
        }

        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { min-width: 100%; }
            .advanced-filter-head { align-items: flex-start; flex-direction: column; }
            .advanced-filter-actions { flex-direction: column; align-items: stretch; }
            .date-range-grid { grid-template-columns: 1fr; }
        }

        .org-group {
            margin-bottom: 1.25rem;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .org-group-header {
            background: var(--primary-soft);
            padding: 0.6rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--primary);
            border-bottom: 1px solid var(--neutral-200);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .org-group-header .badge {
            background: var(--primary);
            color: #fff;
            font-weight: 500;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }

        .table { font-size: 0.875rem; margin-bottom: 0; }
        .table thead th {
            font-size: 0.688rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            background: var(--neutral-100);
            font-weight: 600;
            padding: 0.6rem 0.75rem;
        }
        .table tbody td { vertical-align: middle; padding: 0.5rem 0.75rem; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.688rem;
            font-weight: 500;
        }
        .status-badge.active { background: var(--success-soft); color: var(--success); }
        .status-badge.expiring { background: var(--warning-soft); color: var(--warning); }
        .status-badge.expired { background: var(--danger-soft); color: var(--danger); }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0d8b5e; color: white; }
        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }
        .btn-outline-secondary:hover {
            background: var(--neutral-100);
        }
        .btn-sm { padding: 0.3rem 0.8rem; font-size: 0.813rem; }

        .selected-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .info-note {
            background: var(--info-soft);
            color: #1d4ed8;
            border-radius: var(--radius-lg);
            padding: 0.6rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .form-control, .form-select {
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            width: 100%;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10,26,47,0.1);
            outline: none;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-top: 0.25rem;
        }

        .form-check-input {
            width: 1.1rem;
            height: 1.1rem;
            margin-top: 0.15rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--neutral-300);
            cursor: pointer;
        }
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(10,26,47,0.1);
        }

        .d-flex-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        @media (max-width: 768px) {
            .card-header-custom { padding: 1rem; }
            .card-body-custom { padding: 1rem; }
            .card-footer-custom { padding: 0.75rem 1rem; }
            .org-group-header { flex-direction: column; text-align: center; }
            .table-responsive { font-size: 0.75rem; }
            .selected-count { font-size: 0.75rem; padding: 0.2rem 0.6rem; }
        }

        @media (max-width: 480px) {
            .dashboard-content { padding: 0.5rem; }
            .d-flex-wrap { flex-direction: column; align-items: stretch; }
            .d-flex-wrap .btn { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../includes/header.php'; ?>

            <div class="dashboard-content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="../members/view_members.php">Members</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Bulk Print</li>
                    </ol>
                </nav>

                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h5><i class="fas fa-print text-primary me-2"></i>Bulk Print ID Cards</h5>
                                <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                                    Select members to print ID cards in bulk. Organizations are always printed separately.
                                </p>
                            </div>
                            <div>
                                <span class="selected-count" id="selectedCount">0 selected</span>
                            </div>
                        </div>

                        <form method="GET" id="memberFilterForm">
                            <div class="filter-bar">
                                <input type="text" name="search" class="form-control"
                                       placeholder="Search name / ID / email..."
                                       value="<?= htmlspecialchars($search) ?>"
                                       style="min-width:200px;">

                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="expiring" <?= $statusFilter === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
                                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                                </select>

                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>

                                <button type="button" class="btn advanced-filter-toggle btn-sm"
                                        onclick="toggleAdvancedFilters()">
                                    <i class="fas fa-sliders-h me-1"></i>Advanced Filter
                                    <?php if ($advancedFilterActive): ?>
                                        <span class="active-filter-badge">Active</span>
                                    <?php endif; ?>
                                </button>

                                <a href="bulk_print.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-redo me-1"></i>Reset
                                </a>
                            </div>

                            <div class="advanced-filter-panel <?= $advancedFilterActive ? 'show' : '' ?>"
                                 id="advancedFilterPanel">
                                <div class="advanced-filter-head">
                                    <div>
                                        <div class="advanced-filter-title">
                                            <i class="fas fa-sliders-h"></i>
                                            Advanced Filter
                                        </div>
                                        <div class="advanced-filter-subtitle">
                                            Only fields available in the selected member template are shown.
                                        </div>
                                    </div>
                                    <?php if ($selectedTemplate): ?>
                                        <span class="active-filter-badge">
                                            <i class="fas fa-layer-group"></i>
                                            <?= htmlspecialchars($selectedTemplate['name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="advanced-filter-section">
                                    <div class="advanced-section-label">
                                        <i class="fas fa-layer-group"></i> Template
                                    </div>
                                    <div class="row g-3">
                                        <?php if ($isSuperAdmin): ?>
                                            <div class="col-md-6">
                                                <label class="advanced-field-label">Organization</label>
                                                <select name="org_id" class="form-select" id="advancedOrgSelect">
                                                    <option value="0">All Organizations</option>
                                                    <?php foreach ($organizations as $org): ?>
                                                        <option value="<?= (int) $org['id'] ?>"
                                                            <?= $orgFilter === (int) $org['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($org['organization_name']) ?>
                                                            <?php if (!empty($org['project_type'])): ?>
                                                                (<?= htmlspecialchars(ucfirst($org['project_type'])) ?>)
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>

                                        <div class="<?= $isSuperAdmin ? 'col-md-6' : 'col-md-12' ?>">
                                            <label class="advanced-field-label">Template</label>
                                            <select name="template_id" class="form-select" id="advancedTemplateSelect">
                                                <option value="0">All Templates</option>
                                                <?php foreach ($templates as $tpl): ?>
                                                    <option value="<?= (int) $tpl['id'] ?>"
                                                        <?= $templateFilter === (int) $tpl['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($tpl['name']) ?>
                                                        (<?= htmlspecialchars(ucfirst($tpl['orientation'])) ?>)
                                                        <?php if (!empty($tpl['is_default'])): ?> ⭐<?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="advanced-filter-section">
                                    <div class="advanced-section-label">
                                        <i class="fas fa-user"></i> Member Details
                                    </div>

                                    <div class="row g-3">
                                        <?php if ($templateFilter === 0): ?>
                                            <div class="col-12">
                                                <div class="filter-empty-note">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Select a template above to show its available member fields.
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php if (in_array('name', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Name</label>
                                                        <i class="fas fa-user field-icon"></i>
                                                        <input type="text" name="name" class="form-control"
                                                               placeholder="Filter by name..."
                                                               value="<?= htmlspecialchars($filterValues['name']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('unique_id', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Unique ID</label>
                                                        <i class="fas fa-id-card field-icon"></i>
                                                        <input type="text" name="unique_id" class="form-control"
                                                               placeholder="Filter by unique id..."
                                                               value="<?= htmlspecialchars($filterValues['unique_id']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('email', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Email</label>
                                                        <i class="fas fa-envelope field-icon"></i>
                                                        <input type="text" name="email" class="form-control"
                                                               placeholder="Filter by email..."
                                                               value="<?= htmlspecialchars($filterValues['email']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('emergency_contact', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Emergency Contact</label>
                                                        <i class="fas fa-phone field-icon"></i>
                                                        <input type="text" name="emergency_contact" class="form-control"
                                                               placeholder="Filter by emergency contact..."
                                                               value="<?= htmlspecialchars($filterValues['emergency_contact']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('guardian_name', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Guardian Name</label>
                                                        <i class="fas fa-user-shield field-icon"></i>
                                                        <input type="text" name="guardian_name" class="form-control"
                                                               placeholder="Filter by guardian name..."
                                                               value="<?= htmlspecialchars($filterValues['guardian_name']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('class', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Class / Grade</label>
                                                        <i class="fas fa-school field-icon"></i>
                                                        <input type="text" name="class" class="form-control"
                                                               placeholder="Filter by class / grade..."
                                                               value="<?= htmlspecialchars($filterValues['class']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('department', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Department</label>
                                                        <i class="fas fa-building field-icon"></i>
                                                        <input type="text" name="department" class="form-control"
                                                               placeholder="Filter by department..."
                                                               value="<?= htmlspecialchars($filterValues['department']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('designation', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Designation</label>
                                                        <i class="fas fa-briefcase field-icon"></i>
                                                        <input type="text" name="designation" class="form-control"
                                                               placeholder="Filter by designation..."
                                                               value="<?= htmlspecialchars($filterValues['designation']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('company', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Company</label>
                                                        <i class="fas fa-building-circle-check field-icon"></i>
                                                        <input type="text" name="company" class="form-control"
                                                               placeholder="Filter by company..."
                                                               value="<?= htmlspecialchars($filterValues['company']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('purpose', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Purpose</label>
                                                        <i class="fas fa-bullseye field-icon"></i>
                                                        <input type="text" name="purpose" class="form-control"
                                                               placeholder="Filter by purpose..."
                                                               value="<?= htmlspecialchars($filterValues['purpose']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (in_array('address', $visibleAdvancedFields, true)): ?>
                                                <div class="col-md-4">
                                                    <div class="advanced-field">
                                                        <label class="advanced-field-label">Address</label>
                                                        <i class="fas fa-location-dot field-icon"></i>
                                                        <input type="text" name="address" class="form-control"
                                                               placeholder="Filter by address..."
                                                               value="<?= htmlspecialchars($filterValues['address']) ?>">
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="advanced-filter-section">
                                    <div class="advanced-section-label">
                                        <i class="fas fa-calendar-days"></i> Date & Media
                                    </div>
                                    <div class="row g-3">
                                        <?php if (in_array('dob', $visibleAdvancedFields, true)): ?>
                                            <div class="col-md-4">
                                                <label class="advanced-field-label">Date of Birth</label>
                                                <div class="date-range-grid">
                                                    <input type="date" name="dob_from" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['dob_from']) ?>"
                                                           aria-label="Date of Birth from">
                                                    <input type="date" name="dob_to" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['dob_to']) ?>"
                                                           aria-label="Date of Birth to">
                                                </div>
                                                <div class="form-text">From / To</div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array('joined_date', $visibleAdvancedFields, true)): ?>
                                            <div class="col-md-4">
                                                <label class="advanced-field-label">Joined Date</label>
                                                <div class="date-range-grid">
                                                    <input type="date" name="joined_date_from" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['joined_date_from']) ?>"
                                                           aria-label="Joined Date from">
                                                    <input type="date" name="joined_date_to" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['joined_date_to']) ?>"
                                                           aria-label="Joined Date to">
                                                </div>
                                                <div class="form-text">From / To</div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array('expiry_date', $visibleAdvancedFields, true)): ?>
                                            <div class="col-md-4">
                                                <label class="advanced-field-label">Expiry Date</label>
                                                <div class="date-range-grid">
                                                    <input type="date" name="expiry_date_from" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['expiry_date_from']) ?>"
                                                           aria-label="Expiry Date from">
                                                    <input type="date" name="expiry_date_to" class="form-control"
                                                           value="<?= htmlspecialchars($filterValues['expiry_date_to']) ?>"
                                                           aria-label="Expiry Date to">
                                                </div>
                                                <div class="form-text">From / To</div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array('photo', $visibleAdvancedFields, true)): ?>
                                            <div class="col-md-4">
                                                <label class="advanced-field-label">Member Photo</label>
                                                <select name="photo" class="form-select">
                                                    <option value="">Any</option>
                                                    <option value="yes" <?= $filterValues['photo'] === 'yes' ? 'selected' : '' ?>>Available</option>
                                                    <option value="no" <?= $filterValues['photo'] === 'no' ? 'selected' : '' ?>>Not Available</option>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array('signature', $visibleAdvancedFields, true)): ?>
                                            <div class="col-md-4">
                                                <label class="advanced-field-label">Signature</label>
                                                <select name="signature" class="form-select">
                                                    <option value="">Any</option>
                                                    <option value="yes" <?= $filterValues['signature'] === 'yes' ? 'selected' : '' ?>>Available</option>
                                                    <option value="no" <?= $filterValues['signature'] === 'no' ? 'selected' : '' ?>>Not Available</option>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="advanced-filter-actions">
                                    <span class="text-muted small">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Filters are combined together.
                                    </span>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-check me-1"></i>Apply Filters
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                onclick="clearAdvancedFilters()">
                                            <i class="fas fa-eraser me-1"></i>Clear Advanced
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="card-body-custom">
                        <?php if ($isSuperAdmin && $totalOrgs > 1): ?>
                            <div class="info-note">
                                <i class="fas fa-info-circle me-1"></i>
                                Results span <strong><?= $totalOrgs ?></strong> organizations. Each organization will be
                                printed as its own separated group on <em>Print ID Cards</em>.
                            </div>
                        <?php endif; ?>

                        <div class="d-flex-wrap justify-content-between mb-3">
                            <div class="d-flex-wrap">
                                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="selectAll()">
                                    <i class="fas fa-check-double me-1"></i>Select All
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="deselectAll()">
                                    <i class="fas fa-times me-1"></i>Deselect All
                                </button>
                            </div>
                            <div class="d-flex-wrap">
                                <button class="btn btn-sm btn-success" type="button" onclick="printSelected()">
                                    <i class="fas fa-print me-1"></i>Print Selected
                                </button>
                                <button class="btn btn-sm btn-primary" type="button" onclick="printSelectedMirror()">
                                    <i class="fas fa-undo me-1"></i>Mirror Print
                                </button>
                            </div>
                        </div>

                        <?php if (empty($groupedMembers)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-users" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>
                                No members found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($groupedMembers as $orgId => $group): ?>
                                <div class="org-group">
                                    <div class="org-group-header">
                                        <span><i class="fas fa-building me-2"></i><?= htmlspecialchars($group['organization_name']) ?>
                                            <?php if (!empty($group['project_type'])): ?>
                                                <span class="text-muted fw-normal">(<?= htmlspecialchars(ucfirst($group['project_type'])) ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="badge"><?= count($group['members']) ?> member(s)</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width:40px;">
                                                        <input type="checkbox" class="form-check-input org-select-all" data-org="<?= (int) $orgId ?>" onchange="toggleOrg(this)">
                                                    </th>
                                                    <th>Name</th>
                                                    <th>ID</th>
                                                    <th>Type</th>
                                                    <th>Status</th>
                                                    <th>Expiry</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['members'] as $member):
                                                    $status = 'active';
                                                    $statusClass = 'active';
                                                    $today = date('Y-m-d');
                                                    $next30 = date('Y-m-d', strtotime('+30 days'));
                                                    if (!empty($member['expiry_date'])) {
                                                        if ($member['expiry_date'] < $today) {
                                                            $status = 'expired';
                                                            $statusClass = 'expired';
                                                        } elseif ($member['expiry_date'] <= $next30) {
                                                            $status = 'expiring';
                                                            $statusClass = 'expiring';
                                                        }
                                                    }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" class="form-check-input member-checkbox" value="<?= (int) $member['id'] ?>"
                                                                   data-org="<?= (int) $orgId ?>"
                                                                   onchange="updateCount()"
                                                                   <?= in_array((int) $member['id'], $preselectedIds, true) ? 'checked' : '' ?>>
                                                        </td>
                                                        <td><?= htmlspecialchars($member['name']) ?></td>
                                                        <td><?= htmlspecialchars($member['unique_id']) ?></td>
                                                        <td><?= htmlspecialchars(ucfirst($member['member_type'])) ?></td>
                                                        <td><span class="status-badge <?= $statusClass ?>"><?= ucfirst($status) ?></span></td>
                                                        <td><?= !empty($member['expiry_date']) ? date('M d, Y', strtotime($member['expiry_date'])) : '—' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="card-footer-custom">
                        <div class="d-flex-wrap justify-content-between align-items-center">
                            <span class="text-muted small"><?= $totalMembers ?> members found across <?= $totalOrgs ?> organization(s)</span>
                            <div class="d-flex-wrap">
                                <button class="btn btn-sm btn-success" type="button" onclick="printSelected()">
                                    <i class="fas fa-print me-1"></i>Print Selected
                                </button>
                                <button class="btn btn-sm btn-primary" type="button" onclick="printSelectedMirror()">
                                    <i class="fas fa-undo me-1"></i>Mirror Print
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAdvancedFilters() {
            const panel = document.getElementById('advancedFilterPanel');
            if (panel) panel.classList.toggle('show');
        }

        function clearAdvancedFilters() {
            const form = document.getElementById('memberFilterForm');
            if (!form) return;

            form.querySelectorAll(
                'input[name="name"], input[name="unique_id"], input[name="email"], ' +
                'input[name="emergency_contact"], input[name="guardian_name"], input[name="class"], ' +
                'input[name="department"], input[name="designation"], input[name="company"], ' +
                'input[name="purpose"], input[name="address"], input[type="date"]'
            ).forEach(el => el.value = '');

            form.querySelectorAll('select[name="photo"], select[name="signature"]')
                .forEach(el => el.value = '');

            form.querySelectorAll('select[name="template_id"]')
                .forEach(el => el.value = '0');

            form.submit();
        }

        const advancedOrgSelect = document.getElementById('advancedOrgSelect');
        if (advancedOrgSelect) {
            advancedOrgSelect.addEventListener('change', function () {
                const url = new URL(window.location.href);
                url.searchParams.set('org_id', this.value || '0');
                url.searchParams.delete('template_id');
                [
                    'search','status','name','unique_id','email','emergency_contact',
                    'guardian_name','class','department','designation','company',
                    'purpose','address','dob_from','dob_to','joined_date_from',
                    'joined_date_to','expiry_date_from','expiry_date_to','photo','signature'
                ].forEach(key => url.searchParams.delete(key));
                window.location.href = url.toString();
            });
        }

        const advancedTemplateSelect = document.getElementById('advancedTemplateSelect');
        if (advancedTemplateSelect) {
            advancedTemplateSelect.addEventListener('change', function () {
                const form = document.getElementById('memberFilterForm');
                if (form) {
                    form.submit();
                }
            });
        }

        function updateCount() {
            const checkboxes = document.querySelectorAll('.member-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length + ' selected';

            document.querySelectorAll('.org-select-all').forEach(function (orgBox) {
                const org = orgBox.dataset.org;
                const boxes = document.querySelectorAll('.member-checkbox[data-org="' + org + '"]');
                const checked = document.querySelectorAll('.member-checkbox[data-org="' + org + '"]:checked');
                orgBox.checked = boxes.length > 0 && boxes.length === checked.length;
            });
        }

        function toggleOrg(master) {
            const org = master.dataset.org;
            document.querySelectorAll('.member-checkbox[data-org="' + org + '"]').forEach(function (cb) {
                cb.checked = master.checked;
            });
            updateCount();
        }

        function selectAll() {
            document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.org-select-all').forEach(cb => cb.checked = true);
            updateCount();
        }

        function deselectAll() {
            document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.org-select-all').forEach(cb => cb.checked = false);
            updateCount();
        }

        function getSelectedIds() {
            return Array.from(document.querySelectorAll('.member-checkbox:checked')).map(cb => cb.value);
        }

        function printSelected() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                alert('Please select at least one member to print.');
                return;
            }
            window.open('print_id_card.php?ids=' + ids.join(',') + '&bulk=1', '_blank');
        }

        function printSelectedMirror() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                alert('Please select at least one member to print.');
                return;
            }
            window.open('print_id_card.php?ids=' + ids.join(',') + '&bulk=1&mirror=1', '_blank');
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); selectAll(); }
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') { e.preventDefault(); printSelected(); }
        });

        document.addEventListener('DOMContentLoaded', updateCount);
    </script>
</body>
</html>