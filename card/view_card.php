<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);
if (!$authUser) {
    header('Location: ../index.php');
    exit();
}

$page_title = 'View ID Card';
$message = '';
$error = '';

$username = $_SESSION['username'];
$isSuperAdmin = auth_is_super_admin($authUser);

$cardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cardId <= 0) {
    $_SESSION['error'] = 'Invalid card ID';
    header('Location: ../dashboard.php');
    exit();
}

// Prefer generated_cards.organization_id; fall back to member org (legacy NULL rows)
$sql = "SELECT g.*,
        m.id as member_id,
        m.name as member_name,
        m.unique_id,
        m.member_type,
        m.expiry_date,
        m.organization_id AS member_organization_id,
        COALESCE(g.organization_id, m.organization_id) AS effective_organization_id,
        t.name as template_name,
        t.orientation,
        t.primary_color,
        t.secondary_color,
        t.text_color,
        t.font,
        t.card_width,
        t.card_height,
        o.organization_name,
        o.logo as org_logo
        FROM generated_cards g
        LEFT JOIN id_members m ON g.member_id = m.id
        LEFT JOIN card_templates t ON g.template_id = t.id
        LEFT JOIN organizations o ON COALESCE(g.organization_id, m.organization_id) = o.id
        WHERE g.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$cardId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    $_SESSION['error'] = 'Card not found';
    header('Location: ../dashboard.php');
    exit();
}

if (!user_can_access_organization($authUser, $card['effective_organization_id'] ?? null)) {
    $_SESSION['error'] = 'You do not have access to this card';
    header('Location: ../dashboard.php');
    exit();
}

// Get card image path
$cardImagePath = '';
if (!empty($card['image_path'])) {
    $relative = ltrim(str_replace('\\', '/', (string)$card['image_path']), '/');
    $absolute = realpath(__DIR__ . '/../' . $relative);
    $cardsRoot = realpath(__DIR__ . '/../images/cards');
    if ($absolute && $cardsRoot && str_starts_with($absolute, $cardsRoot) && is_file($absolute)) {
        $cardImagePath = '../' . $relative;
    } elseif (is_file(__DIR__ . '/../' . $relative)) {
        // Allow legacy paths under images/ while still using basename-safe relative URL
        $cardImagePath = '../' . $relative;
    }
}

$printMode = isset($_GET['print']);

// Live render for correct scaled preview (matches Template Designer / Generate)
$viewHtmlFront = '';
$viewHtmlBack = '';
$viewCardW = 533;
$viewCardH = 864;
$viewScale = 0.55;
$memberIdForView = (int)($card['member_id'] ?? 0);
$templateIdForView = (int)($card['template_id'] ?? 0);
if ($memberIdForView > 0 && $templateIdForView > 0) {
    try {
        ensure_card_renderer_schema($pdo);
        $viewTemplate = card_renderer_template($pdo, $templateIdForView, true);
        $viewMember = card_renderer_member($pdo, $memberIdForView);
        $viewDefs = card_renderer_definitions($pdo, $templateIdForView);
        $viewLayout = card_renderer_layout($pdo, $templateIdForView);
        $viewHtmlFront = card_renderer_html($viewTemplate, $viewMember, $viewDefs, $viewLayout, 'front', '../');
        $viewHtmlBack = card_renderer_html($viewTemplate, $viewMember, $viewDefs, $viewLayout, 'back', '../');
        $viewCardW = max(50, (int)($viewTemplate['card_width'] ?? 533));
        $viewCardH = max(50, (int)($viewTemplate['card_height'] ?? 864));
        $orient = strtolower((string)($viewTemplate['orientation'] ?? ($card['orientation'] ?? 'portrait')));
        $boxW = $orient === 'landscape' ? 640 : 360;
        $boxH = $orient === 'landscape' ? 400 : 584;
        $viewScale = min($boxW / $viewCardW, $boxH / $viewCardH);
    } catch (Throwable $e) {
        $viewHtmlFront = '';
        $viewHtmlBack = '';
    }
}

