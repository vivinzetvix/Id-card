<?php
/**
 * Phase 3 Module 2 — Template Designer
 * Interact.js layout editor (no Fabric.js). Soft-archives template_fields.
 * Enhanced with Undo/Redo, Multi-select, Layers, Snap to Grid, Export/Import, Auto-save.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/template_functions.php';
require_once __DIR__ . '/template_mgmt_helpers.php';
require_once __DIR__ . '/../includes/card_renderer.php';
require_once __DIR__ . '/../middleware/auth.php';

ensure_card_renderer_schema($pdo);
try { $pdo->exec('ALTER TABLE template_fields DROP INDEX uq_template_field_side'); } catch (Throwable $e) { /* ok */ }
try { $pdo->exec('ALTER TABLE template_fields DROP INDEX uq_template_field'); } catch (Throwable $e) { /* ok */ }

require_login();

$authUser = get_auth_user($pdo);
if (!$authUser) {
    header('Location: ' . auth_login_url());
    exit();
}

$page_title = 'Template Designer';
$message = '';
$error = '';
$isSuperAdmin = auth_is_super_admin($authUser);
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);
$username = (string)($authUser['username'] ?? $_SESSION['username'] ?? '');

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

function designer_wants_json(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) return true;
    return !empty($_POST['ajax']) || !empty($_GET['ajax']);
}

function designer_json_response(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function designer_csrf_ok(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function designer_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function designer_clamp_pct(float $v, float $min = 0.0, float $max = 100.0): float {
    return max($min, min($max, $v));
}

function designer_allowed_object_types(): array {
    return ['dynamic', 'static_text', 'photo', 'logo', 'qr', 'barcode', 'signature', 'image'];
}

function designer_normalize_object_type(string $type): string {
    $type = strtolower(trim($type));
    return in_array($type, designer_allowed_object_types(), true) ? $type : 'dynamic';
}

function designer_safe_hex_color(?string $color, ?string $fallback = null): ?string {
    if ($color === null || $color === '') return $fallback;
    $color = trim($color);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color)) return $color;
    return $fallback;
}

