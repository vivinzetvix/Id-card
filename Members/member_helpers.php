<?php
/**
 * Phase 4 — shared Member Management helpers.
 * Ethiopia: English (primary) + Amharic (secondary) for template input fields only.
 */
if (!function_exists('member_project_languages')) {
    function member_project_languages(): array
    {
        return [
            ['language_code' => 'en', 'language_name' => 'English', 'is_default' => 1],
            ['language_code' => 'am', 'language_name' => 'Amharic', 'is_default' => 0],
        ];
    }
}

if (!function_exists('member_normalize_bilingual_mode')) {
    function member_normalize_bilingual_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['bilingual', 'dual'], true) ? 'bilingual' : 'single';
    }
}

if (!function_exists('member_duplicate_settings')) {
    function member_duplicate_settings(PDO $pdo): array
    {
        $settings = load_system_settings($pdo);
        return [
            'unique_id' => (bool)(int)get_system_setting($settings, 'member_duplicate_check_unique_id', '1'),
            'email' => (bool)(int)get_system_setting($settings, 'member_duplicate_check_email', '0'),
            'phone' => (bool)(int)get_system_setting($settings, 'member_duplicate_check_phone', '0'),
        ];
    }
}

if (!function_exists('member_find_duplicates')) {
    /**
     * @return array<int, array{field:string,label:string,members:array}>
     */
    function member_find_duplicates(PDO $pdo, array $data, ?int $excludeMemberId = null): array
    {
        $settings = member_duplicate_settings($pdo);
        $orgId = (int)($data['organization_id'] ?? 0) ?: null;
        $warnings = [];

        $checks = [];
        if ($settings['unique_id'] && !empty($data['unique_id'])) {
            $checks['unique_id'] = ['column' => 'unique_id', 'label' => 'Employee / Unique ID', 'value' => trim((string)$data['unique_id'])];
        }
        if ($settings['email'] && !empty($data['email'])) {
            $checks['email'] = ['column' => 'email', 'label' => 'Email', 'value' => trim((string)$data['email'])];
        }
        if ($settings['phone'] && !empty($data['emergency_contact'])) {
            $checks['phone'] = ['column' => 'emergency_contact', 'label' => 'Mobile', 'value' => trim((string)$data['emergency_contact'])];
        }

        foreach ($checks as $key => $check) {
            $sql = 'SELECT id, name, unique_id FROM id_members WHERE deleted_at IS NULL AND ' . $check['column'] . ' = ?';
            $params = [$check['value']];
            if ($orgId) {
                $sql .= ' AND organization_id = ?';
                $params[] = $orgId;
            }
            if ($excludeMemberId) {
                $sql .= ' AND id != ?';
                $params[] = $excludeMemberId;
            }
            $sql .= ' LIMIT 5';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $warnings[] = ['field' => $key, 'label' => $check['label'], 'members' => $rows];
            }
        }

        return $warnings;
    }
}

if (!function_exists('member_validate_photo_upload')) {
    function member_validate_photo_upload(array $file, int $maxBytes = 5242880): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload failed.'];
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            return ['success' => false, 'error' => 'File must be 5MB or smaller.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => 'Invalid upload.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'error' => 'Image must be JPG, PNG, WEBP, or GIF.'];
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return ['success' => false, 'error' => 'Invalid file extension.'];
        }
        return ['success' => true, 'ext' => $allowed[$mime]];
    }
}

if (!function_exists('member_store_uploaded_image')) {
    function member_store_uploaded_image(array $file, string $subdir = ''): array
    {
        $valid = member_validate_photo_upload($file);
        if (!$valid['success']) {
            return $valid;
        }
        $base = dirname(__DIR__) . '/images/uploads/';
        $dir = $subdir !== '' ? $base . trim($subdir, '/') . '/' : $base;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => 'Cannot create upload directory.'];
        }
        $name = 'member_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $valid['ext'];
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
            return ['success' => false, 'error' => 'Failed to save file.'];
        }
        return ['success' => true, 'filename' => $name];
    }
}

