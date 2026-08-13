<?php
/**
 * Phase 3 Module 3 — shared Template Management helpers.
 * Additive only; no new tables.
 */
if (!function_exists('template_allowed_field_types')) {
    function template_allowed_field_types(): array
    {
        return [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'number' => 'Number',
            'date' => 'Date',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'id_number' => 'ID Number',
            'select' => 'Select',
          
            'signature' => 'Signature',
            'qr' => 'QR Code',
            'barcode' => 'Barcode',
            'static_text' => 'Static Text',
            'logo' => 'Logo',
        ];
    }
}

if (!function_exists('template_normalize_field_type')) {
    function template_normalize_field_type(string $type): string
    {
        $type = strtolower(trim($type));
        return array_key_exists($type, template_allowed_field_types()) ? $type : 'text';
    }
}

if (!function_exists('template_fetch_organization')) {
    function template_fetch_organization(PDO $pdo, ?int $organizationId): ?array
    {
        if (!$organizationId || $organizationId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, organization_name, project_type, status, deleted_at
             FROM organizations WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$organizationId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        return $org ?: null;
    }
}

if (!function_exists('template_validate_orientation_for_org')) {
    /**
     * Residence → landscape only. Returns error string or null.
     */
    function template_validate_orientation_for_org(?array $org, string $orientation): ?string
    {
        $orientation = strtolower($orientation);
        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            return 'Invalid orientation.';
        }
        $projectType = strtolower((string)($org['project_type'] ?? 'corporate'));
        if ($projectType === 'residence' && $orientation !== 'landscape') {
            return 'Residence organizations only allow landscape templates.';
        }
        return null;
    }
}

if (!function_exists('template_name_exists_in_org')) {
    function template_name_exists_in_org(PDO $pdo, string $name, ?int $organizationId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM card_templates
                WHERE LOWER(name) = LOWER(?)
                  AND status = 1
                  AND deleted_at IS NULL';
        $params = [$name];
        if ($organizationId === null || $organizationId <= 0) {
            $sql .= ' AND (organization_id IS NULL OR organization_id = 0)';
        } else {
            $sql .= ' AND organization_id = ?';
            $params[] = $organizationId;
        }
        if ($excludeId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('template_clear_default_for_org')) {
    /**
     * One default per organization (org has one project_type → one default per project type).
     */
    function template_clear_default_for_org(PDO $pdo, ?int $organizationId, ?int $exceptId = null): void
    {
        if ($organizationId === null || $organizationId <= 0) {
            $sql = 'UPDATE card_templates SET is_default = 0
                    WHERE is_default = 1 AND (organization_id IS NULL OR organization_id = 0)';
            $params = [];
            if ($exceptId) {
                $sql .= ' AND id != ?';
                $params[] = $exceptId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return;
        }
        $sql = 'UPDATE card_templates SET is_default = 0 WHERE is_default = 1 AND organization_id = ?';
        $params = [$organizationId];
        if ($exceptId) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

if (!function_exists('template_is_active')) {
    function template_is_active(array $template): bool
    {
        return (int)($template['status'] ?? 0) === 1 && empty($template['deleted_at']);
    }
}

if (!function_exists('template_can_use_for_new_operations')) {
    /** Active templates only for register / generate / new selection. */
    function template_can_use_for_new_operations(array $template): bool
    {
        return template_is_active($template);
    }
}

if (!function_exists('template_store_background')) {
    function template_store_background(array $file, int $templateId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Background upload failed.'];
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Background must be 5MB or smaller.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => 'Invalid upload.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'error' => 'Background must be JPG, PNG, WEBP, or GIF.'];
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return ['success' => false, 'error' => 'Invalid background file extension.'];
        }
        $dir = dirname(__DIR__) . '/images/templates';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Cannot create upload directory.'];
        }
        $name = 'bg_' . $templateId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['success' => false, 'error' => 'Failed to save background.'];
        }
        return ['success' => true, 'path' => 'images/templates/' . $name];
    }
}

if (!function_exists('template_log_audit')) {
    function template_log_audit(PDO $pdo, ?int $userId, ?int $organizationId, string $action, string $details): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (user_id, organization_id, action, action_type, details, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId ?: null,
                $organizationId ?: null,
                $action,
                'templates',
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId ?: null, $action, 'templates', $details,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);
            } catch (Throwable $e2) {
                // non-blocking
            }
        }
    }
}

if (!function_exists('template_user_can_manage')) {
    function template_user_can_manage(PDO $pdo, array $user, array $template): bool
    {
        if (function_exists('auth_is_super_admin') && auth_is_super_admin($user)) {
            return true;
        }
        return function_exists('user_can_access_organization')
            && user_can_access_organization($user, $template['organization_id'] ?? null);
    }
}

