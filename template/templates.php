<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/template_mgmt_helpers.php';
require_once __DIR__ . '/template_functions.php';
require_once __DIR__ . '/../includes/card_renderer.php'; // ADDED: needed for live card preview

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Templates', 'View');

// Make sure template_fields / card_templates have the columns card_renderer_* expects.
ensure_card_renderer_schema($pdo); // ADDED

$page_title = 'ID Card Templates';
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

$username = $_SESSION['username'];
$userId = (int)($authUser['id'] ?? 0);
$isSuperAdmin = auth_is_super_admin($authUser);
$canEdit = has_permission($pdo, 'Templates', 'Edit');
$canCreate = has_permission($pdo, 'Templates', 'Create');
$canDelete = has_permission($pdo, 'Templates', 'Delete');

// Get organization filter
$orgFilter = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
$orientationFilter = $_GET['orientation'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');
$showArchived = !empty($_GET['show_archived']);

// Get organizations for filter
$organizations = [];
if ($isSuperAdmin) {
    $orgs = $conn->query("SELECT id, organization_name, project_type FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name");
    while ($row = $orgs->fetch_assoc()) {
        $organizations[] = $row;
    }
}

// Build query
if ($showArchived) {
    $where = ['t.status = 0', 't.deleted_at IS NOT NULL'];
} else {
    $where = ['t.status = 1', 't.deleted_at IS NULL'];
}
$params = [];
$types = '';

if (!$isSuperAdmin) {
    $where[] = '(t.organization_id = ? OR t.organization_id IS NULL OR t.organization_id = 0)';
    $params[] = $_SESSION['organization_id'] ?? 0;
    $types .= 'i';
}

if ($orgFilter > 0 && $isSuperAdmin) {
    $where[] = 't.organization_id = ?';
    $params[] = $orgFilter;
    $types .= 'i';
}

if ($orientationFilter !== '') {
    $where[] = 't.orientation = ?';
    $params[] = $orientationFilter;
    $types .= 's';
}

if ($searchFilter !== '') {
    $where[] = '(t.name LIKE ? OR t.description LIKE ?)';
    $like = '%' . $searchFilter . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$whereClause = implode(' AND ', $where);

// Get templates
$sql = "SELECT t.*, 
        o.organization_name,
        o.project_type,
        o.logo AS org_logo,
        COUNT(DISTINCT m.id) as member_count,
        COUNT(DISTINCT f.id) as field_count
        FROM card_templates t
        LEFT JOIN organizations o ON t.organization_id = o.id
        LEFT JOIN id_members m ON t.id = m.template_id
        LEFT JOIN template_fields f ON t.id = f.template_id AND f.archived_at IS NULL
        WHERE $whereClause
        GROUP BY t.id
        ORDER BY t.is_default DESC, t.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ADDED: Batch-fetch layout objects for every template shown on this page in ONE query
// instead of querying per-card (avoids N+1 queries on the listing page).
$templateFieldsByTemplate = [];
if (!empty($templates)) {
    $templateIds = array_map(static fn($t) => (int)$t['id'], $templates);
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $fieldsStmt = $pdo->prepare(
        "SELECT * FROM template_fields
         WHERE template_id IN ($placeholders) AND archived_at IS NULL
         ORDER BY template_id, z_index ASC, id"
    );
    $fieldsStmt->execute($templateIds);
    foreach ($fieldsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $templateFieldsByTemplate[(int)$row['template_id']][] = $row;
    }
}

// Get statistics
$stats = [];
$stats['total'] = $conn->query("SELECT COUNT(*) FROM card_templates WHERE status = 1")->fetch_row()[0] ?? 0;
$stats['landscape'] = $conn->query("SELECT COUNT(*) FROM card_templates WHERE status = 1 AND orientation = 'landscape'")->fetch_row()[0] ?? 0;
$stats['portrait'] = $conn->query("SELECT COUNT(*) FROM card_templates WHERE status = 1 AND orientation = 'portrait'")->fetch_row()[0] ?? 0;
$stats['default'] = $conn->query("SELECT COUNT(*) FROM card_templates WHERE status = 1 AND is_default = 1")->fetch_row()[0] ?? 0;

// Handle template archive (soft delete)
if (isset($_POST['delete_template']) && $canDelete) {
    $templateId = (int)$_POST['template_id'];

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } else {
        $tpl = template_fetch_by_id($pdo, $templateId);
        if (!$tpl || !template_user_can_manage($pdo, $authUser, $tpl)) {
            $error = 'Template not found or access denied.';
        } else {
$result = template_archive($pdo, $templateId);

if ($result['success']) {
    $_SESSION['message'] = 'Template archived successfully. Existing members and cards are preserved.';

    template_log_audit(
        $pdo,
        $userId,
        (int)($tpl['organization_id'] ?? 0) ?: null,
        'Archived template',
        "Template ID: $templateId"
    );

    // Preserve current filters/search after archive
    $redirectParams = [];

    if (!empty($_GET['org_id'])) {
        $redirectParams['org_id'] = (int)$_GET['org_id'];
    }

    if (!empty($_GET['orientation'])) {
        $redirectParams['orientation'] = $_GET['orientation'];
    }

    if (!empty($_GET['search'])) {
        $redirectParams['search'] = $_GET['search'];
    }

    if (!empty($_GET['show_archived'])) {
        $redirectParams['show_archived'] = 1;
    }

    $redirectUrl = 'templates.php';

    if (!empty($redirectParams)) {
        $redirectUrl .= '?' . http_build_query($redirectParams);
    }

    // Redirect immediately after successful archive
    header('Location: ' . $redirectUrl);
    exit;

} else {
    $error = $result['error'] ?? 'Failed to archive template.';
}
        }
    }
}

// Handle restore
if (isset($_POST['restore_template']) && $canEdit) {
    $templateId = (int)$_POST['template_id'];

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } 
    else {
        $stmt = $pdo->prepare('SELECT * FROM card_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl || !template_user_can_manage($pdo, $authUser, $tpl)) {
            $error = 'Template not found or access denied.';
        } else {
            $result = template_restore($pdo, $templateId);
            if ($result['success']) {
                $message = 'Template restored successfully.';
                template_log_audit($pdo, $userId, (int)($tpl['organization_id'] ?? 0) ?: null, 'Restored template', "Template ID: $templateId");
            } else {
                $error = $result['error'] ?? 'Failed to restore template.';
            }
        }
    }
}