if (!function_exists('member_load_input_fields')) {
    /**
     * Active fields for new ops; on edit include archived fields that have stored values.
     * @return array<string, array>
     */
    function member_load_input_fields(PDO $pdo, int $templateId, ?int $memberId = null): array
    {
        if ($templateId <= 0) {
            return [];
        }
        $fields = get_template_input_fields($pdo, $templateId, false, false);
        if (!$memberId) {
            return $fields;
        }
        $stmt = $pdo->prepare(
            'SELECT tif.* FROM template_input_fields tif
             WHERE tif.template_id = ?
               AND tif.archived_at IS NOT NULL
               AND EXISTS (
                 SELECT 1 FROM member_dynamic_values mdv
                 WHERE mdv.member_id = ? AND mdv.field_key = tif.field_key
               )
             ORDER BY tif.sort_order'
        );
        $stmt->execute([$templateId, $memberId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['field_key'] ?? '');
            if ($key !== '' && !isset($fields[$key])) {
                $fields[$key] = [
                    'id' => (int)$row['id'],
                    'template_id' => (int)$row['template_id'],
                    'field_key' => $key,
                    'field_label' => (string)$row['field_label'],
                    'field_type' => (string)$row['field_type'],
                    'bilingual_mode' => member_normalize_bilingual_mode((string)($row['bilingual_mode'] ?? 'single')),
                    'is_required' => (bool)$row['is_required'],
                    'is_enabled' => false,
                    'placeholder' => (string)($row['placeholder'] ?? ''),
                    'default_value' => (string)($row['default_value'] ?? ''),
                    'validation_rules' => (string)($row['validation_rules'] ?? ''),
                    'sort_order' => (int)$row['sort_order'],
                    'archived_at' => $row['archived_at'],
                    '_archived' => true,
                ];
            }
        }
        uasort($fields, static fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        return $fields;
    }
}

if (!function_exists('member_validate_dynamic_fields')) {
    function member_validate_dynamic_fields(PDO $pdo, int $templateId, array $posted, array $fieldDefs): array
    {
        $errors = [];
        foreach ($fieldDefs as $fieldKey => $field) {
            if (!empty($field['_archived'])) {
                continue;
            }
            $raw = $posted[$fieldKey] ?? '';
            $fieldForValidation = $field;
            $fieldForValidation['bilingual_mode'] = member_normalize_bilingual_mode((string)($field['bilingual_mode'] ?? 'single'));
            if ($fieldForValidation['bilingual_mode'] === 'bilingual' && is_array($raw)) {
                $err = validate_dynamic_field_value($fieldForValidation, $raw['translations'] ?? $raw);
            } else {
                $value = is_array($raw) ? ($raw['value'] ?? '') : $raw;
                $err = validate_dynamic_field_value($fieldForValidation, $value);
            }
            if ($err) {
                $errors[] = $err;
            }
        }
        return $errors;
    }
}

if (!function_exists('member_prepare_dynamic_save_payload')) {
    function member_prepare_dynamic_save_payload(array $posted, array $fieldDefs): array
    {
        $payload = [];
        foreach ($fieldDefs as $fieldKey => $field) {
            if (!isset($posted[$fieldKey])) {
                continue;
            }
            $raw = $posted[$fieldKey];
            $mode = member_normalize_bilingual_mode((string)($field['bilingual_mode'] ?? 'single'));
            if ($mode === 'bilingual' && is_array($raw)) {
                $translations = [];
                foreach (member_project_languages() as $lang) {
                    $code = (string)$lang['language_code'];
                    $translations[$code] = trim((string)($raw['translations'][$code] ?? ''));
                }
                $payload[$fieldKey] = [
                    'value' => $translations['en'] ?? '',
                    'translations' => $translations,
                ];
            } else {
                $payload[$fieldKey] = is_array($raw) ? ($raw['value'] ?? '') : $raw;
            }
        }
        return $payload;
    }
}

if (!function_exists('member_purge_orphan_dynamic_values')) {
    function member_purge_orphan_dynamic_values(PDO $pdo, int $memberId, int $templateId, array $allowedKeys): void
    {
        if ($memberId <= 0 || $templateId <= 0) {
            return;
        }
        $stmt = $pdo->prepare('SELECT field_key FROM member_dynamic_values WHERE member_id = ? AND template_id = ?');
        $stmt->execute([$memberId, $templateId]);
        $deleteValue = $pdo->prepare('DELETE FROM member_dynamic_values WHERE member_id = ? AND field_key = ?');
        $deleteTrans = $pdo->prepare(
            'DELETE mft FROM member_field_translations mft
             INNER JOIN template_input_fields tif ON tif.id = mft.template_field_id
             WHERE mft.member_id = ? AND tif.template_id = ? AND tif.field_key = ?'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $deleteTrans->execute([$memberId, $templateId, $key]);
                $deleteValue->execute([$memberId, $key]);
            }
        }
    }
}

