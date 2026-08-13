<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_helpers.php';

$page_title = 'Add Member';
require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Members', 'Create');

$currentUser = $authUser;
$userOrgId = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);
$isSuperAdmin = auth_is_super_admin($authUser);

// Get organizations (for super admin)
$organizations = [];
if ($isSuperAdmin) {
    $stmt = $pdo->query("SELECT id, organization_name, project_type, status FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name");
    $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get user's organization (for non-super admin)
$userOrg = null;
if (!$isSuperAdmin && $userOrgId > 0) {
    $stmt = $pdo->prepare("SELECT id, organization_name, project_type FROM organizations WHERE id = ? AND deleted_at IS NULL AND status = 1");
    $stmt->execute([$userOrgId]);
    $userOrg = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get templates based on organization
$templates = [];
$templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : (int)($_POST['template_id'] ?? 0);
$selectedOrgId = $isSuperAdmin ? (int)($_GET['org_id'] ?? 0) : $userOrgId;

$templateSql = "SELECT id, name, orientation, primary_color, secondary_color, text_color, 
                font, background_image, mirror_print, is_default, 
                card_width, card_height, description
                FROM card_templates 
                WHERE status = 1 AND deleted_at IS NULL ";
$params = [];

if ($selectedOrgId > 0) {
    // A member may only use a template owned by their selected organization.
    $templateSql .= " AND organization_id = ?";
    $params[] = $selectedOrgId;
} else {
    $templateSql .= " AND 1 = 0";
}

$templateSql .= " ORDER BY is_default DESC, name";
$stmt = $pdo->prepare($templateSql);
$stmt->execute($params);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Member types

$projectTypes = ['residence', 'corporate'];
$duplicateWarnings = [];

require_once __DIR__ . '/../template/template_mgmt_helpers.php';

$templateFieldDefs = [];
if ($templateId > 0) {
    $templateFieldDefs = member_load_input_fields($pdo, $templateId);
}
$activeKeys = template_get_active_field_keys($pdo, $templateId);

$standardKeys = ['name', 'unique_id', 'email', 'emergency_contact', 'dob', 'address', 'guardian_name', 'class', 'department', 'designation', 'company', 'purpose', 'joined_date', 'expiry_date', 'photo', 'signature', 'organization_id', 'template_id', 'member_type'];

$customTemplateFields = [];
foreach ($templateFieldDefs as $k => $field) {
    if (!in_array($k, $standardKeys, true) && in_array($k, $activeKeys, true)) {
        $customTemplateFields[$k] = $field;
    }
}

// Handle form submission
$errors = [];
$success = false;
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh the page.';
    } else {
        // Collect form data
        $formData = [
            'organization_id' => $isSuperAdmin
                ? ($_POST['organization_id'] ?? null)
                : $userOrgId,
            'member_type' => 'student',
            'template_id' => $_POST['template_id'] ?? null,
            'unique_id' => trim($_POST['unique_id'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'guardian_name' => trim($_POST['guardian_name'] ?? ''),
            'class' => trim($_POST['class'] ?? ''),
            'department' => trim($_POST['department'] ?? ''),
            'designation' => trim($_POST['designation'] ?? ''),
            'company' => trim($_POST['company'] ?? ''),
            'purpose' => trim($_POST['purpose'] ?? ''),
            'dob' => $_POST['dob'] ?? null,
            'address' => trim($_POST['address'] ?? ''),
            'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'joined_date' => $_POST['joined_date'] ?? date('Y-m-d'),
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'language' => 'en',
            'photo' => null,
            'signature' => null,
            'dynamic_fields' => $_POST['dynamic_fields'] ?? []
        ];

        // Validate required fields
        if (empty($formData['name'])) {
            $errors[] = 'Full Name is required';
        }

        if (empty($formData['organization_id'])) {
            $errors[] = 'Please select an organization first.';
        }
        if (empty($formData['template_id'])) {
            $errors[] = 'Please select a template.';
        }

        // Validate email
        if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        // Validate expiry date
        if (!empty($formData['expiry_date']) && strtotime($formData['expiry_date']) < strtotime(date('Y-m-d'))) {
            $errors[] = 'Expiry date cannot be in the past';
        }

        // Validate template based on project type
        if (!empty($formData['template_id'])) {
            $stmt = $pdo->prepare("SELECT orientation, organization_id FROM card_templates WHERE id = ? AND organization_id = ? AND status = 1 AND deleted_at IS NULL");
            $stmt->execute([$formData['template_id'], (int)$formData['organization_id']]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                $orgId = $formData['organization_id'] ?? $userOrgId;
                if ($orgId) {
                    $stmt = $pdo->prepare("SELECT project_type FROM organizations WHERE id = ?");
                    $stmt->execute([$orgId]);
                    $org = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($org && $org['project_type'] === 'residence' && $template['orientation'] !== 'landscape') {
                        $errors[] = 'Residence project requires Landscape orientation templates only.';
                    }
                }
            } else {
                $errors[] = 'Selected template does not belong to the selected organization or is unavailable.';
            }
        }

        $fieldDefs = member_load_input_fields($pdo, (int)($formData['template_id'] ?? 0));
        $errors = array_merge($errors, member_validate_dynamic_fields($pdo, (int)($formData['template_id'] ?? 0), $formData['dynamic_fields'], $fieldDefs));

        $duplicateWarnings = member_find_duplicates($pdo, $formData);
        if (!empty($duplicateWarnings) && empty($_POST['confirm_duplicate'])) {
            foreach ($duplicateWarnings as $w) {
                $names = array_map(static fn($m) => $m['name'] . ' (' . $m['unique_id'] . ')', $w['members']);
                $errors[] = 'Duplicate ' . $w['label'] . ' found: ' . implode(', ', $names) . '. Check "Save anyway" to continue.';
            }
        }

        if (!empty($_FILES['photo']['name'])) {
            $stored = member_store_uploaded_image($_FILES['photo']);
            if ($stored['success']) {
                $formData['photo'] = $stored['filename'];
            } else {
                $errors[] = $stored['error'] ?? 'Photo upload failed';
            }
        }

        if (!empty($_FILES['signature']['name'])) {
            $stored = member_store_uploaded_image($_FILES['signature'], 'signatures');
            if ($stored['success']) {
                $formData['signature'] = $stored['filename'];
            } else {
                $errors[] = $stored['error'] ?? 'Signature upload failed';
            }
        }

        // If no errors, save member
        if (empty($errors)) {
            // Generate unique ID if not provided
            if (empty($formData['unique_id'])) {
                $formData['unique_id'] = generate_unique_id($pdo, 'MEM');
            }

            // Save member
            $result = save_member_advanced($pdo, $formData);
            
            if ($result['success']) {
                $dynamicPayload = member_prepare_dynamic_save_payload($formData['dynamic_fields'], $fieldDefs);
                member_handle_dynamic_file_uploads($dynamicPayload, $fieldDefs, $_FILES['dynamic_file'] ?? []);
                if (!empty($dynamicPayload) && !empty($result['id'])) {
                    save_member_dynamic_values($pdo, (int)$result['id'], (int)$formData['template_id'], $dynamicPayload);
                }
                member_log_audit($pdo, (int)($authUser['id'] ?? 0), (int)($formData['organization_id'] ?? 0) ?: null,
                    'Member Created', 'Added member: ' . $formData['name'] . ' (' . $formData['unique_id'] . ')');
                
                $_SESSION['member_message'] = 'Member added successfully! ID: ' . $formData['unique_id'];
                
                // Redirect based on action
                if (isset($_POST['action']) && $_POST['action'] === 'generate_card') {
                    header('Location: ../generate_id_card.php?member_id=' . $result['id']);
                } elseif (isset($_POST['action']) && $_POST['action'] === 'add_another') {
                    header('Location: add_member.php?success=1');
                } else {
                    header('Location: view_members.php');
                }
                exit();
            } else {
                $errors[] = $result['error'] ?? 'Failed to save member';
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$displayTemplateId = (int)($templateId ?: ($_POST['template_id'] ?? 0));
$displayFieldDefs = $displayTemplateId > 0 ? member_load_input_fields($pdo, $displayTemplateId) : [];
$templateIsBilingual = false;
foreach ($displayFieldDefs as $displayField) {
    if (($displayField['bilingual_mode'] ?? 'single') === 'bilingual') {
        $templateIsBilingual = true;
        break;
    }
}
$templateLanguages = get_active_languages($pdo, 2);
$secondaryLanguage = $templateLanguages[1]['language_name'] ?? 'Secondary Language';

// Helper functions
function get_member_type_label($type) {
    $labels = [
        'student' => 'Student',
        'employee' => 'Employee',
        'staff' => 'Staff',
        'faculty' => 'Faculty',
        'visitor' => 'Visitor',
        'office' => 'Office'
    ];
    return $labels[$type] ?? ucfirst($type);
}

function save_member_advanced($pdo, $data) {
    try {
        $pdo->beginTransaction();
        
        $sql = "INSERT INTO id_members (
                    organization_id, member_type, template_id, unique_id, 
                    name, guardian_name, class, department, designation, 
                    company, purpose, dob, address, emergency_contact, 
                    email, joined_date, expiry_date, photo, signature, language,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    NOW(), NOW()
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['organization_id'] ?? null,
            $data['member_type'] ?? 'student',
            $data['template_id'] ?? null,
            $data['unique_id'],
            $data['name'],
            $data['guardian_name'] ?? null,
            $data['class'] ?? null,
            $data['department'] ?? null,
            $data['designation'] ?? null,
            $data['company'] ?? null,
            $data['purpose'] ?? null,
            $data['dob'] ?? null,
            $data['address'] ?? null,
            $data['emergency_contact'] ?? null,
            $data['email'] ?? null,
            $data['joined_date'] ?? date('Y-m-d'),
            $data['expiry_date'] ?? null,
            $data['photo'] ?? null,
            $data['signature'] ?? null,
            $data['language'] ?? 'en'
        ]);
        
        $memberId = $pdo->lastInsertId();
        $pdo->commit();
        return ['success' => true, 'id' => $memberId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Add Member · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
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
        .dashboard-content { padding: 2rem; max-width: 1600px; margin: 0 auto; }

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
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header-custom h5 {
            font-weight: 600;
            margin: 0;
            color: var(--neutral-800);
        }
        .card-header-custom h5 i { color: var(--primary); margin-right: 0.5rem; }

        .card-body-custom { padding: 1.5rem; }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--neutral-700);
            margin-bottom: 0.25rem;
        }
        .form-label .required { color: var(--danger); }

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

        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }
        .section-title i { margin-right: 0.5rem; }

        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease;
        }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert-success { background: var(--success-soft); color: var(--success); }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #e0a832; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        .photo-preview-container {
            text-align: center;
            padding: 1rem;
            background: var(--neutral-50);
            border-radius: var(--radius-lg);
            border: 2px dashed var(--neutral-300);
            transition: all 0.3s;
        }
        .photo-preview-container:hover { border-color: var(--primary); }

        .photo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--neutral-200);
            margin-bottom: 0.5rem;
        }
        .photo-preview-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--neutral-200);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 3rem;
            color: var(--neutral-400);
        }

        .signature-preview {
            width: 200px;
            height: 60px;
            object-fit: contain;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-md);
            background: white;
            padding: 0.25rem;
        }

        .dynamic-field-group {
            background: var(--neutral-50);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 0.75rem;
            border: 1px solid var(--neutral-200);
        }
        .dynamic-field-group .field-label {
            font-weight: 500;
            font-size: 0.813rem;
            color: var(--neutral-700);
        }
        .dynamic-field-group .field-type-badge {
            font-size: 0.625rem;
            padding: 0.15rem 0.4rem;
            border-radius: var(--radius-sm);
            background: var(--info-soft);
            color: var(--info);
        }

        .language-toggle {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .language-toggle .btn-lang {
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--neutral-300);
            background: white;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .language-toggle .btn-lang.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .language-toggle .btn-lang:hover:not(.active) {
            background: var(--neutral-100);
        }

        .secondary-lang-field { display: none; }
        .secondary-lang-field.visible { display: block; }

        @media (max-width: 768px) {
            .photo-preview, .photo-preview-placeholder { width: 100px; height: 100px; }
            .signature-preview { width: 150px; height: 50px; }
            .card-header-custom { flex-direction: column; align-items: stretch; text-align: center; }
            .card-header-custom .btn { width: 100%; justify-content: center; }
        }

        @media (max-width: 480px) {
            .dashboard-content { padding: 1rem; }
            .card-body-custom { padding: 1rem; }
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
                        <li class="breadcrumb-item"><a href="view_members.php">Members</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Add Member</li>
                    </ol>
                </nav>

                <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1">Member added successfully! <a href="view_members.php" class="fw-bold text-success">View all members</a></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <div class="main-card">
                    <div class="card-header-custom">
                        <div>
                            <h5><i class="fas fa-user-plus text-primary me-2"></i>Add New Member</h5>
                            <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                                Create a new ID card holder record with all details
                                <?php if ($userOrg && $userOrg['project_type']): ?>
                                    <span class="badge bg-primary ms-2"><?= ucfirst($userOrg['project_type']) ?> Project</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="view_members.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to List
                        </a>
                    </div>

                    <div class="card-body-custom">
                        <form method="post" enctype="multipart/form-data" id="memberForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="alert alert-info py-2 small mb-4" id="templateLanguageNotice">
                                <i class="fas fa-language me-1"></i>
                                <?php if ($displayTemplateId > 0 && $templateIsBilingual): ?>
                                    Template supports: English + <?= htmlspecialchars($secondaryLanguage) ?>
                                <?php elseif ($displayTemplateId > 0): ?>
                                    Template supports: English
                                <?php else: ?>
                                    Select an organization and template to view supported languages.
                                <?php endif; ?>
                            </div>

                            <div class="row g-3">
                                <!-- Basic Information -->
                                <div class="col-md-6">
                                    <h6 class="section-title"><i class="fas fa-user"></i>Basic Information</h6>

                                    <div class="mb-3">
                                        <label class="form-label">Full Name <span class="required">*</span></label>
                                        <input type="text" name="name" class="form-control" 
                                               required value="<?= htmlspecialchars($formData['name'] ?? '') ?>" 
                                               placeholder="Enter full name">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Template Source</label>
                                        <div class="form-control bg-light text-muted" style="min-height: 48px;">
                                            The selected card template controls the custom fields shown below.
                                        </div>
                                        <div class="form-text">Choose a template to load its enabled fields for this member.</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Unique ID</label>
                                        <div class="input-group">
                                            <input type="text" name="unique_id" class="form-control" 
                                                   placeholder="Auto-generate if empty" 
                                                   value="<?= htmlspecialchars($formData['unique_id'] ?? '') ?>">
                                            <button type="button" class="btn btn-outline-secondary" onclick="generateUniqueId()">
                                                <i class="fas fa-sync"></i> Generate
                                            </button>
                                        </div>
                                        <div class="form-text">Leave empty to auto-generate</div>
                                    </div>

                                    <?php if (in_array('email', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" 
                                               value="<?= htmlspecialchars($formData['email'] ?? '') ?>" 
                                               placeholder="email@example.com">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('emergency_contact', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Emergency Contact</label>
                                        <input type="text" name="emergency_contact" class="form-control" 
                                               value="<?= htmlspecialchars($formData['emergency_contact'] ?? '') ?>" 
                                               placeholder="Phone number">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('dob', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="date" name="dob" class="form-control" 
                                               value="<?= htmlspecialchars($formData['dob'] ?? '') ?>">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('guardian_name', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Guardian Name</label>
                                        <input type="text" name="guardian_name" class="form-control" 
                                               value="<?= htmlspecialchars($formData['guardian_name'] ?? '') ?>" 
                                               placeholder="Parent/Guardian Name">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('class', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Class / Grade</label>
                                        <input type="text" name="class" class="form-control" 
                                               value="<?= htmlspecialchars($formData['class'] ?? '') ?>" 
                                               placeholder="e.g. 10-A">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('department', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Department</label>
                                        <input type="text" name="department" class="form-control" 
                                               value="<?= htmlspecialchars($formData['department'] ?? '') ?>" 
                                               placeholder="Department">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('designation', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Designation</label>
                                        <input type="text" name="designation" class="form-control" 
                                               value="<?= htmlspecialchars($formData['designation'] ?? '') ?>" 
                                               placeholder="Designation">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('company', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Company</label>
                                        <input type="text" name="company" class="form-control" 
                                               value="<?= htmlspecialchars($formData['company'] ?? '') ?>" 
                                               placeholder="Company Name">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('purpose', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Purpose</label>
                                        <input type="text" name="purpose" class="form-control" 
                                               value="<?= htmlspecialchars($formData['purpose'] ?? '') ?>" 
                                               placeholder="Purpose of visit">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('address', $activeKeys, true)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="2" 
                                                  placeholder="Address"><?= htmlspecialchars($formData['address'] ?? '') ?></textarea>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Organization & Details -->
                                <div class="col-md-6">
                                    <h6 class="section-title"><i class="fas fa-building"></i>Organization & Details</h6>

                                    <?php if ($isSuperAdmin): ?>
                                        <div class="mb-3">
                                            <label class="form-label">Organization</label>
                                            <select name="organization_id" class="form-select" id="organizationSelect">
                                                <option value="">Select Organization</option>
                                                <?php foreach ($organizations as $org): ?>
                                                    <option value="<?= (int)$org['id'] ?>" 
                                                            data-project="<?= $org['project_type'] ?>"
                                                            <?= ($formData['organization_id'] ?? $selectedOrgId) == $org['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($org['organization_name']) ?>
                                                        <?php if ($org['project_type']): ?>
                                                            (<?= ucfirst($org['project_type']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Select the organization this member belongs to</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-3">
                                            <label class="form-label">Organization</label>
                                            <input type="text" class="form-control" readonly 
                                                   value="<?= htmlspecialchars($userOrg['organization_name'] ?? 'Default Organization') ?>">
                                            <input type="hidden" name="organization_id" value="<?= $userOrgId ?>">
                                            <?php if ($userOrg && $userOrg['project_type']): ?>
                                                <div class="form-text">Project Type: <strong><?= ucfirst($userOrg['project_type']) ?></strong></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Template <span class="required">*</span></label>
                                        <select name="template_id" class="form-select" id="templateSelect" required <?= $selectedOrgId <= 0 ? 'disabled' : '' ?>>
                                            <?php if ($selectedOrgId <= 0): ?>
                                                <option value="">Select Organization first</option>
                                            <?php else: ?>
                                                <option value="">Select Template</option>
                                                <?php foreach ($templates as $tpl): ?>
                                                    <option value="<?= (int)$tpl['id'] ?>" 
                                                            data-orientation="<?= $tpl['orientation'] ?>"
                                                            <?= ($formData['template_id'] ?? $templateId) == $tpl['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($tpl['name']) ?>
                                                        (<?= ucfirst($tpl['orientation']) ?>)
                                                        <?php if ($tpl['is_default']): ?>⭐<?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text" id="templateInfo">
                                            <?php if (!empty($templates)): ?>
                                                <?php 
                                                $selectedTpl = null;
                                                foreach ($templates as $tpl) {
                                                    if ($tpl['id'] == $templateId) {
                                                        $selectedTpl = $tpl;
                                                        break;
                                                    }
                                                }
                                                if ($selectedTpl): ?>
                                                    Size: <?= $selectedTpl['card_width'] ?? 'Default' ?> × <?= $selectedTpl['card_height'] ?? 'Default' ?> | 
                                                    Orientation: <?= ucfirst($selectedTpl['orientation']) ?>
                                                <?php else: ?>
                                                    Select a template to see details
                                                <?php endif; ?>
                                            <?php else: ?>
                                                No templates available
                                            <?php endif; ?>
                                        </div>
                                    </div>                                    <?php if (in_array('joined_date', $activeKeys, true) || in_array('expiry_date', $activeKeys, true)): ?>
                                    <div class="row g-2">
                                        <?php if (in_array('joined_date', $activeKeys, true)): ?>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Joined Date</label>
                                                <input type="date" name="joined_date" class="form-control" 
                                                       value="<?= htmlspecialchars($formData['joined_date'] ?? date('Y-m-d')) ?>">
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (in_array('expiry_date', $activeKeys, true)): ?>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Expiry Date</label>
                                                <input type="date" name="expiry_date" class="form-control" 
                                                       value="<?= htmlspecialchars($formData['expiry_date'] ?? '') ?>">
                                                <div class="form-text">Leave empty for no expiry</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('photo', $activeKeys, true) || !empty($member['photo']) || in_array('signature', $activeKeys, true)): ?>
                                    <div class="col-12 mt-3">
                                        <h6 class="section-title"><i class="fas fa-camera"></i>Photos & Signatures</h6>
                                        <div class="row g-3">
                                            <?php if (in_array('photo', $activeKeys, true)): ?>
                                            <div class="col-md-6">
                                                <div class="photo-preview-container">
                                                    <label class="form-label">Member Photo</label>
                                                    <?php if (!empty($formData['photo'])): ?>
                                                        <img src="../images/uploads/<?= htmlspecialchars($formData['photo']) ?>" class="photo-preview" alt="Photo">
                                                    <?php else: ?>
                                                        <div class="photo-preview-placeholder">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(this)">
                                                    <div class="form-text">JPG, PNG, GIF (Max 5MB)</div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (in_array('signature', $activeKeys, true)): ?>
                                            <div class="col-md-6">
                                                <div class="photo-preview-container">
                                                    <label class="form-label">Signature</label>
                                                    <?php if (!empty($formData['signature'])): ?>
                                                        <img src="../images/uploads/signatures/<?= htmlspecialchars($formData['signature']) ?>" class="signature-preview" alt="Signature">
                                                    <?php else: ?>
                                                        <div style="height:60px;display:flex;align-items:center;justify-content:center;background:var(--neutral-100);border-radius:var(--radius-md);">
                                                            <span class="text-muted">No signature</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <input type="file" name="signature" class="form-control" accept="image/*" onchange="previewSignature(this)">
                                                    <div class="form-text">JPG, PNG (Max 2MB)</div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Dynamic Fields -->
                                <div class="col-12">
                                    <h6 class="section-title"><i class="fas fa-cogs"></i>Template Fields</h6>
                                    <?php if (!empty($customTemplateFields)): ?>
                                        <div class="row g-3">
                                            <?php foreach ($customTemplateFields as $field):
                                                $fv = $formData['dynamic_fields'][$field['field_key']] ?? '';
                                                echo member_render_dynamic_field_input($field, $fv);
                                            endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                         <div class="alert alert-info mb-0">
                                             <i class="fas fa-info-circle me-1"></i>
                                             <?php if (empty($selectedOrgId)): ?>
                                                 Please select an Organization first.
                                             <?php elseif ($templateId <= 0): ?>
                                                 Please select a Template to load its fields for this member.
                                             <?php else: ?>
                                                 No custom fields configured for this template.
                                             <?php endif; ?>
                                         </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($duplicateWarnings)): ?>
                                    <div class="col-12">
                                        <div class="alert alert-warning">
                                            <strong>Possible duplicates detected.</strong> Check below to save anyway.
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="confirm_duplicate" id="confirmDuplicate" value="1">
                                                <label class="form-check-label" for="confirmDuplicate">Save anyway (I confirm this is intentional)</label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Submit -->
                                <div class="col-12">
                                    <hr>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" name="action" value="save" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Save Member
                                        </button>
                                        <button type="submit" name="action" value="generate_card" class="btn btn-success">
                                            <i class="fas fa-id-card me-1"></i>Save & Generate Card
                                        </button>
                                        <button type="submit" name="action" value="add_another" class="btn btn-warning">
                                            <i class="fas fa-plus me-1"></i>Save & Add Another
                                        </button>
                                        <a href="view_members.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ============================================================
        // PHOTO PREVIEW
        // ============================================================
        function previewPhoto(input) {
            const preview = document.querySelector('.photo-preview');
            const placeholder = document.querySelector('.photo-preview-placeholder');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ============================================================
        // SIGNATURE PREVIEW
        // ============================================================
        function previewSignature(input) {
            const preview = document.querySelector('.signature-preview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ============================================================
        // GENERATE UNIQUE ID
        // ============================================================
        function generateUniqueId() {
            const prefix = 'MEM';
            const year = new Date().getFullYear();
            const month = String(new Date().getMonth() + 1).padStart(2, '0');
            const random = String(Math.floor(Math.random() * 9999)).padStart(4, '0');
            
            document.querySelector('input[name="unique_id"]').value = prefix + year + month + random;
        }

        function previewDynamicFieldImage(input) {
            const previewId = input.dataset.previewId;
            if (!previewId || !input.files || !input.files[0]) {
                return;
            }
            const previewContainer = document.getElementById(previewId);
            const img = previewContainer?.querySelector('img');
            if (!img) {
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                img.style.display = 'block';
                if (previewContainer) {
                    previewContainer.style.display = 'block';
                }
            };
            reader.readAsDataURL(input.files[0]);
        }

        document.querySelectorAll('input.dynamic-file-input').forEach(function(fileInput) {
            fileInput.addEventListener('change', function() {
                previewDynamicFieldImage(this);
            });
        });

        // ============================================================
        // TEMPLATE SELECTION - FIXED
        // ============================================================
        document.getElementById('templateSelect')?.addEventListener('change', function() {
            const templateId = this.value;
            if (templateId) {
                // Reload page with selected template
                const url = new URL(window.location.href);
                url.searchParams.set('template_id', templateId);
                const orgId = document.getElementById('organizationSelect')?.value;
                if (orgId) url.searchParams.set('org_id', orgId);
                window.location.href = url.toString();
            }
        });

        // ============================================================
        // ORGANIZATION CHANGE
        // ============================================================
        document.getElementById('organizationSelect')?.addEventListener('change', function() {
            const orgId = this.value;
            if (orgId) {
                window.location.href = 'add_member.php?org_id=' + orgId;
            }
        });

        // ============================================================
        // FORM VALIDATION
        // ============================================================
        document.getElementById('memberForm')?.addEventListener('submit', function(e) {
            const template = document.querySelector('select[name="template_id"]');
            if (template && !template.value) {
                e.preventDefault();
                alert('Please select a template for the ID card.');
                template.focus();
                return false;
            }
            
            const name = document.querySelector('input[name="name"]');
            if (name && !name.value.trim()) {
                e.preventDefault();
                alert('Please enter the member\'s full name.');
                name.focus();
                return false;
            }
            
            return true;
        });

        // ============================================================
        // KEYBOARD SHORTCUTS
        // ============================================================
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[name="action"][value="save"]')?.click();
            }
            
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('button[name="action"][value="generate_card"]')?.click();
            }
        });

        // ============================================================
        // TOUCH-FRIENDLY
        // ============================================================
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
    </script>
</body>
</html>