function designer_log_audit(PDO $pdo, ?int $userId, ?int $orgId, string $action, string $actionType, string $details): void {
    try {
        $hasOrg = designer_column_exists($pdo, 'audit_log', 'organization_id');
        if ($hasOrg) {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (user_id, organization_id, action, action_type, details, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $orgId, $action, $actionType, $details, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $action, $actionType, $details, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

function designer_store_template_image(array $file, string $prefix = 'tpl'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }
    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Image must be 5MB or smaller.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
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
    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Failed to save uploaded file.'];
    }
    return ['ok' => true, 'path' => 'images/templates/' . $filename];
}

// ─── Load template ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT t.*, o.organization_name, o.project_type, o.logo AS org_logo
     FROM card_templates t
     LEFT JOIN organizations o ON t.organization_id = o.id
     WHERE t.id = ? AND t.status = 1 AND (t.deleted_at IS NULL OR t.deleted_at = \'0000-00-00 00:00:00\')'
);
$stmt->execute([$templateId]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

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

$orgId = (int)($template['organization_id'] ?? 0);

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!designer_csrf_ok()) {
        if (designer_wants_json()) designer_json_response(['success' => false, 'error' => 'Invalid security token.'], 403);
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        if (isset($_POST['upload_background'])) {
            $result = designer_store_template_image($_FILES['background'] ?? [], 'bg' . $templateId);
            if (!$result['ok']) {
                if (designer_wants_json()) designer_json_response(['success' => false, 'error' => $result['error']], 400);
                $error = $result['error'];
            } else {
                $old = (string)($template['background_image'] ?? '');
                $upd = $pdo->prepare(
                    'UPDATE card_templates SET background_image = ?, updated_by = ?, layout_version = 2 WHERE id = ?'
                );
                $upd->execute([$result['path'], $userId ?: null, $templateId]);
                $template['background_image'] = $result['path'];
                if ($old !== '' && strpos($old, 'images/templates/') === 0) {
                    $oldPath = dirname(__DIR__) . '/' . $old;
                    if (is_file($oldPath)) @unlink($oldPath);
                }
                designer_log_audit($pdo, $userId ?: null, $orgId ?: null, 'Updated template background', 'templates', "Template ID: $templateId");
                if (designer_wants_json()) {
                    designer_json_response(['success' => true, 'background_image' => $result['path'], 'url' => '../' . $result['path']]);
                }
                $message = 'Background image updated.';
            }
        }

        if ($error === '' && isset($_POST['remove_background'])) {
            $old = (string)($template['background_image'] ?? '');
            $upd = $pdo->prepare(
                'UPDATE card_templates SET background_image = NULL, updated_by = ?, layout_version = 2 WHERE id = ?'
            );
            $upd->execute([$userId ?: null, $templateId]);
            $template['background_image'] = null;
            if ($old !== '' && strpos($old, 'images/templates/') === 0) {
                $oldPath = dirname(__DIR__) . '/' . $old;
                if (is_file($oldPath)) @unlink($oldPath);
            }
            designer_log_audit($pdo, $userId ?: null, $orgId ?: null, 'Removed template background', 'templates', "Template ID: $templateId");
            if (designer_wants_json()) designer_json_response(['success' => true, 'background_image' => null]);
            $message = 'Background image removed.';
        }

        if ($error === '' && isset($_POST['save_background_settings'])) {
            $posX = isset($_POST['bg_pos_x']) ? (float)$_POST['bg_pos_x'] : 50.0;
            $posY = isset($_POST['bg_pos_y']) ? (float)$_POST['bg_pos_y'] : 50.0;
            $bgSize = isset($_POST['bg_size']) ? trim((string)$_POST['bg_size']) : 'cover';
            $allowedSizes = ['cover', 'contain', '100% 100%', 'auto', '110%', '120%', '150%', '200%'];
            if (!in_array($bgSize, $allowedSizes, true)) $bgSize = 'cover';

            $upd = $pdo->prepare('UPDATE card_templates SET bg_pos_x = ?, bg_pos_y = ?, bg_size = ?, updated_by = ? WHERE id = ?');
            $upd->execute([$posX, $posY, $bgSize, $userId ?: null, $templateId]);
            $template['bg_pos_x'] = $posX;
            $template['bg_pos_y'] = $posY;
            $template['bg_size'] = $bgSize;
            if (designer_wants_json()) designer_json_response(['success' => true, 'bg_pos_x' => $posX, 'bg_pos_y' => $posY, 'bg_size' => $bgSize]);
        }

        if ($error === '' && isset($_POST['add_input_field'])) {
            $fLabel = trim((string)($_POST['field_label'] ?? ''));
            $rawKey = trim((string)($_POST['field_key'] ?? ''));
            $fKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($rawKey ?: $fLabel));
            $fType = strtolower(trim((string)($_POST['field_type'] ?? 'text')));
            $isReq = !empty($_POST['is_required']) ? 1 : 0;
            $placeholder = trim((string)($_POST['placeholder'] ?? ''));
            $defaultValue = trim((string)($_POST['default_value'] ?? ''));
            $bilingualMode = strtolower(trim((string)($_POST['bilingual_mode'] ?? 'single'))) === 'bilingual' ? 'bilingual' : 'single';
            $isCommon = !empty($_POST['is_common']) ? 1 : 0;
            $targetTplId = $isCommon ? 0 : $templateId;

            if ($fLabel === '' || $fKey === '') {
                if (designer_wants_json()) designer_json_response(['success' => false, 'error' => 'Field label and key are required.'], 400);
                $error = 'Field label and key are required.';
            } else {
                // Check duplicate key
                $chk = $pdo->prepare('SELECT COUNT(*) FROM template_input_fields WHERE field_key = ? AND template_id IN (?, 0) AND archived_at IS NULL');
                $chk->execute([$fKey, $templateId]);
                $exists = (int)$chk->fetchColumn() > 0;
                $systemKeys = ['name', 'guardian_name', 'member_type', 'unique_id', 'email', 'emergency_contact', 'dob', 'address', 'department', 'class', 'designation', 'company', 'purpose', 'joined_date', 'expiry_date', 'organization_name', 'photo', 'signature', 'terms'];
                if ($exists || in_array($fKey, $systemKeys, true)) {
                    if (designer_wants_json()) designer_json_response(['success' => false, 'error' => "Field Key '{$fKey}' already exists. Please choose a unique field key."], 400);
                    $error = "Field Key '{$fKey}' already exists. Please choose a unique field key.";
                } else {
                    $stmt = $pdo->prepare('INSERT INTO template_input_fields 
                        (template_id, field_key, field_label, field_type, bilingual_mode, is_required, is_enabled, placeholder, default_value)
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
                    $stmt->execute([
                        $targetTplId, $fKey, $fLabel, $fType, $bilingualMode, $isReq,
                        $placeholder !== '' ? $placeholder : null,
                        $defaultValue !== '' ? $defaultValue : null
                    ]);
                    if (designer_wants_json()) {
                        designer_json_response([
                            'success' => true,
                            'field' => [
                                'field_key' => $fKey,
                                'field_label' => $fLabel,
                                'field_type' => $fType,
                                'template_id' => $targetTplId,
                                'is_common' => $isCommon
                            ]
                        ]);
                    }
                }
            }
        }

if ($error === '' && isset($_POST['update_field_definition'])) {

$fKey = trim((string)($_POST['field_key'] ?? ''));
$fLabel = (string)($_POST['field_label'] ?? '');
$fLabelForValidation = trim($fLabel);
$placeholder = trim((string)($_POST['placeholder'] ?? ''));
$defaultValue = trim((string)($_POST['default_value'] ?? ''));

    if ($fKey === '') {
        if (designer_wants_json()) {
            designer_json_response([
                'success' => false,
                'error' => 'Field key required.'
            ], 400);
        }

        $error = 'Field key required.';

    } elseif ($fLabelForValidation === '') {

        if (designer_wants_json()) {
            designer_json_response([
                'success' => false,
                'error' => 'Field label cannot be empty.'
            ], 400);
        }

        $error = 'Field label cannot be empty.';

    } else {

        // Get existing field definition.
        $existingStmt = $pdo->prepare(
            'SELECT id, field_type, bilingual_mode, is_required
             FROM template_input_fields
             WHERE field_key = ?
               AND template_id IN (?, 0)
               AND archived_at IS NULL
             ORDER BY template_id DESC, id DESC
             LIMIT 1'
        );

        $existingStmt->execute([
            $fKey,
            $templateId
        ]);

        $existingDef = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $fType = $existingDef['field_type'] ?? 'text';
        $bilingual = $existingDef['bilingual_mode'] ?? 'single';
        $isReq = !empty($existingDef['is_required']) ? 1 : 0;

        // IMPORTANT:
        // Always create/update a template-specific override.
        // We do NOT modify the global common field (template_id = 0).

        $checkStmt = $pdo->prepare(
            'SELECT id
             FROM template_input_fields
             WHERE template_id = ?
               AND field_key = ?
             ORDER BY id DESC
             LIMIT 1'
        );

        $checkStmt->execute([
            $templateId,
            $fKey
        ]);

        $templateFieldId = (int)($checkStmt->fetchColumn() ?: 0);

        if ($templateFieldId > 0) {

            // Existing template override → UPDATE
            $updateStmt = $pdo->prepare(
                'UPDATE template_input_fields
                 SET field_label = ?,
                     placeholder = ?,
                     default_value = ?,
                     field_type = ?,
                     bilingual_mode = ?,
                     is_required = ?,
                     is_enabled = 1,
                     archived_at = NULL
                 WHERE id = ?'
            );

            $updateStmt->execute([
                $fLabel,
                $placeholder !== '' ? $placeholder : null,
                $defaultValue !== '' ? $defaultValue : null,
                $fType,
                $bilingual,
                $isReq,
                $templateFieldId
            ]);

        } else {

            // No template override → INSERT
            $insertStmt = $pdo->prepare(
                'INSERT INTO template_input_fields
                (
                    template_id,
                    field_key,
                    field_label,
                    field_type,
                    bilingual_mode,
                    is_required,
                    is_enabled,
                    placeholder,
                    default_value
                )
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );

            $insertStmt->execute([
                $templateId,
                $fKey,
                $fLabel,
                $fType,
                $bilingual,
                $isReq,
                $placeholder !== '' ? $placeholder : null,
                $defaultValue !== '' ? $defaultValue : null
            ]);
        }

        if (designer_wants_json()) {
            designer_json_response([
                'success' => true,
                'field_key' => $fKey,
                'field_label' => $fLabel
            ]);
        }
    }
}

        if ($error === '' && isset($_POST['delete_custom_field'])) {
            $fKey = trim((string)($_POST['field_key'] ?? ''));
            if ($fKey !== '') {
                // Archive the input field definition only for this template (not global common fields)
                $stmt = $pdo->prepare(
                    'UPDATE template_input_fields SET archived_at = NOW(), is_enabled = 0
                     WHERE field_key = ? AND template_id = ? AND archived_at IS NULL'
                );
                $stmt->execute([$fKey, $templateId]);
                // Also archive the layout object for this template
                $stmt2 = $pdo->prepare(
                    'UPDATE template_fields SET archived_at = NOW()
                     WHERE field_key = ? AND template_id = ? AND archived_at IS NULL'
                );
                $stmt2->execute([$fKey, $templateId]);
                if (designer_wants_json()) designer_json_response(['success' => true]);
            } else {
                if (designer_wants_json()) designer_json_response(['success' => false, 'error' => 'Field key required.'], 400);
            }
        }

        if ($error === '' && isset($_POST['upload_object_image'])) {
            $result = designer_store_template_image($_FILES['object_image'] ?? [], 'obj' . $templateId);
            if (!$result['ok']) designer_json_response(['success' => false, 'error' => $result['error']], 400);
            designer_json_response(['success' => true, 'image_path' => $result['path'], 'url' => '../' . $result['path']]);
        }

        // ─── Create new template version ────────────────────────────────────────
        if ($error === '' && isset($_POST['create_new_version'])) {
            $result = template_create_new_version($pdo, $templateId, $userId);
            if (!$result['success']) {
                designer_json_response(['success' => false, 'error' => $result['error']], 500);
            }
            designer_json_response([
                'success' => true,
                'new_id' => $result['new_id'],
                'version' => $result['version'],
                'redirect' => 'design_template.php?id=' . $result['new_id'] . '&new_version=1'
            ]);
        }

        if ($error === '' && isset($_POST['save_positions'])) {
            // Check if template is in-use and caller did NOT explicitly confirm versioning
            if (!isset($_POST['version_confirmed'])) {
                $memberCount = template_is_in_use($pdo, $templateId);
                if ($memberCount > 0) {
                    designer_json_response([
                        'success'       => false,
                        'needs_version' => true,
                        'member_count'  => $memberCount,
                        'template_name' => (string)($template['name'] ?? ''),
                        'error'         => "This template is used by {$memberCount} member(s). Please confirm whether to save as a new version."
                    ]);
                }
            }
            $raw = $_POST['positions'] ?? '[]';
            $positions = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($positions)) {
                if (designer_wants_json()) designer_json_response(['success' => false, 'error' => 'Invalid position data.'], 400);
                $error = 'Invalid position data.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $insertSql = 'INSERT INTO template_fields (
                        template_id, field_key, object_type, side, x, y, width, height, visible,
                        font_size, font_family, font_weight, font_style, color, text_align, text_decoration,
                        opacity, border_width, border_color, border_style, border_radius, show_label, content, image_path, z_index
                    ) VALUES (
                        ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                    )';
                    $insertStmt = $pdo->prepare($insertSql);

                    $updateSql = 'UPDATE template_fields SET
                        field_key = ?, object_type = ?, side = ?, x = ?, y = ?, width = ?, height = ?, visible = ?,
                        font_size = ?, font_family = ?, font_weight = ?, font_style = ?, color = ?, text_align = ?,
                        text_decoration = ?, opacity = ?, border_width = ?, border_color = ?, border_style = ?,
                        border_radius = ?, show_label = ?, content = ?, image_path = ?, z_index = ?, archived_at = NULL
                        WHERE id = ? AND template_id = ?';
                    $updateStmt = $pdo->prepare($updateSql);

                    $savedIds = [];
                    $successCount = 0;

                    foreach ($positions as $pos) {
                        if (!is_array($pos)) continue;

                        $objectType = designer_normalize_object_type((string)($pos['object_type'] ?? 'dynamic'));
                        $side = strtolower((string)($pos['side'] ?? 'front'));
                        if (!in_array($side, ['front', 'back'], true)) $side = 'front';

                        $x = designer_clamp_pct((float)($pos['x'] ?? 0));
                        $y = designer_clamp_pct((float)($pos['y'] ?? 0));
                        $width = designer_clamp_pct((float)($pos['width'] ?? 20), 1, 100);
                        $height = designer_clamp_pct((float)($pos['height'] ?? 10), 1, 100);
                        if ($x + $width > 100) $width = max(1, 100 - $x);
                        if ($y + $height > 100) $height = max(1, 100 - $y);

                        $visible = !empty($pos['visible']) ? 1 : 0;
                        $fontSize = max(7, min(72, (int)($pos['font_size'] ?? 12)));
                        $fontFamily = isset($pos['font_family']) && $pos['font_family'] !== '' ? substr(trim((string)$pos['font_family']), 0, 100) : null;
                        $fontWeight = isset($pos['font_weight']) && $pos['font_weight'] !== '' ? substr(trim((string)$pos['font_weight']), 0, 16) : null;
                        $fontStyle = isset($pos['font_style']) && $pos['font_style'] !== '' ? substr(trim((string)$pos['font_style']), 0, 16) : null;
                        $color = designer_safe_hex_color(isset($pos['color']) ? (string)$pos['color'] : null);
                        $textAlign = strtolower((string)($pos['text_align'] ?? 'left'));
                        if (!in_array($textAlign, ['left', 'center', 'right'], true)) $textAlign = 'left';
                        $textDecoration = isset($pos['text_decoration']) && $pos['text_decoration'] !== '' ? substr(trim((string)$pos['text_decoration']), 0, 32) : null;
                        $opacity = isset($pos['opacity']) ? (float)$pos['opacity'] : 1.0;
                        $opacity = max(0, min(1, $opacity));
                        $borderWidth = isset($pos['border_width']) && $pos['border_width'] !== '' && $pos['border_width'] !== null ? max(0, (float)$pos['border_width']) : null;
                        $borderColor = designer_safe_hex_color(isset($pos['border_color']) ? (string)$pos['border_color'] : null);
                        $borderStyle = isset($pos['border_style']) && $pos['border_style'] !== '' ? substr(trim((string)$pos['border_style']), 0, 16) : null;
                        $borderRadius = isset($pos['border_radius']) && $pos['border_radius'] !== '' && $pos['border_radius'] !== null ? max(0, (float)$pos['border_radius']) : null;
                        $showLabel = !empty($pos['show_label']) ? 1 : 0;
                        $zIndex = isset($pos['z_index']) ? (int)$pos['z_index'] : 0;

                        $fieldKey = null;
                        $content = null;
                        $imagePath = null;

                        if ($objectType === 'static_text') {
                            $content = (string)($pos['content'] ?? 'Text');
                            $fieldKey = null;
                        } elseif ($objectType === 'image') {
                            $imagePath = isset($pos['image_path']) ? substr(trim((string)$pos['image_path']), 0, 255) : null;
                            $fieldKey = null;
                        } else {
                            $rawKey = isset($pos['field_key']) ? trim((string)$pos['field_key']) : '';
                            if ($objectType === 'dynamic') {
                                if ($rawKey === '') throw new InvalidArgumentException('Dynamic objects require field_key.');
                                $fieldKey = substr($rawKey, 0, 64);
                            } elseif ($rawKey !== '') {
                                $fieldKey = substr($rawKey, 0, 64);
                            }
                            if (in_array($fieldKey, ['terms', 'organization_name'], true)) {
                                $content = isset($pos['content']) ? substr((string)$pos['content'], 0, 65535) : null;
                            }
                        }

                        $idRaw = $pos['id'] ?? null;
                        $id = 0;
                        if (is_numeric($idRaw) && (int)$idRaw > 0) $id = (int)$idRaw;

                        if ($id > 0) {
                            $ownCheck = $pdo->prepare('SELECT id FROM template_fields WHERE id = ? AND template_id = ? LIMIT 1');
                            $ownCheck->execute([$id, $templateId]);
                            if ($ownCheck->fetchColumn()) {
                                $updateStmt->execute([
                                    $fieldKey, $objectType, $side, $x, $y, $width, $height, $visible,
                                    $fontSize, $fontFamily, $fontWeight, $fontStyle, $color, $textAlign,
                                    $textDecoration, $opacity, $borderWidth, $borderColor, $borderStyle,
                                    $borderRadius, $showLabel, $content, $imagePath, $zIndex, $id, $templateId,
                                ]);
                            } else {
                                $insertStmt->execute([
                                    $templateId, $fieldKey, $objectType, $side, $x, $y, $width, $height, $visible,
                                    $fontSize, $fontFamily, $fontWeight, $fontStyle, $color, $textAlign, $textDecoration,
                                    $opacity, $borderWidth, $borderColor, $borderStyle, $borderRadius, $showLabel, $content, $imagePath, $zIndex,
                                ]);
                                $id = (int)$pdo->lastInsertId();
                            }
                        } else {
                            $insertStmt->execute([
                                $templateId, $fieldKey, $objectType, $side, $x, $y, $width, $height, $visible,
                                $fontSize, $fontFamily, $fontWeight, $fontStyle, $color, $textAlign, $textDecoration,
                                $opacity, $borderWidth, $borderColor, $borderStyle, $borderRadius, $showLabel, $content, $imagePath, $zIndex,
                            ]);
                            $id = (int)$pdo->lastInsertId();
                        }

                        if ($id > 0) {
                            $savedIds[] = $id;
                            $successCount++;
                        }
                    }

                    if (!empty($savedIds)) {
                        $placeholders = implode(',', array_fill(0, count($savedIds), '?'));
                        $archiveSql = "UPDATE template_fields SET archived_at = NOW()
                                        WHERE template_id = ? AND archived_at IS NULL AND id NOT IN ($placeholders)";
                        $archiveStmt = $pdo->prepare($archiveSql);
                        $archiveStmt->execute(array_merge([$templateId], $savedIds));
                    } else {
                        $archiveStmt = $pdo->prepare(
                            'UPDATE template_fields SET archived_at = NOW() WHERE template_id = ? AND archived_at IS NULL'
                        );
                        $archiveStmt->execute([$templateId]);
                    }

                    $layoutStmt = $pdo->prepare('UPDATE card_templates SET layout_version = 2, updated_by = ? WHERE id = ?');
                    $layoutStmt->execute([$userId ?: null, $templateId]);

                    $pdo->commit();

                    designer_log_audit($pdo, $userId ?: null, $orgId ?: null, 'Updated template layout', 'templates', "Template ID: $templateId, Objects saved: $successCount");

                    if (designer_wants_json()) {
                        designer_json_response(['success' => true, 'saved' => $successCount, 'ids' => $savedIds, 'layout_version' => 2]);
                    }

                    $message = "Saved $successCount object(s) successfully.";
                    header('Location: design_template.php?id=' . $templateId . '&saved=1');
                    exit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if (designer_wants_json()) designer_json_response(['success' => false, 'error' => 'Failed to save layout.'], 500);
                    $error = 'Failed to save field positions. No changes were saved.';
                }
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] == '1' && $message === '') {
    $message = 'Layout saved successfully.';
}

// ─── Load layout objects ──────────────────────────────────────────────────────
$fieldsStmt = $pdo->prepare(
    'SELECT * FROM template_fields WHERE template_id = ? AND archived_at IS NULL ORDER BY z_index ASC, id'
);
$fieldsStmt->execute([$templateId]);
$fields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ─── Load input fields ────────────────────────────────────────────────────────
$memberPaletteFields = [
    ['field_key' => 'name', 'field_label' => 'Full Name', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'guardian_name', 'field_label' => 'Guardian Name', 'field_type' => 'text', 'is_common' => true],

    ['field_key' => 'unique_id', 'field_label' => 'Unique ID', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'email', 'field_label' => 'Email', 'field_type' => 'email', 'is_common' => true],
    ['field_key' => 'emergency_contact', 'field_label' => 'Emergency Contact', 'field_type' => 'phone', 'is_common' => true],
    ['field_key' => 'dob', 'field_label' => 'Date of Birth', 'field_type' => 'date', 'is_common' => true],
    ['field_key' => 'address', 'field_label' => 'Address', 'field_type' => 'textarea', 'is_common' => true],
    ['field_key' => 'department', 'field_label' => 'Department', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'class', 'field_label' => 'Class', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'designation', 'field_label' => 'Designation', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'company', 'field_label' => 'Company', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'purpose', 'field_label' => 'Purpose', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'joined_date', 'field_label' => 'Joined Date', 'field_type' => 'date', 'is_common' => true],
    ['field_key' => 'expiry_date', 'field_label' => 'Expiry Date', 'field_type' => 'date', 'is_common' => true],
    ['field_key' => 'organization_name', 'field_label' => 'Organization Name', 'field_type' => 'text', 'is_common' => true],
    ['field_key' => 'photo', 'field_label' => 'Member Photo', 'field_type' => 'photo', 'is_common' => true],
    ['field_key' => 'signature', 'field_label' => 'Signature', 'field_type' => 'signature', 'is_common' => true],
    ['field_key' => 'terms', 'field_label' => 'Terms', 'field_type' => 'text', 'is_common' => true],
];
// Build effective field definitions. Template-specific definitions override defaults.
$inputFields = $memberPaletteFields;
$paletteIndex = [];
foreach ($inputFields as $idx => $baseField) {
    $paletteIndex[(string)$baseField['field_key']] = $idx;
}

$templateInputDefinitions = get_template_input_fields($pdo, $templateId);
foreach ($templateInputDefinitions as $fieldDef) {
    $key = (string)($fieldDef['field_key'] ?? '');
    if ($key === '') continue;

    $definitionTemplateId = (int)($fieldDef['template_id'] ?? 0);
    $isTemplateOverride = $definitionTemplateId === $templateId && $templateId > 0;

    if (isset($paletteIndex[$key])) {
        if ($isTemplateOverride) {
            $inputFields[$paletteIndex[$key]] = array_merge(
                $inputFields[$paletteIndex[$key]],
                $fieldDef,
                ['is_common' => false]
            );
        }
        continue;
    }

    $fieldDef['is_common'] = !$isTemplateOverride;
    $paletteIndex[$key] = count($inputFields);
    $inputFields[] = $fieldDef;
}

// ─── Preview member ──────────────────────────────────────────────────────────
$previewMember = [
    'name' => 'Sample Name',
    'unique_id' => 'ID-001',
    'organization_name' => $template['organization_name'] ?? 'Organization',
    'photo' => '',
    'org_logo' => $template['org_logo'] ?? '',
    'dynamic_fields' => [],
    'member_type' => 'employee',
    'expiry_date' => date('Y-m-d', strtotime('+1 year')),
];

if ($orgId > 0) {
    try {
        $mStmt = $pdo->prepare(
            'SELECT m.*, o.organization_name, o.logo AS org_logo
             FROM id_members m
             LEFT JOIN organizations o ON o.id = m.organization_id
             WHERE m.organization_id = ? AND m.deleted_at IS NULL
             ORDER BY m.id DESC LIMIT 1'
        );
        $mStmt->execute([$orgId]);
        $realMember = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($realMember) {
            $previewMember = array_merge($previewMember, $realMember, [
                'name' => $realMember['name'] ?? $previewMember['name'],
                'unique_id' => $realMember['unique_id'] ?? $previewMember['unique_id'],
                'organization_name' => $realMember['organization_name'] ?? $previewMember['organization_name'],
                'photo' => $realMember['photo'] ?? '',
                'org_logo' => $realMember['org_logo'] ?? ($template['org_logo'] ?? ''),
                'member_type' => $realMember['member_type'] ?? 'employee',
                'expiry_date' => $realMember['expiry_date'] ?? $previewMember['expiry_date'],
            ]);
            if (!empty($realMember['id']) && function_exists('get_member_dynamic_field_records')) {
                $previewMember['dynamic_fields'] = get_member_dynamic_field_records($pdo, (int)$realMember['id'], $templateId);
            }
        }
    } catch (Throwable $e) { /* keep sample */ }
}

$previewDefinitions = card_renderer_definitions($pdo, $templateId);

// Overlay the effective field definitions so template-specific labels are used
// consistently by the designer preview and Inspector.
foreach ($inputFields as $effectiveField) {
    $key = (string)($effectiveField['field_key'] ?? '');
    $label = (string)($effectiveField['field_label'] ?? '');
    if ($key !== '' && $label !== '') {
        $previewDefinitions[$key] = array_merge(
            $previewDefinitions[$key] ?? [],
            $effectiveField,
            ['field_label' => $label]
        );
    }
}

$previewLayout = $fields;
$previewFrontHtml = card_renderer_html($template, $previewMember, $previewDefinitions, $previewLayout, 'front', '../');
$previewBackHtml = card_renderer_html($template, $previewMember, $previewDefinitions, $previewLayout, 'back', '../');

$cardWidth = (int)($template['card_width'] ?: (strtolower((string)($template['orientation'] ?? '')) === 'landscape' ? 864 : 533));
$cardHeight = (int)($template['card_height'] ?: (strtolower((string)($template['orientation'] ?? '')) === 'landscape' ? 533 : 864));
if ($cardWidth < 50) $cardWidth = strtolower((string)($template['orientation'] ?? '')) === 'landscape' ? 864 : 533;
if ($cardHeight < 50) $cardHeight = strtolower((string)($template['orientation'] ?? '')) === 'landscape' ? 533 : 864;
$orientation = strtolower((string)($template['orientation'] ?? 'portrait')) === 'landscape' ? 'landscape' : 'portrait';
$displayW = (int)round($cardWidth / 2);
$displayH = (int)round($cardHeight / 2);
$canvasRenderScale = min($displayW / max(1, $cardWidth), $displayH / max(1, $cardHeight));
$bgUrl = !empty($template['background_image']) ? '../' . ltrim(str_replace('\\', '/', (string)$template['background_image']), '/') : '';

$designerSampleValues = [];
$designerSampleLabels = [];
foreach (array_merge($inputFields, $fields) as $sampleField) {
    $key = (string)($sampleField['field_key'] ?? '');
    if ($key === '') continue;
    $val = card_renderer_value($previewMember, $key);
    if ($val === '' || $val === null) {
        $def = $previewDefinitions[$key] ?? $sampleField;
        if (!empty($def['default_value'])) {
            $val = $def['default_value'];
        } elseif (!empty($sampleField['default_value'])) {
            $val = $sampleField['default_value'];
        } else {
            $val = card_renderer_value(['unique_id' => 'ID-001', 'name' => 'John Doe'], $key);
            if ($val === '') {
                $val = ucwords(str_replace(['_', '-'], ' ', $key));
            }
        }
    }
    $designerSampleValues[$key] = $val;

    // Keep the label independent from the sample value.
    $labelDef = $previewDefinitions[$key] ?? $sampleField;
    $designerSampleLabels[$key] = (string)(
        $labelDef['field_label']
        ?? $sampleField['field_label']
        ?? card_renderer_field_label($key, $labelDef)
    );
}
$designerSampleAssets = [
    'photo' => card_renderer_member_image('../', 'images/uploads', $previewMember['photo'] ?? null),
    'logo' => card_renderer_member_image_or_null('../', 'organizations/assets/uploads/logo', $previewMember['org_logo'] ?? null)
        ?: card_renderer_member_image('../', 'images/uploads', null),
    'signature' => card_renderer_member_image_or_null('../', 'images/uploads/signatures', $previewMember['signature'] ?? null),
    'qr' => card_renderer_code('qr', (string)($previewMember['unique_id'] ?? 'ID-001')),
    'barcode' => card_renderer_code('barcode', (string)($previewMember['unique_id'] ?? 'ID-001')),
];
$usedFieldKeys = [];

foreach ($fields as $field) {
    $key = trim((string)($field['field_key'] ?? ''));
    if ($key !== '' && !in_array($key, $usedFieldKeys, true)) {
        $usedFieldKeys[] = $key;
    }
}

$usedFields = [];

foreach ($inputFields as $field) {
    if (in_array((string)$field['field_key'], $usedFieldKeys, true)) {
        $usedFields[] = $field;
    }
}
$objectsForJs = [];
foreach ($fields as $field) {
    $objectsForJs[] = [
        'id' => (int)$field['id'],
        'field_key' => $field['field_key'],
        'object_type' => $field['object_type'] ?? 'dynamic',
        'side' => $field['side'] ?? 'front',
        'x' => (float)$field['x'],
        'y' => (float)$field['y'],
        'width' => (float)$field['width'],
        'height' => (float)$field['height'],
        'visible' => (int)($field['visible'] ?? 1),
        'font_size' => (int)($field['font_size'] ?? 12),
        'font_family' => $field['font_family'] ?? null,
        'font_weight' => $field['font_weight'] ?? null,
        'font_style' => $field['font_style'] ?? null,
        'color' => $field['color'] ?? null,
        'text_align' => $field['text_align'] ?? 'left',
        'text_decoration' => $field['text_decoration'] ?? null,
        'opacity' => isset($field['opacity']) ? (float)$field['opacity'] : 1.0,
        'border_width' => $field['border_width'] ?? null,
        'border_color' => $field['border_color'] ?? null,
        'border_style' => $field['border_style'] ?? null,
        'border_radius' => $field['border_radius'] ?? null,
        'show_label' => (int)($field['show_label'] ?? 0),
        'content' => $field['content'] ?? null,
        'image_path' => $field['image_path'] ?? null,
        'z_index' => (int)($field['z_index'] ?? 0),
        'locked' => 0,
    ];
}

$inputFieldsForJs = [];
foreach ($inputFields as $inf) {
    $inputFieldsForJs[] = ['field_key' => $inf['field_key'], 'field_label' => $inf['field_label'], 'field_type' => $inf['field_type']];
}

function designer_type_icon(string $type): string {
    $icons = [
        'text' => 'fa-font', 'textarea' => 'fa-align-left', 'number' => 'fa-hashtag',
        'date' => 'fa-calendar', 'select' => 'fa-list', 'barcode' => 'fa-barcode',
        'qr' => 'fa-qrcode', 'photo' => 'fa-camera', 'signature' => 'fa-pen',
        'logo' => 'fa-image', 'static_text' => 'fa-font', 'image' => 'fa-image',
        'dynamic' => 'fa-database'
    ];
    return $icons[$type] ?? 'fa-cog';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Template Designer · ID Card Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.17/dist/interact.min.js"></script>
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
        .dashboard-content { padding: 1.5rem; max-width: 1800px; margin: 0 auto; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        .breadcrumb { display: flex; gap: 0.5rem; list-style: none; padding: 0; margin: 0 0 1rem 0; font-size: 0.875rem; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }
        .alert { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1rem; }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .btn-close-custom { cursor: pointer; background: none; border: none; font-size: 1.25rem; color: inherit; opacity: 0.5; }
        .btn-close-custom:hover { opacity: 1; }

        .designer-layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr) 300px;
            gap: 1rem;
        }
        @media (max-width: 1200px) { .designer-layout { grid-template-columns: 1fr; } }

        .sidebar-panel, .properties-panel {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            padding: 1rem;
            max-height: calc(100vh - 160px);
            overflow-y: auto;
        }
        .sidebar-panel h6, .properties-panel h6 {
            font-weight: 600; color: var(--neutral-700); margin-bottom: 0.75rem;
            padding-bottom: 0.5rem; border-bottom: 1px solid var(--neutral-200); font-size: 0.875rem;
        }
        .sidebar-panel h6 i, .properties-panel h6 i { color: var(--primary); margin-right: 0.4rem; }

        .field-item {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.45rem 0.65rem; border-radius: var(--radius-md);
            border: 1px solid var(--neutral-200); margin-bottom: 0.4rem;
            cursor: grab; background: white; font-size: 0.8rem; user-select: none;
        }
        .field-item:hover { background: var(--primary-soft); border-color: var(--primary); }
        .field-item .field-badge { font-size: 0.6rem; padding: 0.1rem 0.35rem; border-radius: var(--radius-sm); background: var(--neutral-100); color: var(--neutral-500); }

        .canvas-wrapper {
            background: white; border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md); border: 1px solid var(--neutral-200);
            padding: 1rem; display: flex; flex-direction: column; align-items: center;
        }
        .canvas-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            width: 100%; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;
        }
        .side-switcher, .zoom-controls, .toolbar-group {
            display: inline-flex; gap: 0.25rem; padding: 0.2rem;
            background: var(--neutral-100); border-radius: var(--radius-md);
        }
        .side-switcher .side-tab.active { color: #fff; background: var(--primary); }
        .toolbar-group .toolbar-btn.active { background: var(--primary); color: #fff; }

        .canvas-stage {
            width: 100%; overflow: auto; display: flex; justify-content: center;
            align-items: flex-start; min-height: 450px; padding: 1rem;
            background: var(--neutral-100); border-radius: var(--radius-lg);
            border: 2px dashed var(--neutral-300);
            position: relative;
        }
        .canvas-scale-wrap {
            transform-origin: top center;
            transition: transform 0.15s ease;
        }
        .id-card-canvas {
            position: relative; background: white; border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg); overflow: hidden; border: 1px solid var(--neutral-200);
            width: <?= $displayW ?>px; height: <?= $displayH ?>px;
        }
        .id-card-canvas .card-background {
            position: absolute; inset: 0; z-index: 0;
            background: linear-gradient(135deg, <?= htmlspecialchars($template['primary_color'] ?? '#0a1a2f') ?>, <?= htmlspecialchars($template['secondary_color'] ?? '#1e3a5f') ?>);
            background-size: <?= htmlspecialchars($template['bg_size'] ?? 'cover') ?>;
            background-position: <?= htmlspecialchars((string)($template['bg_pos_x'] ?? 50)) ?>% <?= htmlspecialchars((string)($template['bg_pos_y'] ?? 50)) ?>%;
            background-repeat: no-repeat;
            <?php if ($bgUrl): ?>background-image: url('<?= htmlspecialchars($bgUrl, ENT_QUOTES) ?>');<?php else: ?>opacity: 0.15;<?php endif; ?>
        }

        .grid-overlay { position: absolute; inset: 0; pointer-events: none; z-index: 5; opacity: 0; transition: opacity 0.2s; }
        .grid-overlay.show { opacity: 1; }
        .grid-overlay .grid-line { stroke: rgba(0,0,0,0.08); stroke-width: 0.5; }
        .grid-overlay .grid-line-major { stroke: rgba(0,0,0,0.15); stroke-width: 1; }

        .safe-print-margin {
            position: absolute; inset: 3%; border: 2px dashed rgba(220,38,38,0.3);
            pointer-events: none; z-index: 4; border-radius: 4px;
        }
        .safe-print-margin .margin-label {
            position: absolute; top: -18px; right: 4px;
            font-size: 8px; color: rgba(220,38,38,0.5);
            background: white; padding: 0 4px; border-radius: 2px;
        }

        .ruler {
            position: absolute; z-index: 6; pointer-events: none;
        }
        .ruler-h { top: 0; left: 0; right: 0; height: 18px; background: rgba(255,255,255,0.85); border-bottom: 1px solid var(--neutral-300); }
        .ruler-v { left: 0; top: 0; bottom: 0; width: 18px; background: rgba(255,255,255,0.85); border-right: 1px solid var(--neutral-300); }
        .ruler .tick { position: absolute; background: var(--neutral-500); }
        .ruler .tick-label { position: absolute; font-size: 6px; color: var(--neutral-600); font-family: monospace; }

        .canvas-object {
            position: absolute; cursor: grab; z-index: 10;
            border: 2px solid transparent;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; user-select: none; min-width: 12px; min-height: 12px;
            font-size: 10px; color: var(--primary); padding: 2px;
            transition: border-color 0.15s;
        }
        .canvas-object.locked { cursor: not-allowed; opacity: 0.7; }
        .canvas-object.locked::after {
            content: '\f023'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; top: 2px; right: 2px; font-size: 8px;
            color: var(--neutral-500); opacity: 0.7;
        }
        .canvas-object.selected {
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(14,159,110,0.3);
            z-index: 50;
            overflow: visible;
        }
        .canvas-object.dragging { cursor: grabbing; z-index: 30; border-color: var(--success); }
        .canvas-object.type-photo, .canvas-object.type-logo { background: rgba(59,130,246,0.08); border-color: rgba(59,130,246,0.2); }
        .canvas-object.type-qr, .canvas-object.type-barcode { background: rgba(0,0,0,0.03); border-color: rgba(107,114,128,0.2); }
        .canvas-object.type-static_text { background: rgba(229,62,62,0.05); }
        .canvas-object.type-image { background: rgba(16,185,129,0.05); }

      .canvas-object img,
.canvas-object .sample-code svg {
    width: 100%;
    height: 100%;
    object-fit: contain;
    pointer-events: none;
    display: block;
}
        .canvas-object .sample-value { width: 100%; pointer-events: none; white-space: pre-wrap; word-break: break-word; }
        .canvas-object .sample-label { font-weight: 700; }
        .canvas-object .move-handle, .canvas-object .resize-grip {
            display: none; position: absolute; z-index: 2; color: #fff;
            background: var(--primary); border-radius: 4px; line-height: 1;
            align-items: center; justify-content: center; pointer-events: auto;
        }
        .move-handle { bottom: -24px; left: 50%; transform: translateX(-50%); width: 22px; height: 20px; cursor: grab; font-size: 10px; }
        .move-handle:active { cursor: grabbing; }
        .resize-grip { right: 1px; bottom: 1px; width: 14px; height: 14px; cursor: nwse-resize; font-size: 9px; }
        .canvas-object.selected .move-handle, .canvas-object.selected .resize-grip { display: inline-flex; }

        .property-group { margin-bottom: 0.65rem; padding-bottom: 0.65rem; border-bottom: 1px solid var(--neutral-100); }
        .property-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--neutral-500); margin-bottom: 0.2rem; }
        .form-control-sm, .form-select-sm { font-size: 0.8rem; border-radius: var(--radius-md); border: 1px solid var(--neutral-300); }
        .btn { border-radius: var(--radius-md); padding: 0.45rem 0.85rem; font-size: 0.8rem; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .btn-group-toggle .btn.active { background: var(--primary); color: #fff; }

        .preview-pane { width: 100%; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--neutral-200); display: none; }
        .preview-pane.show { display: block; }
        .preview-cards { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; transform-origin: top center; }
        .preview-cards .id-card-renderer { transform: scale(0.45); transform-origin: top left; margin-right: -50%; margin-bottom: -50%; }

        .saving-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; display: none; align-items: center; justify-content: center; flex-direction: column; color: #fff; }
        .saving-overlay.active { display: flex; }
        .saving-overlay .spinner { width: 48px; height: 48px; border: 4px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 1rem; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .selection-rect { position: absolute; border: 1px dashed var(--info); background: rgba(59,130,246,0.1); pointer-events: none; z-index: 40; display: none; }

        .shortcuts-hint { font-size: 0.6rem; color: var(--neutral-400); padding: 0.25rem 0.5rem; background: var(--neutral-100); border-radius: var(--radius-sm); }
        .shortcuts-hint kbd { background: black; padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.6rem; font-family: monospace; }
        .ruler-h,
.ruler-v {
    display: none !important;
}
 .canvas-object .object-content {
    position: absolute;
    inset: 0;
    overflow: hidden;
    border-radius: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
}

.canvas-object .object-content img,
.canvas-object .object-content .sample-code {
    width: 100%;
    height: 100%;
    object-fit: contain;
    pointer-events: none;
}   


.used-fields-box {
    background: #fff;
    border: 1px solid var(--neutral-200);
    border-radius: var(--radius-lg);
    padding: 0.75rem;
    margin-bottom: 1rem;
}

.used-fields-box h6 {
    font-weight: 600;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--neutral-200);
}

.used-fields-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.used-field-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.55rem;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-size: 0.75rem;
}</style>
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
                    <li class="breadcrumb-item active">Design</li>
                </ol>
            </nav>

            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i><div class="flex-1"><?= htmlspecialchars($message) ?></div><button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><div class="flex-1"><?= htmlspecialchars($error) ?></div><button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button></div>
            <?php endif; ?>

            <div class="designer-layout">
                
                <!-- LEFT: palette -->
                <div class="sidebar-panel">
                    <div class="used-fields-box">
    <h6>
        <i class="fas fa-list"></i> Input Fields Used
    </h6>

 <div class="used-fields-list" id="usedFieldsList">
        <?php if (!empty($usedFields)): ?>
            <?php foreach ($usedFields as $field): ?>
                <span class="used-field-chip">
                    <i class="fas fa-tag"></i>
                    <?= htmlspecialchars($field['field_label']) ?>
                </span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="text-muted small">No input fields used</span>
        <?php endif; ?>
    </div>