function getStatusBadge($expiry_date) {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));

    if (!$expiry_date) {
        return '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
    }

    if ($expiry_date >= $today) {
        if ($expiry_date <= $next30) {
            return '<span class="status-badge expiring"><i class="fas fa-clock"></i>Expiring Soon</span>';
        }
        return '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
    }

    return '<span class="status-badge expired"><i class="fas fa-exclamation-circle"></i>Expired</span>';
}

function getMemberTypeLabel($type) {
    $labels = [
        'student' => 'Student',
        'employee' => 'Employee',
        'staff' => 'Staff',
        'faculty' => 'Faculty',
        'visitor' => 'Visitor',
        'office' => 'Office'
    ];
    return $labels[$type] ?? ucfirst($type);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View ID Card · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #f4b740;
            --warning-soft: #fef5e0;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-500: #6b7280;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --radius-lg: 0.75rem;
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
        .alert-success { background: var(--success-soft); color: var(--success); }

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
            background: var(--neutral-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header-custom h5 {
            font-weight: 600;
            margin: 0;
            color: var(--neutral-800);
        }
        .card-header-custom h5 i { color: var(--primary); margin-right: 0.5rem; }

        .card-body-custom { padding: 1.5rem; }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            gap: 0.25rem;
        }
        .status-badge.active { background: var(--success-soft); color: var(--success); }
        .status-badge.expiring { background: var(--warning-soft); color: var(--warning); }
        .status-badge.expired { background: var(--danger-soft); color: var(--danger); }

        /* Buttons */
        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #e0a832; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        /* Card Display */
        .card-display {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 1.5rem;
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            min-height: 400px;
            overflow: auto;
        }

        .view-card-stage {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            width: 100%;
        }

        .view-card-frame {
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }

        .view-card-scale-wrap {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #fff;
        }

        .view-card-scale-inner {
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
        }

        .card-image {
            max-width: 100%;
            max-height: 600px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--neutral-200);
        }

        .card-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            padding: 0.75rem;
            background: var(--neutral-50);
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-200);
        }

        .info-item .label {
            font-size: 0.688rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
        }

        .info-item .value {
            font-size: 0.938rem;
            font-weight: 500;
            color: var(--neutral-800);
            margin-top: 0.15rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .card-header-custom { flex-direction: column; align-items: stretch; text-align: center; }
            .action-buttons { justify-content: center; }
            .card-display { min-height: 200px; padding: 1rem; }
            .card-info-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; justify-content: center; }
        }

        /* Print options */
        .print-options {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
            border-radius: var(--radius-lg);
        }
        .print-options-title {
            font-size: .78rem;
            font-weight: 700;
            color: var(--neutral-700);
            margin-bottom: .65rem;
            display: flex;
            align-items: center;
            gap: .45rem;
        }
        .print-options-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .5rem;
        }
        .print-options-grid .btn {
            justify-content: center;
            min-height: 42px;
        }
        .print-note {
            margin-top: .6rem;
            font-size: .72rem;
            line-height: 1.45;
            color: var(--neutral-500);
        }
        @media (max-width: 576px) {
            .print-options-grid { grid-template-columns: 1fr; }
        }

        /* Print styles */
        @media print {
            .sidebar, .top-header, .breadcrumb, .card-header-custom, .no-print {
                display: none !important;
            }
            .main-content { margin-left: 0 !important; }
            .dashboard-content { padding: 0 !important; }
            .main-card { box-shadow: none !important; border: none !important; }
            .card-body-custom { padding: 0 !important; }
            .card-display { background: white !important; padding: 0 !important; min-height: auto !important; }
            .card-image { max-height: 800px !important; }
            .card-info-grid { display: none !important; }
        }
        .preview-container{
    display: flex;
    justify-content: center;
    align-items: flex-start;
    gap: 30px;
    flex-wrap: wrap;
}

