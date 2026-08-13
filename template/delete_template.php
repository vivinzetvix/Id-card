<?php
/**
 * Delete Template — standalone confirmation page.
 * "Delete" in this system is a soft delete (archive): status = 0, deleted_at set,
 * existing members/cards are preserved, matching the inline archive action already
 * in templates.php / view_template.php. This page adds:
 *   - a dedicated confirmation screen (usage warning, member/field counts)
 *   - a permanent (hard) delete option for templates that are ALREADY archived,
 *     restricted to super admins, which removes the row, its layout fields, and
 *     its background image file. Regular archiving never hard-deletes data.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/template_mgmt_helpers.php';
require_once __DIR__ . '/template_functions.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Templates', 'Delete');

$page_title = 'Delete Template';
$isSuperAdmin = auth_is_super_admin($authUser);
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);

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
    $_SESSION['error'] = 'You do not have permission to delete this template';
    header('Location: templates.php');
    exit();
}

$orgId = (int)($template['organization_id'] ?? 0);
$isArchived = (int)($template['status'] ?? 1) === 0 || !empty($template['deleted_at']);
$isDefault = !empty($template['is_default']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$errors = [];

// ─── Usage stats (for the warning banner) ──────────────────────────────────
$memberCount = 0;
try {
    $mc = $pdo->prepare('SELECT COUNT(*) FROM id_members WHERE template_id = ? AND deleted_at IS NULL');
    $mc->execute([$templateId]);
    $memberCount = (int)$mc->fetchColumn();
} catch (Throwable $e) { /* table may not exist in some setups */ }

