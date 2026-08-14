<?php
/**
 * print_id_card.php
 * -----------------------------------------------------------------------
 * Renders single or bulk ID cards for printing.
 *
 * GET params:
 *   ids     - comma separated member IDs (required)
 *   bulk    - 1 for bulk mode (multiple cards), omitted/0 for single
 *   mirror  - 1 forces mirror printing ON regardless of template default
 *   rotate  - 1|0 forces landscape auto-rotate ON/OFF (default: on for landscape)
 *
 * Notes on assumptions (adjust if your schema differs):
 *   - id_members: id, organization_id, template_id, unique_id, name,
 *     guardian_name, email, emergency_contact, department, class,
 *     designation, company, purpose, dob, address, joined_date,
 *     expiry_date, photo, signature, language, deleted_at
 *   - card_templates: id, name, orientation, primary_color, secondary_color,
 *     text_color, font, background_image, mirror_print, card_width,
 *     card_height, organization_id
 *   - Per-member dynamic/custom field VALUES are read defensively from a
 *     small set of likely table names (see print_get_member_dynamic_values
 *     below). If your table/columns are named differently, edit that one
 *     function only - everything else stays the same.
 * -----------------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);

if (!$authUser) {
    http_response_code(401);
    exit('Authentication required.');
}

require_permission($pdo, 'Members', 'Print');

$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId    = (int) ($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

// Optional helper libraries - loaded only if present, never fatal if missing.
$templateHelpers = __DIR__ . '/../template/template_mgmt_helpers.php';
if (file_exists($templateHelpers)) {
    require_once $templateHelpers;
}
$memberHelpers = __DIR__ . '/../members/member_helpers.php';
if (file_exists($memberHelpers)) {
    require_once $memberHelpers;
}

/*
|--------------------------------------------------------------------------
| Parse & validate input
|--------------------------------------------------------------------------
*/
/*
 * Accept both:
 *   ?ids=214,215,192&bulk=1   -> bulk print
 *   ?id=215                    -> single print
 *
 * The single-card page was sending `id`, while this print page was only
 * reading `ids`, which caused "No member IDs supplied."
 */
$rawIds = trim((string) ($_GET['ids'] ?? ''));

if ($rawIds === '' && isset($_GET['id'])) {
    $rawIds = trim((string) $_GET['id']);
}

$ids = array_values(
    array_unique(
        array_filter(
            array_map('intval', preg_split('/\s*,\s*/', $rawIds)),
            static fn($v) => $v > 0
        )
    )
);
$ids = array_slice($ids, 0, 500); // sane hard cap

// If a single id is supplied, automatically treat it as single print.
$isBulk = !empty($_GET['bulk']) && count($ids) > 1;

$forceMirror  = isset($_GET['mirror']) && $_GET['mirror'] == '1';
$rotateParam  = isset($_GET['rotate']) ? ($_GET['rotate'] == '1') : null; // null = auto for landscape

if (empty($ids)) {
    http_response_code(400);
    exit('No member IDs supplied.');
}

/*
|--------------------------------------------------------------------------
| Fetch members (+ organization + template), scoped to the caller's org
| unless super admin. This prevents printing another organization's data.
|--------------------------------------------------------------------------
*/
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;

$sql = "SELECT m.*,
               o.id AS org_id, o.organization_name, o.project_type,
               t.id AS tpl_id, t.name AS template_name, t.orientation,
               t.primary_color, t.secondary_color, t.text_color, t.font,
               t.background_image, t.mirror_print, t.card_width, t.card_height
        FROM id_members m
        LEFT JOIN organizations o ON m.organization_id = o.id
        LEFT JOIN card_templates t ON m.template_id = t.id
        WHERE m.id IN ($placeholders)
          AND m.deleted_at IS NULL";

if (!$isSuperAdmin) {
    $sql .= " AND m.organization_id = ?";
    $params[] = $userOrgId;
}

$sql .= " ORDER BY o.organization_name, m.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$skippedCount = count($ids) - count($rows);

if (empty($rows)) {
    http_response_code(403);
    exit('No accessible members found for the requested IDs.');
}

