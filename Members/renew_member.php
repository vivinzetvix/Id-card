<?php
/**
 * Renew Member — extend the expiry date of an existing member.
 * Preserves all existing generated cards and member data.
 * Follows the same design conventions as add_member.php, view_member.php, etc.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_helpers.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Members', 'Edit');

$page_title = 'Renew Member';
$isSuperAdmin = auth_is_super_admin($authUser);
$userId = (int)($authUser['id'] ?? $_SESSION['user_id'] ?? 0);
$userOrgId = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($memberId <= 0) {
    $_SESSION['member_error'] = 'Invalid member ID';
    header('Location: view_members.php');
    exit();
}

// ─── Load member ─────────────────────────────────────────────────────────────
$member = fetch_member_for_user($pdo, $authUser, $memberId);
if (!$member) {
    $_SESSION['member_error'] = 'Member not found or access denied';
    header('Location: view_members.php');
    exit();
}

// Check if member is already expired or about to expire
$currentExpiry = $member['expiry_date'] ?? null;
$isExpired = $currentExpiry && strtotime($currentExpiry) < strtotime(date('Y-m-d'));
$expiringSoon = $currentExpiry && strtotime($currentExpiry) < strtotime('+30 days');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$errors = [];
$formData = [
    'expiry_date' => '',
    'notes' => '',
];

// Default new expiry: +1 year from today, or +1 year from current expiry if it's in the future
$defaultNewExpiry = date('Y-m-d', strtotime('+1 year'));
if ($currentExpiry && strtotime($currentExpiry) > strtotime(date('Y-m-d'))) {
    $defaultNewExpiry = date('Y-m-d', strtotime($currentExpiry . ' +1 year'));
}

$memberName = htmlspecialchars($member['name'] ?? '');
$memberUniqueId = htmlspecialchars($member['unique_id'] ?? '');

// ─── Handle form submission ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_member'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $newExpiry = trim((string)($_POST['expiry_date'] ?? ''));
        $formData['notes'] = trim((string)($_POST['notes'] ?? ''));
        $formData['expiry_date'] = $newExpiry;

        // Validate expiry date
        if (empty($newExpiry)) {
            $errors[] = 'Expiry date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiry)) {
            $errors[] = 'Invalid date format. Please use YYYY-MM-DD.';
        } elseif (strtotime($newExpiry) < strtotime(date('Y-m-d'))) {
            $errors[] = 'Expiry date cannot be in the past.';
        }

        // Check if new expiry is actually an extension
        if (empty($errors) && $currentExpiry) {
            $currentTimestamp = strtotime($currentExpiry);
            $newTimestamp = strtotime($newExpiry);
            if ($newTimestamp <= $currentTimestamp) {
                $errors[] = 'New expiry date must be later than the current expiry date (' . date('M d, Y', $currentTimestamp) . ').';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $oldExpiry = $member['expiry_date'] ?? null;

                // Update the member's expiry date
                $stmt = $pdo->prepare('
                    UPDATE id_members 
                    SET expiry_date = ?, updated_at = NOW() 
                    WHERE id = ? AND deleted_at IS NULL
                ');
                $stmt->execute([$newExpiry, $memberId]);

                // Log the renewal in audit trail
                if (function_exists('member_log_audit')) {
                    member_log_audit(
                        $pdo,
                        $userId,
                        (int)($member['organization_id'] ?? 0) ?: null,
                        'Member Renewed',
                        "Member ID: {$memberId}, Name: {$member['name']}, " .
                        "Old expiry: " . ($oldExpiry ?: 'None') . ", New expiry: {$newExpiry}" .
                        ($formData['notes'] ? ", Notes: {$formData['notes']}" : '')
                    );
                }

                // If there's a member_renewals table, log the renewal there
                // This is optional but good for tracking renewal history
                try {
                    $renewalStmt = $pdo->prepare('
                        INSERT INTO member_renewals (member_id, old_expiry_date, new_expiry_date, renewed_by, notes, renewed_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ');
                    $renewalStmt->execute([
                        $memberId,
                        $oldExpiry,
                        $newExpiry,
                        $userId ?: null,
                        $formData['notes'] ?: null,
                    ]);
                } catch (PDOException $e) {
                    // Table might not exist — silently continue
                }

                $pdo->commit();

                $_SESSION['member_message'] = 'Member "' . $member['name'] . '" renewed successfully until ' . date('M d, Y', strtotime($newExpiry)) . '.';
                header('Location: view_member.php?id=' . $memberId);
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Failed to renew member. Please try again.';
                // Log error for debugging
                error_log('Member renewal failed: ' . $e->getMessage());
            }
        }
    }
}

// ─── Generate CSRF token ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper for formatting dates
function renew_format_date($date) {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Renew Member · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

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
            padding: 0;
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
            margin: 0 0 1.5rem 0;
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
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-warning { background: var(--warning-soft); color: #b45309; }
        .alert-info { background: var(--info-soft); color: var(--info); }
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
        .card-header-custom h5 i {
            color: var(--success);
            margin-right: 0.5rem;
        }

        .card-body-custom { padding: 1.5rem; }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--neutral-700);
            margin-bottom: 0.25rem;
        }
        .form-label .required { color: var(--danger); }

        .form-control, .form-select {
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            width: 100%;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10,26,47,0.1);
            outline: none;
        }
        .form-control:disabled, .form-control[readonly] {
            background: var(--neutral-100);
            cursor: not-allowed;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-top: 0.25rem;
        }

        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }
        .section-title i { margin-right: 0.5rem; }

        .member-info-card {
            background: var(--neutral-50);
            border-radius: var(--radius-lg);
            padding: 1rem;
            border: 1px solid var(--neutral-200);
        }
        .member-info-card .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            border-bottom: 1px solid var(--neutral-200);
        }
        .member-info-card .info-row:last-child { border-bottom: none; }
        .member-info-card .info-label {
            font-weight: 500;
            color: var(--neutral-600);
            font-size: 0.813rem;
        }
        .member-info-card .info-value {
            font-weight: 500;
            color: var(--neutral-800);
            font-size: 0.813rem;
            text-align: right;
        }
        .member-info-card .info-value.expired {
            color: var(--danger);
        }
        .member-info-card .info-value.expiring-soon {
            color: #b45309;
        }

        .renewal-summary {
            background: var(--info-soft);
            border-radius: var(--radius-lg);
            padding: 1rem;
            border-left: 4px solid var(--info);
        }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }
        .btn-outline-secondary:hover { background: var(--neutral-100); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #b91c1c; }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
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

        .renewal-notes {
            margin-top: 0.5rem;
        }
        .renewal-notes textarea {
            min-height: 60px;
        }

        @media (max-width: 768px) {
            .card-header-custom { flex-direction: column; align-items: stretch; text-align: center; }
            .card-header-custom .btn { width: 100%; justify-content: center; }
            .member-info-card .info-row { flex-direction: column; align-items: flex-start; gap: 0.15rem; }
            .member-info-card .info-value { text-align: left; }
        }

        @media (max-width: 480px) {
            .dashboard-content { padding: 1rem; }
            .card-body-custom { padding: 1rem; }
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
                        <li class="breadcrumb-item"><a href="view_member.php?id=<?= $memberId ?>"><?= $memberName ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Renew</li>
                    </ol>
                </nav>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle mt-1"></i>
                        <div>
                            <strong>Please fix the following:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($isExpired): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>This member's ID has expired.</strong> Renewal will restore active status.
                        </div>
                    </div>
                <?php elseif ($expiringSoon): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>This member's ID is expiring soon.</strong> Expires <?= renew_format_date($currentExpiry) ?>.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="main-card">
                    <div class="card-header-custom">
                        <div>
                            <h5><i class="fas fa-redo"></i>Renew Member</h5>
                            <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                                Extend the membership expiry date for <?= $memberName ?>
                                <span class="badge bg-secondary ms-2">ID: <?= $memberUniqueId ?></span>
                            </p>
                        </div>
                        <a href="view_member.php?id=<?= $memberId ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to Member
                        </a>
                    </div>

                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- Member Information -->
                            <div class="col-md-5">
                                <h6 class="section-title"><i class="fas fa-user"></i>Member Information</h6>
                                <div class="member-info-card">
                                    <div class="info-row">
                                        <span class="info-label">Name</span>
                                        <span class="info-value"><?= $memberName ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Unique ID</span>
                                        <span class="info-value"><?= $memberUniqueId ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Organization</span>
                                        <span class="info-value">
                                            <?php
                                            $orgName = '';
                                            if (!empty($member['organization_id'])) {
                                                $orgStmt = $pdo->prepare('SELECT organization_name FROM organizations WHERE id = ?');
                                                $orgStmt->execute([$member['organization_id']]);
                                                $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC);
                                                $orgName = $orgRow['organization_name'] ?? 'Unknown';
                                            }
                                            echo htmlspecialchars($orgName ?: '—');
                                            ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Current Expiry</span>
                                        <span class="info-value <?= $isExpired ? 'expired' : ($expiringSoon ? 'expiring-soon' : '') ?>">
                                            <?= renew_format_date($currentExpiry) ?>
                                            <?php if ($isExpired): ?>
                                                <span class="status-badge expired">Expired</span>
                                            <?php elseif ($expiringSoon): ?>
                                                <span class="status-badge expiring">Expiring Soon</span>
                                            <?php else: ?>
                                                <span class="status-badge active">Active</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Member Since</span>
                                        <span class="info-value"><?= renew_format_date($member['created_at'] ?? null) ?></span>
                                    </div>
                                </div>

                                <div class="renewal-summary mt-3">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <strong>What happens during renewal?</strong>
                                    <ul class="mb-0 mt-1 small">
                                        <li>Expiry date is extended to your chosen date</li>
                                        <li>All existing generated cards are preserved</li>
                                        <li>Member status is automatically updated</li>
                                        <li>Renewal is logged in the audit trail</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Renewal Form -->
                            <div class="col-md-7">
                                <h6 class="section-title"><i class="fas fa-calendar-plus"></i>Renewal Details</h6>

                                <form method="POST" id="renewForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="renew_member" value="1">

                                    <div class="mb-3">
                                        <label class="form-label">New Expiry Date <span class="required">*</span></label>
                                        <input type="date" name="expiry_date" id="expiryDate" class="form-control"
                                               required
                                               min="<?= date('Y-m-d') ?>"
                                               value="<?= htmlspecialchars($formData['expiry_date'] ?: $defaultNewExpiry) ?>">
                                        <div class="form-text">
                                            <?php if ($isExpired): ?>
                                                <i class="fas fa-exclamation-triangle text-danger me-1"></i>
                                                Member is expired. Set a new expiry date to reactivate.
                                            <?php elseif ($currentExpiry): ?>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Recommended: <?= date('M d, Y', strtotime($currentExpiry . ' +1 year')) ?>
                                                (current expiry + 1 year)
                                            <?php else: ?>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Recommended: <?= date('M d, Y', strtotime('+1 year')) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="mb-3 renewal-notes">
                                        <label class="form-label">Renewal Notes <span class="text-muted">(optional)</span></label>
                                        <textarea name="notes" class="form-control" rows="2"
                                                  placeholder="Add any notes about this renewal (e.g., reason, approval, etc.)"><?= htmlspecialchars($formData['notes'] ?? '') ?></textarea>
                                        <div class="form-text">These notes will be stored in the audit log.</div>
                                    </div>

                                    <hr>

                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-success" id="submitBtn">
                                            <i class="fas fa-check me-1"></i>Confirm Renewal
                                        </button>
                                        <a href="view_member.php?id=<?= $memberId ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </a>
                                    </div>
                                </form>
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

            // ─── Form validation ──────────────────────────────────────────
            const form = document.getElementById('renewForm');
            const expiryInput = document.getElementById('expiryDate');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    const expiry = expiryInput.value.trim();

                    if (!expiry) {
                        e.preventDefault();
                        alert('Please select a new expiry date.');
                        expiryInput.focus();
                        return false;
                    }

                    // Check if date is in the past
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const selectedDate = new Date(expiry + 'T00:00:00');

                    if (selectedDate < today) {
                        e.preventDefault();
                        alert('Expiry date cannot be in the past. Please select a future date.');
                        expiryInput.focus();
                        return false;
                    }

                    // Check if the new date is actually an extension
                    <?php if ($currentExpiry): ?>
                    const currentExpiry = new Date('<?= $currentExpiry ?>T00:00:00');
                    if (selectedDate <= currentExpiry) {
                        e.preventDefault();
                        alert('New expiry date must be later than the current expiry date (<?= date('M d, Y', strtotime($currentExpiry)) ?>).');
                        expiryInput.focus();
                        return false;
                    }
                    <?php endif; ?>

                    // Confirm with user if expiry is more than 2 years out
                    const twoYearsFromNow = new Date();
                    twoYearsFromNow.setFullYear(twoYearsFromNow.getFullYear() + 2);
                    if (selectedDate > twoYearsFromNow) {
                        if (!confirm('You are setting an expiry date more than 2 years from now. Is this correct?')) {
                            e.preventDefault();
                            return false;
                        }
                    }

                    return true;
                });
            }

            // ─── Touch-friendly ──────────────────────────────────────────
            if ('ontouchstart' in window) {
                document.querySelectorAll('.btn, .form-control, .form-select').forEach(el => {
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
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    submitBtn?.click();
                }
            });

        })();
    </script>
</body>
</html>