</div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6><i class="fas fa-list"></i>Input Fields</h6>
                        <button class="btn btn-sm btn-primary" onclick="Designer.openFieldModal()"><i class="fas fa-plus"></i></button>
                    </div>
                    <p class="text-muted small mb-2">Drag onto the canvas</p>
                    <div class="field-list" id="fieldList">
                        <?php 
                        $commonFields = array_filter($inputFields, fn($f) => !empty($f['is_common']));
                        $customFields = array_filter($inputFields, fn($f) => empty($f['is_common']));
                        ?>
                        <div class="mb-3">
                            <small class="text-uppercase text-muted fw-bold d-block mb-1"><i class="fas fa-globe me-1"></i>Common Fields</small>
                            <?php foreach ($commonFields as $inf): ?>
                                <?php $ft = strtolower((string)$inf['field_type']); $otype = in_array($ft, ['photo','logo','qr','barcode','signature'], true) ? $ft : 'dynamic'; ?>
                                <div class="field-item" draggable="true"
                                     data-field-key="<?= htmlspecialchars($inf['field_key']) ?>"
                                     data-field-type="<?= htmlspecialchars($inf['field_type']) ?>"
                                     data-object-type="<?= htmlspecialchars($otype) ?>"
                                     data-field-label="<?= htmlspecialchars($inf['field_label']) ?>"
                                     data-placeholder="<?= htmlspecialchars((string)($inf['placeholder'] ?? '')) ?>"
                                     data-default-value="<?= htmlspecialchars((string)($inf['default_value'] ?? '')) ?>">
                                    <i class="fas <?= designer_type_icon($ft) ?> text-muted"></i>
                                    <span class="flex-grow-1"><?= htmlspecialchars($inf['field_label']) ?></span>
                                    <span class="field-badge me-1"><?= htmlspecialchars(ucfirst($ft)) ?></span>
                                    <button type="button" class="btn btn-xs p-0 ms-1 text-primary"
                                            title="Edit Field for this template"
                                            onclick="event.stopPropagation(); Designer.openEditFieldModal(this.closest('.field-item'))">
                                        <i class="fas fa-pen" style="font-size:0.7rem"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <small class="text-uppercase text-muted fw-bold d-block mb-1"><i class="fas fa-sliders-h me-1"></i>Template Custom Fields</small>
                            <?php if (!empty($customFields)): ?>
                                <?php foreach ($customFields as $inf): ?>
                                    <?php $ft = strtolower((string)$inf['field_type']); $otype = in_array($ft, ['photo','logo','qr','barcode','signature'], true) ? $ft : 'dynamic'; ?>
                                    <div class="field-item" draggable="true"
                                         data-field-key="<?= htmlspecialchars($inf['field_key']) ?>"
                                         data-field-type="<?= htmlspecialchars($inf['field_type']) ?>"
                                         data-object-type="<?= htmlspecialchars($otype) ?>"
                                         data-field-label="<?= htmlspecialchars($inf['field_label']) ?>"
                                         data-placeholder="<?= htmlspecialchars((string)($inf['placeholder'] ?? '')) ?>"
                                         data-default-value="<?= htmlspecialchars((string)($inf['default_value'] ?? '')) ?>">
                                        <i class="fas <?= designer_type_icon($ft) ?> text-muted"></i>
                                        <span class="flex-grow-1"><?= htmlspecialchars($inf['field_label']) ?></span>
                                        <span class="field-badge me-1"><?= htmlspecialchars(ucfirst($ft)) ?></span>
                                        <button type="button" class="btn btn-xs p-0 ms-1 text-primary"
                                                title="Edit Field"
                                                onclick="event.stopPropagation(); Designer.openEditFieldModal(this.closest('.field-item'))">
                                            <i class="fas fa-pen" style="font-size:0.7rem"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs p-0 ms-1 text-danger"
                                                title="Delete Field"
                                                onclick="event.stopPropagation(); Designer.deleteCustomField('<?= htmlspecialchars($inf['field_key'], ENT_QUOTES) ?>', '<?= htmlspecialchars($inf['field_label'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash" style="font-size:0.7rem"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small py-1 text-center">No custom fields for this template</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr>
                    <h6><i class="fas fa-plus-circle"></i>Add Objects</h6>
                    <div class="d-grid gap-1 add-tools mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addStaticText()"><i class="fas fa-font"></i> Static Text</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('objectImageInput').click()"><i class="fas fa-image"></i> Add Image</button>
                        <input type="file" id="objectImageInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addPlaceholder('photo')"><i class="fas fa-camera"></i> Photo</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addPlaceholder('logo')"><i class="fas fa-building"></i> Logo</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addPlaceholder('qr')"><i class="fas fa-qrcode"></i> QR</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addPlaceholder('barcode')"><i class="fas fa-barcode"></i> Barcode</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.addPlaceholder('signature')"><i class="fas fa-pen"></i> Signature</button>
                    </div>

                    <h6><i class="fas fa-image"></i>Background Image & Crop</h6>
                    <div class="bg-tools">
                        <?php if ($bgUrl): ?><div class="mb-2 small text-muted text-truncate" id="bgPathLabel"><?= htmlspecialchars((string)$template['background_image']) ?></div>
                        <?php else: ?><div class="mb-2 small text-muted" id="bgPathLabel">No background set</div><?php endif; ?>
                        <input type="file" id="bgFileInput" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control form-control-sm mb-2">
                        <div class="d-grid gap-1 mb-2">
                            <button type="button" class="btn btn-sm btn-primary" onclick="Designer.uploadBackground()"><i class="fas fa-upload"></i> Upload / Replace</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="Designer.removeBackground()" <?= $bgUrl ? '' : 'disabled' ?> id="btnRemoveBg"><i class="fas fa-trash"></i> Remove</button>
                        </div>
                        <div class="p-2 border rounded bg-light mb-2">
                            <div class="small fw-semibold mb-1">Position & Fit</div>
                            <div class="row g-1 mb-1">
                                <div class="col-6">
                                    <label class="form-label mb-0" style="font-size:0.7rem">Pos X (%)</label>
                                    <input type="number" class="form-control form-control-sm" id="bgPosXInput" value="<?= htmlspecialchars((string)($template['bg_pos_x'] ?? 50)) ?>" min="0" max="100" step="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label mb-0" style="font-size:0.7rem">Pos Y (%)</label>
                                    <input type="number" class="form-control form-control-sm" id="bgPosYInput" value="<?= htmlspecialchars((string)($template['bg_pos_y'] ?? 50)) ?>" min="0" max="100" step="1">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label mb-0" style="font-size:0.7rem">Fit Mode / Zoom</label>
                                <select class="form-select form-select-sm" id="bgSizeInput">
                                    <option value="cover" <?= ($template['bg_size'] ?? 'cover') === 'cover' ? 'selected' : '' ?>>Cover (Fill Card)</option>
                                    <option value="contain" <?= ($template['bg_size'] ?? 'cover') === 'contain' ? 'selected' : '' ?>>Contain (Fit Card)</option>
                                    <option value="100% 100%" <?= ($template['bg_size'] ?? 'cover') === '100% 100%' ? 'selected' : '' ?>>Stretch 100%</option>
                                    <option value="110%" <?= ($template['bg_size'] ?? 'cover') === '110%' ? 'selected' : '' ?>>Zoom 110%</option>
                                    <option value="120%" <?= ($template['bg_size'] ?? 'cover') === '120%' ? 'selected' : '' ?>>Zoom 120%</option>
                                    <option value="150%" <?= ($template['bg_size'] ?? 'cover') === '150%' ? 'selected' : '' ?>>Zoom 150%</option>
                                    <option value="200%" <?= ($template['bg_size'] ?? 'cover') === '200%' ? 'selected' : '' ?>>Zoom 200%</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="Designer.saveBackgroundSettings()"><i class="fas fa-save me-1"></i> Save Background Settings</button>
                        </div>
                    </div>
                </div>

                <!-- CENTER: canvas -->
                <div class="canvas-wrapper">
                    <div class="canvas-toolbar">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <span class="badge bg-primary"><?= htmlspecialchars(ucfirst($orientation)) ?></span>
                            <span class="badge bg-secondary"><?= $cardWidth ?>×<?= $cardHeight ?></span>
                            <span class="badge bg-light text-dark" id="objectCountBadge"><?= count($fields) ?> objects</span>
                            <div class="side-switcher" role="tablist">
                                <button type="button" class="btn btn-sm btn-light side-tab active" data-card-side="front"><i class="fas fa-id-card"></i> Front</button>
                                <button type="button" class="btn btn-sm btn-light side-tab" data-card-side="back"><i class="fas fa-address-card"></i> Back</button>
                                <button type="button" class="btn btn-sm btn-light" onclick="Designer.copyLayoutToOtherSide()" title="Copy layout to other side"><i class="fas fa-copy"></i></button>
                            </div>
                            <div class="toolbar-group">
                                <button type="button" class="btn btn-sm btn-light toolbar-btn" onclick="Designer.toggleGrid()" title="Toggle Grid (G)"><i class="fas fa-th"></i></button>
                                <button type="button" class="btn btn-sm btn-light toolbar-btn" onclick="Designer.toggleSnap()" title="Toggle Snap (S)"><i class="fas fa-magnet"></i></button>
                                <button type="button" class="btn btn-sm btn-light toolbar-btn" onclick="Designer.toggleSafeMargin()" title="Toggle Safe Print Margin (M)"><i class="fas fa-print"></i></button>
                            </div>
                            <div class="toolbar-group" id="actionsToolbarGroup">
                                <button class="btn btn-sm btn-light" onclick="Designer.undo()" id="undoBtn" title="Undo (Ctrl+Z)" disabled><i class="fas fa-undo"></i></button>
                                <button class="btn btn-sm btn-light" onclick="Designer.redo()" id="redoBtn" title="Redo (Ctrl+Shift+Z)" disabled><i class="fas fa-redo"></i></button>
                                <button class="btn btn-sm btn-light" onclick="Designer.copySelected()" id="copyBtn" title="Copy (Ctrl+C)"><i class="fas fa-copy"></i></button>
                                <button class="btn btn-sm btn-light" onclick="Designer.pasteObjects()" id="pasteBtn" title="Paste (Ctrl+V)"><i class="fas fa-paste"></i></button>
                                <button class="btn btn-sm btn-light" onclick="Designer.exportLayout()" title="Export JSON"><i class="fas fa-file-export"></i></button>
                                <button class="btn btn-sm btn-light" onclick="document.getElementById('importInput').click()" title="Import JSON"><i class="fas fa-file-import"></i></button>
                                <input type="file" id="importInput" accept=".json" hidden>
                            </div>
                            <div class="zoom-controls">
                                <button type="button" class="btn btn-sm btn-light" onclick="Designer.zoomOut()" title="Zoom out"><i class="fas fa-search-minus"></i></button>
                                <button type="button" class="btn btn-sm btn-light" id="zoomLabel" onclick="Designer.zoomFit()" title="Fit">100%</button>
                                <button type="button" class="btn btn-sm btn-light" onclick="Designer.zoomIn()" title="Zoom in"><i class="fas fa-search-plus"></i></button>
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.togglePreview()"><i class="fas fa-eye"></i> Preview</button>
                            <button type="button" class="btn btn-sm btn-success" onclick="Designer.save()" id="saveBtn"><i class="fas fa-save"></i> Save</button>
                        </div>
                    </div>

                    <div class="canvas-stage" id="canvasStage">
                        <div class="canvas-scale-wrap" id="canvasScaleWrap">
                            <div class="id-card-canvas" id="cardCanvas" data-display-w="<?= $displayW ?>" data-display-h="<?= $displayH ?>">
                                <div class="card-background" id="cardBackground"></div>
                                <div class="ruler ruler-h" id="rulerH"></div>
                                <div class="ruler ruler-v" id="rulerV"></div>
                                <div class="safe-print-margin" id="safeMargin" style="display:none;"><span class="margin-label">Safe Print</span></div>
                                <svg class="grid-overlay" id="gridOverlay" width="100%" height="100%">
                                    <?php for ($i = 1; $i < 20; $i++): ?>
                                        <?php $isMajor = $i % 5 === 0; ?>
                                        <line class="grid-line <?= $isMajor ? 'grid-line-major' : '' ?>" x1="<?= $i * 5 ?>%" y1="0" x2="<?= $i * 5 ?>%" y2="100%"></line>
                                        <line class="grid-line <?= $isMajor ? 'grid-line-major' : '' ?>" x1="0" y1="<?= $i * 5 ?>%" x2="100%" y2="<?= $i * 5 ?>%"></line>
                                    <?php endfor; ?>
                                </svg>
                                <div class="selection-rect" id="selectionRect"></div>
                            </div>
                        </div>
                    </div>

                    <div class="shortcuts-hint mt-2">
                        <kbd>Ctrl+Z</kbd> Undo · <kbd>Ctrl+Shift+Z</kbd> Redo · <kbd>Ctrl+C</kbd> Copy · <kbd>Ctrl+V</kbd> Paste · <kbd>Del</kbd> Archive
                    </div>

                    <div class="preview-pane" id="previewPane">
                        <h6 class="mb-2"><i class="fas fa-eye me-1"></i>Live Preview</h6>
                        <div class="preview-cards" id="previewCards">
                            <div><div class="small text-muted mb-1">Front</div><?= $previewFrontHtml ?></div>
                            <div><div class="small text-muted mb-1">Back</div><?= $previewBackHtml ?></div>
                        </div>
                        <p class="small text-muted mt-2 mb-0">Preview reflects last saved layout. Save to refresh.</p>
                    </div>
                </div>

                <!-- RIGHT: inspector -->
                <div class="properties-panel" id="propertiesPanel">
                    <h6><i class="fas fa-sliders-h"></i>Inspector</h6>
                    <div id="noFieldSelected" class="text-center text-muted py-4">
                        <i class="fas fa-hand-pointer" style="font-size:1.75rem;display:block;margin-bottom:0.5rem;"></i>
                        <p class="small mb-0">Select an object on the card</p>
                        <p class="small text-muted mt-1">Shift+Click to multi-select</p>
                    </div>
                    <div id="fieldProperties" style="display:none;">
                        <div class="property-group">
                            <div class="property-label">Object</div>
                            <div class="property-value" id="propObjectMeta">—</div>
                        </div>
