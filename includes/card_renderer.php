<?php
/**
 * Single source of truth for ID-card layouts.
 * Coordinates are percentages. Supports layout_version 1+ (object_type model).
 */
require_once __DIR__ . '/../vendor/autoload.php';

function ensure_card_renderer_schema(PDO $pdo): void
{
    ensure_template_fields_table($pdo);
    foreach ([
        'ALTER TABLE template_fields MODIFY x DECIMAL(7,3) NOT NULL DEFAULT 0',
        'ALTER TABLE template_fields MODIFY y DECIMAL(7,3) NOT NULL DEFAULT 0',
        'ALTER TABLE template_fields MODIFY width DECIMAL(7,3) NOT NULL DEFAULT 0',
        'ALTER TABLE template_fields MODIFY height DECIMAL(7,3) NOT NULL DEFAULT 0',
        "ALTER TABLE template_fields ADD COLUMN side VARCHAR(10) NOT NULL DEFAULT 'front' AFTER field_key",
        "ALTER TABLE template_fields ADD COLUMN object_type VARCHAR(32) NOT NULL DEFAULT 'dynamic' AFTER field_key",
        'ALTER TABLE template_fields ADD COLUMN font_family VARCHAR(100) NULL AFTER font_size',
        'ALTER TABLE template_fields ADD COLUMN font_weight VARCHAR(16) NULL AFTER font_family',
        'ALTER TABLE template_fields ADD COLUMN font_style VARCHAR(16) NULL AFTER font_weight',
        'ALTER TABLE template_fields ADD COLUMN color VARCHAR(32) NULL AFTER font_style',
        "ALTER TABLE template_fields ADD COLUMN text_align ENUM('left','center','right') NOT NULL DEFAULT 'left' AFTER color",
        'ALTER TABLE template_fields ADD COLUMN text_decoration VARCHAR(32) NULL AFTER text_align',
        'ALTER TABLE template_fields ADD COLUMN opacity DECIMAL(4,3) NOT NULL DEFAULT 1.000 AFTER text_decoration',
        'ALTER TABLE template_fields ADD COLUMN border_width DECIMAL(6,2) NULL AFTER opacity',
        'ALTER TABLE template_fields ADD COLUMN border_color VARCHAR(32) NULL AFTER border_width',
        'ALTER TABLE template_fields ADD COLUMN border_style VARCHAR(16) NULL AFTER border_color',
        'ALTER TABLE template_fields ADD COLUMN border_radius DECIMAL(6,2) NULL AFTER border_style',
        'ALTER TABLE template_fields ADD COLUMN show_label TINYINT(1) NOT NULL DEFAULT 1 AFTER border_radius',
        'ALTER TABLE template_fields ADD COLUMN content TEXT NULL AFTER show_label',
        'ALTER TABLE template_fields ADD COLUMN image_path VARCHAR(255) NULL AFTER content',
        'ALTER TABLE template_fields ADD COLUMN z_index INT NOT NULL DEFAULT 0 AFTER image_path',
        'ALTER TABLE template_fields ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER visible',
        'ALTER TABLE template_fields MODIFY field_key VARCHAR(64) NULL DEFAULT NULL',
        'ALTER TABLE card_templates ADD COLUMN layout_version INT NOT NULL DEFAULT 1 AFTER mirror_print',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* already applied */ }
    }
    try {
        $pdo->exec('ALTER TABLE template_fields DROP INDEX uq_template_field, ADD UNIQUE KEY uq_template_field_side (template_id, side, field_key)');
    } catch (Throwable $e) { /* ok */ }
}

function card_renderer_template(PDO $pdo, int $templateId, bool $includeInactive = false): array
{
    $sql = 'SELECT * FROM card_templates WHERE id = ?';
    if (!$includeInactive) {
        $sql .= ' AND status = 1 AND deleted_at IS NULL';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        throw new RuntimeException('Template not found.');
    }
    $portrait = strtolower((string)($template['orientation'] ?? 'portrait')) === 'portrait';
    $template['card_width'] = (int)($template['card_width'] ?: ($portrait ? 533 : 864));
    $template['card_height'] = (int)($template['card_height'] ?: ($portrait ? 864 : 533));
    return $template;
}