if (!function_exists('member_log_audit')) {
    function member_log_audit(PDO $pdo, ?int $userId, ?int $organizationId, string $action, string $details): void
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
                'members',
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
                    $userId ?: null, $action, 'members', $details,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);
            } catch (Throwable $e2) {
                // non-blocking
            }
        }
    }
}

if (!function_exists('member_render_dynamic_field_input')) {
    /**
     * Render one dynamic field input block (returns HTML string).
     */
    function member_render_dynamic_field_input(array $field, $value, string $namePrefix = 'dynamic_fields'): string
    {
        $key = htmlspecialchars((string)$field['field_key'], ENT_QUOTES);
        $label = htmlspecialchars((string)$field['field_label']);
        $type = strtolower((string)($field['field_type'] ?? 'text'));
        $required = !empty($field['is_required']) && empty($field['_archived']);
        $placeholder = htmlspecialchars((string)($field['placeholder'] ?? ''));
        $reqAttr = $required ? ' required' : '';
        $archivedNote = !empty($field['_archived']) ? ' <span class="badge bg-secondary">Archived field</span>' : '';
        $baseName = $namePrefix . '[' . $key . ']';
        $mode = member_normalize_bilingual_mode((string)($field['bilingual_mode'] ?? 'single'));

        $html = '<div class="col-md-6 mb-3 dynamic-field" data-field-key="' . $key . '">';
        $html .= '<label class="form-label">' . $label . $archivedNote;
        if ($required) {
            $html .= ' <span class="text-danger">*</span>';
        }
        $html .= '</label>';

        if ($mode === 'bilingual') {
            $translations = is_array($value) ? ($value['translations'] ?? $value) : [];
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            foreach (member_project_languages() as $lang) {
                $code = (string)$lang['language_code'];
                $lname = htmlspecialchars((string)$lang['language_name']);
                $v = $translations[$code] ?? ($code === 'en' ? $scalar : '');
                $fname = $baseName . '[translations][' . htmlspecialchars($code, ENT_QUOTES) . ']';
                if ($type === 'textarea' || $type === 'address') {
                    $html .= '<div class="mb-2"><small class="text-muted">' . $lname . '</small>';
                    $html .= '<textarea name="' . $fname . '" class="form-control" rows="2"' . $reqAttr . ' placeholder="' . $placeholder . '">' . htmlspecialchars((string)$v) . '</textarea></div>';
                } else {
                    $html .= '<div class="mb-2"><small class="text-muted">' . $lname . '</small>';
                    $html .= '<input type="text" name="' . $fname . '" class="form-control" value="' . htmlspecialchars((string)$v) . '"' . $reqAttr . ' placeholder="' . $placeholder . '"></div>';
                }
            }
        } elseif ($type === 'textarea' || $type === 'address') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<textarea name="' . $baseName . '" class="form-control" rows="2"' . $reqAttr . ' placeholder="' . $placeholder . '">' . htmlspecialchars($scalar) . '</textarea>';
        } elseif ($type === 'number') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="number" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . '>';
        } elseif ($type === 'date') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="date" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . '>';
        } elseif ($type === 'email') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="email" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . ' placeholder="' . $placeholder . '">';
        } elseif ($type === 'phone') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="tel" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . ' placeholder="' . $placeholder . '">';
        } elseif ($type === 'select') {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<select name="' . $baseName . '" class="form-select"' . $reqAttr . '><option value="">Select...</option>';
            $rules = parse_dynamic_field_validation_rules((string)($field['validation_rules'] ?? ''));
            $opts = $rules['options'] ?? explode(',', (string)($field['validation_rules'] ?? ''));
            if (!is_array($opts)) {
                $opts = explode(',', (string)$opts);
            }
            foreach ($opts as $opt) {
                $opt = trim((string)$opt);
                if ($opt === '') continue;
                $sel = $scalar === $opt ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($opt, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
            }
            $html .= '</select>';
        } elseif (in_array($type, ['photo', 'signature', 'logo'], true)) {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $src = '';
            if ($scalar !== '') {
                $cleanValue = ltrim((string)$scalar, '/');
                if (strpos($cleanValue, 'images/uploads/') === 0 || strpos($cleanValue, 'uploads/') === 0) {
                    $src = '../' . $cleanValue;
                } else {
                    $src = '../images/uploads/' . $cleanValue;
                }
            }
            $previewId = 'dynamicPreview_' . $key;
            if ($src !== '') {
                $html .= '<div class="mb-2"><img id="' . htmlspecialchars($previewId, ENT_QUOTES) . '" src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" style="max-height:80px;border-radius:6px;"></div>';
            } else {
                $html .= '<div class="mb-2" id="' . htmlspecialchars($previewId, ENT_QUOTES) . '" style="display:none;"><img src="" alt="" style="max-height:80px;border-radius:6px;"></div>';
            }
            $html .= '<input type="file" name="dynamic_file[' . $key . ']" class="form-control dynamic-file-input" data-preview-id="' . htmlspecialchars($previewId, ENT_QUOTES) . '" accept="image/jpeg,image/png,image/webp,image/gif">';
            $html .= '<input type="hidden" name="' . $baseName . '" value="' . htmlspecialchars($scalar, ENT_QUOTES) . '">';
        } elseif (in_array($type, ['qr', 'barcode', 'id_number'], true)) {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="text" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . ' placeholder="' . $placeholder . '">';
            if (in_array($type, ['qr', 'barcode'], true)) {
                $html .= '<div class="form-text">Value used when generating ' . htmlspecialchars($type) . ' on the card.</div>';
            }
        } else {
            $scalar = is_array($value) ? (string)($value['value'] ?? '') : (string)$value;
            $html .= '<input type="text" name="' . $baseName . '" class="form-control" value="' . htmlspecialchars($scalar) . '"' . $reqAttr . ' placeholder="' . $placeholder . '">';
        }

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('member_handle_dynamic_file_uploads')) {
    function member_handle_dynamic_file_uploads(array &$payload, array $fieldDefs, array $filesPosted): void
    {
        foreach ($fieldDefs as $fieldKey => $field) {
            $type = strtolower((string)($field['field_type'] ?? ''));
            if (!in_array($type, ['photo', 'signature', 'logo'], true)) {
                continue;
            }
            if (!empty($filesPosted['name'][$fieldKey])
                && ($filesPosted['error'][$fieldKey] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $filesPosted['name'][$fieldKey],
                    'type' => $filesPosted['type'][$fieldKey] ?? '',
                    'tmp_name' => $filesPosted['tmp_name'][$fieldKey],
                    'error' => $filesPosted['error'][$fieldKey],
                    'size' => $filesPosted['size'][$fieldKey] ?? 0,
                ];
                $subdir = $type === 'signature' ? 'signatures' : '';
                $stored = member_store_uploaded_image($file, $subdir);
                if ($stored['success']) {
                    $payload[$fieldKey] = $stored['filename'];
                }
            } elseif (!isset($payload[$fieldKey]) || $payload[$fieldKey] === '') {
                $hidden = $_POST['dynamic_fields'][$fieldKey] ?? '';
                if ($hidden !== '') {
                    $payload[$fieldKey] = $hidden;
                }
            }
        }
    }
}