// Handle set default
if (isset($_POST['set_default']) && $canEdit) {
    $templateId = (int)$_POST['template_id'];

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } else {
        $tpl = template_fetch_by_id($pdo, $templateId);
        if (!$tpl || !template_user_can_manage($pdo, $authUser, $tpl)) {
            $error = 'Template not found or access denied.';
        } else {
            $result = template_set_default($pdo, $templateId);
            if ($result['success']) {
                $message = 'Default template updated for this organization.';
                template_log_audit($pdo, $userId, (int)($tpl['organization_id'] ?? 0) ?: null, 'Set default template', "Template ID: $templateId");
            } else {
                $error = $result['error'] ?? 'Failed to set default template.';
            }
        }
    }
}

// Handle template duplication
// Handle template duplication
if (isset($_POST['duplicate_template']) && $canCreate) {
    $templateId = (int)$_POST['template_id'];

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'] ?? '',
            (string)$_POST['csrf_token']
        )
    ) {
        $error = 'Invalid security token.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM card_templates WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tpl || !template_user_can_manage($pdo, $authUser, $tpl)) {
            $error = 'Template not found or access denied.';
        } else {
            $result = template_duplicate($pdo, $templateId, $userId);

            if ($result['success']) {

                $_SESSION['message'] =
                    'Template duplicated as "' .
                    ($result['name'] ?? 'Copy') .
                    '".';

                template_log_audit(
                    $pdo,
                    $userId,
                    (int)($tpl['organization_id'] ?? 0) ?: null,
                    'Duplicated template',
                    "Source ID: $templateId, New ID: {$result['new_id']}"
                );

                // Preserve current filters/search
                $redirectParams = [];

                if (!empty($_GET['org_id'])) {
                    $redirectParams['org_id'] = (int)$_GET['org_id'];
                }

                if (!empty($_GET['orientation'])) {
                    $redirectParams['orientation'] = $_GET['orientation'];
                }

                if (!empty($_GET['search'])) {
                    $redirectParams['search'] = $_GET['search'];
                }

                if (!empty($_GET['show_archived'])) {
                    $redirectParams['show_archived'] = 1;
                }

                $redirectUrl = 'templates.php';

                if (!empty($redirectParams)) {
                    $redirectUrl .= '?' . http_build_query($redirectParams);
                }

                // IMPORTANT:
                // Redirect immediately after successful duplicate
                header('Location: ' . $redirectUrl);
                exit;

            } else {
                $error = $result['error'] ?? 'Failed to duplicate template.';
            }
        }
    }
}

