<?php
/**
 * View Template — read-only detail page for a single card template.
 * Uses the same card_renderer_html() pipeline as design_template.php / templates.php
 * so the preview shown here is always in sync with the saved layout.
 *
 * NOTE: This file was not provided in the original upload, so it has been
 * built to match the conventions of templates.php / design_template.php
 * (same includes, CSRF pattern, permission checks, styling). If you already
 * have a view_template.php, share it and I will patch that one instead.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/template_mgmt_helpers.php';
require_once __DIR__ . '/template_functions.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Templates', 'View');

ensure_card_renderer_schema($pdo);

$isSuperAdmin = auth_is_super_admin($authUser);
$canEdit = has_permission($pdo, 'Templates', 'Edit');
$canCreate = has_permission($pdo, 'Templates', 'Create');
$canDelete = has_permission($pdo, 'Templates', 'Delete');
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);

$page_title = 'View Template';
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($templateId <= 0) {
    $_SESSION['error'] = 'Invalid template ID';
    header('Location: templates.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ─── Load template ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT t.*, o.organization_name, o.project_type, o.logo AS org_logo
     FROM card_templates t
     LEFT JOIN organizations o ON t.organization_id = o.id
     WHERE t.id = ?
     LIMIT 1'
);
$stmt->execute([$templateId]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$template) {
    $_SESSION['error'] = 'Template not found';
    header('Location: templates.php');
    exit();
}

$isArchived = (int)($template['status'] ?? 1) === 0 || !empty($template['deleted_at']);

if ((int)($template['organization_id'] ?? 0) > 0
    && !user_can_access_organization($authUser, $template['organization_id'])) {
    $_SESSION['error'] = 'You do not have access to this template';
    header('Location: templates.php');
    exit();
}

$orgId = (int)($template['organization_id'] ?? 0);
$canManage = template_user_can_manage($pdo, $authUser, $template);

// ─── POST actions (archive / restore / duplicate / set default) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token.';
        header('Location: view_template.php?id=' . $templateId);
        exit();
    }

    if (isset($_POST['delete_template']) && $canDelete && $canManage) {
        $result = template_archive($pdo, $templateId);
        if ($result['success']) {
            $_SESSION['message'] = 'Template archived successfully. Existing members and cards are preserved.';
            template_log_audit($pdo, $userId, $orgId ?: null, 'Archived template', "Template ID: $templateId");
        } else {
            $_SESSION['error'] = $result['error'] ?? 'Failed to archive template.';
        }
        header('Location: view_template.php?id=' . $templateId);
        exit();
    }

    if (isset($_POST['restore_template']) && $canEdit && $canManage) {
        $result = template_restore($pdo, $templateId);
        if ($result['success']) {
            $_SESSION['message'] = 'Template restored successfully.';
            template_log_audit($pdo, $userId, $orgId ?: null, 'Restored template', "Template ID: $templateId");
        } else {
            $_SESSION['error'] = $result['error'] ?? 'Failed to restore template.';
        }
        header('Location: view_template.php?id=' . $templateId);
        exit();
    }

    if (isset($_POST['set_default']) && $canEdit && $canManage) {
        $result = template_set_default($pdo, $templateId);
        if ($result['success']) {
            $_SESSION['message'] = 'Default template updated for this organization.';
            template_log_audit($pdo, $userId, $orgId ?: null, 'Set default template', "Template ID: $templateId");
        } else {
            $_SESSION['error'] = $result['error'] ?? 'Failed to set default template.';
        }
        header('Location: view_template.php?id=' . $templateId);
        exit();
    }

    if (isset($_POST['duplicate_template']) && $canCreate && $canManage) {
        $result = template_duplicate($pdo, $templateId, $userId);
        if ($result['success']) {
            $_SESSION['message'] = 'Template duplicated as "' . ($result['name'] ?? 'Copy') . '".';
            template_log_audit($pdo, $userId, $orgId ?: null, 'Duplicated template', "Source ID: $templateId, New ID: {$result['new_id']}");
            header('Location: view_template.php?id=' . (int)$result['new_id']);
            exit();
        }
        $_SESSION['error'] = $result['error'] ?? 'Failed to duplicate template.';
        header('Location: view_template.php?id=' . $templateId);
        exit();
    }
}

// ─── Load layout objects (front + back) ────────────────────────────────────
$fieldsStmt = $pdo->prepare(
    'SELECT * FROM template_fields WHERE template_id = ? AND archived_at IS NULL ORDER BY z_index ASC, id'
);
$fieldsStmt->execute([$templateId]);
$fields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$frontFieldCount = count(array_filter($fields, static fn($f) => ($f['side'] ?? 'front') === 'front'));
$backFieldCount = count(array_filter($fields, static fn($f) => ($f['side'] ?? 'front') === 'back'));

// ─── Input field definitions (for the "Fields used" list) ─────────────────
$allInputFields = get_template_input_fields($pdo, $templateId, true);

$usedKeys = array_unique(array_filter(array_map(
    fn($f) => trim((string)($f['field_key'] ?? '')),
    $fields
)));

$inputFields = array_values(array_filter(
    $allInputFields,
    fn($f) => in_array((string)$f['field_key'], $usedKeys, true)
));

// ─── Member / usage stats ──────────────────────────────────────────────────
$memberCount = 0;
try {
    $mc = $pdo->prepare('SELECT COUNT(*) FROM id_members WHERE template_id = ? AND deleted_at IS NULL');
    $mc->execute([$templateId]);
    $memberCount = (int)$mc->fetchColumn();
} catch (Throwable $e) { /* table may not exist in some setups */ }

