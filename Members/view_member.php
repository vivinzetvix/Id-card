<?php
/**
 * View Member — read-only detail page for a single member.
 * Shows all member information, assigned template, generated cards,
 * and provides actions like edit, renew, generate card, etc.
 * Follows the same design conventions as view_template.php, add_member.php, etc.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_helpers.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Members', 'View');

$page_title = 'View Member';
$isSuperAdmin = auth_is_super_admin($authUser);
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);
$userOrgId = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($memberId <= 0) {
    $_SESSION['member_error'] = 'Invalid member ID';
    header('Location: view_members.php');
    exit();
}

$message = $_SESSION['member_message'] ?? '';
$error = $_SESSION['member_error'] ?? '';
unset($_SESSION['member_message'], $_SESSION['member_error']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ─── Load member ─────────────────────────────────────────────────────────────
$member = fetch_member_for_user($pdo, $authUser, $memberId);
if (!$member) {
    $_SESSION['member_error'] = 'Member not found or access denied';
    header('Location: view_members.php');
    exit();
}

// ─── Load member's assigned template ──────────────────────────────────────
$template = null;
$templateId = (int)($member['template_id'] ?? 0);
if ($templateId > 0) {
    $stmt = $pdo->prepare(
        'SELECT t.*, o.organization_name AS org_name, o.project_type
         FROM card_templates t
         LEFT JOIN organizations o ON t.organization_id = o.id
         WHERE t.id = ? AND t.deleted_at IS NULL AND t.status = 1'
    );
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ─── Load generated cards ──────────────────────────────────────────────────
$cards = [];
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM generated_cards
         WHERE member_id = ? AND deleted_at IS NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute([$memberId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Table might not exist yet
}

// ─── Load dynamic field values ─────────────────────────────────────────────
$dynamicFields = [];
try {
    $stmt = $pdo->prepare(
        'SELECT field_key, field_value FROM member_dynamic_values
         WHERE member_id = ? AND deleted_at IS NULL
         ORDER BY field_key'
    );
    $stmt->execute([$memberId]);
    $dynamicFields = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    // Table might not exist yet
}

// ─── Load template input field definitions ─────────────────────────────────
$fieldDefinitions = [];
if ($templateId > 0) {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM template_input_fields
             WHERE template_id = ? AND is_enabled = 1 AND deleted_at IS NULL
             ORDER BY sort_order ASC'
        );
        $stmt->execute([$templateId]);
        $fieldDefinitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table might not exist yet
    }
}

// ─── Check template compatibility ──────────────────────────────────────────
$missingRequired = [];
if ($templateId > 0) {
    $compat = member_check_template_compatibility($pdo, $memberId, $templateId);
    $missingRequired = $compat['missing_required'] ?? [];
}

// ─── Helper functions ──────────────────────────────────────────────────────

function view_member_format_date($date) {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}

function view_member_format_datetime($date) {
    if (!$date) return '—';
    return date('M d, Y g:i A', strtotime($date));
}

function view_member_get_status($member) {
    $expiry = $member['expiry_date'] ?? null;
    if (!$expiry) return ['label' => 'Active', 'class' => 'active'];
    $today = strtotime(date('Y-m-d'));
    $expiryTs = strtotime($expiry);
    if ($expiryTs < $today) return ['label' => 'Expired', 'class' => 'expired'];
    if ($expiryTs < strtotime('+30 days')) return ['label' => 'Expiring Soon', 'class' => 'expiring'];
    return ['label' => 'Active', 'class' => 'active'];
}

function view_member_get_status_badge($member) {
    $status = view_member_get_status($member);
    return '<span class="status-badge ' . $status['class'] . '">' . $status['label'] . '</span>';
}

function view_member_render_field_value($member, $fieldKey, $dynamicFields = []) {
    $fixedColumns = [
        'name', 'unique_id', 'guardian_name', 'email', 'emergency_contact',
        'department', 'class', 'designation', 'company', 'purpose',
        'dob', 'address', 'joined_date', 'expiry_date', 'language',
        'photo', 'signature', 'member_type'
    ];
    
    if (in_array($fieldKey, $fixedColumns, true)) {
        $value = $member[$fieldKey] ?? '';
        if ($fieldKey === 'photo' && $value) {
            return '<img src="../images/uploads/' . htmlspecialchars($value) . '" class="field-photo-thumb" alt="Photo">';
        }
        if ($fieldKey === 'signature' && $value) {
            return '<img src="../images/uploads/signatures/' . htmlspecialchars($value) . '" class="field-signature-thumb" alt="Signature">';
        }
        if (in_array($fieldKey, ['dob', 'joined_date', 'expiry_date']) && $value) {
            return view_member_format_date($value);
        }
        return htmlspecialchars($value ?: '—');
    }
    
    // Dynamic field
    $value = $dynamicFields[$fieldKey] ?? '';
    return htmlspecialchars($value ?: '—');
}

// ─── Handle POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } else {
        // Delete member
        if (isset($_POST['delete_member']) && has_permission($pdo, 'Members', 'Delete')) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE id_members SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?');
                $stmt->execute([$memberId]);
                member_log_audit(
                    $pdo,
                    $userId,
                    (int)($member['organization_id'] ?? 0) ?: null,
                    'Member Deleted',
                    "Member ID: {$memberId}, Name: {$member['name']}"
                );
                $pdo->commit();
                $_SESSION['member_message'] = 'Member "' . $member['name'] . '" has been deleted.';
                header('Location: view_members.php');
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to delete member. Please try again.';
            }
        }
        
        // Regenerate card
        if (isset($_POST['regenerate_card']) && has_permission($pdo, 'Generate ID', 'Create')) {
            $mirror = isset($_POST['mirror']) ? 1 : 0;
            $generateType = $_POST['generate_type'] ?? 'single';
            
            if ($templateId <= 0) {
                $error = 'This member does not have an assigned template.';
            } else {
                $compat = member_check_template_compatibility($pdo, $memberId, $templateId);
                if (!empty($compat['missing_required'])) {
                    $labels = array_map(fn($d) => $d['field_label'] ?? $d['field_key'], $compat['missing_required']);
                    $error = 'Missing required data: ' . implode(', ', $labels) . '. Please fill the missing fields first.';
                } else {
                    // Use the card renderer to generate
                    try {
                        require_once __DIR__ . '/../includes/card_renderer.php';
                        ensure_card_renderer_schema($pdo);
                        
                        $result = generateCardImage($pdo, $memberId, $templateId, $mirror, $generateType);
                        if ($result['success']) {
                            $orgIdForCard = (int)($member['organization_id'] ?? 0) ?: null;
                            $stmt = $pdo->prepare(
                                'INSERT INTO generated_cards (organization_id, member_id, template_id, image_path, created_at)
                                 VALUES (?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE
                                   organization_id = VALUES(organization_id),
                                   template_id = VALUES(template_id),
                                   image_path = VALUES(image_path),
                                   created_at = NOW(),
                                   id = LAST_INSERT_ID(id)'
                            );
                            $stmt->execute([$orgIdForCard, $memberId, $templateId, $result['path']]);
                            $cardId = (int)$pdo->lastInsertId();
                            member_log_audit(
                                $pdo,
                                $userId,
                                (int)($member['organization_id'] ?? 0) ?: null,
                                'Generated ID Card',
                                "Member ID: {$memberId}, Template ID: {$templateId}"
                            );
                            $_SESSION['member_message'] = 'Card generated successfully!';
                            header('Location: ../card/view_card.php?id=' . $cardId . '&generated=1');
                            exit();
                        }
                        $error = $result['error'] ?? 'Failed to generate card.';
                    } catch (Throwable $e) {
                        $error = 'Failed to generate card: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

/**
 * Generate card using shared card_renderer (designer coordinates).
 */