.preview-card{
    flex: 0 0 auto;
}.preview-card{
    display: block;
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
                        <li class="breadcrumb-item"><a href="../Members/view_members.php">Members</a></li>
                        <li class="breadcrumb-item active" aria-current="page">View Card</li>
                    </ol>
                </nav>

                <?php if (isset($_GET['generated'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1">ID Card generated successfully! You can now print or download it.</div>
                    </div>
                <?php endif; ?>

                <div class="main-card">
                    <div class="card-header-custom">
                        <div>
                            <h5><i class="fas fa-id-card text-primary me-2"></i>ID Card Details</h5>
                            <div class="d-flex gap-2 mt-1 flex-wrap">
                                <?= getStatusBadge($card['expiry_date'] ?? null) ?>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-user me-1"></i>
                                    <?= getMemberTypeLabel($card['member_type'] ?? '') ?>
                                </span>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-paint-brush me-1"></i>
                                    <?= htmlspecialchars($card['template_name'] ?? 'Default') ?>
                                </span>
                            </div>
                        </div>
<div class="action-buttons no-print">
    <button type="button" class="btn btn-warning" onclick="openPrintPage('normal')">
        <i class="fas fa-print"></i> Print Card
    </button>
    <button type="button" class="btn btn-primary" onclick="openPrintPage('mirror')">
        <i class="fas fa-undo"></i> Mirror Print
    </button>
    <button type="button" class="btn btn-dark" onclick="openPrintPage('rotate')">
        <i class="fas fa-rotate-right"></i> Rotate 90°
    </button>
    <a href="download_card.php?id=<?= $cardId ?>" class="btn btn-success">
        <i class="fas fa-download"></i> Download
    </a>
    <a href="../generate_id_card.php?member_id=<?= $card['member_id'] ?>" class="btn btn-outline-secondary">
        <i class="fas fa-sync"></i> Regenerate
    </a>
    <a href="allid.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>
                    </div>

                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Card Image -->
                            <div class="col-lg-8">
                                <div class="card-display">
                                    <?php
                                    $scaledViewW = max(120, (int)round($viewCardW * $viewScale));
                                    $scaledViewH = max(120, (int)round($viewCardH * $viewScale));
                                    $viewScaleCss = number_format($viewScale, 4, '.', '');
                                    ?>
                                    <?php if ($viewHtmlFront !== ''): ?>
                                        <?= card_renderer_css() ?>
                                        <div class="view-card-stage">
                                            <div class="view-card-frame" style="width:<?= $scaledViewW ?>px;height:<?= $scaledViewH ?>px;">
                                                <div class="view-card-scale-wrap">
                                                    <div class="view-card-scale-inner"
                                                         style="width:<?= (int)$viewCardW ?>px;height:<?= (int)$viewCardH ?>px;transform:scale(<?= $viewScaleCss ?>);">
                                                        <?= $viewHtmlFront ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($viewHtmlBack !== ''): ?>
                                            <div class="view-card-frame" style="width:<?= $scaledViewW ?>px;height:<?= $scaledViewH ?>px;">
                                                <div class="view-card-scale-wrap">
                                                    <div class="view-card-scale-inner"
                                                         style="width:<?= (int)$viewCardW ?>px;height:<?= (int)$viewCardH ?>px;transform:scale(<?= $viewScaleCss ?>);">
                                                        <?= $viewHtmlBack ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($cardImagePath): ?>
                                        <?php
                                        $ext = strtolower(pathinfo($cardImagePath, PATHINFO_EXTENSION));
                                        $isHtmlCard = in_array($ext, ['html', 'htm'], true);
                                        ?>
                                        <?php if ($isHtmlCard): ?>
                                            <iframe src="<?= htmlspecialchars($cardImagePath, ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Generated ID card for <?= htmlspecialchars($card['member_name'] ?? 'member', ENT_QUOTES, 'UTF-8') ?>"
                                                    id="cardImage"
                                                    style="width:100%;min-height:620px;border:0;background:#fff;border-radius:8px;"></iframe>
                                        <?php else: ?>
                                            <img src="<?= htmlspecialchars($cardImagePath, ENT_QUOTES, 'UTF-8') ?>"
                                                 class="card-image"
                                                 alt="Generated ID card for <?= htmlspecialchars($card['member_name'] ?? 'member', ENT_QUOTES, 'UTF-8') ?>"
                                                 id="cardImage">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-5">
                                            <i class="fas fa-id-card" style="font-size:4rem;display:block;margin-bottom:1rem;"></i>
                                            <p>Card image file was not found. Click “Regenerate” to create a new card.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Card Information -->
                            <div class="col-lg-4">
                                <h6 class="fw-bold mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Card Information</h6>
                                <div class="card-info-grid">
                                    <div class="info-item">
                                        <div class="label">Member Name</div>
                                        <div class="value"><?= htmlspecialchars($card['member_name'] ?? '—') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Unique ID</div>
                                        <div class="value"><?= htmlspecialchars($card['unique_id'] ?? '—') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Organization</div>
                                        <div class="value"><?= htmlspecialchars($card['organization_name'] ?? '—') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Template</div>
                                        <div class="value"><?= htmlspecialchars($card['template_name'] ?? '—') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Orientation</div>
                                        <div class="value">
                                            <span class="badge <?= ($card['orientation'] ?? 'portrait') === 'landscape' ? 'bg-info' : 'bg-secondary' ?>">
                                                <?= ucfirst($card['orientation'] ?? 'Portrait') ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Generated On</div>
                                        <div class="value"><?= date('M d, Y g:i A', strtotime($card['created_at'])) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Expiry Date</div>
                                        <div class="value">
                                            <?= !empty($card['expiry_date']) ? date('M d, Y', strtotime($card['expiry_date'])) : 'No expiry' ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="label">Card ID</div>
                                        <div class="value"><code>#<?= $cardId ?></code></div>
                                    </div>
                                </div>

                                <div class="print-options no-print">
    <div class="print-options-title">
        <i class="fas fa-print text-primary"></i> Print Options
    </div>
    <div class="print-options-grid">
        <button type="button" class="btn btn-warning" onclick="openPrintPage('normal')">
            <i class="fas fa-print"></i> Normal Print
        </button>
        <button type="button" class="btn btn-primary" onclick="openPrintPage('mirror')">
            <i class="fas fa-undo"></i> Mirror Print
        </button>
        <button type="button" class="btn btn-dark" onclick="openPrintPage('rotate')">
            <i class="fas fa-rotate-right"></i> Rotate 90°
        </button>
        <button type="button" class="btn btn-success" onclick="openPrintPage('rotate-mirror')">
            <i class="fas fa-arrows-rotate"></i> Rotate + Mirror
        </button>
    </div>
    <div class="print-note">
        Print uses the same saved template layout shown here. Landscape cards can be rotated 90° without changing the design. Mirror applies to both front and back.
    </div>
</div>
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
        // Open the dedicated print page so the dashboard UI itself is never printed.
        function openPrintPage(mode) {
            const memberId = <?= (int)($card['member_id'] ?? 0) ?>;
            if (!memberId) return;

            const params = new URLSearchParams();
            params.set('id', memberId);

            switch (mode) {
                case 'mirror':
                    params.set('mirror', '1');
                    params.set('rotate', '0');
                    break;
                case 'rotate':
                    params.set('mirror', '0');
                    params.set('rotate', '1');
                    break;
                case 'rotate-mirror':
                    params.set('mirror', '1');
                    params.set('rotate', '1');
                    break;
                default:
                    params.set('mirror', '0');
                    params.set('rotate', '0');
            }

            window.open('print_id_card.php?' + params.toString(), '_blank', 'noopener');
        }

        function printCard() {
            openPrintPage('normal');
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
                e.preventDefault();
                printCard();
            }

            if (e.key === 'Escape') {
                window.location.href = 'allid.php';
            }
        });

        <?php if ($printMode): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                openPrintPage('normal');
            }, 250);
        });
        <?php endif; ?>
    </script>
</body>
</html>