<div class="property-group" id="propFieldLabelGroup" style="display:none;">
    <div class="property-label">
        Field Label (Member Form &amp; Card Label)
    </div>

    <input
        type="text"
        class="form-control form-control-sm"
        id="propFieldLabel"
        placeholder="Enter field label"
    >

    <button
        type="button"
        class="btn btn-sm btn-primary w-100 mt-2"
        id="propFieldLabelSave">
        <i class="fas fa-save"></i> Save Label
    </button>
</div>
                        <div class="property-group" id="propContentGroup" style="display:none;">
                            <div class="property-label" id="propContentLabel">Content</div>
                            <textarea class="form-control form-control-sm" id="propContent" rows="2"></textarea>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Position (X, Y %)</div>
                            <div class="row g-1"><div class="col-6"><input type="number" class="form-control form-control-sm" id="propX" step="0.1"></div><div class="col-6"><input type="number" class="form-control form-control-sm" id="propY" step="0.1"></div></div>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Size (W, H %)</div>
                            <div class="row g-1"><div class="col-6"><input type="number" class="form-control form-control-sm" id="propWidth" step="0.1"></div><div class="col-6"><input type="number" class="form-control form-control-sm" id="propHeight" step="0.1"></div></div>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Z-Index</div>
                            <input type="number" class="form-control form-control-sm" id="propZIndex" min="0" step="1">
                        </div>
                        <div class="property-group">
                            <div class="property-label">Font Family</div>
                            <select class="form-select form-select-sm" id="propFontFamily"><option value="">Default</option><option value="Inter">Inter</option><option value="Poppins">Poppins</option><option value="Arial">Arial</option><option value="Helvetica">Helvetica</option><option value="Times New Roman">Times New Roman</option><option value="Georgia">Georgia</option><option value="Courier New">Courier New</option><option value="Lato">Lato</option><option value="Roboto">Roboto</option></select>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Font Size</div>
                            <input type="number" class="form-control form-control-sm" id="propFontSize" min="7" max="72" step="1">
                        </div>
                        <div class="property-group">
                            <div class="property-label">Color</div>
                            <input type="color" class="form-control form-control-sm form-control-color w-100" id="propColor" value="#0a1a2f">
                        </div>
                        <div class="property-group">
                            <div class="property-label">Align</div>
                            <div class="btn-group btn-group-toggle w-100" id="propAlignGroup">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-align="left"><i class="fas fa-align-left"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-align="center"><i class="fas fa-align-center"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-align="right"><i class="fas fa-align-right"></i></button>
                            </div>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Style</div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="propBold" title="Bold"><i class="fas fa-bold"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="propItalic" title="Italic"><i class="fas fa-italic"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="propUnderline" title="Underline"><i class="fas fa-underline"></i></button>
                            </div>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Opacity</div>
                            <input type="range" class="form-range" id="propOpacity" min="0" max="1" step="0.05" value="1">
                            <div class="small text-muted" id="propOpacityVal">1.00</div>
                        </div>
                        <div class="property-group">
                            <div class="property-label">Border</div>
                            <div class="row g-1 mb-1">
                                <div class="col-4"><input type="number" class="form-control form-control-sm" id="propBorderWidth" min="0" step="0.5" placeholder="W"></div>
                                <div class="col-4"><select class="form-select form-select-sm" id="propBorderStyle"><option value="">—</option><option value="solid">Solid</option><option value="dashed">Dashed</option><option value="dotted">Dotted</option></select></div>
                                <div class="col-4"><input type="color" class="form-control form-control-sm form-control-color w-100" id="propBorderColor" value="#000000"></div>
                            </div>
                            <div class="property-label">Border Radius</div>
                            <input type="number" class="form-control form-control-sm" id="propBorderRadius" min="0" step="1" placeholder="px">
                        </div>
                        <div class="property-group">
                            <div class="form-check"><input type="checkbox" class="form-check-input" id="propVisible" checked><label class="form-check-label" for="propVisible">Visible</label></div>
                            <div class="form-check mt-1" id="propShowLabelGroup"><input type="checkbox" class="form-check-input" id="propShowLabel"><label class="form-check-label" for="propShowLabel">Show field label</label></div>
                            <div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="propLocked"><label class="form-check-label" for="propLocked"><i class="fas fa-lock"></i> Locked</label></div>
                        </div>
                        <div class="d-grid gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.duplicateSelected()"><i class="fas fa-copy"></i> Duplicate</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.bringForward()"><i class="fas fa-arrow-up"></i> Bring Forward</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Designer.sendBackward()"><i class="fas fa-arrow-down"></i> Send Backward</button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="Designer.archiveSelected()"><i class="fas fa-archive"></i> Archive</button>
                        </div>
                        <div class="mt-2">
                            <div class="property-label">Align Selected</div>
                            <div class="d-flex gap-1 flex-wrap">
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('left')"><i class="fas fa-align-left"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('center')"><i class="fas fa-align-center"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('right')"><i class="fas fa-align-right"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('top')"><i class="fas fa-arrow-up"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('middle')"><i class="fas fa-arrows-alt-v"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.alignSelected('bottom')"><i class="fas fa-arrow-down"></i></button>
                            </div>
                            <div class="d-flex gap-1 flex-wrap mt-1">
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.equalSpacing('horizontal')"><i class="fas fa-arrows-alt-h"></i> Space H</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="Designer.equalSpacing('vertical')"><i class="fas fa-arrows-alt-v"></i> Space V</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </main>
</div>

<div class="saving-overlay" id="savingOverlay">
    <div class="spinner"></div>
    <h4>Saving…</h4>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrfToken) ?>;
    const TEMPLATE_ID = <?= (int)$templateId ?>;
    const DEFAULT_TEXT_COLOR = <?= json_encode($template['text_color'] ?? '#0a1a2f') ?>;
    const CANVAS_RENDER_SCALE = <?= json_encode($canvasRenderScale) ?>;
    const SAMPLE_VALUES = <?= json_encode($designerSampleValues) ?>;
    const SAMPLE_LABELS = <?= json_encode($designerSampleLabels) ?>;
    const SAMPLE_ASSETS = <?= json_encode($designerSampleAssets) ?>;
    const DISPLAY_W = <?= $displayW ?>;
    const DISPLAY_H = <?= $displayH ?>;

    const Designer = {
        objects: {},
        selectedIds: [],
        activeSide: 'front',
        zoom: 1,
        newCounter: 0,
        clipboard: null,

        // Undo/Redo
        undoStack: [],
        redoStack: [],
        maxUndo: 50,
        isUndoing: false,

        // Grid & Snap
        showGrid: false,
        snapEnabled: false,
        snapSize: 5, // percentage
        showSafeMargin: false,

        // Auto-save
        autoSaveTimer: null,
        autoSaveInterval: 30000, // 30 seconds
        hasUnsavedChanges: false,

        // Selection rect
        selectionStartX: 0,
        selectionStartY: 0,
        isSelecting: false,

        init(initialObjects) {
            initialObjects.forEach((obj) => {
                const cid = String(obj.id);
                this.objects[cid] = Object.assign({}, obj, { clientId: cid });
                this.renderObject(this.objects[cid]);
            });
            this.bindUi();
            this.switchSide('front');
            this.zoomFit();

            // Auto-save draft
            this.loadDraft();
            this.startAutoSave();

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => this.handleKeyboard(e));

            // Selection rect
            this.setupSelectionRect();

            // Update counts
            this.updateCount();
            this.updateUndoButtons();
        },

        bindUi() {
            document.querySelectorAll('.side-tab').forEach((tab) => {
                tab.addEventListener('click', () => this.switchSide(tab.dataset.cardSide));
            });

            const canvas = document.getElementById('cardCanvas');
            canvas.addEventListener('click', (e) => {
                if (e.target === canvas || e.target.id === 'cardBackground' || e.target.classList.contains('grid-overlay')
                    || e.target.classList.contains('ruler') || e.target.classList.contains('safe-print-margin')) {
                    if (!e.shiftKey) this.deselect();
                }
            });

            // Drag from palette
            document.querySelectorAll('#fieldList .field-item').forEach((item) => {
                item.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('application/json', JSON.stringify({
                        field_key: item.dataset.fieldKey,
                        field_type: item.dataset.fieldType,
                        object_type: item.dataset.objectType
                    }));
                });
            });
            canvas.addEventListener('dragover', (e) => e.preventDefault());
            canvas.addEventListener('drop', (e) => {
                e.preventDefault();
                let data;
                try { data = JSON.parse(e.dataTransfer.getData('application/json')); } catch (err) { return; }
                if (!data || !data.field_key) return;
                const rect = canvas.getBoundingClientRect();
                const rawW = canvas.offsetWidth * this.zoom;
                const rawH = canvas.offsetHeight * this.zoom;
                const px = ((e.clientX - rect.left) / rawW) * 100;
                const py = ((e.clientY - rect.top) / rawH) * 100;
                this.addFromInputField(data, px, py);
            });

            // Inspector bindings
            const bindNum = (id, key, parseFn) => {
                document.getElementById(id).addEventListener('change', (e) => {
                    const val = parseFn(e.target.value);
                    if (key === 'z_index') {
                        this.updateSelectedZIndex(val);
                    } else {
                        this.updateSelected(key, val);
                    }
                });
            };
            bindNum('propX', 'x', parseFloat);
            bindNum('propY', 'y', parseFloat);
            bindNum('propWidth', 'width', parseFloat);
            bindNum('propHeight', 'height', parseFloat);
            bindNum('propFontSize', 'font_size', (v) => parseInt(v, 10));
            bindNum('propBorderWidth', 'border_width', parseFloat);
            bindNum('propBorderRadius', 'border_radius', parseFloat);
            bindNum('propZIndex', 'z_index', (v) => parseInt(v, 10) || 0);

            document.getElementById('propFontFamily').addEventListener('change', (e) => this.updateSelected('font_family', e.target.value || null));
            document.getElementById('propColor').addEventListener('input', (e) => this.updateSelected('color', e.target.value));
            document.getElementById('propBorderColor').addEventListener('input', (e) => this.updateSelected('border_color', e.target.value));
            document.getElementById('propBorderStyle').addEventListener('change', (e) => this.updateSelected('border_style', e.target.value || null));
            document.getElementById('propVisible').addEventListener('change', (e) => this.updateSelected('visible', e.target.checked ? 1 : 0));
            document.getElementById('propShowLabel').addEventListener('change', (e) => this.updateSelected('show_label', e.target.checked ? 1 : 0));
            document.getElementById('propContent').addEventListener('change', (e) => this.updateSelected('content', e.target.value));
            document.getElementById('propLocked').addEventListener('change', (e) => this.updateSelected('locked', e.target.checked ? 1 : 0));
            document.getElementById('propOpacity').addEventListener('input', (e) => {
                const v = parseFloat(e.target.value);
                document.getElementById('propOpacityVal').textContent = v.toFixed(2);
                this.updateSelected('opacity', v);
            });

            document.querySelectorAll('#propAlignGroup [data-align]').forEach((btn) => {
                btn.addEventListener('click', () => this.updateSelected('text_align', btn.dataset.align));
            });
            document.getElementById('propFieldLabelSave')
    .addEventListener('click', () => {
        this.saveSelectedFieldLabel();
    });
            document.getElementById('propBold').addEventListener('click', () => {
                const obj = this.getSelected();
                if (!obj) return;
                this.updateSelected('font_weight', obj.font_weight === 'bold' || obj.font_weight === '700' ? null : 'bold');
            });
            document.getElementById('propItalic').addEventListener('click', () => {
                const obj = this.getSelected();
                if (!obj) return;
                this.updateSelected('font_style', obj.font_style === 'italic' ? null : 'italic');
            });
            document.getElementById('propUnderline').addEventListener('click', () => {
                const obj = this.getSelected();
                if (!obj) return;
                this.updateSelected('text_decoration', obj.text_decoration === 'underline' ? null : 'underline');
            });

            document.getElementById('objectImageInput').addEventListener('change', (e) => {
                const file = e.target.files && e.target.files[0];
                if (file) this.uploadObjectImage(file);
                e.target.value = '';
            });

            document.getElementById('importInput').addEventListener('change', (e) => {
                const file = e.target.files && e.target.files[0];
                if (file) this.importLayout(file);
                e.target.value = '';
            });

            // Ctrl+S save
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    this.save();
                }
            });
        },

        handleKeyboard(e) {
            // Don't interfere with input fields
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes((e.target.tagName || ''))) return;

            const ctrl = e.ctrlKey || e.metaKey;

            if (ctrl && e.key.toLowerCase() === 'z') {
                e.preventDefault();
                if (e.shiftKey) {
                    this.redo();
                } else {
                    this.undo();
                }
                return;
            }
            if (ctrl && e.key.toLowerCase() === 'y') {
                e.preventDefault();
                this.redo();
                return;
            }
            if (ctrl && e.key.toLowerCase() === 'c') {
                e.preventDefault();
                this.copySelected();
                return;
            }
            if (ctrl && e.key.toLowerCase() === 'v') {
                e.preventDefault();
                this.pasteObjects();
                return;
            }
            if (e.key === 'Delete' || e.key === 'Backspace') {
                e.preventDefault();
                this.archiveSelected();
                return;
            }
            // ─── Arrow key movement ─────────────────────────────────────