// Helper functions
function getOrientationLabel($orientation) {
    return $orientation === 'landscape' ? 'Landscape' : 'Portrait';
}

function getOrientationIcon($orientation) {
    return $orientation === 'landscape' ? 'fa-arrows-alt-h' : 'fa-arrows-alt-v';
}

function getProjectTypeLabel($type) {
    return $type === 'residence' ? 'Residence' : 'Corporate';
}

function logAuditActivity($conn, $username, $action, $action_type, $details) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssss", $user_id, $action, $action_type, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

// ADDED: build a lightweight sample member + resolved layout HTML for the mini live preview.
// Mirrors the same sample-member shape design_template.php uses for its own preview pane.
function templates_page_build_preview_html(PDO $pdo, array $template, array $fields): string
{
    $templateId = (int)$template['id'];

    $sampleMember = [
        'name' => 'John Doe',
        'unique_id' => 'ID-' . str_pad((string)$templateId, 3, '0', STR_PAD_LEFT) . '001',
        'organization_name' => $template['organization_name'] ?? 'Organization',
        'photo' => '',
        'org_logo' => $template['org_logo'] ?? '',
        'dynamic_fields' => [],
        'member_type' => 'employee',
        'expiry_date' => date('Y-m-d', strtotime('+1 year')),
    ];

    $definitions = card_renderer_definitions($pdo, $templateId);

    // '../' because templates.php lives in the same directory as design_template.php
    // (i.e. one level below project root, same asset prefix convention).
    return card_renderer_html($template, $sampleMember, $definitions, $fields, 'front', '../');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>ID Card Templates · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= card_renderer_css() /* ADDED: styles for .id-card-renderer used by the live mini preview */ ?>

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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            transition: all 0.3s ease;
            text-align: center;
            cursor: pointer;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .stat-card .stat-number { font-size: 1.75rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.7rem; font-weight: 500; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card .stat-icon { font-size: 1.5rem; margin-bottom: 0.25rem; }

        .text-primary { color: var(--primary); }
        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        .text-info { color: var(--info); }

        /* Breadcrumb */
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

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            
        }

        .filter-bar select, .filter-bar input {
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--neutral-300);
            font-size: 0.875rem;
            background: white;
            min-width: 140px;
        }
        .filter-bar .btn { padding: 0.375rem 0.75rem; border-radius: var(--radius-md); font-size: 0.875rem; }

        /* Template Grid */
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .template-card {
            background: white;
            border-radius: var(--radius-2xl);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            transition: all 0.3s ease;
        }

        .template-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .template-preview {
            height: 200px;
            background: var(--neutral-100);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ADDED: wrapper that scales the real id-card-renderer output down to thumbnail size.
           Replaces the old hand-drawn .mini-card / .mini-header / .mini-photo mockup classes. */
        .template-preview .live-mini-card-wrap {
            transform-origin: center center;
            pointer-events: none;
        }
        .template-preview .live-mini-card-wrap .id-card-renderer {
            box-shadow: var(--shadow-md);
            border-radius: 6px;
        }
        .template-preview .preview-unavailable {
            font-size: 0.75rem;
            color: var(--neutral-500);
            text-align: center;
            padding: 0 1rem;
        }

        .template-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.688rem;
            font-weight: 600;
            z-index: 2;
        }

        .template-badge.default {
            background: var(--success-soft);
            color: var(--success);
        }

        .template-badge.landscape {
            background: var(--info-soft);
            color: var(--info);
        }

        .template-badge.portrait {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .template-body {
            padding: 1rem 1.25rem;
        }

        .template-body h5 {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            color: var(--neutral-800);
        }

        .template-body .template-meta {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-bottom: 0.5rem;
        }

        .template-body .template-meta span {
            margin-right: 0.75rem;
        }

        .template-body .template-description {
            font-size: 0.813rem;
            color: var(--neutral-600);
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .template-footer {
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--neutral-200);
            background: var(--neutral-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .template-footer .btn-group {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .template-footer .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: var(--radius-md);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid var(--neutral-200);
            background: white;
            color: var(--neutral-600);
            transition: all 0.2s;
            cursor: pointer;
        }

        .template-footer .btn:hover {
            background: var(--neutral-100);
            transform: translateY(-1px);
        }

        .template-footer .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .template-footer .btn-primary:hover {
            background: var(--primary-light);
        }

        .template-footer .btn-success {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .template-footer .btn-success:hover {
            background: #0d8b5e;
        }

        .template-footer .btn-warning {
            background: var(--warning);
            color: white;
            border-color: var(--warning);
        }

        .template-footer .btn-warning:hover {
            background: #e0a832;
        }

        .template-footer .btn-danger {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .template-footer .btn-danger:hover {
            background: #b91c1c;
        }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease;
        }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert .btn-close-custom {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.5;
            padding: 0 0.25rem;
        }
        .alert .btn-close-custom:hover { opacity: 1; }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .template-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { min-width: 100%; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* Modal */
        .modal-content { border-radius: var(--radius-2xl); border: none; box-shadow: var(--shadow-xl); }
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--neutral-200); }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1.25rem 1.5rem; border-top: 1px solid var(--neutral-200); }

        .btn { border-radius: var(--radius-md); padding: 0.375rem 0.75rem; font-size: 0.875rem; }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 3rem; color: var(--neutral-300); margin-bottom: 1rem; }
        .empty-state p { color: var(--neutral-500); margin-bottom: 1rem; }

        /* Print */
        @media print {
            .sidebar, .top-header, .filter-bar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .template-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
        }
        .filter-toolbar{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:18px;
    padding:22px;
    box-shadow:0 8px 25px rgba(0,0,0,.06);
    margin-bottom:25px;
}

.toolbar-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:18px;
}