/*
|--------------------------------------------------------------------------
| Defensive helper: read per-member dynamic field values.
| Tries a few likely table names; returns [] if none match your schema.
| EDIT HERE if your dynamic-values table has a different name/columns.
|--------------------------------------------------------------------------
*/
function print_get_member_dynamic_values(PDO $pdo, int $memberId, int $templateId): array
{
    static $workingTable = null;
    $candidates = ['member_dynamic_values', 'member_field_values', 'member_custom_field_values', 'member_custom_values'];

    if ($workingTable !== null && $workingTable !== false) {
        $candidates = [$workingTable];
    }

    foreach ($candidates as $table) {
        try {
            $stmt = $pdo->prepare("SELECT field_key, field_value FROM `$table` WHERE member_id = ? AND template_id = ?");
            $stmt->execute([$memberId, $templateId]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $workingTable = $table;

            $out = [];
            foreach ($rows as $key => $value) {
                $decoded = json_decode((string) $value, true);
                $out[$key] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $value;
            }
            return $out;
        } catch (Throwable $e) {
            continue;
        }
    }

    $workingTable = false;
    return [];
}

/**
 * Resolve active field keys for a template, falling back to a sane default
 * set if the template helper isn't available.
 */
function print_get_active_keys(PDO $pdo, int $templateId): array
{
    if ($templateId > 0 && function_exists('template_get_active_field_keys')) {
        try {
            $keys = template_get_active_field_keys($pdo, $templateId);
            if (is_array($keys) && !empty($keys)) {
                return array_map('strtolower', $keys);
            }
        } catch (Throwable $e) {
            // fall through to default
        }
    }
    return ['name', 'unique_id', 'dob', 'guardian_name', 'department', 'class',
            'designation', 'company', 'purpose', 'address', 'emergency_contact',
            'email', 'joined_date', 'expiry_date', 'photo', 'signature'];
}

/** Two active languages (English + secondary), degrades gracefully. */
function print_get_languages(PDO $pdo): array
{
    if (function_exists('get_active_languages')) {
        try {
            $langs = get_active_languages($pdo, 2);
            if (is_array($langs) && !empty($langs)) {
                return $langs;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }
    return [
        ['language_code' => 'en', 'language_name' => 'English'],
    ];
}

$languages      = print_get_languages($pdo);
$secondaryLang  = $languages[1] ?? null;

/*
|--------------------------------------------------------------------------
| Build a print-ready structure for each member
|--------------------------------------------------------------------------
*/
$uploadsBase = '../images/uploads/';
$defaultCardCm = [
    'landscape' => ['w' => 8.64, 'h' => 5.33],
    'portrait'  => ['w' => 5.33, 'h' => 8.64],
];

$cards = [];
$orgGroups = []; // preserves organization ordering for page separation

foreach ($rows as $row) {
    $orientation = strtolower((string) ($row['orientation'] ?? 'landscape')) === 'portrait' ? 'portrait' : 'landscape';

    /*
     * IMPORTANT:
     * card_templates.card_width / card_height are renderer dimensions
     * (the shared renderer uses values such as 533 x 864), not CSS cm.
     * The print page must convert them to physical cm before applying
     * width/height. 100 renderer pixels = 1 cm in this project.
     */
    $savedWpx = is_numeric($row['card_width'] ?? null) && (float)$row['card_width'] > 0
        ? (float) $row['card_width'] : null;
    $savedHpx = is_numeric($row['card_height'] ?? null) && (float)$row['card_height'] > 0
        ? (float) $row['card_height'] : null;

    if ($savedWpx !== null && $savedHpx !== null) {
        // First normalize the stored renderer dimensions to the selected
        // orientation, then convert px -> cm.
        if ($orientation === 'landscape' && $savedWpx < $savedHpx) {
            [$savedWpx, $savedHpx] = [$savedHpx, $savedWpx];
        } elseif ($orientation === 'portrait' && $savedWpx > $savedHpx) {
            [$savedWpx, $savedHpx] = [$savedHpx, $savedWpx];
        }

        $cardW = $savedWpx / 100;
        $cardH = $savedHpx / 100;
    } else {
        $cardW = $defaultCardCm[$orientation]['w'];
        $cardH = $defaultCardCm[$orientation]['h'];
    }

    $templateId = (int) ($row['tpl_id'] ?? 0);
    $activeKeys = print_get_active_keys($pdo, $templateId);
    $dynamicValues = $templateId > 0 ? print_get_member_dynamic_values($pdo, (int) $row['id'], $templateId) : [];

    $photoPath = null;
    if (!empty($row['photo']) && is_file(__DIR__ . '/../images/uploads/' . $row['photo'])) {
        $photoPath = $uploadsBase . rawurlencode($row['photo']);
    }
    $signaturePath = null;
    if (!empty($row['signature']) && is_file(__DIR__ . '/../images/uploads/signatures/' . $row['signature'])) {
        $signaturePath = $uploadsBase . 'signatures/' . rawurlencode($row['signature']);
    }
    $bgPath = null;
    if (!empty($row['background_image']) && is_file(__DIR__ . '/../images/templates/' . $row['background_image'])) {
        $bgPath = '../images/templates/' . rawurlencode($row['background_image']);
    } elseif (!empty($row['background_image']) && is_file(__DIR__ . '/../' . ltrim((string) $row['background_image'], '/'))) {
        $bgPath = '../' . ltrim((string) $row['background_image'], '/');
    }

    $orgId = (int) ($row['org_id'] ?? 0);
    if (!isset($orgGroups[$orgId])) {
        $orgGroups[$orgId] = [
            'organization_name' => $row['organization_name'] ?? 'Unassigned',
            'card_ids'          => [],
        ];
    }
    $orgGroups[$orgId]['card_ids'][] = (int) $row['id'];

    $cards[] = [
        'id'              => (int) $row['id'],
        'template_id'      => $templateId,
        'org_id'          => $orgId,
        'organization'    => $row['organization_name'] ?? '—',
        'name'            => $row['name'] ?? '',
        'unique_id'       => $row['unique_id'] ?? '',
        'orientation'     => $orientation,
        'card_w_cm'       => $cardW,
        'card_h_cm'       => $cardH,
        'renderer_w'      => (int)($row['card_width'] ?: 533),
        'renderer_h'      => (int)($row['card_height'] ?: 864),
        'primary_color'   => $row['primary_color'] ?: '#0a1a2f',
        'secondary_color' => $row['secondary_color'] ?: '#0e9f6e',
        'text_color'      => $row['text_color'] ?: '#1f2937',
        'font'            => $row['font'] ?: 'Inter, sans-serif',
        'background'      => $bgPath,
        'mirror_default'  => !empty($row['mirror_print']),
        'photo'           => $photoPath,
        'signature'       => $signaturePath,
        'active_keys'     => $activeKeys,
        'dynamic_values'  => $dynamicValues,
        'fields'          => [
            'dob'               => $row['dob'] ?? null,
            'guardian_name'     => $row['guardian_name'] ?? null,
            'department'        => $row['department'] ?? null,
            'class'             => $row['class'] ?? null,
            'designation'       => $row['designation'] ?? null,
            'company'           => $row['company'] ?? null,
            'purpose'           => $row['purpose'] ?? null,
            'address'           => $row['address'] ?? null,
            'emergency_contact' => $row['emergency_contact'] ?? null,
            'email'             => $row['email'] ?? null,
            'joined_date'       => $row['joined_date'] ?? null,
            'expiry_date'       => $row['expiry_date'] ?? null,
        ],
    ];
}

$totalCards = count($cards);
$totalOrgs  = count($orgGroups);

/** Small formatting helper for dates already stored as Y-m-d. */
function print_fmt_date($value): string
{
    if (empty($value)) {
        return '';
    }
    $ts = strtotime((string) $value);
    return $ts ? date('d-m-Y', $ts) : htmlspecialchars((string) $value);
}

/** Human label for a field key. */
function print_field_label(string $key): string
{
    $labels = [
        'dob' => 'Date of Birth',
        'guardian_name' => 'Guardian Name',
        'emergency_contact' => 'Phone',
        'joined_date' => 'Joined',
        'expiry_date' => 'Valid Till',
        'unique_id' => 'ID No',
    ];
    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print ID Cards</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-500: #6b7280;
            --neutral-700: #374151;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--neutral-100); margin: 0; color: var(--neutral-700); }

        /* ---------------- Toolbar (screen only) ---------------- */
        .toolbar {
            position: sticky; top: 0; z-index: 50;
            background: #fff; border-bottom: 1px solid var(--neutral-200);
            padding: 0.85rem 1.5rem;
            display: flex; flex-wrap: wrap; gap: 0.75rem;
            align-items: center; justify-content: space-between;
        }
        .toolbar h1 { font-size: 1rem; font-weight: 700; margin: 0; color: var(--primary); }
        .badge-pill {
            display: inline-flex; align-items: center; gap: 0.3rem;
            font-size: 0.72rem; font-weight: 500; padding: 0.2rem 0.6rem;
            border-radius: 9999px; background: var(--neutral-100); color: var(--neutral-700);
            border: 1px solid var(--neutral-200);
        }
        .toolbar .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .btn {
            border: none; border-radius: 0.6rem; padding: 0.5rem 0.9rem;
            font-size: 0.85rem; font-weight: 500; cursor: pointer;
            display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .btn-dark { background: var(--primary); color: #fff; }
        .btn-outline { background: #fff; border: 1px solid var(--neutral-200); color: var(--neutral-700); }
        .btn-outline.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .toolbar label.switch-label { font-size: 0.8rem; display: flex; align-items: center; gap: 0.35rem; }

        .warn-banner {
            background: #fef5e0; color: #92620a; font-size: 0.8rem;
            padding: 0.5rem 1.5rem; border-bottom: 1px solid #f4dfa0;
        }

        .sheet-wrap { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }

        .org-section { margin-bottom: 2.5rem; }
        .org-section-title {
            font-size: 0.95rem; font-weight: 700; color: var(--primary);
            margin: 0 0 0.75rem 2px; display: flex; align-items: center; gap: 0.5rem;
        }
        .org-section-title .count { font-weight: 400; color: var(--neutral-500); font-size: 0.8rem; }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 1.25rem;
            align-items: start;
        }

        .card-slot {
            background: #fff; border-radius: 0.75rem; overflow: visible;
            border: 1px solid var(--neutral-200); box-shadow: 0 1px 3px rgba(0,0,0,.06);
            min-width: 0;
        }
        .card-face-label {
            font-size: 0.68rem;
            color: var(--neutral-500);
            padding: 0.3rem 0.5rem 0;
        }

        .renderer-face {
            --face-w: 5.33cm;
            --face-h: 8.64cm;
            --renderer-w: 533px;
            --renderer-h: 864px;
            --screen-scale: .37795276;
            --rotation: 0deg;
            --mirror-x: 1;
            position: relative;
            display: block;
            width: var(--face-w);
            height: var(--face-h);
            margin: .4rem auto;
            overflow: visible !important;
            flex: 0 0 auto;
            transform-origin: center center;
        }

        .renderer-scale-inner {
            position: absolute;
            left: 50%;
            top: 50%;
            width: var(--renderer-w);
            height: var(--renderer-h);
            transform-origin: center center;
            transform:
                translate(-50%, -50%)
                rotate(var(--rotation))
                scaleX(var(--mirror-x))
                scale(var(--screen-scale));
        }

        .renderer-scale-inner > .id-card-renderer {
            display: block;
        }

        /* Rotate changes the OUTER layout box too, so the rotated card
           always has enough room and never escapes the white card slot. */
        .renderer-face.print-rotate-landscape {
            width: var(--face-h);
            height: var(--face-w);
        }

        .renderer-face.print-rotate-landscape {
            --rotation: 90deg;
        }

        .renderer-face.print-mirror {
            --mirror-x: -1;
        }

        .renderer-face.print-rotate-landscape.print-mirror {
            --rotation: 90deg;
            --mirror-x: -1;
        }

        @media screen {
            .renderer-face {
                margin: .6rem auto;
            }
        }

        .print-render-error {
            min-width: 260px;
            min-height: 160px;
            padding: 1rem;
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
            font-size: 0.8rem;
        }

        .renderer-empty-back {
            min-width: 260px;
            min-height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--neutral-500);
            border: 1px dashed var(--neutral-300);
            border-radius: 0.5rem;
        }

        /* ---------------- Print rules ---------------- */
        @page { size: A4 portrait; margin: 8mm; }
        @media print {
            /*
             * PRINT LAYOUT
             * One member = one A4 print unit containing FRONT + BACK
             * side-by-side. This prevents the browser from treating the
             * transformed renderer as a separate overflowing page.
             */
            @page {
                size: A4 portrait;
                margin: 8mm;
            }

            html, body {
                width: 100%;
                min-width: 0;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }

            .toolbar, .warn-banner, .card-face-label, .org-section-title {
                display: none !important;
            }

            .sheet-wrap {
                width: 194mm;
                max-width: 194mm;
                padding: 0 !important;
                margin: 0 auto !important;
            }

            .org-section {
                width: 194mm;
                max-width: 194mm;
                margin: 0 !important;
                padding: 0 !important;
                break-after: page;
                page-break-after: always;
            }

            .org-section:last-child {
                break-after: auto;
                page-break-after: auto;
            }

            /*
             * Every member is a single print pair. The pair itself is kept
             * together and centered. Two portrait pairs can fit vertically
             * on one A4; the browser may continue naturally for larger
             * bulk batches.
             */
            .cards-grid {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: flex-start !important;
                gap: 6mm !important;
                width: 194mm !important;
                max-width: 194mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .card-slot {
                width: auto !important;
                min-width: 0 !important;
                max-width: 100% !important;
                display: grid !important;
                grid-template-columns: var(--pair-w) var(--pair-w) !important;
                grid-template-rows: auto !important;
                column-gap: 8mm !important;
                row-gap: 0 !important;
                align-items: start !important;
                justify-content: center !important;
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .renderer-face {
                width: var(--face-w) !important;
                height: var(--face-h) !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                flex: none !important;
                position: relative !important;
                display: block !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }

            .front-face {
                grid-column: 1 !important;
                grid-row: 1 !important;
            }

            .back-face {
                grid-column: 2 !important;
                grid-row: 1 !important;
            }

            /*
             * IMPORTANT: In print, do not use transform:scale() for sizing.
             * Chrome can calculate the transformed overflow as extra pages.
             * `zoom` scales layout itself, so the physical card stays exactly
             * at its configured cm size and remains inside the A4 flow.
             */
            /* Physical card size is controlled by zoom below. Browser print
               dialog should remain at 100% for the configured cm size. */
            .renderer-scale-inner {
                position: relative !important;
                left: auto !important;
                top: auto !important;
                width: var(--renderer-w) !important;
                height: var(--renderer-h) !important;
                margin: 0 !important;
                padding: 0 !important;
                transform: none !important;
                transform-origin: top left !important;
                zoom: var(--screen-scale) !important;
                overflow: visible !important;
            }

            .renderer-face.print-mirror .renderer-scale-inner {
                transform: scaleX(-1) !important;
                transform-origin: center center !important;
            }

            /*
             * Rotate the already correctly-sized physical card. The outer
             * box swaps dimensions so the rotation remains inside the A4
             * layout instead of creating an extra overflow page.
             */
            .renderer-face.print-rotate-landscape {
                width: var(--face-h) !important;
                height: var(--face-w) !important;
            }

            .renderer-face.print-rotate-landscape .renderer-scale-inner {
                position: absolute !important;
                left: 50% !important;
                top: 50% !important;
                width: var(--renderer-w) !important;
                height: var(--renderer-h) !important;
                zoom: var(--screen-scale) !important;
                transform: translate(-50%, -50%) rotate(90deg) !important;
                transform-origin: center center !important;
                transform-box: border-box !important;
            }

            .renderer-face.print-rotate-landscape.print-mirror .renderer-scale-inner {
                transform: translate(-50%, -50%) rotate(90deg) scaleX(-1) !important;
                transform-origin: center center !important;
            }

            .renderer-empty-back {
                min-width: 0 !important;
                min-height: 0 !important;
            }
        }
    </style>
    <?= card_renderer_css() ?>
</head>
<body>

<div class="toolbar">
    <div>
        <h1>Printing: <?= $totalCards ?> card<?= $totalCards === 1 ? '' : 's' ?></h1>
        <div class="d-flex" style="gap:.4rem;margin-top:.35rem;flex-wrap:wrap;">
            <span class="badge-pill"><i class="fas fa-layer-group"></i> <?= $isBulk ? 'Bulk Print' : 'Single Print'; ?></span>
            <span class="badge-pill" id="mirrorBadge"><i class="fas fa-undo"></i> <?= $forceMirror ? 'Mirror ON' : 'Normal (no mirror)'; ?></span>
            <span class="badge-pill"><i class="fas fa-building"></i> <?= $totalOrgs ?> organization(s)</span>
        </div>
    </div>
    <div class="actions">
        <label class="switch-label">
            <input type="checkbox" id="rotateToggle" <?= $rotateParam === true ? 'checked' : ''; ?>>
            Rotate 90°
        </label>
        <button type="button" class="btn btn-outline <?= $forceMirror ? 'active' : '' ?>" id="mirrorToggle">
            <i class="fas fa-undo"></i> <?= $forceMirror ? 'Disable Mirror' : 'Enable Mirror' ?>
        </button>
        <button type="button" class="btn btn-dark" onclick="window.print()">
            <i class="fas fa-print"></i> Print Cards
        </button>
        <button type="button" class="btn btn-outline" onclick="window.close()">
            <i class="fas fa-arrow-left"></i> Back
        </button>
    </div>
</div>

<?php if ($skippedCount > 0): ?>
    <div class="warn-banner">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <?= $skippedCount ?> selected member(s) were skipped — they belong to an organization you don't have access to,
        or were not found.
    </div>
<?php endif; ?>

<div class="sheet-wrap" id="sheetWrap">
    <?php foreach ($orgGroups as $orgId => $group):
        $orgCards = array_values(array_filter($cards, static fn($c) => $c['org_id'] === $orgId));
        if (empty($orgCards)) {
            continue;
        }
    ?>
        <section class="org-section">
            <div class="org-section-title">
                <i class="fas fa-building"></i>
                <?= htmlspecialchars($group['organization_name']) ?>
                <span class="count">— <?= count($orgCards) ?> card(s)</span>
            </div>

            <div class="cards-grid">
                <?php foreach ($orgCards as $card):
                    /*
                     * Use the SAME renderer used by View ID Card / Generate ID
                     * Card. The old print page rebuilt the design with fixed
                     * positions, which caused the printed card to differ from
                     * the saved template.
                     */
                    try {
                        ensure_card_renderer_schema($pdo);

                        $templateId = (int)($card['template_id'] ?? 0);
                        if ($templateId <= 0) {
                            throw new RuntimeException('No template assigned to this member.');
                        }

                        $printTemplate = card_renderer_template($pdo, $templateId, true);
                        if (!$printTemplate || (int)($printTemplate['id'] ?? 0) !== $templateId) {
                            throw new RuntimeException('Template not found.');
                        }

                        $printMember = card_renderer_member($pdo, (int)$card['id']);
                        $printDefinitions = card_renderer_definitions($pdo, $templateId);
                        $printLayout = card_renderer_layout($pdo, $templateId);

                        $printOrientation = strtolower(
                            (string)($printTemplate['orientation'] ?? $card['orientation'] ?? 'portrait')
                        ) === 'landscape' ? 'landscape' : 'portrait';

                        $frontHtml = card_renderer_html(
                            $printTemplate,
                            $printMember,
                            $printDefinitions,
                            $printLayout,
                            'front',
                            '../'
                        );

                        $backHtml = card_renderer_html(
                            $printTemplate,
                            $printMember,
                            $printDefinitions,
                            $printLayout,
                            'back',
                            '../'
                        );
                    } catch (Throwable $e) {
                        $printOrientation = 'portrait';
                        $frontHtml = '<div class="print-render-error">' .
                            htmlspecialchars($e->getMessage()) . '</div>';
                        $backHtml = '';
                    }
                    $initialRotate = ($rotateParam === true);
                    $initialMirror = $forceMirror;
                    $initialPairW = $initialRotate
                        ? (float)$card['card_h_cm']
                        : (float)$card['card_w_cm'];
                    $initialClasses = trim(
                        ($initialRotate ? ' print-rotate-landscape' : '') .
                        ($initialMirror ? ' print-mirror' : '')
                    );
                ?>
                    <div class="card-slot renderer-card-slot"
                         data-member-id="<?= (int)$card['id'] ?>"
                         data-orientation="<?= htmlspecialchars($printOrientation) ?>"
                         style="--pair-w:<?= number_format($initialPairW, 4, '.', '') ?>cm;">

                        <div class="card-face-label">
                            <?= htmlspecialchars($card['name']) ?> — Front
                        </div>

                        <div class="renderer-face front-face<?= $initialClasses ?>"
                             style="--face-w:<?= htmlspecialchars((string)$card['card_w_cm']) ?>cm;--face-h:<?= htmlspecialchars((string)$card['card_h_cm']) ?>cm;--renderer-w:<?= (int)$card['renderer_w'] ?>px;--renderer-h:<?= (int)$card['renderer_h'] ?>px;--screen-scale:<?= number_format(((float)$card['card_w_cm'] * 37.7952755906) / max(1,(int)$card['renderer_w']), 6, '.', '') ?>;">
                            <div class="renderer-scale-inner">
                                <?= $frontHtml ?>
                            </div>
                        </div>

                        <div class="card-face-label">Back</div>

                        <?php if ($backHtml !== ''): ?>
                            <div class="renderer-face back-face<?= $initialClasses ?>"
                                 style="--face-w:<?= htmlspecialchars((string)$card['card_w_cm']) ?>cm;--face-h:<?= htmlspecialchars((string)$card['card_h_cm']) ?>cm;--renderer-w:<?= (int)$card['renderer_w'] ?>px;--renderer-h:<?= (int)$card['renderer_h'] ?>px;--screen-scale:<?= number_format(((float)$card['card_w_cm'] * 37.7952755906) / max(1,(int)$card['renderer_w']), 6, '.', '') ?>;">
                                <div class="renderer-scale-inner">
                                    <?= $backHtml ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="renderer-face renderer-empty-back">
                                No back-side layout configured
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script>
    // ------------------------------------------------------------------
    // Mirror + rotate controls
    // ------------------------------------------------------------------
    let mirrorOn = <?= $forceMirror ? 'true' : 'false' ?>;
    const mirrorBtn = document.getElementById('mirrorToggle');
    const mirrorBadge = document.getElementById('mirrorBadge');
    const rotateToggle = document.getElementById('rotateToggle');

    function applyPrintState() {
        document.querySelectorAll('.renderer-card-slot').forEach(function(slot) {
            const rotated = rotateToggle.checked;

            slot.style.setProperty(
                '--pair-w',
                rotated
                    ? getComputedStyle(slot.querySelector('.renderer-face')).getPropertyValue('--face-h')
                    : getComputedStyle(slot.querySelector('.renderer-face')).getPropertyValue('--face-w')
            );

            slot.querySelectorAll('.renderer-face').forEach(function(face) {
                face.classList.toggle('print-mirror', mirrorOn);
                face.classList.toggle('print-rotate-landscape', rotated);
            });
        });
        mirrorBtn.classList.toggle('active', mirrorOn);
        mirrorBtn.innerHTML = '<i class="fas fa-undo"></i> ' + (mirrorOn ? 'Disable Mirror' : 'Enable Mirror');
        mirrorBadge.innerHTML = '<i class="fas fa-undo"></i> ' + (mirrorOn ? 'Mirror ON' : 'Normal (no mirror)');
    }

    mirrorBtn.addEventListener('click', function() {
        mirrorOn = !mirrorOn;
        applyPrintState();
    });
    rotateToggle.addEventListener('change', applyPrintState);

    // Rotate 90° is enabled from the URL (?rotate=1) or by the checkbox.
    applyPrintState();

    // ------------------------------------------------------------------
    // QR codes
    // ------------------------------------------------------------------
    document.querySelectorAll('.qr-box').forEach(function (box) {
        const value = box.dataset.qrValue || '';
        if (value && window.QRCode) {
            new QRCode(box, { text: value, width: 90, height: 90, correctLevel: QRCode.CorrectLevel.M });
        }
    });

    // ------------------------------------------------------------------
    // Barcodes
    // ------------------------------------------------------------------
    document.querySelectorAll('.barcode-svg').forEach(function (svg) {
        const value = svg.dataset.barcodeValue || '';
        if (value && window.JsBarcode) {
            try {
                JsBarcode(svg, value, { format: 'CODE128', displayValue: false, height: 40, margin: 0 });
            } catch (e) { /* ignore invalid characters for barcode */ }
        }
    });
</script>
</body>
</html>