if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) {

    // Nothing selected
    if (this.selectedIds.length === 0) return;

    e.preventDefault();

    // Normal = small movement
    // Shift = faster movement
    const step = e.shiftKey ? 2.5 : 0.5;

    let dx = 0;
    let dy = 0;

    switch (e.key) {
        case 'ArrowLeft':
            dx = -step;
            break;

        case 'ArrowRight':
            dx = step;
            break;

        case 'ArrowUp':
            dy = -step;
            break;

        case 'ArrowDown':
            dy = step;
            break;
    }

    // Save only once before the movement
    this.saveState();

    let moved = false;

    this.selectedIds.forEach((cid) => {

        const obj = this.objects[cid];

        if (!obj || obj.locked) return;

        let newX = obj.x + dx;
        let newY = obj.y + dy;

        // Keep object inside canvas
        newX = Math.max(0, Math.min(100 - obj.width, newX));
        newY = Math.max(0, Math.min(100 - obj.height, newY));

        // Snap if enabled
        newX = this.snapPosition(newX);
        newY = this.snapPosition(newY);

        obj.x = newX;
        obj.y = newY;

        const el = document.querySelector(
            '[data-object-id="' + CSS.escape(cid) + '"]'
        );

        if (el) {
            this.applyStyles(el, obj);
        }

        moved = true;
    });

    if (moved) {
        this.hasUnsavedChanges = true;

        if (this.selectedIds.length === 1) {
            this.showProperties();
        }
    }

    return;
}
            if (e.key === 'g' || e.key === 'G') {
                e.preventDefault();
                this.toggleGrid();
                return;
            }
            if (e.key === 's' || e.key === 'S') {
                e.preventDefault();
                this.toggleSnap();
                return;
            }
            if (e.key === 'm' || e.key === 'M') {
                e.preventDefault();
                this.toggleSafeMargin();
                return;
            }
        },

        // ─── Undo/Redo ──────────────────────────────────────────────────────────
        // Model: undoStack holds PAST snapshots (states before each change).
        // The live `this.objects` is always the "current" state and is never itself
        // stored on the undo stack. saveState() is called right BEFORE a mutation,
        // capturing what the state looked like just before that change.

        saveState() {
            if (this.isUndoing) return;
            this.undoStack.push(JSON.stringify(this.objects));
            if (this.undoStack.length > this.maxUndo) {
                this.undoStack.shift();
            }
            this.redoStack = [];
            this.hasUnsavedChanges = true;
            this.updateUndoButtons();
        },

        undo() {
            if (this.undoStack.length === 0) return;
            this.isUndoing = true;
            // Stash the current (about-to-be-replaced) state so redo can restore it.
            this.redoStack.push(JSON.stringify(this.objects));
            const prevState = JSON.parse(this.undoStack.pop());
            this.restoreState(prevState);
            this.isUndoing = false;
            this.updateUndoButtons();
            this.hasUnsavedChanges = true;
        },

        redo() {
            if (this.redoStack.length === 0) return;
            this.isUndoing = true;
            // Stash current state back onto the undo stack before moving forward.
            this.undoStack.push(JSON.stringify(this.objects));
            const nextState = JSON.parse(this.redoStack.pop());
            this.restoreState(nextState);
            this.isUndoing = false;
            this.updateUndoButtons();
            this.hasUnsavedChanges = true;
        },

        restoreState(state) {
            // Clear all existing objects
            Object.keys(this.objects).forEach((cid) => {
                const el = document.querySelector('[data-object-id="' + CSS.escape(cid) + '"]');
                if (el) {
                    interact(el).unset();
                    el.remove();
                }
            });
            this.objects = {};
            this.selectedIds = [];

            // Restore
            Object.values(state).forEach((obj) => {
                this.objects[obj.clientId] = obj;
                this.renderObject(obj);
            });
            this.updateCount();
            this.deselect();
        },

        updateUndoButtons() {
            document.getElementById('undoBtn').disabled = this.undoStack.length === 0;
            document.getElementById('redoBtn').disabled = this.redoStack.length === 0;
        },

        // ─── Copy/Paste ─────────────────────────────────────────────────────────

        copySelected() {
            if (this.selectedIds.length === 0) return;
            this.clipboard = this.selectedIds.map((cid) => {
                const obj = this.objects[cid];
                return Object.assign({}, obj, { id: null, clientId: null });
            });
            this.toast('Copied ' + this.clipboard.length + ' object(s)', 'success');
        },

        pasteObjects() {
            if (!this.clipboard || this.clipboard.length === 0) {
                this.toast('Nothing to paste. Copy an object first.', 'warning');
                return;
            }
            this.saveState();
            const newIds = [];
            this.clipboard.forEach((data) => {
                const cid = this.nextClientId();
                const obj = Object.assign({}, data, {
                    clientId: cid,
                    id: null,
                    x: Math.min(97, (data.x || 10) + 3 + Math.random() * 5),
                    y: Math.min(97, (data.y || 10) + 3 + Math.random() * 5)
                });
                this.objects[cid] = obj;
                this.renderObject(obj);
                newIds.push(cid);
            });
            this.selectedIds = newIds;
            this.refreshSelection();
            this.updateCount();
            this.hasUnsavedChanges = true;
            this.toast('Pasted ' + newIds.length + ' object(s)', 'success');
        },

        // ─── Selection ──────────────────────────────────────────────────────────

        select(cid, additive = false) {
            if (!additive) {
                this.selectedIds = [];
            }
            // Locked objects can still be selected (read-only) so the user can
            // reach the Inspector's "Locked" checkbox and unlock them again.
            if (!this.selectedIds.includes(cid)) {
                this.selectedIds.push(cid);
            }
            this.refreshSelection();
            this.showProperties();
        },

        deselect() {
            this.selectedIds = [];
            this.refreshSelection();
            document.getElementById('noFieldSelected').style.display = 'block';
            document.getElementById('fieldProperties').style.display = 'none';
        },

        getSelected() {
            if (this.selectedIds.length === 0) return null;
            return this.objects[this.selectedIds[0]] || null;
        },

        getSelectedObjects() {
            return this.selectedIds.map((cid) => this.objects[cid]).filter(Boolean);
        },

        refreshSelection() {
            Object.keys(this.objects).forEach((cid) => {
                const el = document.querySelector('[data-object-id="' + CSS.escape(cid) + '"]');
                if (el) {
                    const isSelected = this.selectedIds.includes(cid);
                    el.classList.toggle('selected', isSelected);
                    const obj = this.objects[cid];
                    if (obj && isSelected) {
                        el.style.borderColor = 'var(--success)';
                        el.style.borderStyle = 'solid';
                    } else if (obj) {
                        el.style.borderColor = obj.locked ? 'rgba(0,0,0,0.2)' : 'transparent';
                        el.style.borderStyle = 'solid';
                    }
                }
            });
            this.updateCount();
            if (this.selectedIds.length > 0) {
                this.showProperties();
            }
        },

        setupSelectionRect() {
            const canvas = document.getElementById('cardCanvas');
            const rect = document.getElementById('selectionRect');

            canvas.addEventListener('mousedown', (e) => {
                if (e.target !== canvas && e.target.id !== 'cardBackground'
                    && !e.target.classList.contains('grid-overlay')) return;
                if (e.button !== 0) return;

                this.selectionStartX = e.clientX;
                this.selectionStartY = e.clientY;
                this.isSelecting = true;
                rect.style.display = 'block';
                rect.style.left = '0';
                rect.style.top = '0';
                rect.style.width = '0';
                rect.style.height = '0';
            });

            document.addEventListener('mousemove', (e) => {
                if (!this.isSelecting) return;
                const canvasRect = canvas.getBoundingClientRect();
                const sx = (this.selectionStartX - canvasRect.left) / canvasRect.width * 100;
                const sy = (this.selectionStartY - canvasRect.top) / canvasRect.height * 100;
                const ex = (e.clientX - canvasRect.left) / canvasRect.width * 100;
                const ey = (e.clientY - canvasRect.top) / canvasRect.height * 100;
                const x = Math.min(sx, ex);
                const y = Math.min(sy, ey);
                const w = Math.abs(ex - sx);
                const h = Math.abs(ey - sy);
                rect.style.left = x + '%';
                rect.style.top = y + '%';
                rect.style.width = w + '%';
                rect.style.height = h + '%';
            });

            document.addEventListener('mouseup', (e) => {
                if (!this.isSelecting) return;
                this.isSelecting = false;
                rect.style.display = 'none';

                const canvasRect = canvas.getBoundingClientRect();
                const sx = (this.selectionStartX - canvasRect.left) / canvasRect.width * 100;
                const sy = (this.selectionStartY - canvasRect.top) / canvasRect.height * 100;
                const ex = (e.clientX - canvasRect.left) / canvasRect.width * 100;
                const ey = (e.clientY - canvasRect.top) / canvasRect.height * 100;
                const x = Math.min(sx, ex);
                const y = Math.min(sy, ey);
                const w = Math.abs(ex - sx);
                const h = Math.abs(ey - sy);

                if (w > 1 && h > 1) {
                    const selected = [];
                    Object.values(this.objects).forEach((obj) => {
                        if (obj.side !== this.activeSide) return;
                        if (obj.x < x + w && obj.x + obj.width > x &&
                            obj.y < y + h && obj.y + obj.height > y) {
                            if (!obj.locked) selected.push(obj.clientId);
                        }
                    });
                    if (selected.length > 0) {
                        this.selectedIds = selected;
                        this.refreshSelection();
                        this.showProperties();
                    } else {
                        this.deselect();
                    }
                }
            });
        },

        // ─── Properties Panel ─────────────────────────────────────────────────

        showProperties() {
            if (this.selectedIds.length === 0) {
                document.getElementById('noFieldSelected').style.display = 'block';
                document.getElementById('fieldProperties').style.display = 'none';
                return;
            }
            document.getElementById('noFieldSelected').style.display = 'none';
            document.getElementById('fieldProperties').style.display = 'block';

            if (this.selectedIds.length === 1) {
                const obj = this.getSelected();
                if (!obj) return;
                this.populateInspector(obj);
            } else {
                // Multi-select: show common properties only
                const objs = this.getSelectedObjects();
                const common = this.commonProperties(objs);
                document.getElementById('propObjectMeta').textContent = objs.length + ' objects selected';
                document.getElementById('propContentGroup').style.display = 'none';
                document.getElementById('propX').value = common.x !== undefined ? common.x.toFixed(1) : '';
                document.getElementById('propY').value = common.y !== undefined ? common.y.toFixed(1) : '';
                document.getElementById('propWidth').value = common.width !== undefined ? common.width.toFixed(1) : '';
                document.getElementById('propHeight').value = common.height !== undefined ? common.height.toFixed(1) : '';
                document.getElementById('propFontFamily').value = common.font_family || '';
                document.getElementById('propFontSize').value = common.font_size || '';
                document.getElementById('propColor').value = common.color || '#000000';
                document.getElementById('propOpacity').value = common.opacity !== undefined ? common.opacity : 1;
                document.getElementById('propOpacityVal').textContent = (common.opacity !== undefined ? common.opacity : 1).toFixed(2);
                document.getElementById('propVisible').checked = common.visible !== 0;
                document.getElementById('propLocked').checked = common.locked === 1;
                document.getElementById('propShowLabelGroup').style.display = 'none';
                // Disable fields that are not common
                ['propX','propY','propWidth','propHeight','propFontFamily','propFontSize','propColor','propOpacity','propVisible','propLocked'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.style.opacity = common[id.replace('prop','').toLowerCase()] !== undefined ? '1' : '0.5';
                });
            }
        },

        populateInspector(obj) {
            document.getElementById('propObjectMeta').textContent = [
                obj.object_type,
                obj.field_key ? ('key=' + obj.field_key) : 'no key',
                obj.id ? ('#' + obj.id) : obj.clientId
            ].join(' · ');
const fieldLabelGroup = document.getElementById('propFieldLabelGroup');
const fieldLabelInput = document.getElementById('propFieldLabel');

if (obj.object_type === 'dynamic' && obj.field_key) {

    // Always show the currently saved/effective label.
    // Do not remove colons or spaces from the label.
    const currentLabel =
        SAMPLE_LABELS[obj.field_key] !== undefined &&
        SAMPLE_LABELS[obj.field_key] !== null
            ? String(SAMPLE_LABELS[obj.field_key])
            : obj.field_key.replace(/_/g, ' ');

    fieldLabelInput.value = currentLabel;
    fieldLabelGroup.style.display = 'block';

} else {

    fieldLabelInput.value = '';
    fieldLabelGroup.style.display = 'none';

}
            const isTemplateText = obj.object_type === 'dynamic' && ['terms', 'organization_name'].includes(obj.field_key);
            document.getElementById('propContentGroup').style.display = (obj.object_type === 'static_text' || isTemplateText) ? 'block' : 'none';
            document.getElementById('propContentLabel').textContent = obj.field_key === 'terms' ? 'Terms text' : (obj.field_key === 'organization_name' ? 'Organization name text' : 'Content');
            document.getElementById('propContent').value = obj.content || '';
            document.getElementById('propX').value = (+obj.x).toFixed(2);
            document.getElementById('propY').value = (+obj.y).toFixed(2);
            document.getElementById('propWidth').value = (+obj.width).toFixed(2);
            document.getElementById('propHeight').value = (+obj.height).toFixed(2);
            document.getElementById('propZIndex').value = (+obj.z_index || 0);
            document.getElementById('propFontFamily').value = obj.font_family || '';
            document.getElementById('propFontSize').value = obj.font_size || 12;
            document.getElementById('propColor').value = obj.color || DEFAULT_TEXT_COLOR;
            document.getElementById('propOpacity').value = obj.opacity != null ? obj.opacity : 1;
            document.getElementById('propOpacityVal').textContent = (obj.opacity != null ? obj.opacity : 1).toFixed(2);
            document.getElementById('propBorderWidth').value = obj.border_width != null ? obj.border_width : '';
            document.getElementById('propBorderStyle').value = obj.border_style || '';
            document.getElementById('propBorderColor').value = obj.border_color || '#000000';
            document.getElementById('propBorderRadius').value = obj.border_radius != null ? obj.border_radius : '';
            document.getElementById('propVisible').checked = obj.visible != 0;
            document.getElementById('propLocked').checked = obj.locked === 1;
            const canShowLabel = obj.object_type === 'dynamic' && obj.field_key !== null;
            document.getElementById('propShowLabelGroup').style.display = canShowLabel ? 'block' : 'none';
            document.getElementById('propShowLabel').checked = obj.show_label != 0;
            document.querySelectorAll('#propAlignGroup [data-align]').forEach((b) => {
                b.classList.toggle('active', b.dataset.align === (obj.text_align || 'left'));
            });
            document.getElementById('propBold').classList.toggle('active', obj.font_weight === 'bold' || obj.font_weight === '700');
            document.getElementById('propItalic').classList.toggle('active', obj.font_style === 'italic');
            document.getElementById('propUnderline').classList.toggle('active', obj.text_decoration === 'underline');

            // Disable editing of position/size/style fields (but not the Locked
            // checkbox itself) while the object is locked, so unlocking stays reachable.
            const lockAffected = ['propX','propY','propWidth','propHeight','propZIndex','propFontFamily','propFontSize','propColor','propOpacity','propVisible'];
            lockAffected.forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.disabled = !!obj.locked;
                    el.style.opacity = obj.locked ? '0.5' : '1';
                }
            });
        },

        commonProperties(objs) {
            const result = {};
            const keys = ['x', 'y', 'width', 'height', 'font_size', 'font_family', 'color', 'opacity', 'visible', 'locked', 'z_index'];
            keys.forEach((key) => {
                const values = objs.map((o) => o[key]);
                const allSame = values.every((v) => v === values[0]);
                if (allSame && values[0] !== undefined) {
                    result[key] = values[0];
                }
            });
            return result;
        },

        updateSelected(key, value) {
            const objs = this.getSelectedObjects();
            if (objs.length === 0) return;
            this.saveState();
            objs.forEach((obj) => {
                // The 'locked' flag itself, and 'visible', must always be toggleable —
                // otherwise a locked object can never be unlocked again.
                if (obj.locked && key !== 'locked' && key !== 'visible') return;
                if (['x', 'y', 'width', 'height', 'font_size', 'opacity', 'border_width', 'border_radius'].includes(key)) {
                    if (value !== null && value !== '' && !Number.isNaN(value)) {
                        obj[key] = value;
                    } else if (key === 'border_width' || key === 'border_radius') {
                        obj[key] = null;
                    }
                } else {
                    obj[key] = value;
                }
                this.renderObject(obj);
            });
            this.showProperties();
            this.hasUnsavedChanges = true;
        },

        updateSelectedZIndex(value) {
            const objs = this.getSelectedObjects();
            if (objs.length === 0) return;
            this.saveState();
            objs.forEach((obj) => {
                if (obj.locked) return;
                obj.z_index = value;
                this.renderObject(obj);
            });
            this.showProperties();
            this.hasUnsavedChanges = true;
        },

        // ─── Layer Management ─────────────────────────────────────────────────

        bringForward() {
            const obj = this.getSelected();
            if (!obj || obj.locked) return;
            this.saveState();
            const sameSide = Object.values(this.objects).filter((o) => o.side === obj.side && !o.locked);
            const maxZ = Math.max(...sameSide.map((o) => o.z_index || 0), 0);
            obj.z_index = maxZ + 1;
            this.renderObject(obj);
            this.showProperties();
            this.hasUnsavedChanges = true;
        },

        sendBackward() {
            const obj = this.getSelected();
            if (!obj || obj.locked) return;
            this.saveState();
            const sameSide = Object.values(this.objects).filter((o) => o.side === obj.side && !o.locked);
            const minZ = Math.min(...sameSide.map((o) => o.z_index || 0), 0);
            obj.z_index = minZ - 1;
            this.renderObject(obj);
            this.showProperties();
            this.hasUnsavedChanges = true;
        },

        // ─── Alignment ─────────────────────────────────────────────────────────

        alignSelected(direction) {
            const objs = this.getSelectedObjects().filter((o) => !o.locked);
            if (objs.length < 2) {
                this.toast('Select at least 2 objects to align', 'warning');
                return;
            }
            this.saveState();
            if (direction === 'left') {
                const minX = Math.min(...objs.map((o) => o.x));
                objs.forEach((o) => o.x = minX);
            } else if (direction === 'right') {
                const maxX = Math.max(...objs.map((o) => o.x + o.width));
                objs.forEach((o) => o.x = maxX - o.width);
            } else if (direction === 'center') {
                const avgX = objs.reduce((s, o) => s + o.x + o.width/2, 0) / objs.length;
                objs.forEach((o) => o.x = avgX - o.width/2);
            } else if (direction === 'top') {
                const minY = Math.min(...objs.map((o) => o.y));
                objs.forEach((o) => o.y = minY);
            } else if (direction === 'bottom') {
                const maxY = Math.max(...objs.map((o) => o.y + o.height));
                objs.forEach((o) => o.y = maxY - o.height);
            } else if (direction === 'middle') {
                const avgY = objs.reduce((s, o) => s + o.y + o.height/2, 0) / objs.length;
                objs.forEach((o) => o.y = avgY - o.height/2);
            }
            objs.forEach((o) => this.renderObject(o));
            this.showProperties();
            this.hasUnsavedChanges = true;
            this.toast('Aligned ' + direction, 'success');
        },

        equalSpacing(direction) {
            const objs = this.getSelectedObjects().filter((o) => !o.locked);
            if (objs.length < 3) {
                this.toast('Select at least 3 objects for equal spacing', 'warning');
                return;
            }
            this.saveState();
            const sorted = direction === 'horizontal'
                ? objs.sort((a, b) => a.x - b.x)
                : objs.sort((a, b) => a.y - b.y);
            const total = direction === 'horizontal'
                ? sorted[sorted.length - 1].x + sorted[sorted.length - 1].width - sorted[0].x
                : sorted[sorted.length - 1].y + sorted[sorted.length - 1].height - sorted[0].y;
            const spacing = total / (sorted.length - 1);
            sorted.forEach((o, i) => {
                if (i === 0) return;
                if (direction === 'horizontal') {
                    o.x = sorted[0].x + i * spacing - o.width/2;
                } else {
                    o.y = sorted[0].y + i * spacing - o.height/2;
                }
            });
            objs.forEach((o) => this.renderObject(o));
            this.showProperties();
            this.hasUnsavedChanges = true;
            this.toast('Equal spacing applied (' + direction + ')', 'success');
        },

        // ─── Grid & Snap ──────────────────────────────────────────────────────

        toggleGrid() {
            this.showGrid = !this.showGrid;
            document.getElementById('gridOverlay').classList.toggle('show', this.showGrid);
            document.querySelector('[onclick="Designer.toggleGrid()"]').classList.toggle('active', this.showGrid);
        },

        toggleSnap() {
            this.snapEnabled = !this.snapEnabled;
            document.querySelector('[onclick="Designer.toggleSnap()"]').classList.toggle('active', this.snapEnabled);
            this.toast('Snap ' + (this.snapEnabled ? 'enabled' : 'disabled'), 'info');
        },

        toggleSafeMargin() {
            this.showSafeMargin = !this.showSafeMargin;
            document.getElementById('safeMargin').style.display = this.showSafeMargin ? 'block' : 'none';
            document.querySelector('[onclick="Designer.toggleSafeMargin()"]').classList.toggle('active', this.showSafeMargin);
        },

        snapPosition(val) {
            if (!this.snapEnabled) return val;
            return Math.round(val / this.snapSize) * this.snapSize;
        },

        // ─── Copy Layout to Other Side ───────────────────────────────────────

        copyLayoutToOtherSide() {
            const currentSide = this.activeSide;
            const targetSide = currentSide === 'front' ? 'back' : 'front';
            this.saveState();

            // Find objects on current side
            const sourceObjs = Object.values(this.objects).filter((o) => o.side === currentSide && !o.locked);
            if (sourceObjs.length === 0) {
                this.toast('No objects on ' + currentSide + ' side to copy', 'warning');
                return;
            }

            // Remove existing objects on target side
            const targetCids = Object.keys(this.objects).filter((cid) => this.objects[cid].side === targetSide);
            targetCids.forEach((cid) => {
                const el = document.querySelector('[data-object-id="' + CSS.escape(cid) + '"]');
                if (el) {
                    interact(el).unset();
                    el.remove();
                }
                delete this.objects[cid];
            });

            // Copy objects to target side
            sourceObjs.forEach((obj) => {
                const cid = this.nextClientId();
                const newObj = Object.assign({}, obj, {
                    clientId: cid,
                    id: null,
                    side: targetSide
                });
                this.objects[cid] = newObj;
                this.renderObject(newObj);
            });

            this.selectedIds = [];
            this.refreshSelection();
            this.updateCount();
            this.hasUnsavedChanges = true;
            this.toast('Copied ' + sourceObjs.length + ' objects from ' + currentSide + ' to ' + targetSide, 'success');
            this.switchSide(targetSide);
        },

        // ─── Export/Import ────────────────────────────────────────────────────

        exportLayout() {
            const data = {
                version: '1.0',
                template_id: TEMPLATE_ID,
                side: this.activeSide,
                objects: Object.values(this.objects).map((obj) => ({
                    field_key: obj.field_key,
                    object_type: obj.object_type,
                    side: obj.side,
                    x: obj.x,
                    y: obj.y,
                    width: obj.width,
                    height: obj.height,
                    visible: obj.visible,
                    font_size: obj.font_size,
                    font_family: obj.font_family,
                    font_weight: obj.font_weight,
                    font_style: obj.font_style,
                    color: obj.color,
                    text_align: obj.text_align,
                    text_decoration: obj.text_decoration,
                    opacity: obj.opacity,
                    border_width: obj.border_width,
                    border_color: obj.border_color,
                    border_style: obj.border_style,
                    border_radius: obj.border_radius,
                    show_label: obj.show_label,
                    content: obj.content,
                    image_path: obj.image_path,
                    z_index: obj.z_index,
                    locked: obj.locked || 0
                }))
            };
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'layout_' + TEMPLATE_ID + '_' + this.activeSide + '.json';
            a.click();
            URL.revokeObjectURL(url);
            this.toast('Layout exported', 'success');
        },

        importLayout(file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const data = JSON.parse(e.target.result);
                    if (!data.objects || !Array.isArray(data.objects)) {
                        this.toast('Invalid layout file', 'danger');
                        return;
                    }
                    this.saveState();
                    // Remove existing objects on current side
                    const targetCids = Object.keys(this.objects).filter((cid) => this.objects[cid].side === this.activeSide);
                    targetCids.forEach((cid) => {
                        const el = document.querySelector('[data-object-id="' + CSS.escape(cid) + '"]');
                        if (el) {
                            interact(el).unset();
                            el.remove();
                        }
                        delete this.objects[cid];
                    });

                    data.objects.forEach((objData) => {
                        const cid = this.nextClientId();
                        const obj = Object.assign({}, objData, {
                            clientId: cid,
                            id: null,
                            side: this.activeSide
                        });
                        this.objects[cid] = obj;
                        this.renderObject(obj);
                    });
                    this.selectedIds = [];
                    this.refreshSelection();
                    this.updateCount();
                    this.hasUnsavedChanges = true;
                    this.toast('Imported ' + data.objects.length + ' objects', 'success');
                } catch (err) {
                    this.toast('Failed to parse layout file', 'danger');
                }
            };
            reader.readAsText(file);
        },

        // ─── Auto-Save ─────────────────────────────────────────────────────────

        startAutoSave() {
            this.autoSaveTimer = setInterval(() => {
                if (this.hasUnsavedChanges) {
                    this.saveDraft();
                }
            }, this.autoSaveInterval);
        },

        clearDraft() {
            try {
                localStorage.removeItem('designer_draft_' + TEMPLATE_ID);
            } catch (e) { /* ignore */ }
        },

        saveDraft() {
            try {
                const data = {
                    objects: Object.values(this.objects),
                    selectedIds: this.selectedIds,
                    activeSide: this.activeSide,
                    timestamp: Date.now()
                };
                localStorage.setItem('designer_draft_' + TEMPLATE_ID, JSON.stringify(data));
                this.hasUnsavedChanges = false;
            } catch (e) { /* ignore */ }
        },

        loadDraft() {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('saved')) {
                    this.clearDraft();
                    return;
                }
                const raw = localStorage.getItem('designer_draft_' + TEMPLATE_ID);
                if (!raw) return;
                const data = JSON.parse(raw);
                const now = Date.now();
                if (data.timestamp && (now - data.timestamp) < 900000) {
                    const serverIds = new Set(Object.keys(this.objects));
                    const serverDbIds = new Set(Object.values(this.objects).map(o => String(o.id)).filter(Boolean));
                    let restored = 0;
                    data.objects.forEach((obj) => {
                        // Skip if object has a DB ID that was archived/deleted on server
                        if (obj.id && Number(obj.id) > 0 && !serverDbIds.has(String(obj.id))) {
                            return;
                        }
                        if (!serverIds.has(obj.clientId) && (!obj.id || !serverDbIds.has(String(obj.id)))) {
                            const cid = this.nextClientId();
                            const newObj = Object.assign({}, obj, { clientId: cid, id: null });
                            this.objects[cid] = newObj;
                            this.renderObject(newObj);
                            restored++;
                        }
                    });
                    if (restored > 0) {
                        this.updateCount();
                        this.toast('Restored ' + restored + ' unsaved objects from draft', 'info');
                    }
                }
            } catch (e) { /* ignore */ }
        },

        // ─── Object CRUD ──────────────────────────────────────────────────────

        nextClientId() {
            this.newCounter += 1;
            return 'new_' + Date.now() + '_' + this.newCounter;
        },

        defaultSize(objectType) {
            const map = {
                photo: [25, 28], logo: [30, 18], qr: [18, 18], barcode: [50, 12],
                signature: [30, 12], static_text: [40, 10], image: [30, 20], dynamic: [40, 10]
            };
            return map[objectType] || [40, 10];
        },

        createObject(partial) {
            const objectType = partial.object_type || 'dynamic';
            const size = this.defaultSize(objectType);
            const cid = this.nextClientId();
            const obj = {
                id: null,
                clientId: cid,
                field_key: partial.field_key || null,
                object_type: objectType,
                side: this.activeSide,
                x: Math.max(0, Math.min(70, partial.x != null ? partial.x : 10)),
                y: Math.max(0, Math.min(80, partial.y != null ? partial.y : 10)),
                width: partial.width != null ? partial.width : size[0],
                height: partial.height != null ? partial.height : size[1],
                visible: 1,
                font_size: 12,
                font_family: null,
                font_weight: null,
                font_style: null,
                color: DEFAULT_TEXT_COLOR,
                text_align: 'left',
                text_decoration: null,
                opacity: 1,
                border_width: null,
                border_color: null,
                border_style: null,
                border_radius: null,
                show_label: objectType === 'dynamic' ? 1 : 0,
                content: partial.content || (objectType === 'static_text' ? 'Static Text' : null),
                image_path: partial.image_path || null,
                z_index: Object.values(this.objects).filter((o) => o.side === this.activeSide).length,
                locked: 0
            };
  this.saveState();
this.objects[cid] = obj;
this.renderObject(obj);
this.selectedIds = [cid];
this.refreshSelection();

this.updateCount();
this.updateUsedFields();
this.hasUnsavedChanges = true;

return obj;
        },

        addFromInputField(data, x, y) {
            this.createObject({
                field_key: data.field_key,
                object_type: data.object_type || 'dynamic',
                x: this.snapPosition(x - 5),
                y: this.snapPosition(y - 5)
            });
        },

        addStaticText() {
            this.createObject({ object_type: 'static_text', content: 'Static Text', field_key: null });
        },

        addPlaceholder(type) {
            this.createObject({ object_type: type, field_key: null });
      
      this.updateCount();
this.updateUsedFields();
this.hasUnsavedChanges = true;
return obj;  },
        