$recentMembers = [];
if ($memberCount > 0) {
    try {
        $rm = $pdo->prepare(
            'SELECT id, name, unique_id, created_at FROM id_members
             WHERE template_id = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 5'
        );
        $rm->execute([$templateId]);
        $recentMembers = $rm->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* ignore */ }
}

// ─── Build sample preview (front + back) using the shared renderer ────────
$previewMember = [
    'name' => 'John Doe',
    'unique_id' => 'ID-' . str_pad((string)$templateId, 3, '0', STR_PAD_LEFT) . '001',
    'organization_name' => $template['organization_name'] ?? 'Organization',
    'photo' => '',
    'org_logo' => $template['org_logo'] ?? '',
    'dynamic_fields' => [],
    'member_type' => 'employee',
    'expiry_date' => date('Y-m-d', strtotime('+1 year')),
];

// Try to use a real recent member for a more representative preview, if any exist.
if (!empty($recentMembers)) {
    try {
        $realMember = card_renderer_member($pdo, (int)$recentMembers[0]['id']);
        $previewMember = array_merge($previewMember, $realMember, [
            'org_logo' => $realMember['org_logo'] ?? ($template['org_logo'] ?? ''),
        ]);
    } catch (Throwable $e) { /* keep sample */ }
}

$definitions = card_renderer_definitions($pdo, $templateId);

$isPortrait = strtolower((string)($template['orientation'] ?? 'portrait')) !== 'landscape';
$cardWidth = (int)($template['card_width'] ?: ($isPortrait ? 533 : 864));
$cardHeight = (int)($template['card_height'] ?: ($isPortrait ? 864 : 533));
if ($cardWidth < 50) $cardWidth = $isPortrait ? 533 : 864;
if ($cardHeight < 50) $cardHeight = $isPortrait ? 864 : 533;
$rendererTemplate = $template;
$rendererTemplate['card_width'] = $cardWidth;
$rendererTemplate['card_height'] = $cardHeight;