function generateCardImage(PDO $pdo, int $memberId, int $templateId, int $mirror = 0, string $generateType = 'single'): array
{
    try {
        ensure_card_renderer_schema($pdo);
        $template = card_renderer_template($pdo, $templateId, true);
        $member = card_renderer_member($pdo, $memberId);
        if (!empty($member['deleted_at'])) {
            return ['success' => false, 'error' => 'Cannot generate card for a deleted member'];
        }
        if ((int)($member['template_id'] ?? 0) !== $templateId) {
            return ['success' => false, 'error' => 'Member template mismatch.'];
        }
        $definitions = card_renderer_definitions($pdo, $templateId);
        $layout = card_renderer_layout($pdo, $templateId);

        $sides = ($generateType === 'both') ? ['front', 'back'] : ['front'];
        $mirrorStyle = $mirror ? 'transform:scaleX(-1);' : '';
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . card_renderer_css()
            . '<style>body{margin:0;padding:16px;background:#f3f4f6}.card-stack{display:flex;flex-wrap:wrap;gap:16px}.card-stack .id-card-renderer{' . $mirrorStyle . '}</style>'
            . '</head><body><div class="card-stack">';
        foreach ($sides as $side) {
            $html .= card_renderer_html($template, $member, $definitions, $layout, $side, '../../');
        }
        $html .= '</div></body></html>';

        $uploadDir = __DIR__ . '/../images/cards/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $timestamp = time();
        $filename = 'card_' . $memberId . '_' . $timestamp . '.html';
        file_put_contents($uploadDir . $filename, $html);

        return [
            'success' => true,
            'path' => 'images/cards/' . $filename,
            'html' => $html,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>View Member · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?= card_renderer_css() ?>

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
        }

        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 1.5rem 2rem; max-width: 1600px; margin: 0 auto; }

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
            margin: 0 0 1.25rem 0;
            font-size: 0.875rem;
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease;
        }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert-warning { background: var(--warning-soft); color: #b45309; }
        .btn-close-custom {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.5;
            margin-left: auto;
        }
        .btn-close-custom:hover { opacity: 1; }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .page-header h4 {
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .page-header .subtitle {
            color: var(--neutral-500);
            font-size: 0.875rem;
        }
        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.7rem;
            border-radius: var(--radius-sm);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge.active {
            background: var(--success-soft);
            color: var(--success);
        }
        .status-badge.expired {
            background: var(--danger-soft);
            color: var(--danger);
        }
        .status-badge.expiring {
            background: var(--warning-soft);
            color: #b45309;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.7rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-default { background: var(--success-soft); color: var(--success); }
        .badge-orientation { background: var(--info-soft); color: var(--info); }

        .btn {
            border-radius: var(--radius-md);
            padding: 0.5rem 0.9rem;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-light); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #0d8b5e; color: #fff; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-warning:hover { background: #e0a832; color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #b91c1c; color: #fff; }
        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        .view-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .view-layout { grid-template-columns: 1fr; }
        }

        .panel {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .panel h6 {
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid var(--neutral-200);
            font-size: 0.9rem;
        }
        .panel h6 i {
            color: var(--primary);
            margin-right: 0.4rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem 1.5rem;
        }
        .info-item {
            font-size: 0.85rem;
            padding: 0.35rem 0;
            border-bottom: 1px solid var(--neutral-100);
        }
        .info-item .info-label {
            color: var(--neutral-500);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: block;
            margin-bottom: 0.05rem;
        }
        .info-item .info-value {
            color: var(--neutral-800);
            font-weight: 500;
            word-break: break-word;
        }
        .info-item .info-value .photo-thumb {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--neutral-200);
        }
        .info-item .info-value .signature-thumb {
            height: 40px;
            object-fit: contain;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-sm);
            padding: 0.2rem;
            background: white;
        }
        .info-item.full-width {
            grid-column: 1 / -1;
        }
        .info-item .info-value .color-swatch {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 4px;
            vertical-align: -2px;
            margin-right: 0.35rem;
            border: 1px solid rgba(0,0,0,0.1);
        }

        .card-preview-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.25rem;
        }
        .card-side-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        .card-side-block .side-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
        }
        .card-frame {
            transform: scale(0.55);
            transform-origin: top center;
            margin-bottom: -390px;
        }
        .preview-unavailable-box {
            width: 260px;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--neutral-100);
            border-radius: var(--radius-md);
            color: var(--neutral-500);
            font-size: 0.8rem;
            text-align: center;
            padding: 1rem;
        }

        .field-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .field-chip {
            font-size: 0.72rem;
            padding: 0.25rem 0.6rem;
            border-radius: var(--radius-sm);
            background: var(--neutral-100);
            color: var(--neutral-600);
            border: 1px solid var(--neutral-200);
        }
        .field-chip i { margin-right: 0.3rem; color: var(--primary); }

        .card-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--neutral-100);
            font-size: 0.85rem;
        }
        .card-item:last-child { border-bottom: none; }
        .card-item .card-info { display: flex; flex-direction: column; gap: 0.1rem; }
        .card-item .card-info .card-name { font-weight: 500; color: var(--neutral-800); }
        .card-item .card-info .card-meta { font-size: 0.72rem; color: var(--neutral-500); }
        .card-item .card-actions { display: flex; gap: 0.4rem; }

        .empty-note {
            color: var(--neutral-500);
            font-size: 0.85rem;
            text-align: center;
            padding: 1rem 0;
        }

        .field-photo-thumb {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--neutral-200);
        }
        .field-signature-thumb {
            height: 32px;
            object-fit: contain;
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-sm);
            padding: 0.1rem 0.3rem;
            background: white;
        }

        .missing-fields-warning {
            background: var(--warning-soft);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1rem;
            border-left: 4px solid var(--warning);
            margin-bottom: 1rem;
        }
        .missing-fields-warning ul {
            margin: 0.25rem 0 0 0;
            padding-left: 1.25rem;
            font-size: 0.85rem;
        }

        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-header .header-actions { justify-content: stretch; }
            .page-header .header-actions .btn { flex: 1; justify-content: center; }
            .card-frame { transform: scale(0.4); margin-bottom: -520px; }
        }

        @media (max-width: 480px) {
            .dashboard-content { padding: 1rem; }
            .panel { padding: 1rem; }
            .card-frame { transform: scale(0.3); margin-bottom: -700px; }
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
                        <li class="breadcrumb-item active"><?= htmlspecialchars($member['name']) ?></li>
                    </ol>
                </nav>

                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div><?= htmlspecialchars($message) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Missing required fields warning -->
                <?php if (!empty($missingRequired)): ?>
                    <div class="missing-fields-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Missing required fields:</strong>
                        <ul>
                            <?php foreach ($missingRequired as $field): ?>
                                <li><?= htmlspecialchars($field['field_label'] ?? $field['field_key']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="edit_member.php?id=<?= $memberId ?>" class="btn btn-sm btn-warning mt-1">
                            <i class="fas fa-edit me-1"></i>Edit Member
                        </a>
                    </div>
                <?php endif; ?>

                <div class="page-header">
                    <div>
                        <h4>
                            <i class="fas fa-user-circle text-primary"></i>
                            <?= htmlspecialchars($member['name']) ?>
                            <?= view_member_get_status_badge($member) ?>
                            <span class="badge-pill badge-orientation">
                                <i class="fas fa-id-card"></i>
                                <?= htmlspecialchars($member['member_type'] ?? 'Member') ?>
                            </span>
                        </h4>
                        <div class="subtitle">
                            <i class="fas fa-barcode"></i>
                            ID: <?= htmlspecialchars($member['unique_id']) ?>
                            &nbsp;·&nbsp;
                            <i class="fas fa-calendar-alt"></i>
                            Joined <?= view_member_format_date($member['created_at'] ?? null) ?>
                            <?php if (!empty($member['organization_id'])): ?>
                                &nbsp;·&nbsp;
                                <i class="fas fa-building"></i>
                                <?php
                                $orgName = '';
                                $orgStmt = $pdo->prepare('SELECT organization_name FROM organizations WHERE id = ?');
                                $orgStmt->execute([$member['organization_id']]);
                                $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC);
                                $orgName = $orgRow['organization_name'] ?? 'Unknown';
                                echo htmlspecialchars($orgName);
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="view_members.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                        <?php if (has_permission($pdo, 'Members', 'Edit')): ?>
                            <a href="edit_member.php?id=<?= $memberId ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
                        <?php endif; ?>
                        <?php if (has_permission($pdo, 'Members', 'Edit') && $templateId > 0): ?>
                            <a href="renew_member.php?id=<?= $memberId ?>" class="btn btn-success"><i class="fas fa-redo"></i> Renew</a>
                        <?php endif; ?>
                        <?php if (has_permission($pdo, 'Generate ID', 'Create') && $templateId > 0): ?>
                            <a href="../generate_id_card.php?member_id=<?= $memberId ?>" class="btn btn-warning"><i class="fas fa-id-card"></i> Generate Card</a>
                        <?php endif; ?>
                        <?php if (has_permission($pdo, 'Members', 'Delete')): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this member and all associated data? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" name="delete_member" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="view-layout">
                    <!-- LEFT: Member Info -->
                    <div>
                        <div class="panel">
                            <h6><i class="fas fa-user"></i>Member Information</h6>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value"><?= htmlspecialchars($member['name']) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Unique ID</span>
                                    <span class="info-value"><?= htmlspecialchars($member['unique_id']) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Member Type</span>
                                    <span class="info-value"><?= ucfirst(htmlspecialchars($member['member_type'] ?? '—')) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Status</span>
                                    <span class="info-value"><?= view_member_get_status_badge($member) ?></span>
                                </div>
                                <?php if (!empty($member['email'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><a href="mailto:<?= htmlspecialchars($member['email']) ?>"><?= htmlspecialchars($member['email']) ?></a></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['emergency_contact'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Emergency Contact</span>
                                    <span class="info-value"><?= htmlspecialchars($member['emergency_contact']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['guardian_name'])): ?>
                                <div class="info-item full-width">
                                    <span class="info-label">Guardian Name</span>
                                    <span class="info-value"><?= htmlspecialchars($member['guardian_name']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['dob'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value"><?= view_member_format_date($member['dob']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['joined_date'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Joined Date</span>
                                    <span class="info-value"><?= view_member_format_date($member['joined_date']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['expiry_date'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Expiry Date</span>
                                    <span class="info-value"><?= view_member_format_date($member['expiry_date']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['class'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Class / Grade</span>
                                    <span class="info-value"><?= htmlspecialchars($member['class']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['department'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Department</span>
                                    <span class="info-value"><?= htmlspecialchars($member['department']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['designation'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Designation</span>
                                    <span class="info-value"><?= htmlspecialchars($member['designation']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['company'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Company</span>
                                    <span class="info-value"><?= htmlspecialchars($member['company']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['purpose'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Purpose</span>
                                    <span class="info-value"><?= htmlspecialchars($member['purpose']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['address'])): ?>
                                <div class="info-item full-width">
                                    <span class="info-label">Address</span>
                                    <span class="info-value"><?= nl2br(htmlspecialchars($member['address'])) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['photo'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Photo</span>
                                    <span class="info-value"><img src="../images/uploads/<?= htmlspecialchars($member['photo']) ?>" class="photo-thumb" alt="Photo"></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['signature'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Signature</span>
                                    <span class="info-value"><img src="../images/uploads/signatures/<?= htmlspecialchars($member['signature']) ?>" class="signature-thumb" alt="Signature"></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Dynamic Fields -->
                        <?php if (!empty($fieldDefinitions)): ?>
                        <div class="panel">
                            <h6><i class="fas fa-cogs"></i>Template Fields</h6>
                            <div class="info-grid">
                                <?php foreach ($fieldDefinitions as $field): ?>
                                    <?php if (in_array($field['field_key'], ['photo', 'signature', 'name', 'unique_id', 'member_type', 'organization_id', 'template_id', 'language', 'created_at', 'updated_at', 'deleted_at'])) continue; ?>
                                    <div class="info-item">
                                        <span class="info-label"><?= htmlspecialchars($field['field_label'] ?? $field['field_key']) ?></span>
                                        <span class="info-value"><?= view_member_render_field_value($member, $field['field_key'], $dynamicFields) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT: Template, Cards, Actions -->
                    <div>
                        <!-- Template -->
                        <div class="panel">
                            <h6><i class="fas fa-id-card"></i>Assigned Template</h6>
                            <?php if ($template): ?>
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($template['name']) ?></div>
                                        <div class="small text-muted">
                                            <i class="fas fa-<?= strtolower($template['orientation'] ?? 'portrait') === 'landscape' ? 'arrows-alt-h' : 'arrows-alt-v' ?>"></i>
                                            <?= ucfirst($template['orientation'] ?? 'Portrait') ?>
                                            &nbsp;·&nbsp;
                                            <?= (int)($template['card_width'] ?? 533) ?> × <?= (int)($template['card_height'] ?? 864) ?> px
                                            <?php if (!empty($template['organization_name'])): ?>
                                                &nbsp;·&nbsp;
                                                <i class="fas fa-building"></i> <?= htmlspecialchars($template['organization_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="../template/view_template.php?id=<?= $templateId ?>" class="btn btn-outline-secondary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="empty-note">
                                    <i class="fas fa-exclamation-triangle text-warning"></i>
                                    No template assigned to this member.
                                    <br>
                                    <a href="edit_member.php?id=<?= $memberId ?>" class="btn btn-sm btn-primary mt-2">
                                        <i class="fas fa-edit"></i> Assign Template
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Generated Cards -->
                        <div class="panel">
                            <h6><i class="fas fa-images"></i>Generated Cards</h6>
                            <?php if (!empty($cards)): ?>
                                <?php foreach ($cards as $card): ?>
                                    <div class="card-item">
                                        <div class="card-info">
                                            <span class="card-name">
                                                <i class="fas fa-id-card text-muted me-1"></i>
                                                Card #<?= (int)$card['id'] ?>
                                            </span>
                                            <span class="card-meta">
                                                <?= view_member_format_datetime($card['created_at'] ?? null) ?>
                                                <?php if (!empty($card['image_path'])): ?>
                                                    &nbsp;·&nbsp;
                                                    <a href="../<?= htmlspecialchars($card['image_path']) ?>" target="_blank">
                                                        <i class="fas fa-file"></i> View
                                                    </a>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="card-actions">
                                            <?php if (!empty($card['image_path'])): ?>
                                                <a href="../<?= htmlspecialchars($card['image_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-note">
                                    <i class="fas fa-id-card text-muted"></i>
                                    No cards generated for this member yet.
                                    <?php if ($templateId > 0 && has_permission($pdo, 'Generate ID', 'Create')): ?>
                                        <br>
                                        <a href="generate_id_card.php?member_id=<?= $memberId ?>" class="btn btn-sm btn-warning mt-2">
                                            <i class="fas fa-id-card"></i> Generate Card
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Actions -->
                        <div class="panel">
                            <h6><i class="fas fa-bolt"></i>Quick Actions</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($templateId > 0 && has_permission($pdo, 'Generate ID', 'Create')): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="regenerate_card" value="1">
                                        <input type="hidden" name="generate_type" value="single">
                                        <button type="submit" class="btn btn-sm btn-warning" <?= !empty($missingRequired) ? 'disabled' : '' ?>>
                                            <i class="fas fa-id-card"></i> Regenerate Card
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="regenerate_card" value="1">
                                        <input type="hidden" name="generate_type" value="both">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= !empty($missingRequired) ? 'disabled' : '' ?>>
                                            <i class="fas fa-layer-group"></i> Front + Back
                                        </button>
                                    </form>
                                    <div class="form-check ms-2">
                                        <input type="checkbox" class="form-check-input" id="mirrorCheck" form="mirrorForm">
                                        <label class="form-check-label small" for="mirrorCheck">Mirror</label>
                                    </div>
                                <?php endif; ?>
                                <?php if (has_permission($pdo, 'Members', 'Edit')): ?>
                                    <a href="edit_member.php?id=<?= $memberId ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if ($templateId > 0): ?>
                                        <a href="renew_member.php?id=<?= $memberId ?>" class="btn btn-sm btn-success">
                                            <i class="fas fa-redo"></i> Renew
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
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
        (function() {
            'use strict';

            // ─── Mirror print toggle ─────────────────────────────────────
            const mirrorCheck = document.getElementById('mirrorCheck');
            if (mirrorCheck) {
                mirrorCheck.addEventListener('change', function() {
                    document.querySelectorAll('form[method="POST"] input[name="mirror"]').forEach(function(input) {
                        input.value = this.checked ? '1' : '0';
                    }.bind(this));
                });
            }

            // ─── Touch-friendly ──────────────────────────────────────────
            if ('ontouchstart' in window) {
                document.querySelectorAll('.btn, .form-control, .form-select').forEach(function(el) {
                    el.addEventListener('touchstart', function() {
                        this.style.opacity = '0.8';
                    });
                    el.addEventListener('touchend', function() {
                        this.style.opacity = '1';
                    });
                });
            }

            // ─── Keyboard shortcuts ──────────────────────────────────────
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                    e.preventDefault();
                    document.querySelector('a[href="edit_member.php?id=<?= $memberId ?>"]')?.click();
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 'g') {
                    e.preventDefault();
                    document.querySelector('a[href="generate_id_card.php?member_id=<?= $memberId ?>"]')?.click();
                }
            });

            // ─── Auto-dismiss alerts ────────────────────────────────────
            document.querySelectorAll('.alert .btn-close-custom').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    this.closest('.alert').remove();
                });
            });

        })();
    </script>
</body>
</html>