updateUsedFields() {
    const box = document.getElementById('usedFieldsList');
    if (!box) return;

    const used = {};

    Object.values(this.objects).forEach(obj => {
        if (obj.field_key && obj.visible != 0) {
            used[obj.field_key] = obj;
        }
    });

    box.innerHTML = Object.values(used).map(obj => {
        const label =
            SAMPLE_LABELS[obj.field_key] ||
            obj.field_key.replace(/_/g, ' ');

        return `<span class="used-field-chip">
            <i class="fas fa-tag"></i>
            ${this.escHtml(label)}
        </span>`;
    }).join('');

    if (!Object.keys(used).length) {
        box.innerHTML = '<span class="text-muted small">No input fields used</span>';
    }
},
        duplicateSelected() {
            const objs = this.getSelectedObjects();
            if (objs.length === 0) return;
            this.saveState();
            const newIds = [];
            objs.forEach((obj) => {
                if (obj.locked) return;
                const cid = this.nextClientId();
                const clone = Object.assign({}, obj, {
                    id: null,
                    clientId: cid,
                    x: Math.min(97, (obj.x || 0) + 3),
                    y: Math.min(97, (obj.y || 0) + 3)
                });
                this.objects[cid] = clone;
                this.renderObject(clone);
                newIds.push(cid);
            });
            this.selectedIds = newIds;
            this.refreshSelection();
            this.updateCount();
            this.hasUnsavedChanges = true;
            this.toast('Duplicated ' + newIds.length + ' object(s)', 'success');
        },

        archiveSelected() {
            const objs = this.getSelectedObjects();
            if (objs.length === 0) return;
            if (!confirm('Archive ' + objs.length + ' object(s)? They will be soft-deleted on save.')) return;
            this.saveState();
            objs.forEach((obj) => {
                if (obj.locked) return;
                const el = document.querySelector('[data-object-id="' + CSS.escape(obj.clientId) + '"]');
                if (el) {
                    interact(el).unset();
                    el.remove();
                }
                delete this.objects[obj.clientId];
            });
            this.selectedIds = [];
            this.refreshSelection();
this.updateCount();
this.updateUsedFields();
this.hasUnsavedChanges = true;
            this.clearDraft();
            this.toast('Archived ' + objs.length + ' object(s)', 'success');
        },

        // ─── Render ───────────────────────────────────────────────────────────

        objectInnerHtml(obj) {
            const type = obj.object_type;
            if (type === 'photo' || type === 'logo') {
                return '<img src="' + this.escAttr(SAMPLE_ASSETS[type] || '') + '" alt="' + type + '">';
            }
            if (type === 'qr' || type === 'barcode') {
                return '<span class="sample-code">' + (SAMPLE_ASSETS[type] || '') + '</span>';
            }
            if (type === 'signature') {
                return SAMPLE_ASSETS.signature
                    ? '<img src="' + this.escAttr(SAMPLE_ASSETS.signature) + '" alt="Signature">'
                    : '<span class="sample-value">Authorized Signature</span>';
            }
            if (type === 'image') {
                if (obj.image_path) {
                    return '<img src="../' + this.escAttr(obj.image_path) + '" alt="">';
                }
                return '<div class="obj-label"><i class="fas fa-image"></i></div>';
            }
            if (type === 'static_text') {
                return '<span class="obj-label static-edit">' + this.escHtml(obj.content || 'Text') + '</span>';
            }
            const key = obj.field_key || '';
            const value = (['terms', 'organization_name'].includes(key) && obj.content)
                ? obj.content
                : (SAMPLE_VALUES[key] !== undefined && SAMPLE_VALUES[key] !== null ? SAMPLE_VALUES[key] : key.replace(/_/g, ' '));
            const labelText = SAMPLE_LABELS[key] !== undefined && SAMPLE_LABELS[key] !== null ? SAMPLE_LABELS[key] : key.replace(/_/g, ' ');

            const label = obj.show_label != 0 && key
                ? '<span class="sample-label">'
                    + this.escHtml(labelText)
                    + '</span>&nbsp;'
                : '';
            return '<span class="sample-value">' + label + this.escHtml(value).replace(/\n/g, '<br>') + '</span>';
        },

        renderObject(obj) {
            const canvas = document.getElementById('cardCanvas');
            let el = canvas.querySelector('[data-object-id="' + CSS.escape(obj.clientId) + '"]');
            const isNew = !el;
            if (isNew) {
                el = document.createElement('div');
                el.className = 'canvas-object type-' + obj.object_type;
                el.dataset.objectId = obj.clientId;
                canvas.appendChild(el);
                this.enableInteract(el);
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.select(obj.clientId, e.shiftKey);
                });
                el.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    if (obj.locked) return;
                    if (obj.object_type === 'static_text') {
                        this.editStaticText(obj.clientId);
                    }
                });
            }
            el.className = 'canvas-object type-' + obj.object_type;
            el.classList.toggle('selected', this.selectedIds.includes(obj.clientId));
            el.classList.toggle('locked', obj.locked === 1);