function card_renderer_member(PDO $pdo, int $memberId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, o.organization_name, o.logo AS org_logo
         FROM id_members m
         LEFT JOIN organizations o ON o.id = m.organization_id
         WHERE m.id = ? AND m.deleted_at IS NULL'
    );
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    if (!$member) {
        throw new RuntimeException('Member not found.');
    }
    $member['dynamic_fields'] = get_member_dynamic_field_records($pdo, $memberId, (int)($member['template_id'] ?? 0));
    return $member;
}

function card_renderer_definitions(PDO $pdo, int $templateId): array
{
    $definitions = array_values(get_template_input_fields($pdo, $templateId, true));
    $byKey = [];
    foreach ($definitions as $def) {
        $byKey[(string)$def['field_key']] = $def;
    }
    foreach (['organization_name', 'photo', 'name', 'unique_id', 'member_type', 'expiry_date', 'barcode', 'qr', 'signature', 'terms'] as $key) {
        if (!isset($byKey[$key])) {
            $type = in_array($key, ['photo', 'barcode', 'qr', 'signature'], true) ? $key : 'text';
            $byKey[$key] = ['field_key' => $key, 'field_label' => ucwords(str_replace('_', ' ', $key)), 'field_type' => $type];
        }
    }
    return $byKey;
}

/**
 * @return list<array> layout objects for the template (archived excluded)
 */
