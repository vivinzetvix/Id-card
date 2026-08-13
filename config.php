
<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "id";

// Create mysqli connection (legacy usage)
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Create PDO connection (preferred)
try {
    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("PDO connection failed: " . $e->getMessage());
}

if (!function_exists('load_system_settings')) {
    function load_system_settings(PDO $pdo): array
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type ENUM('text', 'number', 'boolean', 'json', 'color') DEFAULT 'text',
            description TEXT,
            updated_by VARCHAR(50),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }
}

if (!function_exists('get_system_setting')) {
    function get_system_setting(array $settings, string $key, string $default = ''): string
    {
        $value = $settings[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }
}

if (!function_exists('ensure_template_fields_table')) {
    function ensure_template_fields_table(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS template_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            x INT DEFAULT 0,
            y INT DEFAULT 0,
            width INT DEFAULT 140,
            height INT DEFAULT 40,
            visible TINYINT(1) DEFAULT 1,
            font_size INT DEFAULT 12,
            z_index INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_template_field (template_id, field_key)
        )");
    }
}

if (!function_exists('ensure_dynamic_field_tables')) {
    function ensure_dynamic_field_tables(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS template_input_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL DEFAULT 0,
            field_key VARCHAR(80) NOT NULL,
            field_label VARCHAR(120) NOT NULL,
            field_type VARCHAR(32) NOT NULL DEFAULT 'text',
            bilingual_mode VARCHAR(20) NOT NULL DEFAULT 'single',
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            placeholder VARCHAR(190) DEFAULT NULL,
            validation_rules TEXT,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_template_input_field (template_id, field_key)
        )");

        $fieldColumns = [];
        $fieldStmt = $pdo->query("SHOW COLUMNS FROM template_input_fields");
        if ($fieldStmt) {
            $fieldColumns = $fieldStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!in_array('is_enabled', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required");
        }
        if (!in_array('bilingual_mode', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN bilingual_mode VARCHAR(20) NOT NULL DEFAULT 'single' AFTER field_type");
        }
        if (!in_array('placeholder', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN placeholder VARCHAR(190) DEFAULT NULL AFTER is_enabled");
        }
        if (!in_array('validation_rules', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN validation_rules TEXT AFTER placeholder");
        }
        if (!in_array('default_value', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN default_value TEXT NULL AFTER placeholder");
        }
        if (!in_array('archived_at', $fieldColumns, true)) {
            $pdo->exec("ALTER TABLE template_input_fields ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER is_enabled");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS languages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            language_code VARCHAR(20) NOT NULL UNIQUE,
            language_name VARCHAR(100) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $languageCount = (int)$pdo->query("SELECT COUNT(*) FROM languages")->fetchColumn();
        if ($languageCount === 0) {
            $languageStmt = $pdo->prepare('INSERT INTO languages (language_code, language_name, is_default, is_active) VALUES (?, ?, ?, ?)');
            foreach ([
                ['lang1', 'Language 1', 1, 1],
                ['lang2', 'Language 2', 0, 1],
            ] as $languageRow) {
                $languageStmt->execute($languageRow);
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS member_field_translations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            template_field_id INT NOT NULL,
            language_code VARCHAR(20) NOT NULL,
            translated_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_member_field_translation (member_id, template_field_id, language_code),
            KEY idx_member_field_translation_member (member_id),
            KEY idx_member_field_translation_field (template_field_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS member_dynamic_values (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            template_id INT NOT NULL DEFAULT 0,
            field_key VARCHAR(80) NOT NULL,
            field_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_member_dynamic_value (member_id, template_id, field_key),
            KEY idx_member_dynamic_member (member_id),
            KEY idx_member_dynamic_template (template_id),
            CONSTRAINT fk_member_dynamic_member FOREIGN KEY (member_id) REFERENCES id_members(id) ON DELETE CASCADE ON UPDATE CASCADE
        )");
    }
}

ensure_dynamic_field_tables($pdo);

if (!function_exists('get_template_input_fields')) {
    function get_template_input_fields(PDO $pdo, int $templateId = 0, bool $includeDisabled = false, bool $includeArchived = false): array
    {
        ensure_dynamic_field_tables($pdo);
        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        $fields = [];
        $sql = 'SELECT id, template_id, field_key, field_label, field_type, bilingual_mode, is_required, is_enabled, placeholder, default_value, validation_rules, sort_order, archived_at
                FROM template_input_fields WHERE template_id IN (?, 0)';
        if (!$includeDisabled) {
            $sql .= ' AND is_enabled = 1';
        }
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY template_id ASC, sort_order ASC, id ASC';

        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return $fields;
        }

        $stmt->execute([$normalizedTemplateId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fieldKey = (string)($row['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }

            $fields[$fieldKey] = [
                'id' => (int)($row['id'] ?? 0),
                'template_id' => (int)($row['template_id'] ?? 0),
                'field_key' => $fieldKey,
                'field_label' => (string)($row['field_label'] ?? ucfirst(str_replace('_', ' ', $fieldKey))),
                'field_type' => (string)($row['field_type'] ?? 'text'),
                'bilingual_mode' => (string)($row['bilingual_mode'] ?? 'single'),
                'is_required' => (bool)$row['is_required'],
                'is_enabled' => isset($row['is_enabled']) ? (bool)$row['is_enabled'] : true,
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'default_value' => (string)($row['default_value'] ?? ''),
                'validation_rules' => (string)($row['validation_rules'] ?? ''),
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'archived_at' => $row['archived_at'] ?? null,
            ];
        }

        return $fields;
    }
}

if (!function_exists('save_template_input_fields')) {
    function save_template_input_fields(PDO $pdo, int $templateId, array $fields): void
    {
        ensure_dynamic_field_tables($pdo);
        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        $allowedTypes = array_keys(function_exists('template_allowed_field_types')
            ? template_allowed_field_types()
            : ['text' => 1, 'number' => 1, 'date' => 1, 'textarea' => 1, 'select' => 1, 'photo' => 1, 'logo' => 1, 'signature' => 1, 'qr' => 1, 'barcode' => 1, 'email' => 1, 'phone' => 1, 'address' => 1, 'id_number' => 1, 'static_text' => 1]);

        $stmt = $pdo->prepare(
            'INSERT INTO template_input_fields
                (template_id, field_key, field_label, field_type, bilingual_mode, is_required, is_enabled, placeholder, default_value, validation_rules, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                field_label = VALUES(field_label),
                field_type = VALUES(field_type),
                bilingual_mode = VALUES(bilingual_mode),
                is_required = VALUES(is_required),
                is_enabled = VALUES(is_enabled),
                placeholder = VALUES(placeholder),
                default_value = VALUES(default_value),
                validation_rules = VALUES(validation_rules),
                sort_order = VALUES(sort_order),
                archived_at = NULL'
        );
        if (!$stmt) {
            return;
        }

        $savedKeys = [];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower(trim((string)($field['field_key'] ?? ''))));
            if ($fieldKey === '') {
                continue;
            }

            $fieldLabel = trim((string)($field['field_label'] ?? ucfirst(str_replace('_', ' ', $fieldKey))));
            if ($fieldLabel === '') {
                $fieldLabel = ucfirst(str_replace('_', ' ', $fieldKey));
            }

            $fieldType = in_array((string)($field['field_type'] ?? 'text'), $allowedTypes, true)
                ? (string)$field['field_type']
                : 'text';

            $bilingualMode = in_array(strtolower((string)($field['bilingual_mode'] ?? 'single')), ['single', 'bilingual'], true)
                ? strtolower((string)$field['bilingual_mode'])
                : 'single';

            $fieldValidation = trim((string)($field['validation_rules'] ?? ''));
            $fieldPlaceholder = trim((string)($field['placeholder'] ?? ''));
            $defaultValue = trim((string)($field['default_value'] ?? ''));

            $stmt->execute([
                $normalizedTemplateId,
                $fieldKey,
                $fieldLabel,
                $fieldType,
                $bilingualMode,
                isset($field['is_required']) ? (int)(bool)$field['is_required'] : 0,
                isset($field['is_enabled']) ? (int)(bool)$field['is_enabled'] : 1,
                $fieldPlaceholder !== '' ? $fieldPlaceholder : null,
                $defaultValue !== '' ? $defaultValue : null,
                $fieldValidation !== '' ? $fieldValidation : null,
                isset($field['sort_order']) ? (int)$field['sort_order'] : $index
            ]);

            $savedKeys[] = $fieldKey;
        }

        // Soft-archive omitted keys instead of hard DELETE (preserve member_dynamic_values)
        if (!empty($savedKeys)) {
            $placeholders = implode(',', array_fill(0, count($savedKeys), '?'));
            $archiveSql = "UPDATE template_input_fields
                            SET archived_at = NOW(), is_enabled = 0
                            WHERE template_id = ?
                              AND archived_at IS NULL
                              AND field_key NOT IN ($placeholders)";
            $archiveStmt = $pdo->prepare($archiveSql);
            if ($archiveStmt) {
                $archiveStmt->execute(array_merge([$normalizedTemplateId], $savedKeys));
            }
        }
    }
}

if (!function_exists('parse_dynamic_field_validation_rules')) {
    function parse_dynamic_field_validation_rules(string $rules): array
    {
        $rules = trim($rules);
        if ($rules === '') {
            return [];
        }

        $decoded = json_decode($rules, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $result = [];
        foreach (preg_split('/[|;\n,]+/', $rules) ?: [] as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }

            $segments = explode(':', $part, 2);
            if (count($segments) === 2) {
                $result[trim($segments[0])] = trim($segments[1]);
            } else {
                $result[$part] = true;
            }
        }

        return $result;
    }
}

if (!function_exists('validate_dynamic_field_value')) {
    function validate_dynamic_field_value(array $field, $value): ?string
    {
        $fieldLabel = trim((string)($field['field_label'] ?? ($field['field_key'] ?? 'Field')));
        $fieldType = strtolower((string)($field['field_type'] ?? 'text'));
        $bilingualMode = strtolower((string)($field['bilingual_mode'] ?? 'single'));
        $required = !empty($field['is_required']);

        if ($bilingualMode === 'bilingual' && is_array($value)) {
            $languageValues = [];
            foreach ($value as $languageCode => $languageValue) {
                if (is_array($languageValue)) {
                    continue;
                }
                $languageValues[(string)$languageCode] = trim((string)$languageValue);
            }

            if ($required) {
                foreach ($languageValues as $languageCode => $languageValue) {
                    if ($languageValue === '') {
                        return $fieldLabel . ' requires both language values.';
                    }
                }
            }

            foreach ($languageValues as $languageValue) {
                if ($languageValue === '') {
                    continue;
                }

                if ($fieldType === 'number' && !is_numeric($languageValue)) {
                    return $fieldLabel . ' must be a number.';
                }

                if ($fieldType === 'date') {
                    $date = DateTime::createFromFormat('Y-m-d', $languageValue);
                    if (!$date || $date->format('Y-m-d') !== $languageValue) {
                        return $fieldLabel . ' must be a valid date (YYYY-MM-DD).';
                    }
                }
            }

            $rules = parse_dynamic_field_validation_rules((string)($field['validation_rules'] ?? ''));
            if (!empty($rules['regex'])) {
                $pattern = (string)$rules['regex'];
                if ($pattern !== '' && @preg_match($pattern, '') === false) {
                    $pattern = trim($pattern);
                    if ($pattern !== '' && ($pattern[0] !== '/' || substr($pattern, -1) !== '/')) {
                        $pattern = '/' . trim($pattern, '/') . '/';
                    }
                }

                foreach ($languageValues as $languageValue) {
                    if ($languageValue === '') {
                        continue;
                    }
                    if ($pattern === '' || @preg_match($pattern, $languageValue) !== 1) {
                        return $fieldLabel . ' does not match the required format.';
                    }
                }
            }

            if (isset($rules['min_length'])) {
                foreach ($languageValues as $languageValue) {
                    if ($languageValue !== '' && mb_strlen($languageValue) < (int)$rules['min_length']) {
                        return $fieldLabel . ' must be at least ' . (int)$rules['min_length'] . ' characters.';
                    }
                }
            }
            if (isset($rules['max_length'])) {
                foreach ($languageValues as $languageValue) {
                    if ($languageValue !== '' && mb_strlen($languageValue) > (int)$rules['max_length']) {
                        return $fieldLabel . ' must be at most ' . (int)$rules['max_length'] . ' characters.';
                    }
                }
            }

            return null;
        }

        $valueString = is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string)$value);

        if ($valueString === '') {
            return $required ? $fieldLabel . ' is required.' : null;
        }

        if ($fieldType === 'number' && !is_numeric($valueString)) {
            return $fieldLabel . ' must be a number.';
        }

        if ($fieldType === 'date') {
            $date = DateTime::createFromFormat('Y-m-d', $valueString);
            if (!$date || $date->format('Y-m-d') !== $valueString) {
                return $fieldLabel . ' must be a valid date (YYYY-MM-DD).';
            }
        }

        if ($fieldType === 'email' && !filter_var($valueString, FILTER_VALIDATE_EMAIL)) {
            return $fieldLabel . ' must be a valid email address.';
        }

        if ($fieldType === 'phone' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $valueString)) {
            return $fieldLabel . ' must be a valid phone number.';
        }

        if ($fieldType === 'id_number' && !preg_match('/^[A-Za-z0-9\-\/]{2,50}$/', $valueString)) {
            return $fieldLabel . ' must be a valid ID number (letters, numbers, hyphens).';
        }

        if ($fieldType === 'address' && mb_strlen($valueString) < 5) {
            return $fieldLabel . ' must be at least 5 characters.';
        }

        $rules = parse_dynamic_field_validation_rules((string)($field['validation_rules'] ?? ''));
        if (!empty($rules['regex'])) {
            $pattern = (string)$rules['regex'];
            if ($pattern !== '' && @preg_match($pattern, '') === false) {
                $pattern = trim($pattern);
                if ($pattern !== '' && ($pattern[0] !== '/' || substr($pattern, -1) !== '/')) {
                    $pattern = '/' . trim($pattern, '/') . '/';
                }
            }

            if ($pattern === '' || @preg_match($pattern, $valueString) !== 1) {
                return $fieldLabel . ' does not match the required format.';
            }
        }

        if (isset($rules['min_length']) && mb_strlen($valueString) < (int)$rules['min_length']) {
            return $fieldLabel . ' must be at least ' . (int)$rules['min_length'] . ' characters.';
        }
        if (isset($rules['max_length']) && mb_strlen($valueString) > (int)$rules['max_length']) {
            return $fieldLabel . ' must be at most ' . (int)$rules['max_length'] . ' characters.';
        }

        if ($fieldType === 'number') {
            $numericValue = (float)$valueString;
            if (isset($rules['min']) && $numericValue < (float)$rules['min']) {
                return $fieldLabel . ' must be at least ' . $rules['min'] . '.';
            }
            if (isset($rules['max']) && $numericValue > (float)$rules['max']) {
                return $fieldLabel . ' must be at most ' . $rules['max'] . '.';
            }
        }

        if (!empty($rules['options'])) {
            $options = is_array($rules['options']) ? $rules['options'] : preg_split('/[|,]+/', (string)$rules['options']);
            $options = array_map(static fn($item) => trim((string)$item), is_array($options) ? $options : []);
            if (!in_array($valueString, $options, true)) {
                return $fieldLabel . ' contains an invalid option.';
            }
        }

        return null;
    }
}

if (!function_exists('get_dynamic_field_template_options')) {
    function get_dynamic_field_template_options(PDO $pdo, ?int $organizationId = null, bool $isSuperAdmin = false): array
    {
        $options = [[
            'value' => 0,
            'label' => 'Global Default Fields',
        ]];

        $defaultTemplates = [
            1 => 'Classic Blue',
            2 => 'Modern Red',
            3 => 'Minimalist White',
            4 => 'Professional Green',
            5 => 'University Gold',
            6 => 'Modern Gradient',
        ];

        foreach ($defaultTemplates as $templateId => $templateName) {
            $options[] = [
                'value' => (int)$templateId,
                'label' => $templateName,
            ];
        }

        if ($isSuperAdmin) {
            $stmt = $pdo->query('SELECT id, name FROM card_templates WHERE status = 1 ORDER BY id DESC');
        } else {
            $stmt = $pdo->prepare('SELECT id, name FROM card_templates WHERE status = 1 AND (organization_id = ? OR organization_id IS NULL OR organization_id = 0) ORDER BY id DESC');
            if ($stmt) {
                $stmt->execute([$organizationId]);
            }
        }

        if (empty($stmt)) {
            return $options;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $templateId = (int)($row['id'] ?? 0);
            if ($templateId <= 0) {
                continue;
            }

            $options[] = [
                'value' => 1000 + $templateId,
                'label' => (string)($row['name'] ?? ('Template ' . $templateId)),
            ];
        }

        return $options;
    }
}

if (!function_exists('get_active_languages')) {
    function get_active_languages(PDO $pdo, int $limit = 2): array
    {
        ensure_dynamic_field_tables($pdo);
        $stmt = $pdo->prepare('SELECT language_code, language_name, is_default, is_active FROM languages WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT ?');
        if (!$stmt) {
            return [];
        }

        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $languages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($languages) < 2) {
            foreach ([
                ['language_code' => 'en', 'language_name' => 'English', 'is_default' => 1, 'is_active' => 1],
                ['language_code' => 'am', 'language_name' => 'Amharic', 'is_default' => 0, 'is_active' => 1],
            ] as $fallback) {
                $exists = false;
                foreach ($languages as $language) {
                    if ((string)($language['language_code'] ?? '') === (string)$fallback['language_code']) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $languages[] = $fallback;
                }

                if (count($languages) >= 2) {
                    break;
                }
            }
        }

        if (empty($languages)) {
            return [
                ['language_code' => 'en', 'language_name' => 'English', 'is_default' => 1, 'is_active' => 1],
                ['language_code' => 'am', 'language_name' => 'Amharic', 'is_default' => 0, 'is_active' => 1],
            ];
        }

        // Map legacy lang1/lang2 to en/am when present
        foreach ($languages as &$lang) {
            $code = (string)($lang['language_code'] ?? '');
            if ($code === 'lang1') {
                $lang['language_code'] = 'en';
                $lang['language_name'] = 'English';
            } elseif ($code === 'lang2') {
                $lang['language_code'] = 'am';
                $lang['language_name'] = 'Amharic';
            }
        }
        unset($lang);

        return array_slice($languages, 0, $limit);
    }
}

if (!function_exists('get_member_dynamic_field_records')) {
    function get_member_dynamic_field_records(PDO $pdo, int $memberId, int $templateId = 0): array
    {
        ensure_dynamic_field_tables($pdo);
        if ($memberId <= 0) {
            return [];
        }

        $fields = get_template_input_fields($pdo, $templateId, true);
        if (empty($fields)) {
            return [];
        }

        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        $values = [];
        $valueStmt = $pdo->prepare('SELECT field_key, field_value, template_id FROM member_dynamic_values WHERE member_id = ? ORDER BY (CASE WHEN template_id = ? THEN 3 WHEN template_id = 0 THEN 2 ELSE 1 END) ASC, id ASC');
        if ($valueStmt) {
            $valueStmt->execute([$memberId, $normalizedTemplateId]);
            foreach ($valueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $fieldKey = (string)($row['field_key'] ?? '');
                if ($fieldKey !== '') {
                    $values[$fieldKey] = (string)($row['field_value'] ?? '');
                }
            }
        }

        $fieldIds = [];
        foreach ($fields as $fieldKey => $field) {
            if (!empty($field['id'])) {
                $fieldIds[$fieldKey] = (int)$field['id'];
            }
        }

        $translationsByFieldId = [];
        if (!empty($fieldIds)) {
            $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
            $translationStmt = $pdo->prepare('SELECT template_field_id, language_code, translated_value FROM member_field_translations WHERE member_id = ? AND template_field_id IN (' . $placeholders . ') ORDER BY language_code ASC');
            if ($translationStmt) {
                $translationStmt->execute(array_merge([$memberId], array_values($fieldIds)));
                foreach ($translationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $fieldId = (int)($row['template_field_id'] ?? 0);
                    if ($fieldId > 0) {
                        $translationsByFieldId[$fieldId][strtolower((string)($row['language_code'] ?? ''))] = (string)($row['translated_value'] ?? '');
                    }
                }
            }
        }

        $records = [];
        foreach ($fields as $fieldKey => $field) {
            $fieldId = (int)($field['id'] ?? 0);
            $records[$fieldKey] = [
                'field_key' => $fieldKey,
                'field_label' => (string)($field['field_label'] ?? $fieldKey),
                'field_type' => (string)($field['field_type'] ?? 'text'),
                'bilingual_mode' => (string)($field['bilingual_mode'] ?? 'single'),
                'value' => $values[$fieldKey] ?? '',
                'translations' => $fieldId > 0 ? ($translationsByFieldId[$fieldId] ?? []) : [],
                'template_field_id' => $fieldId,
            ];
        }

        return $records;
    }
}

if (!function_exists('format_dynamic_field_display_value')) {
    function format_dynamic_field_display_value(array $fieldRecord, array $languages = []): string
    {
        $mode = strtolower((string)($fieldRecord['bilingual_mode'] ?? 'single'));
        if ($mode === 'dual') {
            $mode = 'bilingual';
        }
        $baseValue = trim((string)($fieldRecord['value'] ?? ''));
        if ($mode !== 'bilingual') {
            return $baseValue;
        }

        $languageLabels = [];
        foreach ($languages as $index => $language) {
            $languageLabels[] = [
                'code' => strtolower((string)($language['language_code'] ?? 'lang' . ($index + 1))),
                'name' => (string)($language['language_name'] ?? ('Language ' . ($index + 1))),
            ];
        }

        if (empty($languageLabels)) {
            $languageLabels = [
                ['code' => 'lang1', 'name' => 'Language 1'],
                ['code' => 'lang2', 'name' => 'Language 2'],
            ];
        }

        $translations = $fieldRecord['translations'] ?? [];
        $lines = [];
        foreach ($languageLabels as $index => $languageLabel) {
            $value = $translations[$languageLabel['code']] ?? '';
            if ($index === 0 && $value === '') {
                $value = $baseValue;
            }
            if ($value !== '') {
                $lines[] = $languageLabel['name'] . ': ' . $value;
            }
        }

        return implode("\n", $lines) !== '' ? implode("\n", $lines) : $baseValue;
    }
}

if (!function_exists('save_member_dynamic_values')) {
    function save_member_dynamic_values(PDO $pdo, int $memberId, int $templateId, array $values): void
    {
        ensure_dynamic_field_tables($pdo);
        if ($memberId <= 0) {
            return;
        }

        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        $fields = get_template_input_fields($pdo, $normalizedTemplateId, true);
        $valueStmt = $pdo->prepare('INSERT INTO member_dynamic_values (member_id, template_id, field_key, field_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)');
        $translationDeleteStmt = $pdo->prepare('DELETE FROM member_field_translations WHERE member_id = ? AND template_field_id = ?');
        $translationStmt = $pdo->prepare('INSERT INTO member_field_translations (member_id, template_field_id, language_code, translated_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE translated_value = VALUES(translated_value)');
        if (!$valueStmt || !$translationDeleteStmt || !$translationStmt) {
            return;
        }

        foreach ($values as $fieldKey => $value) {
            $normalizedKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower(trim((string)$fieldKey)));
            if ($normalizedKey === '' || !isset($fields[$normalizedKey])) {
                continue;
            }

            $field = $fields[$normalizedKey];
            $fieldId = (int)($field['id'] ?? 0);
            $mode = strtolower((string)($field['bilingual_mode'] ?? 'single'));
            if ($mode === 'dual') {
                $mode = 'bilingual';
            }
            $primaryValue = '';
            $translationValues = [];

            if (is_array($value)) {
                if (isset($value['translations']) && is_array($value['translations'])) {
                    $translationValues = $value['translations'];
                    $primaryValue = trim((string)($value['value'] ?? ''));
                } else {
                    $translationValues = $value;
                    $primaryValue = trim((string)($value['value'] ?? ''));
                }
            } else {
                $primaryValue = trim((string)$value);
            }

            if ($mode === 'bilingual') {
                if ($primaryValue === '' && !empty($translationValues)) {
                    $firstTranslation = reset($translationValues);
                    $primaryValue = trim((string)$firstTranslation);
                }

                $translationDeleteStmt->execute([$memberId, $fieldId]);
                foreach ($translationValues as $languageCode => $translatedValue) {
                    $translationStmt->execute([
                        $memberId,
                        $fieldId,
                        strtolower(trim((string)$languageCode)),
                        trim((string)$translatedValue) !== '' ? trim((string)$translatedValue) : null,
                    ]);
                }
            } elseif ($fieldId > 0) {
                $translationDeleteStmt->execute([$memberId, $fieldId]);
            }

            $valueStmt->execute([
                $memberId,
                $normalizedTemplateId,
                $normalizedKey,
                $primaryValue !== '' ? $primaryValue : null
            ]);
        }
    }
}

if (!function_exists('get_member_dynamic_values')) {
    function get_member_dynamic_values(PDO $pdo, int $memberId, int $templateId = 0): array
    {
        $records = get_member_dynamic_field_records($pdo, $memberId, $templateId);
        $values = [];
        foreach ($records as $fieldKey => $record) {
            $values[$fieldKey] = (string)($record['value'] ?? '');
        }

        return $values;
    }
}

if (!function_exists('get_template_field_settings')) {
    function get_template_field_settings(PDO $pdo, int $templateId): array
    {
        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        $settings = [];
        $stmt = $pdo->prepare('SELECT field_key, x, y, width, height, visible, font_size, side, font_family, color, show_label FROM template_fields WHERE template_id = ?');
        if (!$stmt) {
            return $settings;
        }

        $stmt->execute([$normalizedTemplateId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(($row['side'] ?? 'front') . ':' . $row['field_key'])] = [
                'x' => (float)$row['x'],
                'y' => (float)$row['y'],
                'width' => (float)$row['width'],
                'height' => (float)$row['height'],
                'visible' => (bool)$row['visible'],
                'font_size' => (int)$row['font_size'],
                'side' => $row['side'] ?? 'front',
                'font_family' => $row['font_family'] ?? null,
                'color' => $row['color'] ?? null,
                'show_label' => isset($row['show_label']) ? (bool)$row['show_label'] : true,
            ];
        }

        return $settings;
    }
}

if (!function_exists('save_template_field_settings')) {
    function save_template_field_settings(PDO $pdo, int $templateId, array $settings): void
    {
        $normalizedTemplateId = $templateId > 1000 ? $templateId - 1000 : $templateId;
        if (function_exists('ensure_card_renderer_schema')) {
            ensure_card_renderer_schema($pdo);
        }
        $stmt = $pdo->prepare('INSERT INTO template_fields (template_id, field_key, side, x, y, width, height, visible, font_size, font_family, color, show_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE x = VALUES(x), y = VALUES(y), width = VALUES(width), height = VALUES(height), visible = VALUES(visible), font_size = VALUES(font_size), font_family = VALUES(font_family), color = VALUES(color), show_label = VALUES(show_label)');
        if (!$stmt) {
            return;
        }

        foreach ($settings as $fieldKey => $fieldData) {
            $stmt->execute([
                $normalizedTemplateId,
                str_contains((string)$fieldKey, ':') ? substr((string)$fieldKey, strpos((string)$fieldKey, ':') + 1) : $fieldKey,
                $fieldData['side'] ?? (str_starts_with((string)$fieldKey, 'back:') ? 'back' : 'front'),
                max(0, min(100, (float)($fieldData['x'] ?? 0))),
                max(0, min(100, (float)($fieldData['y'] ?? 0))),
                max(1, min(100, (float)($fieldData['width'] ?? 20))),
                max(1, min(100, (float)($fieldData['height'] ?? 5))),
                isset($fieldData['visible']) ? (int)(bool)$fieldData['visible'] : 1,
                max(7, min(72, (int)($fieldData['font_size'] ?? 12))),
                $fieldData['font_family'] ?? null,
                $fieldData['color'] ?? null,
                isset($fieldData['show_label']) ? (int)(bool)$fieldData['show_label'] : 1,
            ]);
        }
    }
}
?>