el.innerHTML =
    '<span class="move-handle" title="Drag to move"><i class="fas fa-arrows-alt"></i></span>'
    + '<span class="resize-grip" title="Drag to resize"><i class="fas fa-expand-alt"></i></span>'
    + '<div class="object-content">'
    + this.objectInnerHtml(obj)
    + '</div>';
            el.querySelector('.move-handle').addEventListener('pointerdown', (event) => {
                if (obj.locked) return;
                this.startHandleMove(event, obj.clientId);
            });
            this.applyStyles(el, obj);
            el.style.display = (obj.side === this.activeSide && obj.visible != 0) ? 'flex' : 'none';
            el.style.zIndex = (obj.z_index || 0) + 10;
        },

        applyStyles(el, obj) {
            el.style.left = obj.x + '%';
            el.style.top = obj.y + '%';
            el.style.width = obj.width + '%';
            el.style.height = obj.height + '%';
            el.style.fontSize = ((obj.font_size || 12) * CANVAS_RENDER_SCALE) + 'px';
            el.style.fontFamily = obj.font_family || '';
            el.style.fontWeight = obj.font_weight || '';
            el.style.fontStyle = obj.font_style || '';
            el.style.textDecoration = obj.text_decoration || '';
            el.style.color = obj.color || DEFAULT_TEXT_COLOR;
            el.style.justifyContent = obj.text_align === 'left' ? 'flex-start' : (obj.text_align === 'right' ? 'flex-end' : 'center');
            el.style.textAlign = obj.text_align || 'left';
            el.style.opacity = obj.opacity != null ? obj.opacity : 1;
            if (obj.border_width && obj.border_width > 0) {
                el.style.borderWidth = (obj.border_width * CANVAS_RENDER_SCALE) + 'px';
                el.style.borderStyle = obj.border_style || 'solid';
                el.style.borderColor = obj.border_color || '#000';
            } else {
                el.style.borderWidth = '0';
                el.style.borderStyle = 'solid';
                el.style.borderColor = 'transparent';
            }
            el.style.borderRadius = (obj.border_radius != null && obj.border_radius !== '') ? (obj.border_radius + 'px') : '';
            el.style.zIndex = (obj.z_index || 0) + 10;
        },

        enableInteract(el) {
            const self = this;
            interact(el).draggable({
                inertia: false,
                ignoreFrom: '.move-handle, .resize-grip',
                modifiers: [
                    interact.modifiers.restrictRect({ restriction: 'parent', endOnly: true })
                ],
                listeners: {
                    start(event) {
                        const cid = event.target.dataset.objectId;
                        const obj = self.objects[cid];
                        if (!obj || obj.locked) {
                            event.preventDefault();
                            return;
                        }
                        if (!self.selectedIds.includes(cid)) {
                            self.select(cid);
                        }
                        self.saveState();
                        event.target.classList.add('dragging');
                    },
                    move(event) {
                        const target = event.target;
                        const cid = target.dataset.objectId;
                        const obj = self.objects[cid];
                        if (!obj || obj.locked) return;
                        const parent = target.parentElement;
                        let x = obj.x + (event.dx / (parent.offsetWidth * self.zoom)) * 100;
                        let y = obj.y + (event.dy / (parent.offsetHeight * self.zoom)) * 100;
                        x = self.snapPosition(Math.max(0, Math.min(100 - obj.width, x)));
                        y = self.snapPosition(Math.max(0, Math.min(100 - obj.height, y)));
                        // Move all selected objects
                        const dx = x - obj.x;
                        const dy = y - obj.y;
                        self.selectedIds.forEach((scid) => {
                            const sobj = self.objects[scid];
                            if (!sobj || sobj.locked) return;
                            let nx = sobj.x + dx;
                            let ny = sobj.y + dy;
                            nx = self.snapPosition(Math.max(0, Math.min(100 - sobj.width, nx)));
                            ny = self.snapPosition(Math.max(0, Math.min(100 - sobj.height, ny)));
                            sobj.x = nx;
                            sobj.y = ny;
                            self.applyStyles(document.querySelector('[data-object-id="' + CSS.escape(scid) + '"]'), sobj);
                        });
                        if (self.selectedIds.length === 1) self.showProperties();
                    },
                    end(event) {
                        event.target.classList.remove('dragging');
                        self.hasUnsavedChanges = true;
                    }
                }
            }).resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                modifiers: [
                    interact.modifiers.restrictEdges({ outer: 'parent' }),
                    interact.modifiers.restrictSize({ min: { width: 12, height: 12 } })
                ],
                listeners: {
                    start(event) {
                        const cid = event.target.dataset.objectId;
                        const obj = self.objects[cid];
                        if (!obj || obj.locked) {
                            event.preventDefault();
                            return;
                        }
                        if (!self.selectedIds.includes(cid)) {
                            self.select(cid);
                        }
                        self.saveState();
                    },
                    move(event) {
                        const target = event.target;
                        const cid = target.dataset.objectId;
                        const obj = self.objects[cid];
                        if (!obj || obj.locked) return;
                        const parent = target.parentElement;
                        let { x, y, width, height } = obj;
                        width += (event.deltaRect.width / (parent.offsetWidth * self.zoom)) * 100;
                        height += (event.deltaRect.height / (parent.offsetHeight * self.zoom)) * 100;
                        x += (event.deltaRect.left / (parent.offsetWidth * self.zoom)) * 100;
                        y += (event.deltaRect.top / (parent.offsetHeight * self.zoom)) * 100;
                        x = self.snapPosition(Math.max(0, x));
                        y = self.snapPosition(Math.max(0, y));
                        width = self.snapPosition(Math.max(2, Math.min(100 - x, width)));
                        height = self.snapPosition(Math.max(2, Math.min(100 - y, height)));
                        obj.x = x;
                        obj.y = y;
                        obj.width = width;
                        obj.height = height;
                        self.applyStyles(target, obj);
                        if (self.selectedIds.length === 1) self.showProperties();
                    },
                    end() {
                        self.hasUnsavedChanges = true;
                    }
                }
            });
        },

        startHandleMove(event, cid) {
            if (event.button !== 0) return;
            event.preventDefault();
            event.stopPropagation();
            const obj = this.objects[cid];
            const canvas = document.getElementById('cardCanvas');
            if (!obj || obj.locked || !canvas) return;

          this.select(cid, event.ctrlKey || event.metaKey || event.shiftKey);
            this.saveState();
            let lastX = event.clientX;
            let lastY = event.clientY;
            const move = (moveEvent) => {
                const dx = moveEvent.clientX - lastX;
                const dy = moveEvent.clientY - lastY;
                lastX = moveEvent.clientX;
                lastY = moveEvent.clientY;
                const target = document.querySelector('[data-object-id="' + CSS.escape(cid) + '"]');
                if (!target) return;
                const parent = target.parentElement;
                let x = obj.x + (dx / (parent.offsetWidth * this.zoom)) * 100;
                let y = obj.y + (dy / (parent.offsetHeight * this.zoom)) * 100;
                x = this.snapPosition(Math.max(0, Math.min(100 - obj.width, x)));
                y = this.snapPosition(Math.max(0, Math.min(100 - obj.height, y)));
                obj.x = x;
                obj.y = y;
                this.applyStyles(target, obj);
                this.showProperties();
            };
            const end = () => {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', end);
                document.removeEventListener('pointercancel', end);
                this.hasUnsavedChanges = true;
            };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', end);
            document.addEventListener('pointercancel', end);
        },

        editStaticText(cid) {
            const obj = this.objects[cid];
            if (!obj || obj.locked) return;
            const next = prompt('Edit text content:', obj.content || '');
            if (next === null) return;
            this.saveState();
            obj.content = next;
            this.renderObject(obj);
            this.showProperties();
            this.hasUnsavedChanges = true;
        },

        // ─── Side Switching ───────────────────────────────────────────────────

        switchSide(side) {
            this.activeSide = side === 'back' ? 'back' : 'front';
            this.deselect();
            document.querySelectorAll('.side-tab').forEach((tab) => {
                tab.classList.toggle('active', tab.dataset.cardSide === this.activeSide);
            });
            Object.values(this.objects).forEach((obj) => {
                const el = document.querySelector('[data-object-id="' + CSS.escape(obj.clientId) + '"]');
                if (el) {
                    el.style.display = (obj.side === this.activeSide && obj.visible != 0) ? 'flex' : 'none';
                }
            });
            this.updateCount();
        },

        // ─── Zoom ─────────────────────────────────────────────────────────────

        setZoom(z) {
            this.zoom = Math.max(0.3, Math.min(3, z));
            document.getElementById('canvasScaleWrap').style.transform = 'scale(' + this.zoom + ')';
            document.getElementById('zoomLabel').textContent = Math.round(this.zoom * 100) + '%';
        },
        zoomIn() { this.setZoom(this.zoom + 0.1); },
        zoomOut() { this.setZoom(this.zoom - 0.1); },
        zoomFit() {
            const stage = document.getElementById('canvasStage');
            const canvas = document.getElementById('cardCanvas');
            const pad = 48;
            const sx = (stage.clientWidth - pad) / canvas.offsetWidth;
            const sy = (stage.clientHeight - pad) / canvas.offsetHeight;
            this.setZoom(Math.max(0.3, Math.min(1, Math.min(sx, sy))));
        },
