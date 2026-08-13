<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';

$page_title = 'Bulk Print';
$message = '';
$error = '';

require_once __DIR__ . '/../middleware/permission.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Members', 'Print');

$username = $_SESSION['username'];
$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId = (int)($authUser['organization_id'] ?? 0);

$preselectedIds = [];
if (!empty($_GET['ids'])) {
    $preselectedIds = array_filter(array_map('intval', explode(',', (string)$_GET['ids'])));
}

// Get filters
$search = trim($_GET['search'] ?? '');
$memberType = trim($_GET['member_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$orgFilter = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
$selectAll = isset($_GET['select_all']) ? true : false;

// Build query
$where = ['m.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(m.name LIKE ? OR m.unique_id LIKE ? OR m.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($memberType !== '') {
    $where[] = 'm.member_type = ?';
    $params[] = $memberType;
}

if ($statusFilter !== '') {
    $today = date('Y-m-d');
    if ($statusFilter === 'active') {
        $where[] = 'm.expiry_date >= ?';
        $params[] = $today;
    } elseif ($statusFilter === 'expiring') {
        $where[] = 'm.expiry_date BETWEEN ? AND ?';
        $params[] = $today;
        $params[] = date('Y-m-d', strtotime('+30 days'));
    } elseif ($statusFilter === 'expired') {
        $where[] = 'm.expiry_date < ?';
        $params[] = $today;
    }
}

if (!$isSuperAdmin && $userOrgId > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $userOrgId;
} elseif ($isSuperAdmin && $orgFilter > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $orgFilter;
}

// Get members
$sql = "SELECT m.id, m.name, m.unique_id, m.member_type, m.expiry_date, 
        o.organization_name, o.id as org_id
        FROM id_members m
        LEFT JOIN organizations o ON m.organization_id = o.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.organization_name, m.name";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
}
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get organizations for filter
$organizations = [];
if ($isSuperAdmin) {
    $orgs = $conn->query("SELECT id, organization_name FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name");
    while ($row = $orgs->fetch_assoc()) {
        $organizations[] = $row;
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Bulk Print · ID Card Generator</title>

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
            --neutral-400: #9ca3af;
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
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
        .card-footer-custom {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
            background: var(--neutral-100);
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            font-size: 0.875rem;
            background: white;
            min-width: 130px;
        }
        .filter-bar .btn { padding: 0.375rem 0.75rem; font-size: 0.875rem; }

        .table { font-size: 0.875rem; }
        .table thead th {
            font-size: 0.688rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            background: var(--neutral-100);
        }
        .table tbody td { vertical-align: middle; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.688rem;
            font-weight: 500;
        }
        .status-badge.active { background: #e3f9ee; color: #0e9f6e; }
        .status-badge.expiring { background: #fef5e0; color: #f4b740; }
        .status-badge.expired { background: #fee2e2; color: #dc2626; }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: #0e9f6e; color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-outline-secondary { border-color: var(--neutral-300); color: var(--neutral-600); }

        .selected-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { min-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include '../includes/header.php'; ?>

            <div class="dashboard-content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="../view_members.php">Members</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Bulk Print</li>
                    </ol>
                </nav>

                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5 style="font-weight:600;margin:0;">
                                    <i class="fas fa-print text-primary me-2"></i>Bulk Print ID Cards
                                </h5>
                                <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                                    Select members to print ID cards in bulk
                                </p>
                            </div>
                            <div>
                                <span class="selected-count" id="selectedCount">0 selected</span>
                            </div>
                        </div>

                        <form method="GET" class="filter-bar mt-3">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search) ?>" style="min-width:180px;">

                            <select name="member_type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($memberTypes as $type): ?>
                                    <option value="<?= $type ?>" <?= $memberType === $type ? 'selected' : '' ?>>
                                        <?= ucfirst($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="expiring" <?= $statusFilter === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
                                <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                            </select>

                            <?php if ($isSuperAdmin): ?>
                                <select name="org_id" class="form-select">
                                    <option value="0">All Organizations</option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?= $org['id'] ?>" <?= $orgFilter == $org['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($org['organization_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-filter me-1"></i>Filter
                            </button>
                            <a href="bulk_print.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-redo me-1"></i>Reset
                            </a>
                        </form>
                    </div>

                    <div class="card-body-custom">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="selectAll()">
                                    <i class="fas fa-check-double me-1"></i>Select All
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                                    <i class="fas fa-times me-1"></i>Deselect All
                                </button>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-success" onclick="printSelected()">
                                    <i class="fas fa-print me-1"></i>Print Selected
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="printSelectedMirror()">
                                    <i class="fas fa-undo me-1"></i>Mirror Print
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this)">
                                        </th>
                                        <th>Name</th>
                                        <th>ID</th>
                                        <th>Type</th>
                                        <th>Organization</th>
                                        <th>Status</th>
                                        <th>Expiry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                <i class="fas fa-users" style="font-size:2rem;display:block;margin-bottom:0.5rem;"></i>
                                                No members found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($members as $member): 
                                            $status = 'active';
                                            $statusClass = 'active';
                                            $today = date('Y-m-d');
                                            $next30 = date('Y-m-d', strtotime('+30 days'));
                                            if (!empty($member['expiry_date'])) {
                                                if ($member['expiry_date'] < $today) {
                                                    $status = 'expired';
                                                    $statusClass = 'expired';
                                                } elseif ($member['expiry_date'] <= $next30) {
                                                    $status = 'expiring';
                                                    $statusClass = 'expiring';
                                                }
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="member-checkbox" value="<?= $member['id'] ?>" 
                                                           data-org="<?= $member['org_id'] ?? 0 ?>"
                                                           onchange="updateCount()"
                                                           <?= in_array((int)$member['id'], $preselectedIds, true) ? 'checked' : '' ?>>
                                                </td>
                                                <td><?= htmlspecialchars($member['name']) ?></td>
                                                <td><?= htmlspecialchars($member['unique_id']) ?></td>
                                                <td><?= ucfirst($member['member_type']) ?></td>
                                                <td><?= htmlspecialchars($member['organization_name'] ?? '—') ?></td>
                                                <td>
                                                    <span class="status-badge <?= $statusClass ?>">
                                                        <?= ucfirst($status) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= !empty($member['expiry_date']) ? date('M d, Y', strtotime($member['expiry_date'])) : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer-custom">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="text-muted small"><?= count($members) ?> members found</span>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-sm btn-success" onclick="printSelected()">
                                    <i class="fas fa-print me-1"></i>Print Selected
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="printSelectedMirror()">
                                    <i class="fas fa-undo me-1"></i>Mirror Print
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include '../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // ============================================================
        // SELECTION FUNCTIONS
        // ============================================================
        function updateCount() {
            const checkboxes = document.querySelectorAll('.member-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length + ' selected';
        }

        function toggleAll(master) {
            document.querySelectorAll('.member-checkbox').forEach(cb => {
                cb.checked = master.checked;
            });
            updateCount();
        }

        function selectAll() {
            document.querySelectorAll('.member-checkbox').forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('selectAllCheckbox').checked = true;
            updateCount();
        }

        function deselectAll() {
            document.querySelectorAll('.member-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('selectAllCheckbox').checked = false;
            updateCount();
        }

        // ============================================================
        // PRINT FUNCTIONS
        // ============================================================
        function getSelectedIds() {
            const checkboxes = document.querySelectorAll('.member-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function printSelected() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                alert('Please select at least one member to print.');
                return;
            }
            const url = 'print_id_card.php?ids=' + ids.join(',') + '&bulk=1';
            window.open(url, '_blank');
        }

        function printSelectedMirror() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                alert('Please select at least one member to print.');
                return;
            }
            const url = 'print_id_card.php?ids=' + ids.join(',') + '&bulk=1&mirror=1';
            window.open(url, '_blank');
        }

        // ============================================================
        // KEYBOARD SHORTCUTS
        // ============================================================
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                selectAll();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printSelected();
            }
        });

        // ============================================================
        // INITIALIZE
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            updateCount();
        });
    </script>
</body>
</html>