.search-box{
    flex:1;
    position:relative;
}

.search-box i{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    color:#6b7280;
}

.search-box input{
    width:100%;
    height:48px;
    padding-left:45px;
    border:1px solid #d1d5db;
    border-radius:12px;
    outline:none;
}

.toolbar-bottom{
    display:grid;
    grid-template-columns:2fr 2fr 2fr auto auto;
    gap:15px;
    align-items:center;
}

.toolbar-bottom .form-select{
    height:48px;
    border-radius:12px;
}

.toolbar-bottom .btn{
    height:48px;
    white-space:nowrap;
    border-radius:12px;
    padding:0 20px;
}

@media(max-width:992px){

    .toolbar-top{
        flex-direction:column;
        align-items:stretch;
    }

    .toolbar-bottom{
        grid-template-columns:1fr;
    }

    .toolbar-bottom .btn{
        width:100%;
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
            <div class="dashboard-content">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Templates</li>
                    </ol>
                </nav>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($message) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card" onclick="window.location.href='templates.php'">
                        <div class="stat-icon text-primary"><i class="fas fa-paint-brush"></i></div>
                        <div class="stat-label">Total Templates</div>
                        <div class="stat-number text-primary"><?= $stats['total'] ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='templates.php?orientation=landscape'">
                        <div class="stat-icon text-info"><i class="fas fa-arrows-alt-h"></i></div>
                        <div class="stat-label">Landscape</div>
                        <div class="stat-number text-info"><?= $stats['landscape'] ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='templates.php?orientation=portrait'">
                        <div class="stat-icon text-warning"><i class="fas fa-arrows-alt-v"></i></div>
                        <div class="stat-label">Portrait</div>
                        <div class="stat-number text-warning"><?= $stats['portrait'] ?></div>
                    </div>
                    <div class="stat-card" onclick="window.location.href='templates.php'">
                        <div class="stat-icon text-success"><i class="fas fa-star"></i></div>
                        <div class="stat-label">Default</div>
                        <div class="stat-number text-success"><?= $stats['default'] ?></div>
                    </div>
                </div>

                <!-- Filter Bar -->
             <!-- Filter Toolbar -->
<div class="filter-toolbar no-print">

    <form method="GET">

        <!-- Top Row -->
        <div class="toolbar-top">

            <div class="search-box">
                <i class="fas fa-search"></i>

                <input
                    type="text"
                    name="search"
                    placeholder="Search templates..."
                    value="<?= htmlspecialchars($searchFilter) ?>">
            </div>

            <?php if ($canCreate): ?>
                <a href="add_template.php" class="btn btn-success">
                    <i class="fas fa-plus me-2"></i>
                    Add Template
                </a>
            <?php endif; ?>

        </div>

        <!-- Bottom Row -->
        <div class="toolbar-bottom">

            <?php if ($isSuperAdmin): ?>

            <select name="org_id" class="form-select">
                <option value="0">Organization</option>

                <?php foreach($organizations as $org): ?>

                    <option
                        value="<?= $org['id'] ?>"
                        <?= $orgFilter==$org['id']?'selected':'' ?>>

                        <?= htmlspecialchars($org['organization_name']) ?>

                    </option>

                <?php endforeach; ?>

            </select>

            <?php endif; ?>

            <select
                name="orientation"
                class="form-select">

                <option value="">Orientation</option>

                <option
                    value="portrait"
                    <?= $orientationFilter=='portrait'?'selected':'' ?>>
                    Portrait
                </option>

                <option
                    value="landscape"
                    <?= $orientationFilter=='landscape'?'selected':'' ?>>
                    Landscape
                </option>

            </select>

            <select
                name="show_archived"
                class="form-select">

                <option
                    value="0"
                    <?= !$showArchived?'selected':'' ?>>
                    Active Templates
                </option>

                <option
                    value="1"
                    <?= $showArchived?'selected':'' ?>>
                    Archived Templates
                </option>

            </select>

            <button
                type="submit"
                class="btn btn-primary">

                <i class="fas fa-filter me-2"></i>

                Apply Filters

            </button>

            <a
                href="templates.php"
                class="btn btn-outline-secondary">

                <i class="fas fa-rotate-left me-2"></i>

                Reset

            </a>

        </div>

    </form>

</div>

                <!-- Template Grid -->
                <div class="template-grid">
                    <?php if (empty($templates)): ?>
                        <div class="empty-state">
                            <i class="fas fa-paint-brush"></i>
                            <p>No templates found. Create your first template to get started.</p>
                            <a href="add_template.php" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>Create Template
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($templates as $template): ?>
                            <?php
                            $primaryColor = $template['primary_color'] ?? '#0a1a2f';
                            $secondaryColor = $template['secondary_color'] ?? '#1e3a5f';
                            $textColor = $template['text_color'] ?? '#ffffff';
                            $orientation = $template['orientation'] ?? 'portrait';
                            $isDefault = (int)$template['is_default'] === 1;

                            // ADDED: resolve real saved layout + card size for the live preview
                            $tplId = (int)$template['id'];
                            $tplFields = $templateFieldsByTemplate[$tplId] ?? [];

                            $isPortraitTpl = strtolower($orientation) === 'portrait';
                            $tplCardWidth = (int)($template['card_width'] ?: ($isPortraitTpl ? 533 : 864));
                            $tplCardHeight = (int)($template['card_height'] ?: ($isPortraitTpl ? 864 : 533));
                            if ($tplCardWidth < 50) $tplCardWidth = $isPortraitTpl ? 533 : 864;
                            if ($tplCardHeight < 50) $tplCardHeight = $isPortraitTpl ? 864 : 533;
                            // Prepare template row for the renderer (needs numeric card_width/height)
                            $rendererTemplate = $template;
                            $rendererTemplate['card_width'] = $tplCardWidth;
                            $rendererTemplate['card_height'] = $tplCardHeight;

                            // Thumbnail box is ~200px tall preview area; scale the true card down to fit.
                            $thumbBoxW = 260;
                            $thumbBoxH = 160;
                            $previewScale = min($thumbBoxW / max(1, $tplCardWidth), $thumbBoxH / max(1, $tplCardHeight));

                            $miniPreviewHtml = null;
                            try {
                                $miniPreviewHtml = templates_page_build_preview_html($pdo, $rendererTemplate, $tplFields);
                            } catch (Throwable $e) {
                                $miniPreviewHtml = null;
                            }
                            ?>
                            <div class="template-card">
                                <!-- Preview -->
                                <div class="template-preview" style="background: linear-gradient(135deg, <?= $primaryColor ?>, <?= $secondaryColor ?>);">
                                    <?php if ($miniPreviewHtml !== null): ?>
                                        <div class="live-mini-card-wrap" style="transform: scale(<?= $previewScale ?>);">
                                            <?= $miniPreviewHtml ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="preview-unavailable">
                                            <i class="fas fa-image d-block mb-1"></i>
                                            Preview unavailable
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($isDefault): ?>
                                        <span class="template-badge default"><i class="fas fa-star"></i> Default</span>
                                    <?php endif; ?>
                                    <span class="template-badge <?= $orientation ?>" style="top:10px;left:10px;right:auto;">
                                        <i class="fas <?= getOrientationIcon($orientation) ?>"></i>
                                        <?= getOrientationLabel($orientation) ?>
                                    </span>
                                </div>

                                <!-- Body -->
                                <div class="template-body">
                                    <h5 class="d-flex align-items-center gap-2 flex-wrap">
                                        <?= htmlspecialchars($template['name']) ?>
                                        <?php if (!empty($template['version']) && (int)$template['version'] > 1): ?>
                                            <span class="badge" style="background:#6366f1;font-size:0.65rem;font-weight:600;padding:0.2rem 0.5rem;border-radius:9999px">
                                                v<?= (int)$template['version'] ?>
                                            </span>
                                        <?php elseif (!empty($template['parent_template_id'])): ?>
                                            <span class="badge" style="background:#6366f1;font-size:0.65rem;font-weight:600;padding:0.2rem 0.5rem;border-radius:9999px">
                                                v<?= (int)$template['version'] ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($template['first_used_at'])): ?>
                                            <span title="Template is in use by members" style="color:#f59e0b;font-size:0.75rem"><i class="fas fa-users"></i></span>
                                        <?php endif; ?>
                                    </h5>
                                    <div class="template-meta">
                                        <span><i class="fas fa-building"></i> <?= htmlspecialchars($template['organization_name'] ?? 'Global') ?></span>
                                        <span><i class="fas fa-users"></i> <?= (int)$template['member_count'] ?> members</span>
                                        <span><i class="fas fa-cogs"></i> <?= (int)$template['field_count'] ?> fields</span>
                                    </div>
                                    <?php if (!empty($template['description'])): ?>
                                        <div class="template-description"><?= htmlspecialchars($template['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2 flex-wrap" style="font-size:0.75rem;color:var(--neutral-500);">
                                        <span><i class="fas fa-palette"></i> <?= $primaryColor ?></span>
                                        <span><i class="fas fa-font"></i> <?= htmlspecialchars($template['font'] ?? 'Inter') ?></span>
                                        <?php if (!empty($template['card_width']) && !empty($template['card_height'])): ?>
                                            <span><i class="fas fa-arrows-alt"></i> <?= $template['card_width'] ?>×<?= $template['card_height'] ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($template['mirror_print'])): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-undo"></i> Mirror</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Footer -->
                                <div class="template-footer">
                                    <span style="font-size:0.75rem;color:var(--neutral-500);">
                                        <i class="fas fa-download"></i> <?= (int)$template['downloads'] ?> downloads
                                    </span>
                                    <div class="btn-group">
                                        <a href="view_template.php?id=<?= $template['id'] ?>" class="btn btn-primary btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (!$showArchived): ?>
                                        <a href="edit_template.php?id=<?= $template['id'] ?>" class="btn btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="design_template.php?id=<?= $template['id'] ?>" class="btn btn-sm" title="Design">
                                            <i class="fas fa-palette"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($canEdit && !$showArchived && !$isDefault): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                                    <button type="submit" name="set_default" class="btn btn-success btn-sm" title="Set as Default">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                </form>
                                        <?php endif; ?>
                                        <?php if ($canCreate && !$showArchived): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                                <button type="submit" name="duplicate_template" class="btn btn-warning btn-sm" title="Duplicate">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canDelete && !$showArchived): ?>
                                            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal" 
                                                    data-id="<?= $template['id'] ?>" 
                                                    data-name="<?= htmlspecialchars($template['name'], ENT_QUOTES) ?>"
                                                    data-members="<?= (int)$template['member_count'] ?>" title="Archive">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canEdit && $showArchived): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                                <button type="submit" name="restore_template" class="btn btn-success btn-sm" title="Restore">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-archive me-2"></i>Archive Template
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="template_id" id="deleteTemplateId" value="">
                        <p class="fs-6 mb-2">Archive <strong id="deleteTemplateName" class="text-danger"></strong>?</p>
                        <div id="deleteWarning" class="text-muted small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_template" class="btn btn-danger">
                            <i class="fas fa-archive me-1"></i>Archive Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Delete modal handler
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-id');
                    const name = button.getAttribute('data-name');
                    const members = button.getAttribute('data-members');

                    document.getElementById('deleteTemplateId').value = id;
                    document.getElementById('deleteTemplateName').textContent = name;

                    const warning = document.getElementById('deleteWarning');
                    if (members > 0) {
                        warning.innerHTML = '<i class="fas fa-info-circle text-info me-1"></i> ' +
                            'This template is used by <strong>' + members + '</strong> members. ' +
                            'Archiving hides it from new registrations; existing members and cards remain unchanged.';
                    } else {
                        warning.textContent = 'Archived templates cannot be used for new members or card generation.';
                    }
                });
            }
        });

        // Auto-submit on filter change
        document.querySelectorAll('.filter-bar select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add_template.php';
            }
            if (e.key === 'Escape') {
                document.querySelector('.sidebar')?.classList.remove('active');
            }
        });

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control, .form-select, .stat-card, .template-card').forEach(el => {
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