async saveSelectedFieldLabel() {

    if (this.selectedIds.length !== 1) {
        this.toast('Select one field first.', 'warning');
        return;
    }

    const obj = this.getSelected();

    if (!obj || obj.object_type !== 'dynamic' || !obj.field_key) {
        this.toast('This object does not have a field label.', 'warning');
        return;
    }

    const labelInput = document.getElementById('propFieldLabel');

    const label = labelInput.value;
    const labelForValidation = label.trim();

    if (!labelForValidation) {
        this.toast('Field label cannot be empty.', 'warning');
        return;
    }

    const fd = new FormData();

    fd.append('csrf_token', CSRF);
    fd.append('update_field_definition', '1');
    fd.append('ajax', '1');
    fd.append('field_key', obj.field_key);
    fd.append('field_label', label);
    // Label-only update: do not overwrite the existing placeholder/default value.

    try {

        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: fd
        });

        const data = await res.json();

        if (!data.success) {
            this.toast(
                data.error || 'Failed to update field label.',
                'danger'
            );
            return;
        }

        // Update label in current page
        SAMPLE_LABELS[obj.field_key] = label;

        // Update all objects using this field
        Object.values(this.objects).forEach((item) => {
            if (item.field_key === obj.field_key) {
                this.renderObject(item);
            }
        });

        // Update sidebar common fields list item text if present
        const sidebarItem = document.querySelector('[data-field-key="' + CSS.escape(obj.field_key) + '"] .field-name');
        if (sidebarItem) {
            sidebarItem.textContent = label;
        }

        this.toast(
            'Field label updated successfully.',
            'success'
        );

    } catch (err) {

        this.toast(
            'Error: ' + err.message,
            'danger'
        );

    }
},
        // ─── Uploads ─────────────────────────────────────────────────────────

        async uploadObjectImage(file) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('upload_object_image', '1');
            fd.append('ajax', '1');
            fd.append('object_image', file);
            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    this.toast(data.error || 'Upload failed', 'danger');
                    return;
                }
                this.createObject({
                    object_type: 'image',
                    field_key: null,
                    image_path: data.image_path
                });
            } catch (err) {
                this.toast('Upload error: ' + err.message, 'danger');
            }
        },

        async uploadBackground() {
            const input = document.getElementById('bgFileInput');
            if (!input.files || !input.files[0]) {
                this.toast('Choose an image first.', 'danger');
                return;
            }
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('upload_background', '1');
            fd.append('ajax', '1');
            fd.append('background', input.files[0]);
            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    this.toast(data.error || 'Upload failed', 'danger');
                    return;
                }
                const bg = document.getElementById('cardBackground');
                bg.style.backgroundImage = "url('" + data.url + "')";
                bg.style.opacity = '1';
                document.getElementById('bgPathLabel').textContent = data.background_image;
                document.getElementById('btnRemoveBg').disabled = false;
                this.toast('Background updated.', 'success');
            } catch (err) {
                this.toast('Upload error: ' + err.message, 'danger');
            }
        },

        async removeBackground() {
            if (!confirm('Remove the background image?')) return;
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('remove_background', '1');
            fd.append('ajax', '1');
            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    this.toast(data.error || 'Failed', 'danger');
                    return;
                }
                const bg = document.getElementById('cardBackground');
                bg.style.backgroundImage = '';
                bg.style.opacity = '0.15';
                document.getElementById('bgPathLabel').textContent = 'No background set';
                document.getElementById('btnRemoveBg').disabled = true;
                this.toast('Background removed.', 'success');
            } catch (err) {
                this.toast('Error: ' + err.message, 'danger');
            }
        },

        // ─── Save ─────────────────────────────────────────────────────────────

        collectPositions() {
            return Object.values(this.objects).map((obj) => ({
                id: obj.id && Number(obj.id) > 0 ? Number(obj.id) : null,
                field_key: obj.field_key,
                object_type: obj.object_type,
                side: obj.side,
                x: +obj.x, y: +obj.y, width: +obj.width, height: +obj.height,
                visible: obj.visible != 0 ? 1 : 0,
                font_size: obj.font_size || 12,
                font_family: obj.font_family,
                font_weight: obj.font_weight,
                font_style: obj.font_style,
                color: obj.color,
                text_align: obj.text_align || 'left',
                text_decoration: obj.text_decoration,
                opacity: obj.opacity != null ? obj.opacity : 1,
                border_width: obj.border_width,
                border_color: obj.border_color,
                border_style: obj.border_style,
                border_radius: obj.border_radius,
                show_label: obj.show_label ? 1 : 0,
                content: (obj.object_type === 'static_text' || ['terms', 'organization_name'].includes(obj.field_key)) ? (obj.content || '') : null,
                image_path: obj.object_type === 'image' ? (obj.image_path || null) : null,
                z_index: obj.z_index || 0
            }));
        },

        async save(versionConfirmed = false) {
            const overlay = document.getElementById('savingOverlay');
            overlay.classList.add('active');
            try {
                const params = {
                    csrf_token: CSRF,
                    save_positions: '1',
                    ajax: '1',
                    positions: JSON.stringify(this.collectPositions())
                };
                if (versionConfirmed) params.version_confirmed = '1';

                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams(params)
                });
                const data = await res.json();
                overlay.classList.remove('active');

                // Template is in use — show versioning modal
                if (data.needs_version) {
                    document.getElementById('versionModalMemberCount').textContent = data.member_count;
                    document.getElementById('versionModalTemplateName').textContent = data.template_name;
                    const modalEl = document.getElementById('versioningModal');
                    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    return;
                }

                if (!data.success) {
                    this.toast(data.error || 'Save failed', 'danger');
                    return;
                }
                this.hasUnsavedChanges = false;
                this.clearDraft();
                this.toast('Saved ' + (data.saved || '') + ' object(s). Reloading…', 'success');
                setTimeout(() => { window.location.href = 'design_template.php?id=' + TEMPLATE_ID + '&saved=1'; }, 600);
            } catch (err) {
                overlay.classList.remove('active');
                this.toast('Save error: ' + err.message, 'danger');
            }
        },

        // Called when user chooses "Save in place" from the versioning modal
        async saveInPlace() {
            const modalEl = document.getElementById('versioningModal');
            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
            await this.save(true);
        },

        // Called when user chooses "Create New Version" from the versioning modal
        async createNewVersion() {
            const modalEl = document.getElementById('versioningModal');
            if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
            const overlay = document.getElementById('savingOverlay');
            overlay.classList.add('active');
            try {
                const fd = new FormData();
                fd.append('csrf_token', CSRF);
                fd.append('create_new_version', '1');
                fd.append('ajax', '1');
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                overlay.classList.remove('active');
                if (!data.success) {
                    this.toast(data.error || 'Failed to create new version.', 'danger');
                    return;
                }

                // A version is created from the last saved layout.  Persist the
                // objects currently on the canvas into that new version before
                // navigating, otherwise Generate/View would show the old layout.
                const saveParams = {
                    csrf_token: CSRF,
                    save_positions: '1',
                    version_confirmed: '1',
                    replace_layout: '1',
                    ajax: '1',
                    positions: JSON.stringify(this.collectPositions())
                };
                const saveResponse = await fetch('design_template.php?id=' + encodeURIComponent(data.new_id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams(saveParams)
                });
                const saveData = await saveResponse.json();
                if (!saveData.success) {
                    this.toast(saveData.error || 'New version was created, but its layout could not be saved.', 'danger');
                    return;
                }

                this.clearDraft();
                this.toast('New version with the current layout created. Redirecting to v' + data.version + '…', 'success');
                setTimeout(() => { window.location.href = data.redirect + '&saved=1'; }, 700);
            } catch (err) {
                overlay.classList.remove('active');
                this.toast('Error: ' + err.message, 'danger');
            }
        },



        // ─── Preview ──────────────────────────────────────────────────────────

        togglePreview() {
            document.getElementById('previewPane').classList.toggle('show');
        },

        // ─── Count ────────────────────────────────────────────────────────────

        updateCount() {
            const count = Object.values(this.objects).filter((o) => o.side === this.activeSide).length;
            document.getElementById('objectCountBadge').textContent = count + ' objects';
        },

        // ─── Utilities ────────────────────────────────────────────────────────

        escHtml(s) {
            return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        },
        escAttr(s) { return this.escHtml(s); },

        openFieldModal() {
            document.getElementById('addFieldForm')?.reset();
            const modalEl = document.getElementById('addFieldModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        },

        openEditFieldModal(fieldItem) {
            const key = fieldItem?.dataset?.fieldKey || '';
            const label =
                SAMPLE_LABELS[key] !== undefined && SAMPLE_LABELS[key] !== null
                    ? String(SAMPLE_LABELS[key])
                    : (fieldItem?.dataset?.fieldLabel || '');
            const placeholder =
                fieldItem?.dataset?.placeholder || '';
            const defaultValue =
                fieldItem?.dataset?.defaultValue ||
                SAMPLE_VALUES[key] ||
                '';

            document.getElementById('editFieldKey').value = key;
            document.getElementById('editFieldKeyDisplay').textContent = key;
            document.getElementById('editFieldLabel').value = label;
            document.getElementById('editFieldPlaceholder').value = placeholder;
            document.getElementById('editFieldDefaultValue').value = defaultValue;
            document.getElementById('editFieldForm').dataset.fieldItem = key;

            const modalEl = document.getElementById('editFieldModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        },

        async submitEditFieldForm() {
            const key = document.getElementById('editFieldKey').value.trim();
            const label = document.getElementById('editFieldLabel').value;
            const placeholder = document.getElementById('editFieldPlaceholder').value.trim();
            const defaultValue = document.getElementById('editFieldDefaultValue').value.trim();

            if (!key) { alert('Field key is missing.'); return; }
            if (!label.trim()) { alert('Field label cannot be empty.'); return; }

            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('update_field_definition', '1');
            fd.append('ajax', '1');
            fd.append('field_key', key);
            fd.append('field_label', label);
            fd.append('placeholder', placeholder);
            fd.append('default_value', defaultValue);

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'Failed to update field.');
                    return;
                }
                // Update SAMPLE values in memory independently
                if (defaultValue !== '') {
                    SAMPLE_VALUES[key] = defaultValue;
                }
                if (label !== '') {
                    SAMPLE_LABELS[key] = label;
                }
                // Re-render all canvas objects that use this key
                Object.values(this.objects).forEach((obj) => {
                    if (obj.field_key === key) this.renderObject(obj);
                });
                // Close modal
                const modalEl = document.getElementById('editFieldModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
                this.toast('Field "' + label + '" updated. Reloading…', 'success');
                setTimeout(() => { window.location.reload(); }, 500);
            } catch (err) {
                alert('Error: ' + err.message);
            }
        },

        async deleteCustomField(key, label) {
            if (!confirm('Delete custom field "' + label + '" (' + key + ')?\n\nThis will remove it from this template\'s member form and archive its canvas object.\nThis action cannot be undone.')) return;

            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('delete_custom_field', '1');
            fd.append('ajax', '1');
            fd.append('field_key', key);

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'Failed to delete field.');
                    return;
                }
                // Remove canvas objects with this field_key
                Object.values(this.objects).forEach((obj) => {
                    if (obj.field_key === key) {
                        const el = document.querySelector('[data-object-id="' + CSS.escape(obj.clientId) + '"]');
                        if (el) { try { interact(el).unset(); } catch(e){} el.remove(); }
                        delete this.objects[obj.clientId];
                    }
                });
                this.selectedIds = this.selectedIds.filter(cid => !(!this.objects[cid]));
                this.refreshSelection();
                this.updateCount();
                this.clearDraft();
                this.toast('Field "' + label + '" deleted. Reloading…', 'success');
                setTimeout(() => { window.location.reload(); }, 500);
            } catch (err) {
                alert('Error: ' + err.message);
            }
        },


        autoGenerateFieldKey(val) {
            const keyInput = document.getElementById('modalFieldKey');
            if (keyInput && (!keyInput.dataset.userEdited || keyInput.dataset.userEdited === '0')) {
                keyInput.value = val.toLowerCase().trim().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_');
            }
        },

        async submitAddFieldForm() {
            const label = document.getElementById('modalFieldLabel').value.trim();
            const key = document.getElementById('modalFieldKey').value.trim();
            const type = document.getElementById('modalFieldType').value;
            const bilingual = document.getElementById('modalBilingualMode').value;
            const placeholder = document.getElementById('modalPlaceholder').value.trim();
            const defaultValue = document.getElementById('modalDefaultValue').value.trim();
            const isReq = document.getElementById('modalIsRequired').checked ? 1 : 0;
            const isCommon = document.getElementById('modalIsCommon').checked ? 1 : 0;

            if (!label || !key) {
                alert('Please enter both Field Label and Field Key.');
                return;
            }

            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('add_input_field', '1');
            fd.append('ajax', '1');
            fd.append('field_label', label);
            fd.append('field_key', key);
            fd.append('field_type', type);
            fd.append('bilingual_mode', bilingual);
            fd.append('placeholder', placeholder);
            fd.append('default_value', defaultValue);
            fd.append('is_required', isReq);
            fd.append('is_common', isCommon);

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
         const responseText = await res.text();

let data;

try {
    data = JSON.parse(responseText);
} catch (e) {
    console.error('SERVER RESPONSE:', responseText);

    alert(
        'PHP Error:\n\n' +
        responseText.replace(/<[^>]*>/g, ' ').trim()
    );

    return;
}
                if (!data.success) {
                    alert(data.error || 'Failed to add field');
                    return;
                }
                const modalEl = document.getElementById('addFieldModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
                SAMPLE_VALUES[key] = defaultValue;
                SAMPLE_LABELS[key] = label;

                this.toast('Field "' + label + '" created. Reloading palette…', 'success');
                setTimeout(() => { window.location.reload(); }, 400);
            } catch (err) {
                alert('Error: ' + err.message);
            }
        },

        async saveBackgroundSettings() {
            const posX = parseFloat(document.getElementById('bgPosXInput').value) || 50;
            const posY = parseFloat(document.getElementById('bgPosYInput').value) || 50;
            const size = document.getElementById('bgSizeInput').value;

            const bg = document.getElementById('cardBackground');
            if (bg) {
                bg.style.backgroundPosition = posX + '% ' + posY + '%';
                bg.style.backgroundSize = size;
            }

            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('save_background_settings', '1');
            fd.append('ajax', '1');
            fd.append('bg_pos_x', posX);
            fd.append('bg_pos_y', posY);
            fd.append('bg_size', size);

            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd
                });
                const data = await res.json();
                if (!data.success) {
                    this.toast(data.error || 'Failed to save background settings', 'danger');
                    return;
                }
                this.toast('Background settings saved to database.', 'success');
            } catch (err) {
                this.toast('Error saving background settings: ' + err.message, 'danger');
            }
        },

        toast(text, type) {
            document.querySelectorAll('.alert-toast-msg').forEach((el) => el.remove());
            const div = document.createElement('div');
            const iconMap = { success: 'check-circle', danger: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
            div.className = 'alert alert-' + type + ' alert-toast-msg mb-3';
            div.innerHTML = '<i class="fas fa-' + (iconMap[type] || 'info-circle') + '"></i><div class="flex-1">' + this.escHtml(text) + '</div><button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>';
            const content = document.querySelector('.dashboard-content') || document.body;
            if (content.firstChild) {
                content.insertBefore(div, content.firstChild);
            } else {
                content.appendChild(div);
            }
            setTimeout(() => { if (div.parentElement) div.remove(); }, 5000);
        }
    };

    window.Designer = Designer;

    document.addEventListener('DOMContentLoaded', function () {
        Designer.init(<?= json_encode($objectsForJs, JSON_UNESCAPED_UNICODE) ?>);
        window.addEventListener('resize', () => Designer.zoomFit());

        // Initialize toolbar button states
        document.querySelectorAll('.toolbar-btn').forEach((btn) => {
            btn.classList.remove('active');
        });
        // Grid off by default
        document.getElementById('gridOverlay').classList.remove('show');
        document.getElementById('modalFieldKey')?.addEventListener('input', function() { this.dataset.userEdited = '1'; });
    });
})();
</script>

<!-- Add New Field Modal -->
<div class="modal fade" id="addFieldModal" tabindex="-1" aria-labelledby="addFieldModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addFieldModalLabel"><i class="fas fa-plus-circle text-primary me-2"></i>Add New Custom Field</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="addFieldForm" onsubmit="event.preventDefault(); Designer.submitAddFieldForm();">
          <div class="mb-3">
            <label class="form-label">Field Name / Label <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="modalFieldLabel" required placeholder="e.g. Blood Group, Roll No" oninput="Designer.autoGenerateFieldKey(this.value)">
          </div>
          <div class="mb-3">
            <label class="form-label">Field Key (System Identifier) <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="modalFieldKey" required placeholder="e.g. blood_group, roll_no">
            <div class="form-text">Unique key used in database (letters, numbers, underscores)</div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Field Type</label>
              <select class="form-select" id="modalFieldType">
                <option value="text">Text</option>
                <option value="textarea">Textarea</option>
                <option value="number">Number</option>
                <option value="date">Date</option>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="address">Address</option>
                <option value="id_number">ID Number</option>
                <option value="photo">Photo</option>
                <option value="signature">Signature</option>
                <option value="qr">QR Code</option>
                <option value="barcode">Barcode</option>
                <option value="logo">Logo</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Bilingual Mode</label>
              <select class="form-select" id="modalBilingualMode">
                <option value="single">Single Language</option>
                <option value="bilingual">Bilingual / Dual</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Member Placeholder (optional)</label>
            <input type="text" class="form-control" id="modalPlaceholder" placeholder="e.g. Select or type blood group">
          </div>
          <div class="mb-3">
            <label class="form-label">Sample Value (Designer Preview)</label>
            <input type="text" class="form-control" id="modalDefaultValue" placeholder="e.g. O+ve">
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="modalIsRequired">
            <label class="form-check-label" for="modalIsRequired">Required on Member Form</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="modalIsCommon">
            <label class="form-check-label" for="modalIsCommon"><i class="fas fa-globe text-primary me-1"></i> Make available as Common Field for all templates</label>
          </div>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Save & Add Field</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Edit Custom Field Modal -->
<div class="modal fade" id="editFieldModal" tabindex="-1" aria-labelledby="editFieldModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editFieldModalLabel"><i class="fas fa-pen text-warning me-2"></i>Edit Custom Field</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="editFieldForm" onsubmit="event.preventDefault(); Designer.submitEditFieldForm();">
          <input type="hidden" id="editFieldKey">
          <div class="mb-3">
            <label class="form-label text-muted" style="font-size:0.8rem">Field Key (cannot change)</label>
            <div class="form-control bg-light text-muted" id="editFieldKeyDisplay" style="font-family:monospace;font-size:0.85rem"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Field Label (Member Form &amp; Card Label) <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="editFieldLabel" required placeholder="e.g. Blood Group, Roll No">
          </div>
          <div class="mb-3">
            <label class="form-label">Member Placeholder</label>
            <input type="text" class="form-control" id="editFieldPlaceholder" placeholder="e.g. Enter blood group">
          </div>
          <div class="mb-3">
            <label class="form-label">Sample Value (Designer Preview)</label>
            <input type="text" class="form-control" id="editFieldDefaultValue" placeholder="e.g. O+ve">
            <div class="form-text">Used to preview this field's value on the canvas.</div>
          </div>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning text-white"><i class="fas fa-save me-1"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<!-- ─── Template Versioning Confirmation Modal ───────────────────────────── -->
<div class="modal fade" id="versioningModal" tabindex="-1" aria-labelledby="versioningModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:1.25rem;overflow:hidden">
      <div class="modal-header border-0 pb-0" style="background:linear-gradient(135deg,#0a1a2f,#1e3a5f);color:#fff;padding:1.5rem 1.5rem 0.75rem">
        <div>
          <h5 class="modal-title mb-1" id="versioningModalLabel">
            <i class="fas fa-layer-group me-2" style="color:#f4b740"></i>Template In Use
          </h5>
          <p class="mb-0" style="font-size:0.8rem;opacity:0.8">
            "<span id="versionModalTemplateName">this template</span>" is being used by
            <strong id="versionModalMemberCount">?</strong> member(s).
          </p>
        </div>
      </div>
      <div class="modal-body" style="padding:1.5rem">
        <p class="mb-3" style="color:#374151;font-size:0.88rem">How would you like to save your changes?</p>
        <div class="row g-3">
          <!-- Option A: Create new version -->
          <div class="col-12">
            <button onclick="Designer.createNewVersion()" class="btn w-100 text-start d-flex align-items-start gap-3 p-3"
              style="border:2px solid #0e9f6e;border-radius:0.75rem;background:#f0fdf4;transition:all 0.2s">
              <div style="width:42px;height:42px;border-radius:50%;background:#0e9f6e;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-code-branch text-white"></i>
              </div>
              <div>
                <div style="font-weight:600;color:#065f46;font-size:0.9rem">Create New Version (Recommended)</div>
                <div style="font-size:0.78rem;color:#047857;margin-top:2px">
                  A new version of this template is created with your changes. Existing members stay on the current version and are not affected.
                </div>
              </div>
            </button>
          </div>
          <!-- Option B: Save in place -->
          <div class="col-12">
            <button onclick="Designer.saveInPlace()" class="btn w-100 text-start d-flex align-items-start gap-3 p-3"
              style="border:2px solid #f59e0b;border-radius:0.75rem;background:#fffbeb;transition:all 0.2s">
              <div style="width:42px;height:42px;border-radius:50%;background:#f59e0b;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-exclamation-triangle text-white"></i>
              </div>
              <div>
                <div style="font-weight:600;color:#92400e;font-size:0.9rem">Save Anyway (Overwrite)</div>
                <div style="font-size:0.78rem;color:#b45309;margin-top:2px">
                  Saves directly to this template. The card layout for all existing members using this template will change immediately.
                </div>
              </div>
            </button>
          </div>
          <!-- Cancel -->
          <div class="col-12">
            <button class="btn btn-outline-secondary w-100" data-bs-dismiss="modal" style="border-radius:0.75rem">
              <i class="fas fa-times me-1"></i> Cancel — don't save yet
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>
