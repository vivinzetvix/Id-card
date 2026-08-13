<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/permission.php';
require_once __DIR__ . '/includes/template_functions.php';
require_once __DIR__ . '/includes/card_renderer.php';
require_once __DIR__ . '/Members/member_helpers.php';

require_login();
$authUser = get_auth_user($pdo);
if (!$authUser) {
    header('Location: index.php');
    exit();
}
require_permission($pdo, 'Generate ID', 'Create');

$page_title = 'Generate ID Card';
$message = '';
$warning = '';
$error = '';
$generationMissing = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$username = $_SESSION['username'];
$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId = (int)($authUser['organization_id'] ?? 0);

$memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$selectedTemplateId = 0;
$member = null;
$assignedTemplate = null;

/**
 * Load templates available to the member's organisation.
 */
function generation_load_available_templates(PDO $pdo, array $member): array
{
    $orgId = (int)($member['organization_id'] ?? 0);
    if ($orgId <= 0) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM card_templates
         WHERE organization_id = ? AND status = 1 AND deleted_at IS NULL
         ORDER BY name ASC, id DESC'
    );
    $stmt->execute([$orgId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function generation_field_value(PDO $pdo, int $memberId, string $fieldKey): string
{
    $fixedColumns = [
        'name','unique_id','guardian_name','email','emergency_contact','department','class',
        'designation','company','purpose','dob','address','joined_date','expiry_date','language',
        'photo','signature','member_type'
    ];
    if (in_array($fieldKey, $fixedColumns, true)) {
        $stmt = $pdo->prepare("SELECT `$fieldKey` FROM id_members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        return (string)($stmt->fetchColumn() ?? '');
    }
    $stmt = $pdo->prepare(
        'SELECT field_value FROM member_dynamic_values
         WHERE member_id = ? AND field_key = ?
         ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$memberId, $fieldKey]);
    return (string)($stmt->fetchColumn() ?? '');
}

function generation_save_field_values(PDO $pdo, int $memberId, int $templateId, array $values): void
{
    $fixedColumns = [
        'name','unique_id','guardian_name','email','emergency_contact','department','class',
        'designation','company','purpose','dob','address','joined_date','expiry_date','language',
        'photo','signature','member_type'
    ];
    $dynamic = [];
    foreach ($values as $key => $value) {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key);
        if ($key === '') continue;
        $value = is_array($value) ? '' : trim((string)$value);
        if (in_array($key, $fixedColumns, true)) {
            $sql = "UPDATE id_members SET `$key` = ?, updated_at = NOW() WHERE id = ?";
            $pdo->prepare($sql)->execute([$value !== '' ? $value : null, $memberId]);
        } else {
            $dynamic[$key] = $value;
        }
    }
    if ($dynamic) {
        $stmt = $pdo->prepare(
            'INSERT INTO member_dynamic_values (member_id, template_id, field_key, field_value)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), template_id = VALUES(template_id), updated_at = NOW()'
        );
        foreach ($dynamic as $key => $value) {
            $stmt->execute([$memberId, $templateId, $key, $value]);
        }
    }
}

function generation_load_assigned_template(PDO $pdo, array $member): ?array
{
    $templateId = (int)($member['template_id'] ?? 0);
    if ($templateId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM card_templates
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        return null;
    }

    $templateOrgId = (int)($template['organization_id'] ?? 0);
    $memberOrgId = (int)($member['organization_id'] ?? 0);
    if ($templateOrgId !== 0 && $templateOrgId !== $memberOrgId) {
        return null;
    }

    return $template;
}

if ($memberId > 0) {
    // Org ownership enforced — returns null on IDOR / soft-deleted
    $member = fetch_member_for_user($pdo, $authUser, $memberId);
    if (!$member) {
        $error = 'Member not found or you do not have access to this member.';
        $memberId = 0;
    } else {
        $assignedTemplate = generation_load_assigned_template($pdo, $member);
        if (!$assignedTemplate) {
            $error = 'This member does not have an accessible assigned template.';
        } else {
            $selectedTemplateId = (int)$assignedTemplate['id'];
            $member = array_merge($member, [
                'template_id' => $assignedTemplate['id'],
                'template_name' => $assignedTemplate['name'],
                'template_orientation' => $assignedTemplate['orientation'],
                'template_primary_color' => $assignedTemplate['primary_color'],
                'template_secondary_color' => $assignedTemplate['secondary_color'],
                'template_text_color' => $assignedTemplate['text_color'],
                'template_font' => $assignedTemplate['font'],
                'template_background' => $assignedTemplate['background_image'],
                'template_mirror_print' => $assignedTemplate['mirror_print'],
                'card_width' => $assignedTemplate['card_width'],
                'card_height' => $assignedTemplate['card_height'],
                'template_deleted_at' => $assignedTemplate['deleted_at'],
                'template_status' => $assignedTemplate['status'],
            ]);

            // Check missing required fields
            $compat = member_check_template_compatibility($pdo, $memberId, $selectedTemplateId);
            if (!empty($compat['missing_required'])) {
                $generationMissing = $compat['missing_required'];
                $labels = array_map(fn($d) => $d['field_label'] ?? $d['field_key'], $compat['missing_required']);
                $warning = 'Member is missing required field value(s) for this template: <strong>' . htmlspecialchars(implode(', ', $labels)) . '</strong>. Click <strong>Generate Card</strong> to fill them in the popup.';
            }
        }
    }
}

// Member picker list — org-scoped, exclude soft-deleted
$members = [];
if (!$member) {
    if ($isSuperAdmin) {
        $stmt = $pdo->query(
            'SELECT id, name, unique_id, member_type, organization_id
             FROM id_members WHERE deleted_at IS NULL ORDER BY name'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, name, unique_id, member_type, organization_id
             FROM id_members WHERE deleted_at IS NULL AND organization_id = ? ORDER BY name'
        );
        $stmt->execute([$userOrgId]);
    }
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$availableTemplates = [];
$targetTemplate = null;
$targetCompatibility = null;
if ($member) {
    $availableTemplates = generation_load_available_templates($pdo, $member);
    $requestedPreviewTemplateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : (int)($member['template_id'] ?? 0);
    foreach ($availableTemplates as $tpl) {
        if ((int)$tpl['id'] === $requestedPreviewTemplateId) { $targetTemplate = $tpl; break; }
    }
    if ($targetTemplate) {
        $targetCompatibility = member_check_template_compatibility($pdo, $memberId, (int)$targetTemplate['id']);
    }
}

$activeTemplate = null;
if ($member && $assignedTemplate) {
    $activeTemplate = $assignedTemplate;
}

$previewFields = [];
$previewHtmlFront = '';
$previewHtmlBack = '';
$previewScale = 0.5;
$previewCardW = 533;
$previewCardH = 864;
if ($selectedTemplateId > 0 && $member) {
    try {
        ensure_card_renderer_schema($pdo);
        $template = card_renderer_template($pdo, $selectedTemplateId, true);
        $renderMember = card_renderer_member($pdo, (int)$member['id']);
        $definitions = card_renderer_definitions($pdo, $selectedTemplateId);
        $layout = card_renderer_layout($pdo, $selectedTemplateId);
        $previewHtmlFront = card_renderer_html($template, $renderMember, $definitions, $layout, 'front', '');
        $previewHtmlBack = card_renderer_html($template, $renderMember, $definitions, $layout, 'back', '');
        $previewFields = array_values($layout);
        $previewCardW = max(50, (int)($template['card_width'] ?? 533));
        $previewCardH = max(50, (int)($template['card_height'] ?? 864));
        $orient = strtolower((string)($template['orientation'] ?? 'portrait'));
        $boxW = $orient === 'landscape' ? 420 : 300;
        $boxH = $orient === 'landscape' ? 260 : 486;
        $previewScale = min($boxW / $previewCardW, $boxH / $previewCardH);
    } catch (Throwable $e) {
        $previewHtmlFront = '';
        $previewHtmlBack = '';
    }
}

// Handle member template switching. Existing member values are never deleted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_member_template'])) {
    $switchMemberId = (int)($_POST['member_id'] ?? 0);
    $newTemplateId = (int)($_POST['new_template_id'] ?? 0);
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } elseif ($switchMemberId <= 0 || $newTemplateId <= 0) {
        $error = 'Please select a valid member and template.';
    } else {
        $ownedMember = fetch_member_for_user($pdo, $authUser, $switchMemberId);
        if (!$ownedMember) {
            $error = 'Member not found or access denied.';
        } else {
            $allowedTemplates = generation_load_available_templates($pdo, $ownedMember);
            $allowedIds = array_map(static fn($t) => (int)$t['id'], $allowedTemplates);
            if (!in_array($newTemplateId, $allowedIds, true)) {
                $error = 'The selected template is not available for this organisation.';
            } else {
                $compat = member_check_template_compatibility($pdo, $switchMemberId, $newTemplateId);
                $missingRequired = $compat['missing_required'] ?? [];
                $extraValues = [];
                foreach ($missingRequired as $key => $field) {
                    $value = $_POST['missing'][$key] ?? '';
                    if (is_array($value)) $value = '';
                    $value = trim((string)$value);
                    // Switching a template must never be blocked by absent
                    // values. Save only what the user supplied; generation
                    // will enforce that template's required fields later.
                    if ($value !== '') $extraValues[$key] = $value;
                }
                if ($error === '') {
                    try {
                        $pdo->beginTransaction();
                        generation_save_field_values($pdo, $switchMemberId, $newTemplateId, $extraValues);
                        $pdo->prepare('UPDATE id_members SET template_id = ?, updated_at = NOW() WHERE id = ?')
                            ->execute([$newTemplateId, $switchMemberId]);
                        if (function_exists('template_mark_first_used')) template_mark_first_used($pdo, $newTemplateId);
                        $pdo->commit();
                        logAuditActivity($pdo, $authUser, 'Changed member template', 'members', "Member ID: {$switchMemberId}, Template ID: {$newTemplateId}", (int)($ownedMember['organization_id'] ?? 0));
                        header('Location: generate_id_card.php?member_id=' . $switchMemberId . '&switched=1');
                        exit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Unable to change template: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

if (isset($_GET['switched']) && $_GET['switched'] === '1' && $message === '') {
    $message = 'Member template changed successfully. Existing member data was preserved.';
}

// Save only the required values supplied in the generation popup. This does
// not change the active template or remove values belonging to other templates.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_missing_fields'])) {
    $saveMemberId = (int)($_POST['member_id'] ?? 0);
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } else {
        $ownedMember = fetch_member_for_user($pdo, $authUser, $saveMemberId);
        $templateId = (int)($ownedMember['template_id'] ?? 0);
        if (!$ownedMember || $templateId <= 0) {
            $error = 'Member or assigned template was not found.';
        } else {
            $required = member_check_template_compatibility($pdo, $saveMemberId, $templateId)['missing_required'] ?? [];
            $submitted = is_array($_POST['missing'] ?? null) ? $_POST['missing'] : [];
            $values = [];
            foreach ($required as $key => $_field) {
                $value = $submitted[$key] ?? '';
                if (!is_array($value) && trim((string)$value) !== '') {
                    $values[$key] = trim((string)$value);
                }
            }
            generation_save_field_values($pdo, $saveMemberId, $templateId, $values);
            $remaining = member_check_template_compatibility($pdo, $saveMemberId, $templateId)['missing_required'] ?? [];
            if (empty($remaining)) {
                header('Location: generate_id_card.php?member_id=' . $saveMemberId . '&missing_saved=1');
                exit();
            }
            $error = 'Please fill all required fields before generating the card.';
        }
    }
}

if (isset($_GET['missing_saved']) && $_GET['missing_saved'] === '1' && $message === '') {
    $message = 'Required member details were saved. You can now generate the card.';
}

// Handle card generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_card'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $requestedTemplateId = (int)($_POST['template_id'] ?? 0);
    $templateId = 0;
    $generateType = $_POST['generate_type'] ?? 'single';
    $mirror = isset($_POST['mirror']) ? 1 : 0;

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Invalid security token.';
    } elseif ($memberId <= 0) {
        $error = 'Please select a member.';
    } else {
        $ownedMember = fetch_member_for_user($pdo, $authUser, $memberId);
        if (!$ownedMember) {
            $error = 'Member not found or access denied.';
        } else {
            $assignedTemplate = generation_load_assigned_template($pdo, $ownedMember);
            if (!$assignedTemplate) {
                $error = 'This member does not have an accessible assigned template.';
            } else {
                $templateId = (int)$assignedTemplate['id'];
            }
        }

        if ($error === '' && $requestedTemplateId > 0 && $requestedTemplateId !== $templateId) {
            $error = 'Please switch the member to the selected template before generating the card.';
        }

        if ($error === '') {
            $compat = member_check_template_compatibility($pdo, $memberId, $templateId);
            if (!empty($compat['missing_required'])) {
                $labels = array_map(fn($d) => $d['field_label'] ?? $d['field_key'], $compat['missing_required']);
                $error = 'Missing required data: ' . implode(', ', $labels) . '. Please fill the missing fields before generating.';
            }
        }

        if ($error === '') {
            $result = generateCardImage($pdo, $memberId, $templateId, $mirror, $generateType);
            if ($result['success']) {
                $orgIdForCard = (int)($ownedMember['organization_id'] ?? 0) ?: null;
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
                logAuditActivity($pdo, $authUser, 'Generated ID card', 'cards', "Member ID: {$memberId}, Template ID: {$templateId}", $orgIdForCard);
                header("Location: card/view_card.php?id={$cardId}&generated=1");
                exit();
            }
            $error = $result['error'] ?? 'Failed to generate card image.';
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

        $uploadDir = __DIR__ . '/images/cards/';
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

function logAuditActivity(PDO $pdo, array $user, string $action, string $action_type, string $details, $organizationId = null): void
{
    $userId = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, organization_id, action, action_type, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $organizationId ? (int)$organizationId : null, $action, $action_type, $details, $ip, $ua]);
    } catch (Throwable $e) {
        // Fallback if organization_id column somehow missing
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, $action_type, $details, $ip, $ua]);
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Generate ID Card · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-500: #6b7280;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --radius-lg: 0.75rem;
            --radius-2xl: 1.5rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
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

        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
        }
        .alert-success { background: #e3f9ee; color: #0e9f6e; }
        .alert-danger { background: #fee2e2; color: #dc2626; }

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
            background: var(--neutral-100);
        }

        .card-body-custom { padding: 1.5rem; }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--neutral-700);
        }
        .form-control, .form-select {
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10,26,47,0.1);
        }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: #0e9f6e; color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-outline-secondary { border-color: var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        .member-list {
            max-height: 300px;
            overflow-y: auto;
        }
        .member-item {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-200);
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .member-item:hover {
            background: var(--neutral-100);
            border-color: var(--primary);
        }
        .member-item.selected {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .card-preview {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            text-align: center;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-direction: column;
            border: 2px dashed var(--neutral-300);
        }

        .preview-stage {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-top: 1rem;
        }

        .card-preview .preview-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
            margin: 0;
            padding: 0;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .preview-scale-wrap {
            position: relative;
            overflow: hidden;
            background: #fff;
            width: 100%;
            height: 100%;
        }

        .preview-scale-inner {
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }

        .preview-tabs { display:flex; gap:0.5rem; margin-bottom:0.75rem; width:100%; justify-content:center; }
        .preview-tabs button { 
            border:1px solid var(--neutral-300); 
            background:#fff; 
            border-radius:6px; 
            padding:0.35rem 0.65rem; 
            font-size:0.8rem; 
            cursor:pointer;
            transition: all 0.2s;
        }
        .preview-tabs button:hover { background:var(--neutral-100); }
        .preview-tabs button.active { 
            color:#fff; 
            background:var(--primary); 
            border-color:var(--primary); 
        }
        .preview-card[data-preview-side] { display:none; }
        .preview-card[data-preview-side].active { display:block; }

        .preview-both .preview-stage { gap: 1rem; flex-wrap: wrap; }
        .preview-both .preview-card[data-preview-side] { display:block !important; }
        .preview-both .preview-tabs { opacity: 0.6; pointer-events: none; }

        @media (max-width: 768px) {
            .card-preview .preview-card {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include 'includes/header.php'; ?>

            <div class="dashboard-content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Generate ID Card</li>
                    </ol>
                </nav>

                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-1"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <?php if ($warning): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <?= $warning ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="main-card">
                    <div class="card-header-custom">
                        <h5 style="font-weight:600;margin:0;">
                            <i class="fas fa-id-card text-primary me-2"></i>Generate ID Card
                        </h5>
                        <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                            Select a member to generate a card from the assigned template
                        </p>
                    </div>

                    <div class="card-body-custom">
                        <?php if ($member): ?>
                            <!-- Member Selected - Show Generation Form -->
                            <div class="row g-4">
                                <div class="col-md-7">
                                    <form method="POST" id="generateCardForm">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="member_id" value="<?= $memberId ?>">
                                        <input type="hidden" name="generate_card" value="1">

                                        <div class="mb-3">
                                            <label class="form-label">Member</label>
                                            <div class="form-control" style="background:var(--neutral-100);">
                                                <strong><?= htmlspecialchars($member['name']) ?></strong>
                                                <span class="text-muted ms-2">(<?= htmlspecialchars($member['unique_id']) ?>)</span>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Current Template</label>
                                            <input type="hidden" name="template_id" value="<?= (int)$selectedTemplateId ?>">
                                            <div class="form-control" style="background:var(--neutral-100);">
                                                <?php if ($activeTemplate): ?>
                                                    <strong><?= htmlspecialchars($activeTemplate['name']) ?></strong>
                                                    <span class="text-muted ms-2">(<?= ucfirst((string)$activeTemplate['orientation']) ?>)</span>
                                                <?php else: ?>
                                                    <span class="text-danger">No assigned template</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($activeTemplate && count($availableTemplates) > 1): ?>
                                            <div class="border rounded p-3 mb-3" style="background:#f8fafc;">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <label class="form-label mb-0"><i class="fas fa-layer-group me-1"></i>Change Template</label>
                                                    <span class="badge bg-light text-dark">Member data is preserved</span>
                                                </div>
                                                <select class="form-select" id="targetTemplateSelect">
                                                    <?php foreach ($availableTemplates as $tpl): ?>
                                                        <option value="<?= (int)$tpl['id'] ?>" <?= (int)$tpl['id'] === (int)$selectedTemplateId ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($tpl['name']) ?> · v<?= (int)($tpl['layout_version'] ?? 1) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">Fields with the same key are reused automatically. Missing required fields will be requested before switching.</div>
                                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="prepareTemplateSwitch()">
                                                    <i class="fas fa-exchange-alt me-1"></i>Check & Change Template
                                                </button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($targetCompatibility && ((int)$targetTemplate['id'] !== (int)$selectedTemplateId || !empty($targetCompatibility['missing_required']))): ?>
                                            <div class="border rounded p-3 mb-3" id="switchPanel" style="background:#fffdf5;">
                                                <div class="fw-semibold mb-2"><i class="fas fa-clipboard-check me-1"></i>Template Data Check</div>
                                                <?php if (!empty($targetCompatibility['missing_required'])): ?>
                                                    <div class="alert alert-warning mb-3">These required fields are missing. Add any values you have now, or skip them and fill them when generating the card.</div>
                                                    <div>
                                                        <input type="hidden" name="member_id" value="<?= $memberId ?>">
                                                        <input type="hidden" name="new_template_id" value="<?= (int)$targetTemplate['id'] ?>">
                                                        <?php foreach ($targetCompatibility['missing_required'] as $key => $field): ?>
                                                            <div class="mb-2">
                                                                <label class="form-label"><?= htmlspecialchars($field['field_label'] ?? $key) ?> <span class="text-danger">*</span></label>
                                                                <?php $ft = strtolower((string)($field['field_type'] ?? 'text')); ?>
                                                                <?php if ($ft === 'textarea'): ?>
                                                                    <textarea class="form-control" name="missing[<?= htmlspecialchars($key) ?>]" rows="2"></textarea>
                                                                <?php else: ?>
                                                                    <input class="form-control" type="<?= in_array($ft, ['date','email','number'], true) ? $ft : 'text' ?>" name="missing[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars((string)($field['default_value'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string)($field['placeholder'] ?? '')) ?>">
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <button class="btn btn-primary btn-sm" type="submit" name="switch_member_template" value="1"><i class="fas fa-save me-1"></i>Save Available Data & Switch</button>
                                                        <button class="btn btn-outline-secondary btn-sm" type="submit" name="switch_member_template" value="1"><i class="fas fa-forward me-1"></i>Skip & Switch Template</button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-success mb-2"><i class="fas fa-check-circle me-1"></i>All required data already exists. You can switch directly.</div>
                                                    <div>
                                                        <input type="hidden" name="member_id" value="<?= $memberId ?>">
                                                        <input type="hidden" name="new_template_id" value="<?= (int)$targetTemplate['id'] ?>">
                                                        <button class="btn btn-primary btn-sm" type="submit" name="switch_member_template" value="1"><i class="fas fa-exchange-alt me-1"></i>Switch Template</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <label class="form-label">Generate Type</label>
                                            <div class="d-flex gap-3">
                                                <div class="form-check">
                                                    <input type="radio" name="generate_type" class="form-check-input" value="single" checked>
                                                    <label class="form-check-label">Single Card</label>
                                                </div>
                                                <div class="form-check">
                                                    <input type="radio" name="generate_type" class="form-check-input" value="both">
                                                    <label class="form-check-label">Front + Back</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="mirror" class="form-check-input" id="mirror">
                                                <label class="form-check-label" for="mirror">
                                                    <i class="fas fa-undo me-1"></i> Mirror Print
                                                </label>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-success" <?= $activeTemplate ? '' : 'disabled' ?>>
                                                <i class="fas fa-id-card me-1"></i>Generate Card
                                            </button>
                                            <a href="view_member.php?id=<?= $memberId ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-arrow-left me-1"></i>Back
                                            </a>
                                        </div>
                                    </form>
                                </div>

                                <div class="col-md-5">
                                    <h6 class="fw-bold mb-3">Preview</h6>
                                    <div class="card-preview" id="cardPreview">
                                        <div class="preview-tabs" role="tablist" aria-label="Card preview side">
                                            <button type="button" class="active" data-preview-tab="front">Front</button>
                                            <button type="button" data-preview-tab="back">Back</button>
                                        </div>

                                        <?= card_renderer_css() ?>
                                        <?php
                                        $scaledW = max(120, (int)round($previewCardW * $previewScale));
                                        $scaledH = max(120, (int)round($previewCardH * $previewScale));
                                        $scaleCss = number_format($previewScale, 4, '.', '');
                                        ?>
                                        <div class="preview-stage">
                                            <div class="preview-card active <?= htmlspecialchars((string)($activeTemplate['orientation'] ?? 'portrait')) ?>"
                                                 data-preview-side="front"
                                                 style="width:<?= $scaledW ?>px;height:<?= $scaledH ?>px;">
                                                <?php if ($previewHtmlFront !== ''): ?>
                                                    <div class="preview-scale-wrap">
                                                        <div class="preview-scale-inner"
                                                             style="width:<?= (int)$previewCardW ?>px;height:<?= (int)$previewCardH ?>px;transform:scale(<?= $scaleCss ?>);">
                                                            <?= $previewHtmlFront ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-muted p-3 text-center">No front layout saved for this template.</div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="preview-card <?= htmlspecialchars((string)($activeTemplate['orientation'] ?? 'portrait')) ?>"
                                                 data-preview-side="back"
                                                 style="width:<?= $scaledW ?>px;height:<?= $scaledH ?>px;">
                                                <?php if ($previewHtmlBack !== ''): ?>
                                                    <div class="preview-scale-wrap">
                                                        <div class="preview-scale-inner"
                                                             style="width:<?= (int)$previewCardW ?>px;height:<?= (int)$previewCardH ?>px;transform:scale(<?= $scaleCss ?>);">
                                                            <?= $previewHtmlBack ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-muted p-3 text-center">No back-side fields configured</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Select Member -->
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <h6 class="fw-bold mb-3">Select Member</h6>
                                    <div class="member-list">
                                        <?php if (empty($members)): ?>
                                            <div class="text-center text-muted py-4">
                                                <i class="fas fa-users" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>
                                                No members found.
                                                <br>
                                                <a href="Members/add_member.php" class="btn btn-sm btn-primary mt-2">
                                                    <i class="fas fa-plus me-1"></i>Add Member
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($members as $m): ?>
                                                <div class="member-item" onclick="selectMember(<?= $m['id'] ?>)">
                                                    <div class="fw-semibold"><?= htmlspecialchars($m['name']) ?></div>
                                                    <div class="small text-muted"><?= htmlspecialchars($m['unique_id']) ?> · <?= ucfirst($m['member_type']) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card-preview" style="min-height:200px;">
                                        <i class="fas fa-hand-pointer" style="font-size:3rem;color:var(--neutral-300);margin-bottom:1rem;"></i>
                                        <p class="text-muted">Select a member from the list to generate ID card</p>
                                    </div>
                                </div>
                            </div>

                            <form method="GET" id="memberSelectForm">
                                <input type="hidden" name="member_id" id="selectedMemberId" value="0">
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <?php if ($member && !empty($generationMissing)): ?>
        <div class="modal fade" id="missingFieldsModal" tabindex="-1" aria-labelledby="missingFieldsModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="member_id" value="<?= (int)$memberId ?>">
                    <input type="hidden" name="save_missing_fields" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title" id="missingFieldsModalTitle"><i class="fas fa-clipboard-list me-2"></i>Fill Required Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-3">Complete these fields to generate <strong><?= htmlspecialchars((string)($activeTemplate['name'] ?? 'this card')) ?></strong>.</p>
                        <?php foreach ($generationMissing as $key => $field): ?>
                            <?php $fieldType = strtolower((string)($field['field_type'] ?? 'text')); ?>
                            <div class="mb-3">
                                <label class="form-label" for="missing_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars((string)($field['field_label'] ?? $key)) ?> <span class="text-danger">*</span></label>
                                <?php if (in_array($fieldType, ['textarea', 'address'], true)): ?>
                                    <textarea class="form-control" id="missing_<?= htmlspecialchars($key) ?>" name="missing[<?= htmlspecialchars($key) ?>]" rows="2" required></textarea>
                                <?php else: ?>
                                    <input class="form-control" id="missing_<?= htmlspecialchars($key) ?>" name="missing[<?= htmlspecialchars($key) ?>]"
                                           type="<?= in_array($fieldType, ['date', 'email', 'number'], true) ? $fieldType : 'text' ?>"
                                           value="<?= htmlspecialchars((string)($field['default_value'] ?? '')) ?>"
                                           placeholder="<?= htmlspecialchars((string)($field['placeholder'] ?? '')) ?>" required>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Required Details</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const missingFieldsModal = document.getElementById('missingFieldsModal');
        const generateCardForm = document.getElementById('generateCardForm');
        if (missingFieldsModal && generateCardForm && window.bootstrap) {
            generateCardForm.addEventListener('submit', function(event) {
                // The switch-template buttons submit this form too; only block
                // a genuine Generate Card submission.
                if (event.submitter && event.submitter.name === 'switch_member_template') return;
                event.preventDefault();
                bootstrap.Modal.getOrCreateInstance(missingFieldsModal).show();
            });
        }

        function selectMember(id) {
            document.getElementById('selectedMemberId').value = id;
            document.getElementById('memberSelectForm').submit();
        }

        function prepareTemplateSwitch() {
            const select = document.getElementById('targetTemplateSelect');
            if (!select || !select.value) return;
            const url = new URL(window.location.href);
            url.searchParams.set('member_id', '<?= (int)$memberId ?>');
            url.searchParams.set('template_id', select.value);
            window.location.href = url.toString();
        }

        function setPreviewSide(side) {
            document.querySelectorAll('[data-preview-tab]').forEach(function(button) {
                button.classList.toggle('active', button.dataset.previewTab === side);
                button.setAttribute('aria-selected', button.dataset.previewTab === side ? 'true' : 'false');
            });
            document.querySelectorAll('[data-preview-side]').forEach(function(card) {
                card.classList.toggle('active', card.dataset.previewSide === side);
            });
        }

        document.querySelectorAll('[data-preview-tab]').forEach(function(tab) {
            tab.addEventListener('click', function() {
                setPreviewSide(tab.dataset.previewTab);
            });
        });

        document.querySelectorAll('input[name="generate_type"]').forEach(function(input) {
            input.addEventListener('change', function() {
                const preview = document.getElementById('cardPreview');
                if (input.value === 'both' && input.checked) {
                    preview?.classList.add('preview-both');
                } else if (input.value === 'single' && input.checked) {
                    preview?.classList.remove('preview-both');
                    setPreviewSide('front');
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                document.querySelector('button[type="submit"]')?.click();
            }
        });
    </script>
</body>
</html>