if (!function_exists('member_type_visible_fields')) {
    function member_type_visible_fields(): array
    {
        return [];
    }
}

if (!function_exists('member_type_conditional_fields')) {
    function member_type_conditional_fields(): array
    {
        return [];
    }
}

if (!function_exists('member_sanitize_type_fields')) {
    function member_sanitize_type_fields(array $data): array
    {
        return $data;
    }
}

if (!function_exists('member_type_fields_script')) {
    function member_type_fields_script(): string
    {
        return '';
    }
}

// ─── Template Switching Helpers ───────────────────────────────────────────────

if (!function_exists('member_get_all_values')) {
    /**
     * Returns all data values for a member as a flat key => value map,
     * combining fixed columns from id_members and all dynamic values.
     */
    function member_get_all_values(PDO $pdo, int $memberId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM id_members WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];

        // Start with fixed columns
        $values = array_filter($row, fn($v) => $v !== null && $v !== '');

        // Merge dynamic values (any template_id)
        $dStmt = $pdo->prepare(
            'SELECT field_key, field_value FROM member_dynamic_values WHERE member_id = ?'
        );
        $dStmt->execute([$memberId]);
        foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $dv) {
            $key = (string)$dv['field_key'];
            if (!isset($values[$key]) || $values[$key] === '') {
                $values[$key] = (string)($dv['field_value'] ?? '');
            }
        }
        return $values;
    }
}

