<?php
/**
 * Edit Template — update an existing card_templates row (name, description,
 * orientation, size, colors, font, mirror print, default flag, background image).
 * Field layout/positions are still edited in design_template.php; this page only
 * touches the template's own metadata, matching the conventions of
 * add_template.php / templates.php / view_template.php (includes, CSRF, styling).
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/template_mgmt_helpers.php';
require_once __DIR__ . '/template_functions.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Templates', 'Edit');

$page_title = 'Edit Template';
$isSuperAdmin = auth_is_super_admin($authUser);
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);
$userOrgId = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($templateId <= 0) {
    $_SESSION['error'] = 'Invalid template ID';
    header('Location: templates.php');
    exit();
}

// ─── Load template ─────────────────────────────────────────────────────────
$template = template_fetch_by_id($pdo, $templateId);
if (!$template) {
    $_SESSION['error'] = 'Template not found';
    header('Location: templates.php');
    exit();
}

if ((int)($template['organization_id'] ?? 0) > 0
    && !user_can_access_organization($authUser, $template['organization_id'])) {
    $_SESSION['error'] = 'You do not have access to this template';
    header('Location: templates.php');
    exit();
}

if (!template_user_can_manage($pdo, $authUser, $template)) {
    $_SESSION['error'] = 'You do not have permission to edit this template';
    header('Location: templates.php');
    exit();
}

$isArchived = (int)($template['status'] ?? 1) === 0 || !empty($template['deleted_at']);
if ($isArchived) {
    $_SESSION['error'] = 'Archived templates cannot be edited. Restore it first.';
    header('Location: view_template.php?id=' . $templateId);
    exit();
}

// Organizations list (super admin picks; others are locked to their own org)
$organizations = [];
if ($isSuperAdmin) {
    $stmt = $pdo->query("SELECT id, organization_name, project_type FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name");
    $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$userOrg = null;
if (!$isSuperAdmin && $userOrgId > 0) {
    $stmt = $pdo->prepare("SELECT id, organization_name, project_type FROM organizations WHERE id = ? AND deleted_at IS NULL AND status = 1");
    $stmt->execute([$userOrgId]);
    $userOrg = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$errors = [];
$formData = [
    'organization_id' => (int)($template['organization_id'] ?? ($isSuperAdmin ? 0 : $userOrgId)),
    'name' => (string)($template['name'] ?? ''),
    'description' => (string)($template['description'] ?? ''),
    'orientation' => ($template['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
    'card_width' => (int)($template['card_width'] ?? 0),
    'card_height' => (int)($template['card_height'] ?? 0),
    'primary_color' => (string)($template['primary_color'] ?? '#0a1a2f'),
    'secondary_color' => (string)($template['secondary_color'] ?? '#1e3a5f'),
    'text_color' => (string)($template['text_color'] ?? '#ffffff'),
    'font' => (string)($template['font'] ?? 'Inter'),
    'mirror_print' => !empty($template['mirror_print']) ? 1 : 0,
    'is_default' => !empty($template['is_default']) ? 1 : 0,
];
$currentBackground = $template['background_image'] ?? null;

$fontOptions = ['Inter', 'Poppins', 'Arial', 'Helvetica', 'Times New Roman', 'Georgia', 'Courier New', 'Lato', 'Roboto'];

function edit_template_store_background(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null]; // no new file supplied
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Background image upload failed.'];
    }
    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Background image must be 5MB or smaller.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid background image upload.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Invalid image type. Use JPG, PNG, WEBP, or GIF.'];
    }
    $ext = $allowed[$mime];
    $dir = dirname(__DIR__) . '/images/templates';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.'];
    }
    $filename = 'tpledit_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Failed to save background image.'];
    }
    return ['ok' => true, 'path' => 'images/templates/' . $filename];
}

function edit_template_safe_hex(string $color, string $fallback): string {
    $color = trim($color);
    return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) ? $color : $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $formData['organization_id'] = $isSuperAdmin ? (int)($_POST['organization_id'] ?? 0) : $formData['organization_id'];
        $formData['name'] = trim((string)($_POST['name'] ?? ''));
        $formData['description'] = trim((string)($_POST['description'] ?? ''));
        $formData['orientation'] = strtolower((string)($_POST['orientation'] ?? 'portrait')) === 'landscape' ? 'landscape' : 'portrait';
        $formData['card_width'] = (int)($_POST['card_width'] ?? 0);
        $formData['card_height'] = (int)($_POST['card_height'] ?? 0);
        $formData['primary_color'] = edit_template_safe_hex((string)($_POST['primary_color'] ?? ''), '#0a1a2f');
        $formData['secondary_color'] = edit_template_safe_hex((string)($_POST['secondary_color'] ?? ''), '#1e3a5f');
        $formData['text_color'] = edit_template_safe_hex((string)($_POST['text_color'] ?? ''), '#ffffff');
        $formData['font'] = in_array((string)($_POST['font'] ?? ''), $fontOptions, true) ? (string)$_POST['font'] : 'Inter';
        $formData['mirror_print'] = !empty($_POST['mirror_print']) ? 1 : 0;
        $formData['is_default'] = !empty($_POST['is_default']) ? 1 : 0;
        $removeBackground = !empty($_POST['remove_background']);

        // Validate
        if ($formData['name'] === '') {
            $errors[] = 'Template name is required.';
        }
        if ($formData['organization_id'] <= 0) {
            $errors[] = $isSuperAdmin ? 'Please select an organization.' : 'Your account has no organization assigned.';
        }

        // Business rule: residence projects require landscape-only templates.
        $orgProjectType = null;
        if ($formData['organization_id'] > 0) {
            $opStmt = $pdo->prepare('SELECT project_type FROM organizations WHERE id = ? AND deleted_at IS NULL AND status = 1');
            $opStmt->execute([$formData['organization_id']]);
            $orgRow = $opStmt->fetch(PDO::FETCH_ASSOC);
            if (!$orgRow) {
                $errors[] = 'Selected organization was not found or is inactive.';
            } else {
                $orgProjectType = $orgRow['project_type'] ?? null;
                if ($orgProjectType === 'residence' && $formData['orientation'] !== 'landscape') {
                    $errors[] = 'Residence organizations require Landscape orientation templates only.';
                    $formData['orientation'] = 'landscape';
                }
            }
        }

        // Default card size per orientation if not provided / invalid
        if ($formData['card_width'] < 50 || $formData['card_height'] < 50) {
            if ($formData['orientation'] === 'landscape') {
                $formData['card_width'] = 864;
                $formData['card_height'] = 533;
            } else {
                $formData['card_width'] = 533;
                $formData['card_height'] = 864;
            }
        }

        $bgResult = ['ok' => true, 'path' => null];
        if (!empty($_FILES['background']['name'])) {
            $bgResult = edit_template_store_background($_FILES['background']);
            if (!$bgResult['ok']) {
                $errors[] = $bgResult['error'];
            }
        }

        // Duplicate name check within the same organization (excluding this template)
        if (empty($errors)) {
            $dupStmt = $pdo->prepare(
                'SELECT id FROM card_templates WHERE organization_id = ? AND name = ? AND id != ? AND deleted_at IS NULL LIMIT 1'
            );
            $dupStmt->execute([$formData['organization_id'], $formData['name'], $templateId]);
            if ($dupStmt->fetchColumn()) {
                $errors[] = 'A template with this name already exists for the selected organization.';
            }
        }

        if (empty($errors)) {
            // Work out what the background_image column should end up as:
            // new upload wins, else "remove" clears it, else keep the existing one.
            $newBackgroundPath = $currentBackground;
            $oldBackgroundToDelete = null;
            if (!empty($bgResult['path'])) {
                $newBackgroundPath = $bgResult['path'];
                $oldBackgroundToDelete = $currentBackground;
            } elseif ($removeBackground) {
                $newBackgroundPath = null;
                $oldBackgroundToDelete = $currentBackground;
            }

            try {
                $pdo->beginTransaction();

                if ($formData['is_default'] === 1) {
                    $unset = $pdo->prepare('UPDATE card_templates SET is_default = 0 WHERE organization_id = ? AND id != ?');
                    $unset->execute([$formData['organization_id'], $templateId]);
                }

                $update = $pdo->prepare(
                    'UPDATE card_templates SET
                        organization_id = ?, name = ?, description = ?, orientation = ?,
                        card_width = ?, card_height = ?, primary_color = ?, secondary_color = ?, text_color = ?,
                        font = ?, mirror_print = ?, is_default = ?, background_image = ?
                     WHERE id = ?'
                );
                $update->execute([
                    $formData['organization_id'],
                    $formData['name'],
                    $formData['description'] !== '' ? $formData['description'] : '',
                    $formData['orientation'],
                    $formData['card_width'],
                    $formData['card_height'],
                    $formData['primary_color'],
                    $formData['secondary_color'],
                    $formData['text_color'],
                    $formData['font'],
                    $formData['mirror_print'],
                    $formData['is_default'],
                    $newBackgroundPath,
                    $templateId,
                ]);

                $pdo->commit();

                // Best-effort cleanup of the old file, after the DB commit succeeds.
                if ($oldBackgroundToDelete) {
                    $oldFull = dirname(__DIR__) . '/' . ltrim($oldBackgroundToDelete, '/');
                    if (is_file($oldFull)) {
                        @unlink($oldFull);
                    }
                }

                if (function_exists('template_log_audit')) {
                    template_log_audit($pdo, $userId, $formData['organization_id'] ?: null, 'Updated template', "Template ID: $templateId, Name: {$formData['name']}");
                }

                $_SESSION['message'] = 'Template "' . $formData['name'] . '" updated successfully.';
                header('Location: view_template.php?id=' . $templateId);
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to update template. Please try again.';
            }
        }
    }

    // Keep the in-memory background preview in sync with the current POST state
    if (!empty($bgResult['path'])) {
        $currentBackground = $bgResult['path'];
    } elseif (!empty($_POST['remove_background'])) {
        $currentBackground = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edit Template · ID Card Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
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
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-2xl: 1.5rem;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--neutral-50); color: var(--neutral-800); margin: 0; }
        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 1.5rem 2rem; max-width: 1600px; margin: 0 auto; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        @media (max-width: 768px) { .dashboard-content { padding: 1rem; } }

        .breadcrumb { display: flex; gap: 0.5rem; list-style: none; padding: 0; margin: 0 0 1.25rem 0; font-size: 0.875rem; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        .alert { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.85rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1rem; }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .btn-close-custom { cursor: pointer; background: none; border: none; font-size: 1.25rem; color: inherit; opacity: 0.5; }
        .btn-close-custom:hover { opacity: 1; }

        .main-card { background: white; border-radius: var(--radius-2xl); box-shadow: var(--shadow-md); border: 1px solid var(--neutral-200); overflow: hidden; }
        .card-header-custom { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--neutral-200); background: var(--neutral-50); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .card-header-custom h5 { font-weight: 600; margin: 0; }
        .card-header-custom h5 i { color: var(--primary); margin-right: 0.5rem; }
        .card-body-custom { padding: 1.5rem; }

        .section-title { font-size: 0.875rem; font-weight: 600; color: var(--primary); margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--primary-soft); }
        .section-title i { margin-right: 0.5rem; }

        .form-label { font-weight: 500; font-size: 0.875rem; color: var(--neutral-700); margin-bottom: 0.25rem; }
        .form-label .required { color: var(--danger); }
        .form-control, .form-select { border-radius: var(--radius-lg); border: 1px solid var(--neutral-300); padding: 0.5rem 0.75rem; font-size: 0.875rem; width: 100%; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(10,26,47,0.1); outline: none; }
        .form-text { font-size: 0.75rem; color: var(--neutral-500); margin-top: 0.25rem; }
        .form-control-color { height: 42px; padding: 0.25rem; }

        .orientation-toggle { display: flex; gap: 0.6rem; }
        .orientation-card { flex: 1; border: 2px solid var(--neutral-200); border-radius: var(--radius-lg); padding: 0.85rem; text-align: center; cursor: pointer; transition: all .15s; }
        .orientation-card i { font-size: 1.4rem; color: var(--neutral-400); display: block; margin-bottom: 0.35rem; }
        .orientation-card.active { border-color: var(--primary); background: var(--primary-soft); }
        .orientation-card.active i { color: var(--primary); }
        .orientation-card input { display: none; }
        .orientation-card small { display: block; color: var(--neutral-500); font-size: 0.7rem; margin-top: 0.15rem; }

        .residence-lock-note { display: none; font-size: 0.75rem; color: var(--info); background: var(--info-soft); padding: 0.5rem 0.75rem; border-radius: var(--radius-md); margin-top: 0.5rem; }
        .residence-lock-note.show { display: block; }

        .color-row { display: flex; gap: 0.75rem; }
        .color-row .color-field { flex: 1; }

        .preview-swatch-card { border-radius: var(--radius-lg); padding: 1rem; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; color: #fff; font-size: 0.8rem; min-height: 130px; box-shadow: var(--shadow-md); }

        .form-check-label { font-size: 0.875rem; color: var(--neutral-700); }

        .current-bg-preview { border: 1px dashed var(--neutral-300); border-radius: var(--radius-lg); padding: 0.6rem; display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; }
        .current-bg-preview img { width: 72px; height: 44px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--neutral-200); background: var(--neutral-100); }
        .current-bg-preview .bg-meta { flex: 1; font-size: 0.75rem; color: var(--neutral-600); }
        .current-bg-preview .bg-meta strong { display: block; color: var(--neutral-800); font-size: 0.8rem; }

        .btn { border-radius: var(--radius-lg); padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }
        .btn-outline-danger { background: transparent; border: 1px solid var(--danger); color: var(--danger); }
        .btn-outline-danger:hover { background: var(--danger-soft); }

        @media (max-width: 480px) {
            .card-body-custom { padding: 1rem; }
            .card-header-custom { flex-direction: column; align-items: stretch; text-align: center; }
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
                    <li class="breadcrumb-item"><a href="templates.php">Templates</a></li>
                    <li class="breadcrumb-item"><a href="view_template.php?id=<?= $templateId ?>"><?= htmlspecialchars($template['name']) ?></a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle mt-1"></i>
                    <div>
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 mt-1">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <div class="main-card">
                <div class="card-header-custom">
                    <div>
                        <h5><i class="fas fa-edit"></i>Edit Template</h5>
                        <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">Update the template's basics. To reposition fields, open the Template Designer.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="design_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-palette me-1"></i>Open Designer</a>
                        <a href="view_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Template</a>
                    </div>
                </div>

                <div class="card-body-custom">
                    <form method="post" enctype="multipart/form-data" id="editTemplateForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-4">
                            <!-- Basic info -->
                            <div class="col-md-6">
                                <h6 class="section-title"><i class="fas fa-info-circle"></i>Basic Information</h6>

                                <div class="mb-3">
                                    <label class="form-label">Template Name <span class="required">*</span></label>
                                    <input type="text" name="name" class="form-control" required
                                           value="<?= htmlspecialchars($formData['name']) ?>" placeholder="e.g. Employee ID – Blue">
                                </div>

                                <?php if ($isSuperAdmin): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Organization <span class="required">*</span></label>
                                        <select name="organization_id" id="organizationSelect" class="form-select" required>
                                            <option value="">Select Organization</option>
                                            <?php foreach ($organizations as $org): ?>
                                                <option value="<?= (int)$org['id'] ?>"
                                                        data-project="<?= htmlspecialchars($org['project_type'] ?? '') ?>"
                                                        <?= (string)$formData['organization_id'] === (string)$org['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($org['organization_name']) ?>
                                                    <?php if ($org['project_type']): ?> (<?= ucfirst($org['project_type']) ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Residence organizations can only use Landscape templates. Moving this template to a different organization will change who can use it.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3">
                                        <label class="form-label">Organization</label>
                                        <input type="text" class="form-control" readonly
                                               value="<?= htmlspecialchars($userOrg['organization_name'] ?? 'Default Organization') ?>">
                                        <input type="hidden" name="organization_id" value="<?= $formData['organization_id'] ?>">
                                        <input type="hidden" id="orgProjectTypeHidden" value="<?= htmlspecialchars($userOrg['project_type'] ?? '') ?>">
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3" placeholder="Optional notes about this template"><?= htmlspecialchars($formData['description']) ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Background Image</label>
                                    <?php if (!empty($currentBackground)): ?>
                                        <div class="current-bg-preview">
                                            <img src="../<?= htmlspecialchars(ltrim($currentBackground, '/')) ?>" alt="Current background">
                                            <div class="bg-meta">
                                                <strong>Current background is set</strong>
                                                Uploading a new file below will replace it.
                                            </div>
                                            <label class="form-check-label" style="display:flex;align-items:center;gap:0.35rem;font-size:0.75rem;white-space:nowrap;">
                                                <input type="checkbox" name="remove_background" value="1" id="removeBgCheck">
                                                Remove
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text mb-2">No background image set yet.</div>
                                    <?php endif; ?>
                                    <input type="file" name="background" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control">
                                    <div class="form-text">Optional. JPG, PNG, WEBP or GIF, max 5MB.</div>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="isDefaultCheck" value="1" <?= $formData['is_default'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="isDefaultCheck">Set as default template for this organization</label>
                                </div>
                            </div>

                            <!-- Layout & style -->
                            <div class="col-md-6">
                                <h6 class="section-title"><i class="fas fa-ruler-combined"></i>Layout & Style</h6>

                                <div class="mb-3">
                                    <label class="form-label">Orientation <span class="required">*</span></label>
                                    <div class="orientation-toggle">
                                        <label class="orientation-card <?= $formData['orientation'] === 'portrait' ? 'active' : '' ?>" data-orientation="portrait">
                                            <input type="radio" name="orientation" value="portrait" <?= $formData['orientation'] === 'portrait' ? 'checked' : '' ?>>
                                            <i class="fas fa-arrows-alt-v"></i>
                                            Portrait
                                            <small>533 × 864</small>
                                        </label>
                                        <label class="orientation-card <?= $formData['orientation'] === 'landscape' ? 'active' : '' ?>" data-orientation="landscape">
                                            <input type="radio" name="orientation" value="landscape" <?= $formData['orientation'] === 'landscape' ? 'checked' : '' ?>>
                                            <i class="fas fa-arrows-alt-h"></i>
                                            Landscape
                                            <small>864 × 533</small>
                                        </label>
                                    </div>
                                    <div class="residence-lock-note" id="residenceLockNote">
                                        <i class="fas fa-info-circle me-1"></i>This organization is a Residence project — orientation is locked to Landscape.
                                    </div>
                                    <div class="form-text mt-1"><i class="fas fa-triangle-exclamation me-1"></i>Changing orientation or size may require re-adjusting field positions in the Designer.</div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label">Card Width (px)</label>
                                        <input type="number" name="card_width" id="cardWidthInput" class="form-control" min="50"
                                               value="<?= $formData['card_width'] ?: 533 ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Card Height (px)</label>
                                        <input type="number" name="card_height" id="cardHeightInput" class="form-control" min="50"
                                               value="<?= $formData['card_height'] ?: 864 ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Font</label>
                                    <select name="font" class="form-select">
                                        <?php foreach ($fontOptions as $fo): ?>
                                            <option value="<?= htmlspecialchars($fo) ?>" <?= $formData['font'] === $fo ? 'selected' : '' ?>><?= htmlspecialchars($fo) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="color-row mb-3">
                                    <div class="color-field">
                                        <label class="form-label">Primary</label>
                                        <input type="color" name="primary_color" id="primaryColorInput" class="form-control form-control-color w-100" value="<?= htmlspecialchars($formData['primary_color']) ?>">
                                    </div>
                                    <div class="color-field">
                                        <label class="form-label">Secondary</label>
                                        <input type="color" name="secondary_color" id="secondaryColorInput" class="form-control form-control-color w-100" value="<?= htmlspecialchars($formData['secondary_color']) ?>">
                                    </div>
                                    <div class="color-field">
                                        <label class="form-label">Text</label>
                                        <input type="color" name="text_color" id="textColorInput" class="form-control form-control-color w-100" value="<?= htmlspecialchars($formData['text_color']) ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div id="colorPreviewCard" class="preview-swatch-card" style="background: linear-gradient(135deg, <?= htmlspecialchars($formData['primary_color']) ?>, <?= htmlspecialchars($formData['secondary_color']) ?>); color: <?= htmlspecialchars($formData['text_color']) ?>;">
                                        <i class="fas fa-id-card mb-2" style="font-size:1.6rem;"></i>
                                        Color Preview
                                    </div>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="mirror_print" id="mirrorPrintCheck" value="1" <?= $formData['mirror_print'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="mirrorPrintCheck">Enable mirror print (for badge printers requiring a mirrored back side)</label>
                                </div>
                            </div>

                            <!-- Submit -->
                            <div class="col-12">
                                <hr>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Changes
                                    </button>
                                    <a href="view_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary">
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
(function () {
    const orientationCards = document.querySelectorAll('.orientation-card');
    const widthInput = document.getElementById('cardWidthInput');
    const heightInput = document.getElementById('cardHeightInput');
    const residenceNote = document.getElementById('residenceLockNote');
    const orgSelect = document.getElementById('organizationSelect');
    const orgProjectTypeHidden = document.getElementById('orgProjectTypeHidden');

    const sizePresets = {
        portrait: [533, 864],
        landscape: [864, 533]
    };

    // Existing template already has a saved size, so don't silently overwrite it
    // on page load — only auto-apply presets once the user actively changes things.
    let userEditedSize = true;
    [widthInput, heightInput].forEach((el) => {
        el.addEventListener('input', () => { userEditedSize = true; });
    });

    function setOrientation(value, { force = false } = {}) {
        orientationCards.forEach((card) => {
            const isActive = card.dataset.orientation === value;
            card.classList.toggle('active', isActive);
            card.querySelector('input').checked = isActive;
        });
        if (force) {
            const preset = sizePresets[value] || sizePresets.portrait;
            widthInput.value = preset[0];
            heightInput.value = preset[1];
        }
    }

    orientationCards.forEach((card) => {
        card.addEventListener('click', () => {
            if (card.classList.contains('locked')) return;
            userEditedSize = false; // user is switching orientation deliberately; offer the preset size
            setOrientation(card.dataset.orientation, { force: true });
            userEditedSize = true;
        });
    });

    function applyResidenceLock(projectType) {
        const isResidence = (projectType || '').toLowerCase() === 'residence';
        residenceNote.classList.toggle('show', isResidence);
        orientationCards.forEach((card) => {
            const isPortrait = card.dataset.orientation === 'portrait';
            card.classList.toggle('locked', isResidence && isPortrait);
            card.style.opacity = (isResidence && isPortrait) ? '0.45' : '1';
            card.style.cursor = (isResidence && isPortrait) ? 'not-allowed' : 'pointer';
        });
        if (isResidence) {
            const currentlyPortrait = document.querySelector('.orientation-card[data-orientation="portrait"]').classList.contains('active');
            if (currentlyPortrait) {
                setOrientation('landscape', { force: true });
            }
        }
    }

    if (orgSelect) {
        orgSelect.addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            applyResidenceLock(opt ? opt.dataset.project : '');
        });
        // Apply on load in case the pre-selected organization is a residence org
        const initialOpt = orgSelect.options[orgSelect.selectedIndex];
        if (initialOpt) applyResidenceLock(initialOpt.dataset.project);
    } else if (orgProjectTypeHidden) {
        applyResidenceLock(orgProjectTypeHidden.value);
    }

    // Live color preview
    const primary = document.getElementById('primaryColorInput');
    const secondary = document.getElementById('secondaryColorInput');
    const text = document.getElementById('textColorInput');
    const previewCard = document.getElementById('colorPreviewCard');

    function updatePreview() {
        previewCard.style.background = 'linear-gradient(135deg, ' + primary.value + ', ' + secondary.value + ')';
        previewCard.style.color = text.value;
    }
    [primary, secondary, text].forEach((el) => el.addEventListener('input', updatePreview));

    // If "Remove" background is checked, disable the new-upload field's confusion by clarifying via disabling remove when a file is chosen
    const removeBgCheck = document.getElementById('removeBgCheck');
    const bgFileInput = document.querySelector('input[name="background"]');
    if (removeBgCheck && bgFileInput) {
        bgFileInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                removeBgCheck.checked = false;
            }
        });
    }

    // Basic client-side validation
    document.getElementById('editTemplateForm').addEventListener('submit', function (e) {
        const name = this.querySelector('input[name="name"]');
        if (!name.value.trim()) {
            e.preventDefault();
            alert('Please enter a template name.');
            name.focus();
            return false;
        }
        if (orgSelect && !orgSelect.value) {
            e.preventDefault();
            alert('Please select an organization.');
            orgSelect.focus();
            return false;
        }
        return true;
    });
})();
</script>
</body>
</html>