$frontHtml = null;
$backHtml = null;
try {
    $frontHtml = card_renderer_html($rendererTemplate, $previewMember, $definitions, $fields, 'front', '../');
    $backHtml = card_renderer_html($rendererTemplate, $previewMember, $definitions, $fields, 'back', '../');
} catch (Throwable $e) {
    $frontHtml = null;
    $backHtml = null;
}

function view_template_orientation_label(string $o): string {
    return strtolower($o) === 'landscape' ? 'Landscape' : 'Portrait';
}
function view_template_orientation_icon(string $o): string {
    return strtolower($o) === 'landscape' ? 'fa-arrows-alt-h' : 'fa-arrows-alt-v';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>View Template · ID Card Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= card_renderer_css() ?>
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
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
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

        .alert { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1rem; }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .btn-close-custom { cursor: pointer; background: none; border: none; font-size: 1.25rem; color: inherit; opacity: 0.5; }
        .btn-close-custom:hover { opacity: 1; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .page-header h4 { font-weight: 700; margin: 0 0 0.25rem 0; display: flex; align-items: center; gap: 0.6rem; }
        .page-header .subtitle { color: var(--neutral-500); font-size: 0.875rem; }
        .header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

        .badge-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.7rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge-default { background: var(--success-soft); color: var(--success); }
        .badge-orientation { background: var(--info-soft); color: var(--info); }
        .badge-archived { background: var(--danger-soft); color: var(--danger); }

        .btn { border-radius: var(--radius-md); padding: 0.5rem 0.9rem; font-size: 0.85rem; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-light); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        .view-layout { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        @media (max-width: 1100px) { .view-layout { grid-template-columns: 1fr; } }

        .panel { background: #fbf8f8; border-radius: var(--radius-2xl); box-shadow: var(--shadow-md); border: 1px solid var(--neutral-200); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
        .panel h6 { font-weight: 600; color: var(--neutral-700); margin-bottom: 1rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--neutral-200); font-size: 0.9rem; }
        .panel h6 i { color: var(--primary); margin-right: 0.4rem; }

        .card-preview-stage { display: flex; flex-direction: column; align-items: center; gap: 1.25rem; }
        .card-side-block { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
        .card-side-block .side-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--neutral-500); }
.card-frame .id-card-renderer {
    width: <?= $cardWidth ?>px !important;
    height: <?= $cardHeight ?>px !important;
    transform: scale(0.65);
    transform-origin: top center;
    flex: 0 0 auto;
}
        .preview-unavailable-box { width: 260px; height: 160px; display: flex; align-items: center; justify-content: center; background: var(--neutral-100); border-radius: var(--radius-md); color: var(--neutral-500); font-size: 0.8rem; text-align: center; padding: 1rem; }

        .meta-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem 1rem; }
        .meta-item { font-size: 0.8rem; }
        .meta-item .meta-label { color: var(--neutral-500); text-transform: uppercase; font-size: 0.65rem; font-weight: 600; letter-spacing: 0.04em; display: block; margin-bottom: 0.15rem; }
        .meta-item .meta-value { color: var(--neutral-800); font-weight: 500; }
        .color-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 4px; vertical-align: -2px; margin-right: 0.35rem; border: 1px solid rgba(0,0,0,0.1); }

        .stat-mini-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
        .stat-mini { text-align: center; padding: 0.85rem 0.5rem; border-radius: var(--radius-lg); background: var(--neutral-50); border: 1px solid var(--neutral-200); }
        .stat-mini .num { font-size: 1.35rem; font-weight: 700; color: var(--primary); }
        .stat-mini .lbl { font-size: 0.65rem; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.04em; }

        .field-chip-list { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .field-chip { font-size: 0.72rem; padding: 0.25rem 0.6rem; border-radius: var(--radius-sm); background: var(--neutral-100); color: var(--neutral-600); border: 1px solid var(--neutral-200); }
        .field-chip i { margin-right: 0.3rem; color: var(--primary); }

        .member-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--neutral-100); font-size: 0.8rem; }
        .member-row:last-child { border-bottom: none; }
        .member-row .m-name { font-weight: 500; color: var(--neutral-800); }
        .member-row .m-id { color: var(--neutral-500); font-size: 0.72rem; }

        .empty-note { color: var(--neutral-500); font-size: 0.8rem; text-align: center; padding: 1rem 0; }
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
                    <li class="breadcrumb-item active"><?= htmlspecialchars($template['name']) ?></li>
                </ol>
            </nav>

            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i><div class="flex-1"><?= htmlspecialchars($message) ?></div><button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><div class="flex-1"><?= htmlspecialchars($error) ?></div><button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button></div>
            <?php endif; ?>

            <div class="page-header">
                <div>
                    <h4>
                        <i class="fas fa-id-card text-primary"></i>
                        <?= htmlspecialchars($template['name']) ?>
                        <?php if ((int)$template['is_default'] === 1): ?>
                            <span class="badge-pill badge-default"><i class="fas fa-star"></i> Default</span>
                        <?php endif; ?>
                        <span class="badge-pill badge-orientation">
                            <i class="fas <?= view_template_orientation_icon($template['orientation'] ?? 'portrait') ?>"></i>
                            <?= view_template_orientation_label($template['orientation'] ?? 'portrait') ?>
                        </span>
                        <?php if ($isArchived): ?>
                            <span class="badge-pill badge-archived"><i class="fas fa-archive"></i> Archived</span>
                        <?php endif; ?>
                    </h4>
                    <div class="subtitle">
                        <i class="fas fa-building"></i> <?= htmlspecialchars($template['organization_name'] ?? 'Global') ?>
                        &nbsp;·&nbsp; Layout v<?= (int)($template['layout_version'] ?? 1) ?>
                        &nbsp;·&nbsp; Updated <?= !empty($template['updated_at']) ? date('M j, Y', strtotime($template['updated_at'])) : '—' ?>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="templates.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                    <?php if (!$isArchived): ?>
                        <?php if ($canEdit): ?>
                            <a href="edit_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary"><i class="fas fa-edit"></i> Edit</a>
                            <a href="design_template.php?id=<?= $templateId ?>" class="btn btn-primary"><i class="fas fa-palette"></i> Design</a>
                        <?php endif; ?>
                        <?php if ($canCreate && $canManage): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" name="duplicate_template" class="btn btn-warning"><i class="fas fa-copy"></i> Duplicate</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canEdit && $canManage && (int)$template['is_default'] !== 1): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" name="set_default" class="btn btn-success"><i class="fas fa-star"></i> Set Default</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canDelete && $canManage): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this template? Existing members and cards are preserved.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" name="delete_template" class="btn btn-danger"><i class="fas fa-archive"></i> Archive</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($canEdit && $canManage): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" name="restore_template" class="btn btn-success"><i class="fas fa-undo"></i> Restore</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="view-layout">
                <!-- LEFT: live preview -->
                <div>
                    <div class="panel">
                        <h6><i class="fas fa-eye"></i>Live Preview</h6>
                        <div class="card-preview-stage">
                            <div class="card-side-block">
                                <span class="side-label">Front</span>
                                <?php if ($frontHtml !== null): ?>
                                    <div class="card-frame"><?= $frontHtml ?></div>
                                <?php else: ?>
                                    <div class="preview-unavailable-box"><i class="fas fa-image d-block mb-1"></i>Preview unavailable</div>
                                <?php endif; ?>
                            </div>
                            <div class="card-side-block">
                                <span class="side-label">Back</span>
                                <?php if ($backHtml !== null): ?>
                                    <div class="card-frame"><?= $backHtml ?></div>
                                <?php else: ?>
                                    <div class="preview-unavailable-box"><i class="fas fa-image d-block mb-1"></i>Preview unavailable</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="text-muted small text-center mt-3 mb-0">
                            <?= !empty($recentMembers) ? 'Showing the most recently added member as a sample.' : 'Showing sample placeholder data — no members registered on this template yet.' ?>
                        </p>
                    </div>

                    <div class="panel">
                        <h6><i class="fas fa-cogs"></i>Layout Objects</h6>
                        <div class="stat-mini-grid">
                            <div class="stat-mini">
                                <div class="num"><?= count($fields) ?></div>
                                <div class="lbl">Total Objects</div>
                            </div>
                            <div class="stat-mini">
                                <div class="num"><?= $frontFieldCount ?></div>
                                <div class="lbl">Front Side</div>
                            </div>
                            <div class="stat-mini">
                                <div class="num"><?= $backFieldCount ?></div>
                                <div class="lbl">Back Side</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: metadata + fields + usage -->
                <div>
                    <div class="panel">
                        <h6><i class="fas fa-info-circle"></i>Template Details</h6>
                        <?php if (!empty($template['description'])): ?>
                            <p class="small text-muted mb-3"><?= nl2br(htmlspecialchars($template['description'])) ?></p>
                        <?php endif; ?>
                        <div class="meta-grid">
                            <div class="meta-item">
                                <span class="meta-label">Card Size</span>
                                <span class="meta-value"><?= $cardWidth ?> × <?= $cardHeight ?> px</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Font</span>
                                <span class="meta-value"><?= htmlspecialchars($template['font'] ?? 'Inter') ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Primary Color</span>
                                <span class="meta-value"><span class="color-swatch" style="background:<?= htmlspecialchars($template['primary_color'] ?? '#0a1a2f') ?>"></span><?= htmlspecialchars($template['primary_color'] ?? '#0a1a2f') ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Secondary Color</span>
                                <span class="meta-value"><span class="color-swatch" style="background:<?= htmlspecialchars($template['secondary_color'] ?? '#1e3a5f') ?>"></span><?= htmlspecialchars($template['secondary_color'] ?? '#1e3a5f') ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Text Color</span>
                                <span class="meta-value"><span class="color-swatch" style="background:<?= htmlspecialchars($template['text_color'] ?? '#ffffff') ?>"></span><?= htmlspecialchars($template['text_color'] ?? '#ffffff') ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Mirror Print</span>
                                <span class="meta-value"><?= !empty($template['mirror_print']) ? 'Enabled' : 'Disabled' ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Downloads</span>
                                <span class="meta-value"><?= (int)($template['downloads'] ?? 0) ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Created</span>
                                <span class="meta-value"><?= !empty($template['created_at']) ? date('M j, Y', strtotime($template['created_at'])) : '—' ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <h6><i class="fas fa-list"></i>Input Fields Used</h6>
                        <?php if (!empty($inputFields)): ?>
                            <div class="field-chip-list">
                                <?php foreach ($inputFields as $inf): ?>
                                    <span class="field-chip"><i class="fas fa-tag"></i><?= htmlspecialchars($inf['field_label'] ?? $inf['field_key']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="empty-note mb-0">No custom input fields configured for this template yet.</p>
                        <?php endif; ?>
                    </div>

                    <div class="panel">
                        <h6><i class="fas fa-users"></i>Usage</h6>
                        <div class="meta-item mb-3">
                            <span class="meta-label">Members on this template</span>
                            <span class="meta-value"><?= $memberCount ?></span>
                        </div>
                        <?php if (!empty($recentMembers)): ?>
                            <div>
                                <?php foreach ($recentMembers as $rm): ?>
                                    <div class="member-row">
                                        <span class="m-name"><?= htmlspecialchars($rm['name']) ?></span>
                                        <span class="m-id"><?= htmlspecialchars($rm['unique_id']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($memberCount > count($recentMembers)): ?>
                                <p class="small text-muted mt-2 mb-0">+ <?= $memberCount - count($recentMembers) ?> more</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="empty-note mb-0">No members have been registered on this template yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>