if (!function_exists('member_check_template_compatibility')) {
    /**
     * Compares the fields required by $newTemplateId against values the member already has.
     *
     * Returns:
     *   'reusable'          => [field_key => field_def, ...]  — field exists and member has a value
     *   'missing_required'  => [field_key => field_def, ...]  — required field with no value
     *   'missing_optional'  => [field_key => field_def, ...]  — optional field with no value
     */
    function member_check_template_compatibility(PDO $pdo, int $memberId, int $newTemplateId): array
    {
        if (!function_exists('get_template_input_fields')) {
            require_once __DIR__ . '/../config.php';
        }

        $newFields = get_template_input_fields($pdo, $newTemplateId);
        $existingValues = member_get_all_values($pdo, $memberId);

        $reusable         = [];
        $missingRequired  = [];
        $missingOptional  = [];

        foreach ($newFields as $field) {
            $key = (string)($field['field_key'] ?? '');
            if ($key === '') continue;

            // A template can contain fields that this member has never had.
            // Read the value safely so missing keys are treated as missing data,
            // rather than raising an "Undefined array key" warning.
            $value = $existingValues[$key] ?? null;
            $hasValue = $value !== null && $value !== '';

            if ($hasValue) {
                $reusable[$key] = $field;
            } elseif ((int)($field['is_required'] ?? 0)) {
                $missingRequired[$key] = $field;
            } else {
                $missingOptional[$key] = $field;
            }
        }

        return [
            'reusable'         => $reusable,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
        ];
    }
}

if (!function_exists('member_switch_template')) {
    /**
     * Switches a member's active template to $newTemplateId.
     * Saves any provided $extraValues to member_dynamic_values.
     * Does NOT delete existing dynamic values.
     *
     * @param array $extraValues  ['field_key' => 'value', ...]
     * @return bool
     */
    function member_switch_template(PDO $pdo, int $memberId, int $newTemplateId, array $extraValues = []): bool
    {
        try {
            $pdo->beginTransaction();

            // Update template assignment
            $pdo->prepare('UPDATE id_members SET template_id = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$newTemplateId, $memberId]);

            // Save any newly-provided field values
            if (!empty($extraValues)) {
                $ins = $pdo->prepare(
                    'INSERT INTO member_dynamic_values (member_id, template_id, field_key, field_value)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = NOW()'
                );
                foreach ($extraValues as $key => $value) {
                    $ins->execute([$memberId, $newTemplateId, $key, (string)$value]);
                }
            }

            // Mark template as first-used if needed
            if (function_exists('template_mark_first_used')) {
                template_mark_first_used($pdo, $newTemplateId);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }
}