$fieldCount = 0;
try {
    $fc = $pdo->prepare('SELECT COUNT(*) FROM template_fields WHERE template_id = ? AND archived_at IS NULL');
    $fc->execute([$templateId]);
    $fieldCount = (int)$fc->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

// ─── POST actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } elseif ($isDefault && ($_POST['action'] ?? '') === 'archive') {
        $errors[] = 'This is the default template for its organization. Set another template as default before archiving it.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'archive' && !$isArchived) {
            $result = template_archive($pdo, $templateId);
            if ($result['success']) {
                template_log_audit($pdo, $userId, $orgId ?: null, 'Archived template', "Template ID: $templateId, Name: {$template['name']}");
                $_SESSION['message'] = 'Template "' . $template['name'] . '" archived. Existing members and cards are preserved.';
                header('Location: templates.php');
                exit();
            }
            $errors[] = $result['error'] ?? 'Failed to archive template.';
        } elseif ($action === 'permanent' && $isArchived && $isSuperAdmin) {
            try {
                $pdo->beginTransaction();

                $delFields = $pdo->prepare('DELETE FROM template_fields WHERE template_id = ?');
                $delFields->execute([$templateId]);

                $delTemplate = $pdo->prepare('DELETE FROM card_templates WHERE id = ?');
                $delTemplate->execute([$templateId]);

                $pdo->commit();

                // Best-effort cleanup of the stored background image, after the DB commit succeeds.
                if (!empty($template['background_image'])) {
                    $bgFull = dirname(__DIR__) . '/' . ltrim((string)$template['background_image'], '/');
                    if (is_file($bgFull)) {
                        @unlink($bgFull);
                    }
                }

                template_log_audit($pdo, $userId, $orgId ?: null, 'Permanently deleted template', "Template ID: $templateId, Name: {$template['name']}");
                $_SESSION['message'] = 'Template "' . $template['name'] . '" permanently deleted.';
                header('Location: templates.php');
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to permanently delete template. It may still be referenced by member records.';
            }
        } elseif ($action === 'permanent' && !$isSuperAdmin) {
            $errors[] = 'Only a super admin can permanently delete a template.';
        } else {
            $errors[] = 'Invalid or unavailable action for this template\'s current status.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Delete Template · ID Card Generator</title>
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
            --warning: #f4b740;
            --warning-soft: #fef5e0;
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
        .dashboard-content { padding: 1.5rem 2rem; max-width: 900px; margin: 0 auto; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        @media (max-width: 768px) { .dashboard-content { padding: 1rem; } }

        .breadcrumb { display: flex; gap: 0.5rem; list-style: none; padding: 0; margin: 0 0 1.25rem 0; font-size: 0.875rem; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        .alert { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.85rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1rem; }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert-warning { background: var(--warning-soft); color: #92620a; }
        .alert-info { background: var(--info-soft); color: #1d4ed8; }
        .btn-close-custom { cursor: pointer; background: none; border: none; font-size: 1.25rem; color: inherit; opacity: 0.5; }
        .btn-close-custom:hover { opacity: 1; }

        .main-card { background: white; border-radius: var(--radius-2xl); box-shadow: var(--shadow-md); border: 1px solid var(--neutral-200); overflow: hidden; }
        .card-header-custom { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--neutral-200); background: var(--neutral-50); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .card-header-custom h5 { font-weight: 600; margin: 0; }
        .card-header-custom h5 i { color: var(--danger); margin-right: 0.5rem; }
        .card-body-custom { padding: 1.5rem; }

        .tpl-summary { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--neutral-50); border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); margin-bottom: 1.25rem; }
        .tpl-summary .swatch { width: 52px; height: 52px; border-radius: var(--radius-md); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.3rem; }
        .tpl-summary .name { font-weight: 600; font-size: 1rem; margin: 0; }
        .tpl-summary .meta { font-size: 0.8rem; color: var(--neutral-500); margin: 0.15rem 0 0; }
        .status-badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; margin-left: 0.5rem; }
        .status-badge.archived { background: var(--neutral-200); color: var(--neutral-600); }
        .status-badge.active { background: var(--success-soft); color: var(--success); }

        .stat-mini-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stat-mini { background: var(--neutral-50); border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); padding: 0.85rem; text-align: center; }
        .stat-mini .num { font-size: 1.4rem; font-weight: 700; color: var(--primary); }
        .stat-mini .lbl { font-size: 0.75rem; color: var(--neutral-500); }

        .danger-zone { border: 1px solid var(--danger-soft); background: #fff8f8; border-radius: var(--radius-lg); padding: 1rem 1.25rem; margin-top: 1.25rem; }
        .danger-zone h6 { color: var(--danger); font-weight: 600; font-size: 0.875rem; margin-bottom: 0.5rem; }
        .danger-zone p { font-size: 0.8rem; color: var(--neutral-600); margin-bottom: 0.75rem; }

        .btn { border-radius: var(--radius-lg); padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }
        .btn-outline-danger { background: transparent; border: 1px solid var(--danger); color: var(--danger); }
        .btn-outline-danger:hover { background: var(--danger-soft); }

        .confirm-check { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.8rem; color: var(--neutral-700); margin-bottom: 0.85rem; }
        .confirm-check input { margin-top: 0.2rem; }

        @media (max-width: 480px) {
            .card-body-custom { padding: 1rem; }
            .stat-mini-grid { grid-template-columns: 1fr 1fr; }
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
                    <li class="breadcrumb-item active">Delete</li>
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
                        <h5><i class="fas fa-<?= $isArchived ? 'trash-alt' : 'archive' ?>"></i><?= $isArchived ? 'Permanently Delete Template' : 'Archive Template' ?></h5>
                        <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                            <?= $isArchived
                                ? 'This template is already archived. You can restore it, or a super admin can remove it for good.'
                                : 'Archiving hides this template from new registrations. Existing members and cards are kept.' ?>
                        </p>
                    </div>
                    <a href="view_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Template</a>
                </div>

                <div class="card-body-custom">
                    <div class="tpl-summary">
                        <div class="swatch" style="background: linear-gradient(135deg, <?= htmlspecialchars($template['primary_color'] ?? '#0a1a2f') ?>, <?= htmlspecialchars($template['secondary_color'] ?? '#1e3a5f') ?>);">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div>
                            <p class="name">
                                <?= htmlspecialchars($template['name']) ?>
                                <span class="status-badge <?= $isArchived ? 'archived' : 'active' ?>"><?= $isArchived ? 'Archived' : 'Active' ?></span>
                                <?php if ($isDefault): ?><span class="status-badge active" style="background:var(--info-soft);color:var(--info);">Default</span><?php endif; ?>
                            </p>
                            <p class="meta">
                                <?= htmlspecialchars($template['organization_name'] ?? 'No organization') ?>
                                &middot; <?= ucfirst($template['orientation'] ?? 'portrait') ?>
                            </p>
                        </div>
                    </div>

                    <div class="stat-mini-grid">
                        <div class="stat-mini">
                            <div class="num"><?= $memberCount ?></div>
                            <div class="lbl">Members using this template</div>
                        </div>
                        <div class="stat-mini">
                            <div class="num"><?= $fieldCount ?></div>
                            <div class="lbl">Layout fields</div>
                        </div>
                    </div>

                    <?php if (!$isArchived): ?>
                        <?php if ($isDefault): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-star mt-1"></i>
                                <div>This is the <strong>default template</strong> for its organization. Set a different template as default before archiving this one.</div>
                            </div>
                        <?php else: ?>
                            <?php if ($memberCount > 0): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mt-1"></i>
                                    <div>This template is used by <strong><?= $memberCount ?></strong> member<?= $memberCount === 1 ? '' : 's' ?>. Archiving will hide it from new registrations, but their records and generated cards stay exactly as they are.</div>
                                </div>
                            <?php endif; ?>

                            <form method="post" id="archiveForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="archive">
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-danger"><i class="fas fa-archive me-1"></i>Archive Template</button>
                                    <a href="view_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <a href="templates.php?show_archived=1" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Go Restore It Instead</a>
                        </div>

                        <?php if ($isSuperAdmin): ?>
                            <div class="danger-zone">
                                <h6><i class="fas fa-triangle-exclamation me-1"></i>Permanent Delete (Super Admin Only)</h6>
                                <p>
                                    This removes the template, its layout fields, and its uploaded background image beyond recovery.
                                    <?php if ($memberCount > 0): ?>
                                        <strong>This template still has <?= $memberCount ?> member record<?= $memberCount === 1 ? '' : 's' ?> pointing to it</strong> — deletion may be blocked or leave those records without a template.
                                    <?php endif; ?>
                                </p>
                                <form method="post" id="permanentForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="permanent">
                                    <label class="confirm-check">
                                        <input type="checkbox" id="confirmPermanent" required>
                                        I understand this cannot be undone and I want to permanently delete "<?= htmlspecialchars($template['name']) ?>".
                                    </label>
                                    <button type="submit" class="btn btn-outline-danger" id="permanentBtn" disabled><i class="fas fa-trash-alt me-1"></i>Permanently Delete</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mt-1"></i>
                                <div>Only a super admin can permanently delete an archived template.</div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const archiveForm = document.getElementById('archiveForm');
    if (archiveForm) {
        archiveForm.addEventListener('submit', function (e) {
            if (!confirm('Archive this template? It will be hidden from new registrations; existing members and cards are kept.')) {
                e.preventDefault();
            }
        });
    }

    const confirmPermanent = document.getElementById('confirmPermanent');
    const permanentBtn = document.getElementById('permanentBtn');
    const permanentForm = document.getElementById('permanentForm');
    if (confirmPermanent && permanentBtn) {
        confirmPermanent.addEventListener('change', function () {
            permanentBtn.disabled = !this.checked;
        });
    }
    if (permanentForm) {
        permanentForm.addEventListener('submit', function (e) {
            if (!confirm('This permanently deletes the template and cannot be undone. Continue?')) {
                e.preventDefault();
            }
        });
    }
})();
</script>
</body>
</html>