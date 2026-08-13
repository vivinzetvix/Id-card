<?php
/**
 * Print ID cards using the shared card_renderer (same as Generate / Template Designer).
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);
if (!$authUser) {
    header('Location: ../index.php');
    exit();
}
require_permission($pdo, 'Generate ID', 'Print');

$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId = (int)($authUser['organization_id'] ?? 0);

$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$memberIds = isset($_GET['ids']) ? array_filter(array_map('intval', explode(',', (string)$_GET['ids']))) : [];
$bulkPrint = isset($_GET['bulk']);
// Mirror ONLY when explicitly requested — never auto from template.mirror_print
$mirrorPrint = isset($_GET['mirror']) && (string)$_GET['mirror'] !== '0';
$orientationOverride = isset($_GET['orientation']) ? (string)$_GET['orientation'] : 'auto';
$orgIdFilter = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;

if ($memberId > 0) {
    $memberIds = [$memberId];
}
$memberIds = array_values(array_unique(array_filter($memberIds)));

if (empty($memberIds)) {
    $_SESSION['error'] = 'No members selected for printing.';
    header('Location: ../Members/view_members.php');
    exit();
}

$placeholders = implode(',', array_fill(0, count($memberIds), '?'));
$sql = "SELECT m.id, m.organization_id, m.template_id, m.name, m.unique_id, m.expiry_date,
               o.organization_name
        FROM id_members m
        LEFT JOIN organizations o ON m.organization_id = o.id
        WHERE m.id IN ($placeholders) AND m.deleted_at IS NULL";
$params = $memberIds;

if ($orgIdFilter > 0) {
    $sql .= ' AND m.organization_id = ?';
    $params[] = $orgIdFilter;
} elseif (!$isSuperAdmin) {
    $sql .= ' AND m.organization_id = ?';
    $params[] = $userOrgId;
}

$sql .= ' ORDER BY o.organization_name, m.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$members = [];
foreach ($rows as $row) {
    if (!user_can_access_organization($authUser, $row['organization_id'] ?? null)) {
        continue;
    }
    $members[] = $row;
}

if (empty($members)) {
    $_SESSION['error'] = 'No valid members found for printing.';
    header('Location: ../Members/view_members.php');
    exit();
}

$orgIdsInBatch = array_unique(array_map(static fn($m) => (int)($m['organization_id'] ?? 0), $members));
if (!$isSuperAdmin && count($orgIdsInBatch) > 1) {
    $_SESSION['error'] = 'Bulk print cannot mix organizations.';
    header('Location: ../Members/view_members.php');
    exit();
}

ensure_card_renderer_schema($pdo);

$printCards = [];
foreach ($members as $row) {
    $tplId = (int)($row['template_id'] ?? 0);
    if ($tplId <= 0) {
        continue;
    }
    // Assigned template may be archived — still printable if owned/global
    try {
        $template = card_renderer_template($pdo, $tplId, true);
        $tplOrg = (int)($template['organization_id'] ?? 0);
        $memberOrg = (int)($row['organization_id'] ?? 0);
        if ($tplOrg !== 0 && $tplOrg !== $memberOrg) {
            continue;
        }

        $memberData = card_renderer_member($pdo, (int)$row['id']);
        $definitions = card_renderer_definitions($pdo, $tplId);
        $layout = card_renderer_layout($pdo, $tplId);

        $orient = strtolower((string)($template['orientation'] ?? 'portrait'));
        if ($orientationOverride === 'landscape' || $orientationOverride === 'portrait') {
            $orient = $orientationOverride;
        }

        $cardW = max(50, (int)($template['card_width'] ?? ($orient === 'landscape' ? 864 : 533)));
        $cardH = max(50, (int)($template['card_height'] ?? ($orient === 'landscape' ? 533 : 864)));

        $printCards[] = [
            'organization_id' => (int)($row['organization_id'] ?? 0),
            'organization_name' => $row['organization_name'] ?? 'Unassigned',
            'member_name' => $row['name'] ?? '',
            'orientation' => $orient,
            'mirror' => $mirrorPrint,
            'card_width' => $cardW,
            'card_height' => $cardH,
            'front' => card_renderer_html($template, $memberData, $definitions, $layout, 'front', '../'),
            'back' => card_renderer_html($template, $memberData, $definitions, $layout, 'back', '../'),
        ];
    } catch (Throwable $e) {
        continue;
    }
}

if (empty($printCards)) {
    $_SESSION['error'] = 'No printable cards could be rendered for the selected members.';
    header('Location: ../Members/view_members.php');
    exit();
}

$membersByOrg = [];
foreach ($printCards as $card) {
    $key = $card['organization_id'];
    if (!isset($membersByOrg[$key])) {
        $membersByOrg[$key] = [
            'organization_name' => $card['organization_name'],
            'cards' => [],
        ];
    }
    $membersByOrg[$key]['cards'][] = $card;
}

try {
    $orgForAudit = count($orgIdsInBatch) === 1 ? (int)reset($orgIdsInBatch) : null;
    $details = ($bulkPrint || count($printCards) > 1)
        ? 'Bulk printed ' . count($printCards) . ' card(s)'
        : 'Printed card for member ' . ($printCards[0]['member_name'] ?? '');
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (user_id, organization_id, action, action_type, details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)($authUser['id'] ?? 0) ?: null,
        $orgForAudit,
        $bulkPrint || count($printCards) > 1 ? 'Bulk Printed' : 'Card Printed',
        'cards',
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
} catch (Throwable $e) {
    // non-blocking
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print ID Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?= card_renderer_css() ?>
    <style>
        @page { size: A4; margin: 0.6cm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f0f0; padding: 20px; margin: 0; color: #111; }
        .print-controls {
            background: #fff; padding: 15px 20px; border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.1); margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;
        }
        .btn {
            padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px;
            cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: #0a1a2f; color: #fff; }
        .btn-outline { background: #fff; border: 1px solid #d1d5db; color: #374151; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #e5e7eb; font-size: 12px; }
        .org-section { margin-bottom: 28px; }
        .org-section-title {
            font-weight: 700; margin-bottom: 12px; padding: 8px 12px; background: #f3f4f6; border-radius: 6px;
        }
        .org-cards { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
        .print-card-wrap {
            background: #fff; padding: 12px; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08); break-inside: avoid;
            page-break-inside: avoid;
        }
        .print-card-wrap .card-side-label {
            font-size: 11px; color: #6b7280; margin: 8px 0 6px;
        }
        .print-card-wrap .card-side-label:first-child { margin-top: 0; }

        /* Keep designer pixel size — same as Generate preview (no forced cm resize) */
        .print-card-wrap .id-card-renderer {
            display: block;
            margin: 0;
            box-shadow: none;
        }

        .print-scale-frame {
            position: relative;
            overflow: hidden;
            background: #fff;
        }

        .print-scale-inner {
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }

        /* Explicit mirror only */
        .print-card-wrap.mirror .id-card-renderer {
            transform: scaleX(-1);
        }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .print-card-wrap {
                box-shadow: none;
                padding: 0;
                margin: 0 0 0.4cm 0;
                background: transparent;
            }
            /* Print at true designer size (fonts/layout match Generate) */
            .print-scale-frame {
                width: auto !important;
                height: auto !important;
                overflow: visible !important;
            }
            .print-scale-inner {
                position: static !important;
                transform: none !important;
            }
            .org-section { page-break-after: always; }
            .org-section:last-child { page-break-after: auto; }
            .print-side {
                page-break-inside: avoid;
                break-inside: avoid;
                margin-bottom: 0.35cm;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <strong>Printing: <?= count($printCards) ?> card(s)</strong>
            <?php if (count($printCards) > 1): ?><span class="badge">Bulk Print</span><?php endif; ?>
            <?php if ($mirrorPrint): ?><span class="badge">Mirror ON</span><?php else: ?><span class="badge">Normal (no mirror)</span><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary" type="button" onclick="window.print()">
                <i class="fas fa-print"></i> Print Cards
            </button>
            <?php if (!$mirrorPrint): ?>
                <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['mirror' => '1']))) ?>">
                    <i class="fas fa-exchange-alt"></i> Enable Mirror
                </a>
            <?php else: ?>
                <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['mirror' => 1]))) ?>">
                    <i class="fas fa-undo"></i> Disable Mirror
                </a>
            <?php endif; ?>
            <a class="btn btn-outline" href="../Members/view_members.php">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div id="cardsContainer">
        <?php foreach ($membersByOrg as $orgKey => $orgData): ?>
            <div class="org-section">
                <div class="org-section-title no-print">
                    <i class="fas fa-building"></i>
                    <?= htmlspecialchars($orgData['organization_name']) ?>
                    <span class="badge"><?= count($orgData['cards']) ?> cards</span>
                </div>
                <div class="org-cards">
                    <?php foreach ($orgData['cards'] as $card):
                        $cardW = (int)$card['card_width'];
                        $cardH = (int)$card['card_height'];
                        // Screen fit only — print uses native designer size
                        $screenScale = min(1, 420 / max(1, $cardW));
                        $scaledW = (int)round($cardW * $screenScale);
                        $scaledH = (int)round($cardH * $screenScale);
                        $wrapClass = trim(
                            ($card['orientation'] === 'landscape' ? 'landscape' : 'portrait')
                            . ($card['mirror'] ? ' mirror' : '')
                        );
                    ?>
                        <div class="print-card-wrap <?= htmlspecialchars($wrapClass) ?>">
                            <div class="card-side-label no-print"><?= htmlspecialchars($card['member_name']) ?> — Front</div>
                            <div class="print-side">
                                <div class="print-scale-frame" style="width:<?= $scaledW ?>px;height:<?= $scaledH ?>px;">
                                    <div class="print-scale-inner" style="width:<?= $cardW ?>px;height:<?= $cardH ?>px;transform:scale(<?= number_format($screenScale, 4, '.', '') ?>);">
                                        <?= $card['front'] ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-side-label no-print">Back</div>
                            <div class="print-side">
                                <div class="print-scale-frame" style="width:<?= $scaledW ?>px;height:<?= $scaledH ?>px;">
                                    <div class="print-scale-inner" style="width:<?= $cardW ?>px;height:<?= $cardH ?>px;transform:scale(<?= number_format($screenScale, 4, '.', '') ?>);">
                                        <?= $card['back'] ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        window.addEventListener('load', function () {
            if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
                window.print();
            }
        });
    </script>
</body>
</html>