function card_renderer_layout(PDO $pdo, int $templateId): array
{
    ensure_card_renderer_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM template_fields
         WHERE template_id = ? AND (archived_at IS NULL)
         ORDER BY side, id'
    );
    $stmt->execute([$templateId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function card_renderer_default_layout(string $key, string $side): array
{
    $front = ['organization_name'=>[5,5,90,12], 'photo'=>[35,21,30,25], 'name'=>[5,52,90,9], 'unique_id'=>[5,63,90,7], 'member_type'=>[5,72,90,7], 'expiry_date'=>[5,81,90,7], 'barcode'=>[8,88,55,8], 'qr'=>[74,70,18,18]];
    $back = ['terms'=>[8,30,84,28], 'barcode'=>[8,10,54,12], 'qr'=>[72,8,20,20], 'signature'=>[68,74,24,10], 'expiry_date'=>[8,62,50,7]];
    $p = ($side === 'back' ? $back : $front)[$key] ?? [5, 5, 90, 8];
    $otype = 'dynamic';
    if (in_array($key, ['photo', 'logo', 'qr', 'barcode', 'signature'], true)) {
        $otype = $key;
    }
    return [
        'id' => 0,
        'field_key' => $key,
        'object_type' => $otype,
        'side' => $side,
        'x' => $p[0], 'y' => $p[1], 'width' => $p[2], 'height' => $p[3],
        'visible' => 1, 'font_size' => 14, 'font_family' => null, 'color' => null,
        'text_align' => 'left', 'show_label' => 0, 'opacity' => 1,
        'content' => null, 'image_path' => null,
    ];
}

function card_renderer_value(array $member, string $key, ?string $contentOverride = null): string
{
    $override = trim((string)($contentOverride ?? ''));

    if ($override !== '' && in_array($key, ['terms', 'organization_name'], true)) {
        return $override;
    }

    if ($key === 'terms') {
        return 'This card is the property of the organization. Please return it if found.';
    }

    if ($key === 'unique_id') {
        return (string)($member['unique_id'] ?? '');
    }

    if ($key === 'organization_name') {
        return (string)($member['organization_name'] ?? 'Organization');
    }

    // Format standard date fields as DD-MM-YYYY
    if (in_array($key, ['dob', 'joined_date', 'expiry_date'], true)) {
        $dateValue = trim((string)($member[$key] ?? ''));

        if ($dateValue !== '') {
            $timestamp = strtotime($dateValue);

            if ($timestamp !== false) {
                return date('d-m-Y', $timestamp);
            }
        }

        return $dateValue;
    }

    if (array_key_exists($key, $member) && !is_array($member[$key])) {
        return (string)($member[$key] ?? '');
    }

    $record = $member['dynamic_fields'][$key] ?? null;

    return is_array($record)
        ? format_dynamic_field_display_value($record)
        : '';
}

/**
 * Human-readable label for a layout/field key (designer + renderer UI).
 */
function card_renderer_field_label(string $key, ?array $definition = null): string
{
    // The designer permits intentional spaces around a colon (e.g. `Name :   `).
    // Only trim to determine whether a label exists; return the original text
    // so Generate, View, Print and Download retain the saved spacing.
    $fromDef = (string)($definition['field_label'] ?? '');
    if (trim($fromDef) !== '') {
        return $fromDef;
    }

    $defaults = [
        'organization_name' => 'Organization Name',
        'unique_id' => 'Unique ID',
        'member_type' => 'Member Type',
        'expiry_date' => 'Expiry Date',
        'joined_date' => 'Joined Date',
        'guardian_name' => 'Guardian Name',
        'emergency_contact' => 'Emergency Contact',
        'photo' => 'Photo',
        'logo' => 'Logo',
        'qr' => 'QR Code',
        'barcode' => 'Barcode',
        'signature' => 'Signature',
        'terms' => 'Terms',
        'name' => 'Full Name',
        'email' => 'Email',
        'address' => 'Address',
        'class' => 'Class',
        'department' => 'Department',
        'designation' => 'Designation',
        'company' => 'Company',
        'purpose' => 'Purpose',
    ];

    if (isset($defaults[$key])) {
        return $defaults[$key];
    }

    $key = trim($key);
    if ($key === '') {
        return 'Field';
    }

    return ucwords(str_replace(['_', '-'], ' ', $key));
}

function card_renderer_member_image(string $assetPrefix, string $subPath, ?string $filename, string $fallback = 'images/uploads/default.png'): string
{
    $normalizedPrefix = str_replace('\\', '/', $assetPrefix);
    $normalizedPrefix = ($normalizedPrefix !== '' && substr($normalizedPrefix, -1) !== '/') ? ($normalizedPrefix . '/') : $normalizedPrefix;

    $safeFallback = $normalizedPrefix . ltrim(str_replace('\\', '/', $fallback), '/');
    $name = basename((string)$filename);
    if ($name === '') {
        return $safeFallback;
    }

    $normalizedSubPath = trim(str_replace('\\', '/', $subPath), '/');
    $absolute = realpath(__DIR__ . '/../' . $normalizedSubPath . '/' . $name);
    if ($absolute && is_file($absolute)) {
        return $normalizedPrefix . $normalizedSubPath . '/' . rawurlencode($name);
    }

    return $safeFallback;
}

function card_renderer_member_image_or_null(string $assetPrefix, string $subPath, ?string $filename): ?string
{
    $name = basename((string)$filename);
    if ($name === '') {
        return null;
    }

    $normalizedPrefix = str_replace('\\', '/', $assetPrefix);
    $normalizedPrefix = ($normalizedPrefix !== '' && substr($normalizedPrefix, -1) !== '/') ? ($normalizedPrefix . '/') : $normalizedPrefix;
    $normalizedSubPath = trim(str_replace('\\', '/', $subPath), '/');
    $absolute = realpath(__DIR__ . '/../' . $normalizedSubPath . '/' . $name);
    if ($absolute && is_file($absolute)) {
        return $normalizedPrefix . $normalizedSubPath . '/' . rawurlencode($name);
    }

    return null;
}

function card_renderer_code(string $type, string $value): string
{
    try {
        if ($type === 'barcode') {
            $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
            return $generator->getBarcode($value ?: ' ', $generator::TYPE_CODE_128, 1, 40);
        }
        $qr = \Endroid\QrCode\QrCode::create($value ?: ' ')->setSize(250)->setMargin(0);
        return (new \Endroid\QrCode\Writer\SvgWriter())->write($qr)->getString();
    } catch (Throwable $e) {
        return '<span>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

function card_renderer_object_style(array $field, array $template): string
{
    $align = strtolower((string)($field['text_align'] ?? 'left'));
    if (!in_array($align, ['left', 'center', 'right'], true)) {
        $align = 'left';
    }
    $justify = $align === 'left' ? 'flex-start' : ($align === 'right' ? 'flex-end' : 'center');
    $opacity = isset($field['opacity']) ? (float)$field['opacity'] : 1.0;
    $opacity = max(0, min(1, $opacity));
    $parts = [
        sprintf('left:%.3f%%', max(0, min(100, (float)$field['x']))),
        sprintf('top:%.3f%%', max(0, min(100, (float)$field['y']))),
        sprintf('width:%.3f%%', max(1, min(100, (float)$field['width']))),
        sprintf('height:%.3f%%', max(1, min(100, (float)$field['height']))),
        sprintf('font-size:%dpx', max(7, min(72, (int)($field['font_size'] ?? 12)))),
        'font-family:' . htmlspecialchars((string)($field['font_family'] ?: ($template['font'] ?? 'Arial')), ENT_QUOTES, 'UTF-8'),
        'color:' . htmlspecialchars((string)($field['color'] ?: ($template['text_color'] ?? '#fff')), ENT_QUOTES, 'UTF-8'),
        'text-align:' . $align,
        'justify-content:' . $justify,
        'opacity:' . $opacity,
    ];
    if (!empty($field['font_weight'])) {
        $parts[] = 'font-weight:' . htmlspecialchars((string)$field['font_weight'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($field['font_style'])) {
        $parts[] = 'font-style:' . htmlspecialchars((string)$field['font_style'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($field['text_decoration'])) {
        $parts[] = 'text-decoration:' . htmlspecialchars((string)$field['text_decoration'], ENT_QUOTES, 'UTF-8');
    }
    $bw = isset($field['border_width']) ? (float)$field['border_width'] : 0;
    if ($bw > 0) {
        $bs = htmlspecialchars((string)($field['border_style'] ?: 'solid'), ENT_QUOTES, 'UTF-8');
        $bc = htmlspecialchars((string)($field['border_color'] ?: '#000000'), ENT_QUOTES, 'UTF-8');
        $parts[] = sprintf('border:%.2fpx %s %s', $bw, $bs, $bc);
    }
    if (isset($field['border_radius']) && $field['border_radius'] !== null && $field['border_radius'] !== '') {
        $parts[] = sprintf('border-radius:%.2fpx', (float)$field['border_radius']);
        $parts[] = 'overflow:hidden';
    }
    return implode(';', $parts) . ';';
}

/**
 * @param array $definitions keyed by field_key
 * @param list<array> $layout
 */
function card_renderer_html(array $template, array $member, array $definitions, array $layout, string $side = 'front', string $assetPrefix = ''): string
{
    // Normalize definitions to keyed map
    if ($definitions && array_is_list($definitions)) {
        $map = [];
        foreach ($definitions as $d) {
            $map[(string)$d['field_key']] = $d;
        }
        $definitions = $map;
    }

    $style = sprintf(
        '--card-primary:%s;--card-secondary:%s;--card-text:%s;--card-font:%s;width:%dpx;height:%dpx;',
        htmlspecialchars((string)($template['primary_color'] ?? '#0a1a2f')),
        htmlspecialchars((string)($template['secondary_color'] ?? '#1e3a5f')),
        htmlspecialchars((string)($template['text_color'] ?? '#fff')),
        htmlspecialchars((string)($template['font'] ?? 'Arial')),
        $template['card_width'],
        $template['card_height']
    );

    $bgLayer = '';
    if (!empty($template['background_image'])) {
        $bg = $assetPrefix . ltrim(str_replace('\\', '/', (string)$template['background_image']), '/');
        $bgPosX = isset($template['bg_pos_x']) ? (float)$template['bg_pos_x'] : 50;
        $bgPosY = isset($template['bg_pos_y']) ? (float)$template['bg_pos_y'] : 50;
        $bgSize = !empty($template['bg_size']) ? $template['bg_size'] : 'cover';
        $bgStyle = sprintf('background-image:url(\'%s\');background-position:%.2f%% %.2f%%;background-size:%s;background-repeat:no-repeat;',
            htmlspecialchars($bg, ENT_QUOTES, 'UTF-8'),
            $bgPosX,
            $bgPosY,
            htmlspecialchars($bgSize, ENT_QUOTES, 'UTF-8')
        );
        $bgLayer = '<div class="card-renderer-bg" style="' . $bgStyle . '"></div>';
    }

    $html = '<section class="id-card-renderer" data-side="' . htmlspecialchars($side, ENT_QUOTES, 'UTF-8') . '" style="' . $style . '">' . $bgLayer;

    // If layout empty, fall back to default keys for compatibility
    $objects = $layout;
    if (empty($objects)) {
        foreach (['organization_name', 'photo', 'name', 'unique_id', 'member_type', 'expiry_date', 'barcode', 'qr'] as $key) {
            if ($side === 'back' && !in_array($key, ['terms', 'barcode', 'qr', 'signature', 'expiry_date'], true)) {
                continue;
            }
            $objects[] = card_renderer_default_layout($key, $side);
        }
        if ($side === 'back') {
            $objects[] = card_renderer_default_layout('terms', 'back');
        }
    }

    foreach ($objects as $field) {
        if (($field['side'] ?? 'front') !== $side) {
            continue;
        }
        if (isset($field['visible']) && (int)$field['visible'] === 0) {
            continue;
        }
        if (!empty($field['archived_at'])) {
            continue;
        }

        $objectType = strtolower((string)($field['object_type'] ?? 'dynamic'));
        $key = (string)($field['field_key'] ?? '');
        $def = ($key !== '' && isset($definitions[$key])) ? $definitions[$key] : null;
        $fieldType = strtolower((string)($def['field_type'] ?? $objectType));

        // Infer type for legacy dynamic rows
        if ($objectType === 'dynamic' && $def) {
            if (in_array($fieldType, ['photo', 'logo', 'qr', 'barcode', 'signature'], true)) {
                $objectType = $fieldType;
            }
        }

        $position = card_renderer_object_style($field, $template);
        $content = '';
        $showLabel = !empty($field['show_label']);

        switch ($objectType) {
            case 'static_text':
                $content = '<span class="static-text">' . nl2br(htmlspecialchars((string)($field['content'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</span>';
                break;
            case 'image':
                $path = (string)($field['image_path'] ?? '');
                if ($path !== '') {
                    $src = $assetPrefix . ltrim(str_replace('\\', '/', $path), '/');
                    $content = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Image">';
                }
                break;
            case 'photo':
                $src = card_renderer_member_image($assetPrefix, 'images/uploads', $member['photo'] ?? null);
                $content = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Photo">';
                break;
            case 'logo':
                $src = card_renderer_member_image($assetPrefix, 'images/uploads', $member['org_logo'] ?? null);
                if ($src === card_renderer_member_image($assetPrefix, 'images/uploads', null) && !empty($member['org_logo'])) {
                    $orgDirs = ['organizations/uploads', 'organizations/assets/uploads/logo', 'images/uploads'];
                    foreach ($orgDirs as $dir) {
                        $candidate = card_renderer_member_image_or_null($assetPrefix, $dir, $member['org_logo'] ?? null);
                        if ($candidate !== null) {
                            $src = $candidate;
                            break;
                        }
                    }
                }
                $content = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Logo">';
                break;
            case 'qr':
            case 'barcode':
                $value = $key !== '' ? card_renderer_value($member, $key, $field['content'] ?? null) : '';
                if ($value === '' && !empty($field['content'])) {
                    $value = (string)$field['content'];
                }
                $content = card_renderer_code($objectType, $value !== '' ? $value : (string)($member['unique_id'] ?? ''));
                break;
            case 'signature':
                $src = card_renderer_member_image_or_null($assetPrefix, 'images/uploads/signatures', $member['signature'] ?? null);
                if ($src !== null) {
                    $content = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Signature">';
                } else {
                    $value = $key !== '' ? card_renderer_value($member, $key, $field['content'] ?? null) : '';
                    $content = '<span class="signature">' . htmlspecialchars($value !== '' ? $value : 'Authorized Signature', ENT_QUOTES, 'UTF-8') . '</span>';
                }
                break;
            case 'dynamic':
            default:
                $value = $key !== '' ? card_renderer_value($member, $key, $field['content'] ?? null) : '';
                $labelHtml = '';
                if ($showLabel && $key !== '') {
                    $labelText = (string)card_renderer_field_label($key, $def);
                    // Match the Template Designer canvas: bold label followed
                    // by one non-breaking space before the field value.
                    $labelHtml = ($labelText !== '')
                        ? '<span class="field-label">' . htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8') . '</span>&nbsp;'
                        : '';
                }
                $content = '<span class="field-value">' . $labelHtml . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) . '</span>';
                break;
        }

        $html .= '<div class="card-renderer-field type-' . htmlspecialchars($objectType, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-object-id="' . (int)($field['id'] ?? 0) . '"'
            . ($key !== '' ? ' data-field-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' : '')
            . ' style="' . $position . '">' . $content . '</div>';
    }

    return $html . '</section>';
}

function card_renderer_css(): string
{
    return '<style>'
        . '.id-card-renderer{position:relative;box-sizing:border-box;overflow:hidden;background:linear-gradient(135deg,var(--card-primary),var(--card-secondary));color:var(--card-text);font-family:var(--card-font),sans-serif}'
        . '.id-card-renderer *{box-sizing:border-box}'
        . '.card-renderer-bg{position:absolute;inset:0;background-size:cover;background-position:center;z-index:0}'
        . '.card-renderer-field{position:absolute;z-index:1;display:flex;align-items:center;justify-content:inherit;overflow:hidden;line-height:1.2;word-break:break-word;padding:1px 2px}'
        // The designer uses pre-wrap for label/value text. Preserve the exact
        // spaces saved in a label (for example before a colon) everywhere else.
        . '.card-renderer-field .field-value{width:100%;display:block;white-space:pre-wrap}'
        . '.card-renderer-field .field-label{font-weight:700}'
        . '.card-renderer-field img,.type-qr svg,.type-barcode svg{width:100%;height:100%;object-fit:contain}'
        . '.type-photo img,.type-logo img,.type-image img{object-fit:cover}'
        . '.type-qr,.type-barcode{background:#fff;padding:3px}'
        . '.signature{width:100%;border-top:1px solid currentColor;padding-top:3px;font-size:.7em}'
        . '.static-text{width:100%;white-space:pre-wrap}'
        . '@media print{.id-card-renderer{box-shadow:none;break-inside:avoid}}'
        . '</style>';
}

/**
 * Resolve a relative asset path (from HTML) to an absolute filesystem path under the project.
 */
function card_renderer_resolve_local_asset(string $projectRoot, string $assetPath): ?string
{
    $path = str_replace('\\', '/', trim($assetPath));
    if ($path === '' || preg_match('#^(data:|https?:|//)#i', $path)) {
        return null;
    }
    $path = rawurldecode($path);
    $path = preg_replace('#^(\.\./)+#', '', $path) ?? $path;
    $path = ltrim($path, '/');
    $candidate = realpath(rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    $root = realpath($projectRoot);
    if (!$candidate || !$root || !is_file($candidate) || !str_starts_with($candidate, $root)) {
        return null;
    }
    return $candidate;
}

/**
 * Convert a local file into a data URI for portable HTML downloads.
 */
function card_renderer_file_to_data_uri(string $absolutePath): ?string
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        return null;
    }
    $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
    $data = @file_get_contents($absolutePath);
    if ($data === false) {
        return null;
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/**
 * Rewrite img src / CSS url(...) to embedded data URIs so the HTML works offline (Downloads folder).
 */
function card_renderer_embed_assets(string $html, string $projectRoot): string
{
    $html = preg_replace_callback(
        '/\bsrc=(["\'])([^"\']+)\1/i',
        static function (array $m) use ($projectRoot): string {
            $file = card_renderer_resolve_local_asset($projectRoot, $m[2]);
            if (!$file) {
                return $m[0];
            }
            $uri = card_renderer_file_to_data_uri($file);
            return $uri ? ('src=' . $m[1] . $uri . $m[1]) : $m[0];
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '/url\((["\']?)([^)\'"\s]+)\1\)/i',
        static function (array $m) use ($projectRoot): string {
            $file = card_renderer_resolve_local_asset($projectRoot, $m[2]);
            if (!$file) {
                return $m[0];
            }
            $uri = card_renderer_file_to_data_uri($file);
            return $uri ? ('url(' . $m[1] . $uri . $m[1] . ')') : $m[0];
        },
        $html
    ) ?? $html;

    return $html;
}

/**
 * Build a self-contained downloadable HTML document for a generated card.
 */
function card_renderer_portable_document(PDO $pdo, int $memberId, int $templateId, bool $includeBack = true, bool $mirror = false): string
{
    ensure_card_renderer_schema($pdo);
    $template = card_renderer_template($pdo, $templateId, true);
    $member = card_renderer_member($pdo, $memberId);
    $definitions = card_renderer_definitions($pdo, $templateId);
    $layout = card_renderer_layout($pdo, $templateId);

    $sides = $includeBack ? ['front', 'back'] : ['front'];
    $mirrorStyle = $mirror ? 'transform:scaleX(-1);' : '';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>ID Card - ' . htmlspecialchars((string)($member['unique_id'] ?? $memberId), ENT_QUOTES, 'UTF-8') . '</title>'
        . card_renderer_css()
        . '<style>body{margin:0;padding:16px;background:#f3f4f6;font-family:Arial,sans-serif}.card-stack{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}.card-stack .id-card-renderer{' . $mirrorStyle . '}@media print{body{background:#fff;padding:0}}</style>'
        . '</head><body><div class="card-stack">';
    foreach ($sides as $side) {
        // Empty asset prefix — embedder resolves project-relative paths
        $html .= card_renderer_html($template, $member, $definitions, $layout, $side, '');
    }
    $html .= '</div></body></html>';

    return card_renderer_embed_assets($html, dirname(__DIR__));
}