if (!function_exists('template_fetch_by_id')) {
    function template_fetch_by_id(PDO $pdo, int $templateId, bool $includeArchived = false): ?array
    {
        $sql = 'SELECT t.*, o.organization_name, o.project_type
                FROM card_templates t
                LEFT JOIN organizations o ON o.id = t.organization_id
                WHERE t.id = ?';
        if (!$includeArchived) {
            $sql .= ' AND t.status = 1 AND t.deleted_at IS NULL';
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('template_resolve_organization_id')) {
    /** Non–super-admin always bound to session org. */
    function template_resolve_organization_id(array $user, ?int $postedOrgId): ?int
    {
        if (function_exists('auth_is_super_admin') && auth_is_super_admin($user)) {
            return ($postedOrgId !== null && $postedOrgId > 0) ? $postedOrgId : null;
        }
        $orgId = (int)($user['organization_id'] ?? 0);
        return $orgId > 0 ? $orgId : null;
    }
}

if (!function_exists('template_duplicate')) {
    function template_duplicate(PDO $pdo, int $sourceId, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM card_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$sourceId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$original) {
            return ['success' => false, 'error' => 'Template not found.'];
        }

        $orgId = (int)($original['organization_id'] ?? 0) ?: null;
        $baseName = $original['name'] . ' (Copy)';
        $name = $baseName;
        $n = 1;
        while (template_name_exists_in_org($pdo, $name, $orgId)) {
            $n++;
            $name = $baseName . ' ' . $n;
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO card_templates (
                    organization_id, name, description, orientation, primary_color, secondary_color, text_color,
                    font, card_width, card_height, background_image, mirror_print, status, is_default,
                    layout_version, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, NOW())'
            );
            $insert->execute([
                $orgId,
                $name,
                $original['description'] ?? '',
                $original['orientation'] ?? 'portrait',
                $original['primary_color'] ?? '#0a1a2f',
                $original['secondary_color'] ?? '#1e3a5f',
                $original['text_color'] ?? '#ffffff',
                $original['font'] ?? 'Inter',
                $original['card_width'],
                $original['card_height'],
                $original['background_image'],
                (int)($original['mirror_print'] ?? 0),
                (int)($original['layout_version'] ?? 1),
                $userId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $fieldStmt = $pdo->prepare(
                'SELECT * FROM template_fields WHERE template_id = ? AND archived_at IS NULL'
            );
            $fieldStmt->execute([$sourceId]);
            $insField = $pdo->prepare(
                'INSERT INTO template_fields (
                    template_id, field_key, object_type, side, x, y, width, height, visible,
                    font_size, font_family, font_weight, font_style, color, text_align, text_decoration,
                    opacity, border_width, border_color, border_style, border_radius, show_label, content, image_path
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($fieldStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $insField->execute([
                    $newId, $f['field_key'], $f['object_type'] ?? 'dynamic', $f['side'], $f['x'], $f['y'],
                    $f['width'], $f['height'], $f['visible'], $f['font_size'], $f['font_family'],
                    $f['font_weight'] ?? null, $f['font_style'] ?? null, $f['color'], $f['text_align'] ?? 'left',
                    $f['text_decoration'] ?? null, $f['opacity'] ?? 1, $f['border_width'], $f['border_color'],
                    $f['border_style'], $f['border_radius'], $f['show_label'] ?? 1, $f['content'], $f['image_path'],
                ]);
            }

            $inStmt = $pdo->prepare(
                'SELECT * FROM template_input_fields WHERE template_id = ? AND archived_at IS NULL'
            );
            $inStmt->execute([$sourceId]);
            $insIn = $pdo->prepare(
                'INSERT INTO template_input_fields (
                    template_id, field_key, field_label, field_type, bilingual_mode, is_required, is_enabled,
                    placeholder, default_value, validation_rules, sort_order
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($inStmt->fetchAll(PDO::FETCH_ASSOC) as $inf) {
                $insIn->execute([
                    $newId, $inf['field_key'], $inf['field_label'], $inf['field_type'],
                    $inf['bilingual_mode'] ?? 'single', $inf['is_required'], $inf['is_enabled'] ?? 1,
                    $inf['placeholder'], $inf['default_value'] ?? null, $inf['validation_rules'], $inf['sort_order'],
                ]);
            }

            $pdo->commit();
            return ['success' => true, 'new_id' => $newId, 'name' => $name];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('template_set_default')) {
    function template_set_default(PDO $pdo, int $templateId): array
    {
        $tpl = template_fetch_by_id($pdo, $templateId);
        if (!$tpl) {
            return ['success' => false, 'error' => 'Template not found or archived.'];
        }
        $orgId = (int)($tpl['organization_id'] ?? 0) ?: null;
        template_clear_default_for_org($pdo, $orgId, $templateId);
        $stmt = $pdo->prepare('UPDATE card_templates SET is_default = 1 WHERE id = ?');
        $stmt->execute([$templateId]);
        return ['success' => true];
    }
}

if (!function_exists('template_archive')) {
    function template_archive(PDO $pdo, int $templateId): array
    {
        $tpl = template_fetch_by_id($pdo, $templateId);
        if (!$tpl) {
            return ['success' => false, 'error' => 'Template not found.'];
        }
        $stmt = $pdo->prepare(
            'UPDATE card_templates SET status = 0, deleted_at = NOW(), is_default = 0 WHERE id = ?'
        );
        $stmt->execute([$templateId]);
        return ['success' => true];
    }
}

if (!function_exists('template_restore')) {
    function template_restore(PDO $pdo, int $templateId): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.*, o.project_type FROM card_templates t
             LEFT JOIN organizations o ON o.id = t.organization_id
             WHERE t.id = ? LIMIT 1'
        );
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            return ['success' => false, 'error' => 'Template not found.'];
        }
        $org = template_fetch_organization($pdo, (int)($tpl['organization_id'] ?? 0) ?: null);
        $orientErr = template_validate_orientation_for_org($org, (string)($tpl['orientation'] ?? 'portrait'));
        if ($orientErr) {
            return ['success' => false, 'error' => $orientErr];
        }
        $pdo->prepare('UPDATE card_templates SET status = 1, deleted_at = NULL WHERE id = ?')->execute([$templateId]);
        return ['success' => true];
    }
}

// ─── Template Versioning ──────────────────────────────────────────────────────

if (!function_exists('template_is_in_use')) {
    /**
     * Returns number of active (non-deleted) members assigned to this template.
     */
    function template_is_in_use(PDO $pdo, int $templateId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM id_members WHERE template_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$templateId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('template_get_root_id')) {
    /**
     * Given any template ID (original or versioned), return the root ID (parent or self).
     */
    function template_get_root_id(PDO $pdo, int $templateId): int
    {
        $stmt = $pdo->prepare('SELECT parent_template_id FROM card_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $templateId;
        return (!empty($row['parent_template_id'])) ? (int)$row['parent_template_id'] : $templateId;
    }
}

if (!function_exists('template_get_version_chain')) {
    /**
     * Returns all versions in the same family as $templateId, ordered by version ASC.
     * Each row includes: id, name, version, status, deleted_at, first_used_at, member_count.
     */
    function template_get_version_chain(PDO $pdo, int $templateId): array
    {
        $rootId = template_get_root_id($pdo, $templateId);
        $stmt = $pdo->prepare(
            'SELECT ct.id, ct.name, ct.version, ct.status, ct.deleted_at, ct.first_used_at,
                    ct.parent_template_id, ct.created_at,
                    (SELECT COUNT(*) FROM id_members m WHERE m.template_id = ct.id AND m.deleted_at IS NULL) AS member_count
             FROM card_templates ct
             WHERE (ct.id = ? OR ct.parent_template_id = ?) AND ct.deleted_at IS NULL
             ORDER BY ct.version ASC'
        );
        $stmt->execute([$rootId, $rootId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('template_create_new_version')) {
    /**
     * Deep-clones a template (design + fields + input fields) as a new version.
     * Returns ['success' => true, 'new_id' => int] or ['success' => false, 'error' => string].
     */
    function template_create_new_version(PDO $pdo, int $fromTemplateId, int $userId): array
    {
        // Load source template
        $stmt = $pdo->prepare('SELECT * FROM card_templates WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$fromTemplateId]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$src) {
            return ['success' => false, 'error' => 'Source template not found.'];
        }

        $rootId = (!empty($src['parent_template_id'])) ? (int)$src['parent_template_id'] : $fromTemplateId;

        // Get max version in this family
        $maxStmt = $pdo->prepare(
            'SELECT MAX(version) FROM card_templates WHERE (id = ? OR parent_template_id = ?) AND deleted_at IS NULL'
        );
        $maxStmt->execute([$rootId, $rootId]);
        $newVersion = (int)$maxStmt->fetchColumn() + 1;

        try {
            $pdo->beginTransaction();

            // Insert new template row
            $ins = $pdo->prepare(
                'INSERT INTO card_templates
                   (organization_id, parent_template_id, version, name, description, primary_color,
                    secondary_color, text_color, orientation, card_width, card_height,
                    background_image, front_image, back_image, mirror_print, layout_version,
                    status, font, is_default, bg_pos_x, bg_pos_y, bg_size, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,0,?,?,?,?)'
            );
            $newName = preg_replace('/ v\d+$/', '', (string)$src['name']) . ' v' . $newVersion;
            $ins->execute([
                $src['organization_id'],
                $rootId,
                $newVersion,
                $newName,
                $src['description'],
                $src['primary_color'],
                $src['secondary_color'],
                $src['text_color'],
                $src['orientation'],
                $src['card_width'],
                $src['card_height'],
                $src['background_image'],
                $src['front_image'],
                $src['back_image'],
                $src['mirror_print'],
                $src['layout_version'],
                $src['font'],
                $src['bg_pos_x'],
                $src['bg_pos_y'],
                $src['bg_size'],
                $userId,
            ]);
            $newTemplateId = (int)$pdo->lastInsertId();

            // Copy template_fields (layout objects)
            $fieldRows = $pdo->prepare(
                'SELECT * FROM template_fields WHERE template_id = ? AND archived_at IS NULL'
            );
            $fieldRows->execute([$fromTemplateId]);
            $insField = $pdo->prepare(
                'INSERT INTO template_fields
                   (template_id, field_key, object_type, side, x, y, width, height, visible,
                    font_size, font_family, font_weight, font_style, color, text_align,
                    text_decoration, opacity, border_width, border_color, border_style,
                    border_radius, show_label, content, image_path, z_index)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($fieldRows->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $insField->execute([
                    $newTemplateId, $f['field_key'], $f['object_type'], $f['side'],
                    $f['x'], $f['y'], $f['width'], $f['height'], $f['visible'],
                    $f['font_size'], $f['font_family'], $f['font_weight'], $f['font_style'],
                    $f['color'], $f['text_align'], $f['text_decoration'], $f['opacity'],
                    $f['border_width'], $f['border_color'], $f['border_style'], $f['border_radius'],
                    $f['show_label'], $f['content'], $f['image_path'], $f['z_index'],
                ]);
            }

            // Copy template_input_fields (custom field definitions)
            $inputRows = $pdo->prepare(
                'SELECT * FROM template_input_fields WHERE template_id = ? AND archived_at IS NULL AND is_enabled = 1'
            );
            $inputRows->execute([$fromTemplateId]);
            $insInput = $pdo->prepare(
                'INSERT INTO template_input_fields
                   (template_id, field_key, field_label, field_type, bilingual_mode,
                    is_required, is_enabled, placeholder, default_value, validation_rules, sort_order)
                 VALUES (?,?,?,?,?,?,1,?,?,?,?)'
            );
            foreach ($inputRows->fetchAll(PDO::FETCH_ASSOC) as $inf) {
                $insInput->execute([
                    $newTemplateId,
                    $inf['field_key'],
                    $inf['field_label'],
                    $inf['field_type'],
                    $inf['bilingual_mode'],
                    $inf['is_required'],
                    $inf['placeholder'],
                    $inf['default_value'],
                    $inf['validation_rules'],
                    $inf['sort_order'],
                ]);
            }

            $pdo->commit();
            return ['success' => true, 'new_id' => $newTemplateId, 'version' => $newVersion];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('template_mark_first_used')) {
    /**
     * Sets first_used_at on a template if not yet set. Call when a member is first created with this template.
     */
    function template_mark_first_used(PDO $pdo, int $templateId): void
    {
        $pdo->prepare(
            'UPDATE card_templates SET first_used_at = NOW() WHERE id = ? AND first_used_at IS NULL'
        )->execute([$templateId]);
    }
}
if (!function_exists('template_get_active_field_keys')) {
    function template_get_active_field_keys(PDO $pdo, int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        $systemFields = [
            'organization_name',
            'organization_id',
            'member_type',
            'template_id',
            'logo',
            'qr',
            'barcode',
            'terms',
            'language',
        ];

        $stmt = $pdo->prepare(
            'SELECT DISTINCT field_key, object_type
             FROM template_fields
             WHERE template_id = ?
               AND archived_at IS NULL
               AND visible = 1'
        );

        $stmt->execute([$templateId]);

        $keys = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

            $fieldKey = trim((string)($row['field_key'] ?? ''));
            $objectType = strtolower(trim((string)($row['object_type'] ?? '')));

            // Photo object
            if (
                $fieldKey === '' &&
                in_array($objectType, ['photo', 'member_photo', 'image'], true)
            ) {
                $fieldKey = 'photo';
            }

            // Normalize member_photo
            if ($fieldKey === 'member_photo') {
                $fieldKey = 'photo';
            }

            if ($fieldKey === '') {
                continue;
            }

            if (in_array($fieldKey, $systemFields, true)) {
                continue;
            }

            $keys[$fieldKey] = true;
        }

        return array_keys($keys);
    }
}
