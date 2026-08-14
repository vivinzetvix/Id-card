<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/member_helpers.php';

$page_title = 'Members Management';

require_login();
$authUser = get_auth_user($pdo) ?: [];
require_permission($pdo, 'Members', 'View');

$isSuperAdmin = auth_is_super_admin($authUser);
$userOrgId    = (int)($authUser['organization_id'] ?? $_SESSION['organization_id'] ?? 0);

$canCreate = has_permission($pdo, 'Members', 'Create');
$canEdit   = has_permission($pdo, 'Members', 'Edit');
$canDelete = has_permission($pdo, 'Members', 'Delete');
$canPrint  = has_permission($pdo, 'Members', 'Print');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

$message = $_SESSION['member_message'] ?? '';
$error   = $_SESSION['member_error'] ?? '';
unset($_SESSION['member_message'], $_SESSION['member_error']);

/* --------------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------------- */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_member_photo_path($photo): string
{
    $photo = trim((string)$photo);
    if ($photo !== '') {
        $basename = basename($photo);
        if (is_file(__DIR__ . '/../images/uploads/' . $basename)) {
            return '../images/uploads/' . rawurlencode($basename);
        }
    }
    return '../images/uploads/default.png';
}

function get_days_remaining($expiryDate): string
{
    if (!$expiryDate) {
        return 'N/A';
    }

    try {
        $today  = new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable((string)$expiryDate);
        $diff   = $today->diff($expiry);

        if ($diff->invert) {
            return '<span class="text-danger">Expired</span>';
        }

        return (int)$diff->days . ' days';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function member_status_key($expiryDate): string
{
    if (!$expiryDate) {
        return 'no_expiry';
    }

    try {
        $today = new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable((string)$expiryDate);
        $days = (int)$today->diff($expiry)->format('%r%a');

        if ($days < 0) {
            return 'expired';
        }
        if ($days <= 30) {
            return 'expiring';
        }
        return 'active';
    } catch (Throwable $e) {
        return 'no_expiry';
    }
}

function get_member_status_badge($expiryDate): string
{
    $status = member_status_key($expiryDate);

    if ($status === 'active') {
        return '<span class="status-badge active"><i class="fas fa-check-circle"></i> Active</span>';
    }
    if ($status === 'expiring') {
        return '<span class="status-badge expiring"><i class="fas fa-clock"></i> Expiring</span>';
    }
    if ($status === 'expired') {
        return '<span class="status-badge expired"><i class="fas fa-exclamation-circle"></i> Expired</span>';
    }

    return '<span class="status-badge"><i class="fas fa-minus-circle"></i> No Expiry</span>';
}

function field_label_from_key(string $key): string
{
    static $labels = [
        'unique_id'          => 'Unique ID',
        'name'               => 'Name',
      
        'guardian_name'      => 'Guardian Name',
        'department'         => 'Department',
        'class'              => 'Class',
        'designation'        => 'Designation',
        'company'            => 'Company',
        'purpose'            => 'Purpose',
        'dob'                => 'DOB',
        'address'            => 'Address',
        'emergency_contact'  => 'Emergency Contact',
        'email'              => 'Email',
        'joined_date'        => 'Joined Date',
        'expiry_date'        => 'Expiry Date',
        'photo'              => 'Photo',
        'signature'          => 'Signature',
    ];

    if (isset($labels[$key])) {
        return $labels[$key];
    }

    return ucwords(str_replace(['_', '-'], ' ', $key));
}

function is_system_field(string $key): bool
{
    static $system = [
        'id',
        'organization_id',
        'template_id',
        'language',
        'created_at',
        'updated_at',
        'deleted_at',
        'status',
        'card_count',
        'field_count',
    ];

    return in_array(strtolower($key), $system, true);
}

function is_original_member_field(string $key): bool
{
    static $fields = [
        'unique_id',
        'name',
        'member_type',
        'guardian_name',
        'department',
        'class',
        'designation',
        'company',
        'purpose',
        'dob',
        'address',
        'emergency_contact',
        'email',
        'joined_date',
        'expiry_date',
        'photo',
        'signature',
    ];

    return in_array(strtolower($key), $fields, true);
}

function load_template_field_definitions(PDO $pdo, int $templateId): array
{
    if ($templateId <= 0) {
        return [];
    }

    $defs = [];

    try {
        if (function_exists('get_template_input_fields')) {
            $raw = get_template_input_fields($pdo, $templateId);
            if (is_array($raw)) {
                foreach ($raw as $k => $field) {
                    if (is_string($field) && $k !== '') {
                        $key = strtolower(trim($k));
                        $defs[$key] = [
                            'field_key'   => $key,
                            'field_label' => field_label_from_key($key),
                            'field_type'  => 'text',
                            'is_required' => 0,
                        ];
                        continue;
                    }

                    if (!is_array($field)) {
                        continue;
                    }

                    $key = strtolower(trim((string)($field['field_key'] ?? $k)));
                    if ($key === '') {
                        continue;
                    }

                    $defs[$key] = [
                        'field_key'       => $key,
                        'field_label'     => trim((string)($field['field_label'] ?? $field['label'] ?? field_label_from_key($key))),
                        'field_type'      => strtolower((string)($field['field_type'] ?? 'text')),
                        'is_required'     => (int)($field['is_required'] ?? 0),
                        'bilingual_mode'  => strtolower((string)($field['bilingual_mode'] ?? 'single')),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        // Fall back to active keys below.
    }

    try {
        if (function_exists('template_get_active_field_keys')) {
            $keys = template_get_active_field_keys($pdo, $templateId);
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    $key = strtolower(trim((string)$key));
                    if ($key === '' || is_system_field($key)) {
                        continue;
                    }

                    if (!isset($defs[$key])) {
                        $defs[$key] = [
                            'field_key'   => $key,
                            'field_label' => field_label_from_key($key),
                            'field_type'  => 'text',
                            'is_required' => 0,
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Keep whatever was loaded.
    }

    return $defs;
}

function load_member_dynamic_values(PDO $pdo, int $memberId): array
{
    if ($memberId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT field_key, field_value
             FROM member_dynamic_values
             WHERE member_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$memberId]);

        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)($row['field_key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $value = (string)($row['field_value'] ?? '');
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = implode(' / ', array_map(
                    static fn($v) => is_scalar($v) ? (string)$v : '',
                    $decoded
                ));
            }

            $values[$key] = $value;
        }

        return $values;
    } catch (Throwable $e) {
        return [];
    }
}

function normalize_bulk_ids($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function selected_ids_from_range(PDO $pdo, array $baseWhere, array $baseParams, int $from, int $to, bool $showArchived, bool $isSuperAdmin, int $userOrgId): array
{
    if ($from <= 0 && $to <= 0) {
        return [];
    }

    if ($from <= 0) {
        $from = 1;
    }
    if ($to <= 0) {
        $to = PHP_INT_MAX;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $where = $baseWhere;
    $params = $baseParams;

    $where[] = 'm.id BETWEEN ? AND ?';
    $params[] = $from;
    $params[] = $to;

    $sql = 'SELECT m.id
            FROM id_members m
            LEFT JOIN organizations o ON o.id = m.organization_id
            WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function validate_member_ids(PDO $pdo, array $ids, bool $isSuperAdmin, int $userOrgId, bool $includeArchived = true): array
{
    if (!$ids) {
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $where = ["m.id IN ($placeholders)"];
    $params = $ids;

    if (!$includeArchived) {
        $where[] = 'm.deleted_at IS NULL';
    }

    if (!$isSuperAdmin && $userOrgId > 0) {
        $where[] = 'm.organization_id = ?';
        $params[] = $userOrgId;
    }

    $stmt = $pdo->prepare(
        'SELECT m.id
         FROM id_members m
         WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function validate_template_for_org(PDO $pdo, int $templateId, bool $isSuperAdmin, int $userOrgId): bool
{
    if ($templateId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, organization_id, status, deleted_at
         FROM card_templates
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$templateId]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tpl || (int)$tpl['status'] !== 1 || !empty($tpl['deleted_at'])) {
        return false;
    }

    $tplOrg = (int)($tpl['organization_id'] ?? 0);

    if ($isSuperAdmin) {
        return true;
    }

    return $tplOrg === 0 || $tplOrg === $userOrgId;
}

function validate_organization(PDO $pdo, int $orgId): bool
{
    if ($orgId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM organizations
         WHERE id = ? AND deleted_at IS NULL AND status = 1
         LIMIT 1'
    );
    $stmt->execute([$orgId]);

    return (bool)$stmt->fetchColumn();
}

function members_page_url(array $filters, int $pageNum = 1): string
{
    $params = $filters;
    $params['page'] = $pageNum > 1 ? $pageNum : null;

    $params = array_filter(
        $params,
        static fn($v) => $v !== null && $v !== ''
    );

    return '?' . http_build_query($params);
}

/* --------------------------------------------------------------------------
 * Filters
 * -------------------------------------------------------------------------- */

$search         = trim((string)($_GET['search'] ?? ''));
$statusFilter   = trim((string)($_GET['status'] ?? ''));
$orgFilter      = trim((string)($_GET['org_id'] ?? ''));
$templateFilter = trim((string)($_GET['template_id'] ?? ''));
$projectType    = trim((string)($_GET['project_type'] ?? ''));
$photoFilter    = trim((string)($_GET['photo_filter'] ?? ''));
$idFrom         = max(0, (int)($_GET['id_from'] ?? 0));
$idTo           = max(0, (int)($_GET['id_to'] ?? 0));
$joinedFrom     = trim((string)($_GET['joined_from'] ?? ''));
$joinedTo       = trim((string)($_GET['joined_to'] ?? ''));
$showArchived   = !empty($_GET['show_archived']);
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = min(100, max(15, (int)($_GET['per_page'] ?? 25)));

$allowedSorts = [
    'id'          => 'm.id',
    'name'        => 'm.name',
    'unique_id'   => 'm.unique_id',
 
    'department'  => 'm.department',
    'class'       => 'm.class',
    'joined_date' => 'm.joined_date',
    'expiry_date' => 'm.expiry_date',
];

$sortKey = (string)($_GET['sort'] ?? 'id');
$sort = $allowedSorts[$sortKey] ?? $allowedSorts['id'];
$order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

/* --------------------------------------------------------------------------
 * Build base query safely
 * -------------------------------------------------------------------------- */

$where = [];
$params = [];

$where[] = $showArchived ? 'm.deleted_at IS NOT NULL' : 'm.deleted_at IS NULL';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(m.name LIKE ?
                 OR m.unique_id LIKE ?
                 OR m.email LIKE ?
                 OR m.emergency_contact LIKE ?
                 OR m.company LIKE ?
                 OR m.department LIKE ?
                 OR m.class LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

if ($statusFilter !== '') {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));

    if ($statusFilter === 'active') {
        $where[] = 'm.expiry_date >= ?';
        $params[] = $today;
    } elseif ($statusFilter === 'expiring') {
        $where[] = 'm.expiry_date BETWEEN ? AND ?';
        $params[] = $today;
        $params[] = $next30;
    } elseif ($statusFilter === 'expired') {
        $where[] = 'm.expiry_date < ?';
        $params[] = $today;
    } elseif ($statusFilter === 'no_expiry') {
        $where[] = '(m.expiry_date IS NULL OR m.expiry_date = "")';
    }
}

if ($isSuperAdmin && $orgFilter !== '') {
    $where[] = 'm.organization_id = ?';
    $params[] = (int)$orgFilter;
} elseif (!$isSuperAdmin && $userOrgId > 0) {
    $where[] = 'm.organization_id = ?';
    $params[] = $userOrgId;
}

if ($templateFilter !== '') {
    $where[] = 'm.template_id = ?';
    $params[] = (int)$templateFilter;
}

if ($projectType !== '') {
    $where[] = 'o.project_type = ?';
    $params[] = $projectType;
}

if ($photoFilter === 'with') {
    $where[] = 'm.photo IS NOT NULL AND m.photo != ""';
} elseif ($photoFilter === 'without') {
    $where[] = '(m.photo IS NULL OR m.photo = "")';
}

if ($idFrom > 0 && $idTo > 0) {
    if ($idFrom > $idTo) {
        [$idFrom, $idTo] = [$idTo, $idFrom];
    }
    $where[] = 'm.id BETWEEN ? AND ?';
    $params[] = $idFrom;
    $params[] = $idTo;
} elseif ($idFrom > 0) {
    $where[] = 'm.id >= ?';
    $params[] = $idFrom;
} elseif ($idTo > 0) {
    $where[] = 'm.id <= ?';
    $params[] = $idTo;
}

if ($joinedFrom !== '') {
    $where[] = 'm.joined_date >= ?';
    $params[] = $joinedFrom;
}

if ($joinedTo !== '') {
    $where[] = 'm.joined_date <= ?';
    $params[] = $joinedTo;
}

$whereSql = implode(' AND ', $where);

/* --------------------------------------------------------------------------
 * POST: bulk actions
 * -------------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf, $postedToken)) {
        $_SESSION['member_error'] = 'Invalid security token. Please refresh the page.';
        header('Location: view_members.php');
        exit;
    }

    $bulkAction = (string)$_POST['bulk_action'];
    $ids = normalize_bulk_ids($_POST['member_ids'] ?? []);

    /*
     * Optional range selection. This lets the user select all matching
     * members between database IDs without manually ticking every row.
     */
    $rangeFrom = max(0, (int)($_POST['range_from'] ?? 0));
    $rangeTo   = max(0, (int)($_POST['range_to'] ?? 0));

    if ($rangeFrom > 0 || $rangeTo > 0) {
        $rangeIds = selected_ids_from_range(
            $pdo,
            $where,
            $params,
            $rangeFrom,
            $rangeTo,
            $showArchived,
            $isSuperAdmin,
            $userOrgId
        );
        $ids = array_values(array_unique(array_merge($ids, $rangeIds)));
    }

    if (!$ids) {
        $_SESSION['member_error'] = 'Please select at least one member.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'view_members.php'));
        exit;
    }

    $includeArchivedForValidation = in_array($bulkAction, ['restore'], true);
    $validIds = validate_member_ids(
        $pdo,
        $ids,
        $isSuperAdmin,
        $userOrgId,
        $includeArchivedForValidation
    );

    if (!$validIds) {
        $_SESSION['member_error'] = 'No accessible members were selected.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'view_members.php'));
        exit;
    }

    try {
        if ($bulkAction === 'archive') {
            if (!$canDelete) {
                throw new RuntimeException('You do not have permission to archive members.');
            }

            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $sql = "UPDATE id_members
                    SET deleted_at = NOW(), updated_at = NOW()
                    WHERE id IN ($placeholders)
                      AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($validIds);

            $_SESSION['member_message'] = $stmt->rowCount() . ' member(s) archived successfully.';
        } elseif ($bulkAction === 'restore') {
            if (!$canEdit) {
                throw new RuntimeException('You do not have permission to restore members.');
            }

            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $sql = "UPDATE id_members
                    SET deleted_at = NULL, updated_at = NOW()
                    WHERE id IN ($placeholders)
                      AND deleted_at IS NOT NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($validIds);

            $_SESSION['member_message'] = $stmt->rowCount() . ' member(s) restored successfully.';
        } elseif ($bulkAction === 'bulk_edit') {
            if (!$canEdit) {
                throw new RuntimeException('You do not have permission to edit members.');
            }

            $expiryDate    = trim((string)($_POST['bulk_expiry_date'] ?? ''));
            $newTemplateId = (int)($_POST['bulk_template_id'] ?? 0);
            $newOrgId      = (int)($_POST['bulk_org_id'] ?? 0);

            $changes = [];
            $changeParams = [];

            if (isset($_POST['bulk_org_apply'])) {
                if (!$isSuperAdmin) {
                    throw new RuntimeException('You do not have permission to change organization.');
                }

                if (!validate_organization($pdo, $newOrgId)) {
                    throw new RuntimeException('Please select a valid organization.');
                }

                $changes[] = 'organization_id = ?';
                $changeParams[] = $newOrgId;
            }

            if (isset($_POST['bulk_expiry_apply'])) {
                if ($expiryDate !== '') {
                    $dateObj = DateTime::createFromFormat('Y-m-d', $expiryDate);
                    if (!$dateObj || $dateObj->format('Y-m-d') !== $expiryDate) {
                        throw new RuntimeException('Please enter a valid expiry date.');
                    }
                    $changes[] = 'expiry_date = ?';
                    $changeParams[] = $expiryDate;
                } else {
                    $changes[] = 'expiry_date = NULL';
                }
            }

            if ($newTemplateId > 0) {
                if (!validate_template_for_org($pdo, $newTemplateId, $isSuperAdmin, $userOrgId)) {
                    throw new RuntimeException('The selected template is not available for your organisation.');
                }

                // A template belonging to one organisation must not be assigned
                // to members from another organisation, even for a super admin.
                $tplStmt = $pdo->prepare('SELECT organization_id FROM card_templates WHERE id = ? LIMIT 1');
                $tplStmt->execute([$newTemplateId]);
                $templateOrgId = (int)($tplStmt->fetchColumn() ?? 0);

                if ($templateOrgId > 0 && !isset($_POST['bulk_org_apply'])) {
                    $checkPh = implode(',', array_fill(0, count($validIds), '?'));
                    $checkStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM id_members WHERE id IN ($checkPh) AND organization_id <> ?"
                    );
                    $checkStmt->execute(array_merge($validIds, [$templateOrgId]));
                    if ((int)$checkStmt->fetchColumn() > 0) {
                        throw new RuntimeException('The selected template belongs to a different organisation. Select members from the same organisation first.');
                    }
                }

                $changes[] = 'template_id = ?';
                $changeParams[] = $newTemplateId;
            }

            if (!$changes) {
                throw new RuntimeException('Choose at least one field to update.');
            }

            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $sql = 'UPDATE id_members
                    SET ' . implode(', ', $changes) . ', updated_at = NOW()
                    WHERE id IN (' . $placeholders . ')
                      AND deleted_at IS NULL';

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($changeParams, $validIds));

            $_SESSION['member_message'] = $stmt->rowCount() . ' member(s) updated successfully.';
        } elseif ($bulkAction === 'print') {
            if (!$canPrint) {
                throw new RuntimeException('You do not have permission to print members.');
            }

            $idString = implode(',', $validIds);
            $mirror = !empty($_POST['mirror']) ? '&mirror=1' : '';
            header('Location: ../card/print_id_card.php?ids=' . rawurlencode($idString) . $mirror);
            exit;
        } else {
            throw new RuntimeException('Unknown bulk action.');
        }
    } catch (Throwable $e) {
        $_SESSION['member_error'] = $e->getMessage();
    }

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'view_members.php'));
    exit;
}

/* --------------------------------------------------------------------------
 * Counts / lists
 * -------------------------------------------------------------------------- */

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM id_members m
     LEFT JOIN organizations o ON o.id = m.organization_id
     WHERE ' . $whereSql
);
$countStmt->execute($params);
$totalMembers = (int)$countStmt->fetchColumn();

$offset = ($page - 1) * $perPage;

$sql = 'SELECT
            m.id,
            m.unique_id,
            m.name,
            m.member_type,
            m.guardian_name,
            m.department,
            m.class,
            m.designation,
            m.company,
            m.purpose,
            m.dob,
            m.address,
            m.emergency_contact,
            m.email,
            m.joined_date,
            m.expiry_date,
            m.photo,
            m.signature,
            m.organization_id,
            m.template_id,
            m.deleted_at,
            o.organization_name,
            o.project_type,
            t.name AS template_name,
            t.orientation AS template_orientation
        FROM id_members m
        LEFT JOIN organizations o ON o.id = m.organization_id
        LEFT JOIN card_templates t ON t.id = m.template_id
        WHERE ' . $whereSql . '
        ORDER BY ' . $sort . ' ' . $order . '
        LIMIT ? OFFSET ?';

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($totalMembers / $perPage));

/* Dynamic values only need to be loaded for the current page. */
foreach ($members as &$member) {
    $member['_dynamic'] = load_member_dynamic_values($pdo, (int)$member['id']);
}
unset($member);

/* Stats */
$stats = [
    'total' => 0,
    'active' => 0,
    'expiring' => 0,
    'expired' => 0,
];

$statsWhere = ['deleted_at IS NULL'];
$statsParams = [];

if (!$isSuperAdmin && $userOrgId > 0) {
    $statsWhere[] = 'organization_id = ?';
    $statsParams[] = $userOrgId;
}

$statsBase = implode(' AND ', $statsWhere);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE $statsBase");
$stmt->execute($statsParams);
$stats['total'] = (int)$stmt->fetchColumn();

$today = date('Y-m-d');
$next30 = date('Y-m-d', strtotime('+30 days'));

$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE $statsBase AND expiry_date >= ?");
$stmt->execute(array_merge($statsParams, [$today]));
$stats['active'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE $statsBase AND expiry_date BETWEEN ? AND ?");
$stmt->execute(array_merge($statsParams, [$today, $next30]));
$stats['expiring'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE $statsBase AND expiry_date < ?");
$stmt->execute(array_merge($statsParams, [$today]));
$stats['expired'] = (int)$stmt->fetchColumn();

/* Organizations - used for the (super admin) filter dropdown AND the
 * bulk-edit "Update Organization" dropdown. Fetched unconditionally so
 * the bulk-edit modal always has the full list available. */
$stmt = $pdo->query(
    'SELECT id, organization_name, project_type
     FROM organizations
     WHERE deleted_at IS NULL AND status = 1
     ORDER BY organization_name'
);
$organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Templates */
$stmt = $pdo->query(
    'SELECT id, name, orientation, primary_color, is_default, organization_id
     FROM card_templates
     WHERE status = 1 AND deleted_at IS NULL
     ORDER BY is_default DESC, name'
);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$isSuperAdmin && $userOrgId > 0) {
    $templates = array_values(array_filter(
        $templates,
        static function ($tpl) use ($userOrgId) {
            $orgId = (int)($tpl['organization_id'] ?? 0);
            return $orgId === 0 || $orgId === $userOrgId;
        }
    ));
}

$projectTypes = ['residence', 'corporate'];

/* Dynamic columns: only show them as real columns when a template is selected. */
$dynamicColumns = [];
$selectedTemplateId = (int)$templateFilter;

if ($selectedTemplateId > 0) {
    $defs = load_template_field_definitions($pdo, $selectedTemplateId);

    foreach ($defs as $key => $field) {
        if (is_system_field($key) || is_original_member_field($key)) {
            continue;
        }

        $dynamicColumns[$key] = $field;
    }
}

/* Preserve filter state for pagination/sorting. */
$filterState = [
    'search'       => $search,
    'status'       => $statusFilter,
    'org_id'       => $orgFilter,
    'template_id'  => $templateFilter,
    'project_type' => $projectType,
    'photo_filter' => $photoFilter,
    'id_from'      => $idFrom ?: null,
    'id_to'        => $idTo ?: null,
    'joined_from'  => $joinedFrom,
    'joined_to'    => $joinedTo,
    'show_archived'=> $showArchived ? '1' : null,
    'per_page'     => $perPage !== 25 ? $perPage : null,
    'sort'         => $sortKey !== 'id' ? $sortKey : null,
    'order'        => $order !== 'DESC' ? $order : null,
];

$columnCount = 7 + count($dynamicColumns) + 1 + ($showArchived ? 1 : 0);

/* --------------------------------------------------------------------------
 * HTML
 * -------------------------------------------------------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Members Management · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary:#0a1a2f;
            --primary-light:#1e3a5f;
            --success:#0e9f6e;
            --warning:#d97706;
            --danger:#dc2626;
            --info:#2563eb;
            --neutral-50:#f8fafc;
            --neutral-100:#f1f5f9;
            --neutral-200:#e2e8f0;
            --neutral-300:#cbd5e1;
            --neutral-500:#64748b;
            --neutral-700:#334155;
            --neutral-800:#1e293b;
            --shadow-sm:0 1px 3px rgba(0,0,0,.05);
            --shadow-md:0 4px 10px rgba(15,23,42,.07);
            --shadow-lg:0 12px 30px rgba(15,23,42,.10);
            --radius:12px;
        }

        * { box-sizing:border-box; }

        body {
            margin:0;
            background:var(--neutral-50);
            color:var(--neutral-800);
            font-family:'Inter',sans-serif;
        }

        .dashboard-wrapper { display:flex; min-height:100vh; }
        .main-content { flex:1; margin-left:280px; min-height:100vh; }
        .dashboard-content { max-width:1800px; margin:0 auto; padding:28px; }

        .breadcrumb-container { margin-bottom:20px; }
        .breadcrumb { margin:0; font-size:.86rem; }
        .breadcrumb a { text-decoration:none; color:var(--info); }

        .stats-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(150px,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card {
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:18px;
            padding:18px;
            box-shadow:var(--shadow-sm);
            cursor:pointer;
            transition:.2s ease;
        }

        .stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
        .stat-icon { font-size:1.25rem; margin-bottom:6px; }
        .stat-label { font-size:.7rem; color:var(--neutral-500); text-transform:uppercase; letter-spacing:.05em; }
        .stat-number { font-size:1.7rem; font-weight:700; }

        .main-card {
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:18px;
            overflow:hidden;
            box-shadow:var(--shadow-md);
        }

        .card-header-custom {
            padding:20px;
            background:#fff;
            border-bottom:1px solid var(--neutral-200);
        }

        .card-body-custom { padding:20px; }
        .card-footer-custom {
            padding:16px 20px;
            background:var(--neutral-50);
            border-top:1px solid var(--neutral-200);
        }

        .quick-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .quick-actions .btn, .bulk-toolbar .btn { font-size:.8rem; }

        .advanced-box {
            margin-top:18px;
            padding:18px;
            border:1px solid var(--neutral-200);
            border-radius:14px;
            background:var(--neutral-50);
        }

        .filter-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(180px,1fr));
            gap:12px;
        }

        .filter-item label {
            display:block;
            margin-bottom:5px;
            font-size:.72rem;
            font-weight:700;
            color:var(--neutral-500);
            text-transform:uppercase;
        }

        .filter-item .form-control,
        .filter-item .form-select {
            min-height:42px;
            border-radius:9px;
            font-size:.82rem;
        }

        .filter-actions {
            display:flex;
            justify-content:flex-end;
            gap:8px;
            margin-top:14px;
        }

        .bulk-toolbar {
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
            padding:12px;
            margin-bottom:14px;
            background:var(--neutral-50);
            border:1px solid var(--neutral-200);
            border-radius:12px;
        }

        .bulk-toolbar .selected-label {
            font-size:.78rem;
            color:var(--neutral-500);
            margin-left:auto;
        }

        .table-wrap {
            width:100%;
            overflow:auto;
            border:1px solid var(--neutral-200);
            border-radius:12px;
        }

        .table {
            margin:0;
            min-width:1100px;
            font-size:.8rem;
        }

        .table thead th {
            background:var(--neutral-50);
            color:var(--neutral-500);
            font-size:.68rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            white-space:nowrap;
            border-bottom:2px solid var(--neutral-200);
            padding:11px 9px;
            vertical-align:middle;
        }

        .table tbody td {
            padding:10px 9px;
            border-bottom:1px solid var(--neutral-100);
            vertical-align:middle;
        }

        .table tbody tr:hover td { background:#fbfdff; }

        .member-photo-thumb {
            width:42px;
            height:42px;
            object-fit:cover;
            border-radius:10px;
            border:1px solid var(--neutral-200);
        }

        .member-name {
            font-weight:700;
            color:var(--neutral-800);
            white-space:nowrap;
        }

        .muted { color:var(--neutral-500); }
        .small-text { font-size:.72rem; }
        .data-value { max-width:220px; white-space:normal; word-break:break-word; }

        .status-badge {
            display:inline-flex;
            align-items:center;
            gap:4px;
            padding:4px 8px;
            border-radius:999px;
            font-size:.66rem;
            font-weight:700;
            white-space:nowrap;
            background:#eef2f7;
            color:#64748b;
        }

        .status-badge.active { background:#dcfce7; color:#15803d; }
        .status-badge.expiring { background:#fef3c7; color:#b45309; }
        .status-badge.expired { background:#fee2e2; color:#b91c1c; }

        .kind-badge {
            display:inline-flex;
            padding:4px 8px;
            border-radius:7px;
            background:#eef2ff;
            color:#3730a3;
            font-size:.68rem;
            font-weight:700;
            white-space:nowrap;
        }

        .template-badge {
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:4px 8px;
            border-radius:7px;
            background:#f1f5f9;
            color:#334155;
            font-size:.68rem;
            white-space:nowrap;
        }

        .org-badge {
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:4px 8px;
            border-radius:7px;
            background:#fef2f2;
            color:#7f1d1d;
            font-size:.68rem;
            white-space:nowrap;
        }

        .dynamic-chip {
            display:inline-flex;
            flex-direction:column;
            margin:0 4px 4px 0;
            padding:4px 7px;
            border-radius:7px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            min-width:90px;
        }

        .dynamic-chip .label {
            color:#64748b;
            font-size:.58rem;
            text-transform:uppercase;
        }

        .dynamic-chip .value {
            font-size:.7rem;
            color:#1e293b;
        }

        .empty-state { padding:60px 20px; text-align:center; }
        .empty-state i { font-size:3rem; color:#cbd5e1; margin-bottom:12px; }
        .empty-state p { color:#64748b; }

        .pagination-custom {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        }

        .pagination-controls {
            display:flex;
            gap:5px;
            list-style:none;
            padding:0;
            margin:0;
        }

        .pagination-controls a {
            display:block;
            padding:6px 10px;
            background:#fff;
            border:1px solid var(--neutral-200);
            border-radius:8px;
            text-decoration:none;
            color:var(--neutral-700);
            font-size:.78rem;
        }

        .pagination-controls .active a {
            background:var(--primary);
            color:#fff;
            border-color:var(--primary);
        }

        .pagination-controls .disabled a {
            opacity:.45;
            pointer-events:none;
        }

        .modal-content { border:0; border-radius:18px; box-shadow:var(--shadow-lg); }
        .modal-header { border-bottom:1px solid var(--neutral-200); }
        .modal-footer { border-top:1px solid var(--neutral-200); }

        @media(max-width:1200px) {
            .filter-grid { grid-template-columns:repeat(3,minmax(180px,1fr)); }
        }

        @media(max-width:992px) {
            .main-content { margin-left:0; }
            .dashboard-content { padding:16px; }
            .stats-grid { grid-template-columns:repeat(2,1fr); }
            .filter-grid { grid-template-columns:repeat(2,minmax(180px,1fr)); }
        }

        @media(max-width:600px) {
            .stats-grid { grid-template-columns:1fr; }
            .filter-grid { grid-template-columns:1fr; }
            .filter-actions { flex-direction:column; }
            .filter-actions .btn { width:100%; }
            .bulk-toolbar .selected-label { width:100%; margin-left:0; }
        }

        @media print {
            .main-content { margin-left:0; }
            .no-print, .sidebar, header, .quick-actions, .advanced-box, .bulk-toolbar, .card-footer-custom, .actions-col { display:none !important; }
            .dashboard-content { padding:0; max-width:none; }
            .main-card { border:0; box-shadow:none; }
            .table-wrap { border:0; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">

    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/../includes/header.php'; ?>

        <div class="dashboard-content">

            <div class="breadcrumb-container">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
                        </li>
                        <li class="breadcrumb-item active">Members</li>
                    </ol>
                </nav>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show no-print">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= h($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= h($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="stats-grid no-print">
                <div class="stat-card" onclick="location.href='view_members.php'">
                    <div class="stat-icon text-primary"><i class="fas fa-users"></i></div>
                    <div class="stat-label">Total Members</div>
                    <div class="stat-number text-primary"><?= number_format($stats['total']) ?></div>
                </div>
                <div class="stat-card" onclick="location.href='view_members.php?status=active'">
                    <div class="stat-icon text-success"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-label">Active</div>
                    <div class="stat-number text-success"><?= number_format($stats['active']) ?></div>
                </div>
                <div class="stat-card" onclick="location.href='view_members.php?status=expiring'">
                    <div class="stat-icon text-warning"><i class="fas fa-clock"></i></div>
                    <div class="stat-label">Expiring Soon</div>
                    <div class="stat-number text-warning"><?= number_format($stats['expiring']) ?></div>
                </div>
                <div class="stat-card" onclick="location.href='view_members.php?status=expired'">
                    <div class="stat-icon text-danger"><i class="fas fa-exclamation-circle"></i></div>
                    <div class="stat-label">Expired</div>
                    <div class="stat-number text-danger"><?= number_format($stats['expired']) ?></div>
                </div>
            </div>

            <div class="main-card">

                <div class="card-header-custom">
                    <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                        <div>
                            <h5 class="mb-1 fw-bold">
                                <i class="fas fa-user-friends text-primary me-2"></i>Member Directory
                            </h5>
                            <div class="small muted">
                                Original member fields are shown. Unwanted system fields are hidden.
                                <?php if ($selectedTemplateId > 0): ?>
                                    <span class="badge bg-primary ms-1">
                                        Template fields enabled: <?= h((string)($templateFilter)) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="quick-actions no-print">
                            <?php if ($canCreate): ?>
                                <a href="add_member.php" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-1"></i>Add Member
                                </a>
                            <?php endif; ?>

                            <a href="bulk_upload.php" class="btn btn-outline-secondary">
                                <i class="fas fa-upload me-1"></i>Bulk Upload
                            </a>

                            <a href="../generate_id_card.php" class="btn btn-outline-success">
                                <i class="fas fa-id-card me-1"></i>Generate ID
                            </a>

                            <button type="button" class="btn btn-outline-dark" onclick="window.print()">
                                <i class="fas fa-print me-1"></i>Print Page
                            </button>
                        </div>
                    </div>

                    <div class="advanced-box no-print">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="fw-bold">
                                    <i class="fas fa-sliders-h me-1"></i>Advanced Filter
                                </div>
                                <div class="small muted">
                                    Filter by original member data, template, ID range and date range.
                                </div>
                            </div>
                            <span class="badge bg-light text-dark border">
                                <?= number_format($totalMembers) ?> result(s)
                            </span>
                        </div>

                        <form method="get">
                            <div class="filter-grid">

                                <div class="filter-item" style="grid-column:span 2;">
                                    <label>Search</label>
                                    <input type="text" name="search" class="form-control"
                                           value="<?= h($search) ?>"
                                           placeholder="Name, Unique ID, Email, Phone, Company, Department, Class">
                                </div>

                                <div class="filter-item">
                                    <label>Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="expiring" <?= $statusFilter === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
                                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                                        <option value="no_expiry" <?= $statusFilter === 'no_expiry' ? 'selected' : '' ?>>No Expiry</option>
                                    </select>
                                </div>

                                <?php if ($isSuperAdmin): ?>
                                    <div class="filter-item">
                                        <label>Organization</label>
                                        <select name="org_id" class="form-select">
                                            <option value="">All Organizations</option>
                                            <?php foreach ($organizations as $org): ?>
                                                <option value="<?= (int)$org['id'] ?>" <?= $orgFilter === (string)$org['id'] ? 'selected' : '' ?>>
                                                    <?= h($org['organization_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="filter-item">
                                        <label>Project</label>
                                        <select name="project_type" class="form-select">
                                            <option value="">All Projects</option>
                                            <?php foreach ($projectTypes as $pt): ?>
                                                <option value="<?= h($pt) ?>" <?= $projectType === $pt ? 'selected' : '' ?>>
                                                    <?= h(ucfirst($pt)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="filter-item">
                                    <label>Template</label>
                                    <select name="template_id" class="form-select">
                                        <option value="">All Templates</option>
                                        <?php foreach ($templates as $tpl): ?>
                                            <option value="<?= (int)$tpl['id'] ?>" <?= $selectedTemplateId === (int)$tpl['id'] ? 'selected' : '' ?>>
                                                <?= h($tpl['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-item">
                                    <label>Photo</label>
                                    <select name="photo_filter" class="form-select">
                                        <option value="">All Photos</option>
                                        <option value="with" <?= $photoFilter === 'with' ? 'selected' : '' ?>>Photo Available</option>
                                        <option value="without" <?= $photoFilter === 'without' ? 'selected' : '' ?>>Photo Missing</option>
                                    </select>
                                </div>

                                <div class="filter-item">
                                    <label>Member ID From</label>
                                    <input type="number" min="1" name="id_from" class="form-control"
                                           value="<?= $idFrom ?: '' ?>" placeholder="Example: 100">
                                </div>

                                <div class="filter-item">
                                    <label>Member ID To</label>
                                    <input type="number" min="1" name="id_to" class="form-control"
                                           value="<?= $idTo ?: '' ?>" placeholder="Example: 200">
                                </div>

                                <div class="filter-item">
                                    <label>Joined From</label>
                                    <input type="date" name="joined_from" class="form-control" value="<?= h($joinedFrom) ?>">
                                </div>

                                <div class="filter-item">
                                    <label>Joined To</label>
                                    <input type="date" name="joined_to" class="form-control" value="<?= h($joinedTo) ?>">
                                </div>

                                <div class="filter-item">
                                    <label>Members</label>
                                    <select name="show_archived" class="form-select">
                                        <option value="">Active Members</option>
                                        <option value="1" <?= $showArchived ? 'selected' : '' ?>>Archived Members</option>
                                    </select>
                                </div>

                                <div class="filter-item">
                                    <label>Rows Per Page</label>
                                    <select name="per_page" class="form-select">
                                        <?php foreach ([15,25,50,100] as $pp): ?>
                                            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> rows</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <a href="view_members.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-rotate-left me-1"></i>Reset
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card-body-custom">

                    <form method="post" id="bulkMemberForm" action="view_members.php" class="no-print">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <div class="bulk-toolbar">
                            <button type="button" class="btn btn-sm btn-outline-dark" id="selectAllBtn">
                                <i class="fas fa-check-double me-1"></i>Select All
                            </button>

                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBtn">
                                <i class="fas fa-xmark me-1"></i>Clear
                            </button>

                            <?php if ($canEdit && !$showArchived): ?>
                                <button type="button" class="btn btn-sm btn-primary" id="bulkEditBtn">
                                    <i class="fas fa-pen-to-square me-1"></i>Edit Selected
                                </button>
                            <?php endif; ?>

                            <?php if ($canDelete && !$showArchived): ?>
                                <button type="submit" name="bulk_action" value="archive"
                                        class="btn btn-sm btn-warning"
                                        onclick="return confirmBulkAction('Archive selected members?')">
                                    <i class="fas fa-box-archive me-1"></i>Archive Selected
                                </button>
                            <?php endif; ?>

                            <?php if ($canEdit && $showArchived): ?>
                                <button type="submit" name="bulk_action" value="restore"
                                        class="btn btn-sm btn-success"
                                        onclick="return confirmBulkAction('Restore selected members?')">
                                    <i class="fas fa-rotate-left me-1"></i>Restore Selected
                                </button>
                            <?php endif; ?>

                            <?php if ($canPrint): ?>
                                <button type="submit" name="bulk_action" value="print"
                                        class="btn btn-sm btn-info"
                                        onclick="return confirmBulkAction('Print the selected ID cards?')">
                                    <i class="fas fa-print me-1"></i>Print Selected
                                </button>
                            <?php endif; ?>

                            <div class="selected-label">
                                <strong id="selectedCount">0</strong> selected
                            </div>
                        </div>

                        <!-- Range selection -->
                        <div class="advanced-box mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">SELECT ID RANGE</label>
                                    <input type="number" class="form-control form-control-sm" name="range_from"
                                           id="rangeFrom" min="1" placeholder="From ID">
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">&nbsp;</label>
                                    <input type="number" class="form-control form-control-sm" name="range_to"
                                           id="rangeTo" min="1" placeholder="To ID">
                                </div>
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="selectRangeBtn">
                                        <i class="fas fa-list-ol me-1"></i>Select Range
                                    </button>
                                </div>
                                <div class="col-md-auto">
                                    <span class="small muted">
                                        Example: From <b>101</b> To <b>150</b>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th style="width:35px;">
                                        <input type="checkbox" id="headerSelectAll">
                                    </th>
                                    <th>Photo</th>
                                    <th>Name / Unique ID</th>
                              
                                    <th>Organization</th>

                                    <?php if ($selectedTemplateId > 0): ?>
                                        <?php foreach ($dynamicColumns as $field): ?>
                                            <th><?= h($field['field_label']) ?></th>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <th>Template</th>
                                    <th>Status</th>
                                    <th>Expiry</th>
                                    <th class="actions-col" style="text-align:right;">Actions</th>
                                </tr>
                                </thead>

                                <tbody>
                                <?php if (!$members): ?>
                                    <tr>
                                        <td colspan="<?= max(8, $columnCount) ?>">
                                            <div class="empty-state">
                                                <i class="fas fa-user-slash"></i>
                                                <p>No members found for the current filters.</p>
                                                <?php if ($canCreate): ?>
                                                    <a href="add_member.php" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-user-plus me-1"></i>Add Member
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>

                                    <?php foreach ($members as $member): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       class="member-select"
                                                       name="member_ids[]"
                                                       value="<?= (int)$member['id'] ?>">
                                            </td>

                                            <td>
                                                <img src="<?= h(get_member_photo_path($member['photo'] ?? '')) ?>"
                                                     class="member-photo-thumb"
                                                     alt="Member photo">
                                            </td>

                                            <td>
                                                <div class="member-name"><?= h($member['name']) ?></div>
                                                <div class="small-text muted">
                                                    ID: <?= h($member['unique_id']) ?>
                                                </div>
                                            </td>

                                

                                            <td>
                                                <?php if (!empty($member['organization_name'])): ?>
                                                    <span class="org-badge">
                                                        <i class="fas fa-building"></i>
                                                        <?= h($member['organization_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="muted">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <?php if ($selectedTemplateId > 0): ?>
                                                <?php foreach ($dynamicColumns as $key => $field): ?>
                                                    <?php
                                                    $value = $member['_dynamic'][$key] ?? '';
                                                    ?>
                                                    <td class="data-value">
                                                        <?= $value !== '' ? h($value) : '<span class="muted">—</span>' ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            <?php endif; ?>

                                            <td>
                                                <?php if (!empty($member['template_name'])): ?>
                                                    <span class="template-badge">
                                                        <i class="fas fa-paintbrush"></i>
                                                        <?= h($member['template_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="muted">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= get_member_status_badge($member['expiry_date']) ?></td>

                                            <td>
                                                <?php if (!empty($member['expiry_date'])): ?>
                                                    <div><?= h(date('d-m-Y', strtotime($member['expiry_date']))) ?></div>
                                                    <div class="small-text muted">
                                                        <?= get_days_remaining($member['expiry_date']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="muted">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="actions-col" style="text-align:right;">
                                                <div class="btn-group btn-group-sm">

                                                    <a href="view_member.php?id=<?= (int)$member['id'] ?>"
                                                       class="btn btn-outline-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>

                                                    <?php if (!$showArchived): ?>

                                                        <?php if ($canEdit): ?>
                                                            <a href="edit_member.php?id=<?= (int)$member['id'] ?>"
                                                               class="btn btn-outline-secondary" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>

                                                            <a href="renew_member.php?id=<?= (int)$member['id'] ?>"
                                                               class="btn btn-outline-primary" title="Renew">
                                                                <i class="fas fa-rotate-right"></i>
                                                            </a>
                                                        <?php endif; ?>

                                                        <a href="../generate_id_card.php?member_id=<?= (int)$member['id'] ?>"
                                                           class="btn btn-outline-success" title="Generate ID">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>

                                                        <?php if ($canPrint): ?>
                                                            <a href="../card/print_id_card.php?id=<?= (int)$member['id'] ?>"
                                                               target="_blank"
                                                               class="btn btn-outline-warning"
                                                               title="Print">
                                                                <i class="fas fa-print"></i>
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if ($canDelete): ?>
                                                            <button type="button"
                                                                    class="btn btn-outline-danger"
                                                                    title="Archive"
                                                                    onclick="archiveOne(<?= (int)$member['id'] ?>, '<?= h($member['name']) ?>')">
                                                                <i class="fas fa-box-archive"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                    <?php else: ?>

                                                        <?php if ($canEdit): ?>
                                                            <button type="button"
                                                                    class="btn btn-outline-success"
                                                                    title="Restore"
                                                                    onclick="restoreOne(<?= (int)$member['id'] ?>)">
                                                                <i class="fas fa-rotate-left"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                    <?php endif; ?>

                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="card-footer-custom no-print">
                        <div class="pagination-custom">
                            <div class="small muted">
                                Showing
                                <strong><?= $totalMembers ? ($offset + 1) : 0 ?></strong>
                                -
                                <strong><?= min($offset + count($members), $totalMembers) ?></strong>
                                of
                                <strong><?= number_format($totalMembers) ?></strong>
                            </div>

                            <ul class="pagination-controls">
                                <?php
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                ?>
                                <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a href="<?= h(members_page_url($filterState, $prev)) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <li class="<?= $i === $page ? 'active' : '' ?>">
                                        <a href="<?= h(members_page_url($filterState, $i)) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a href="<?= h(members_page_url($filterState, $next)) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </main>
</div>

<!-- Bulk Edit Modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-pen-to-square me-2"></i>Edit Selected Members
                    </h5>
                    <div class="small muted">
                        Only checked fields will be changed. Unchecked fields remain unchanged.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="post" action="view_members.php" id="bulkEditForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="bulk_action" value="bulk_edit">

                <div id="bulkEditIds"></div>
                <input type="hidden" name="range_from" id="bulkEditRangeFrom">
                <input type="hidden" name="range_to" id="bulkEditRangeTo">

                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-circle-info me-2"></i>
                        <span id="bulkEditSelectedText">0 members selected.</span>
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="bulk_org_apply" id="applyOrganization">
                                <label class="form-check-label fw-semibold" for="applyOrganization">
                                    Update Organization
                                </label>
                            </div>
                            <select class="form-select" name="bulk_org_id" id="bulkOrgSelect">
                                <option value="">Select Organization</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?= (int)$org['id'] ?>">
                                        <?= h($org['organization_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="bulk_expiry_apply" id="applyExpiry">
                                <label class="form-check-label fw-semibold" for="applyExpiry">
                                    Update Expiry Date
                                </label>
                            </div>
                            <input type="date" class="form-control" name="bulk_expiry_date">
                        </div>

                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="applyTemplate">
                                <label class="form-check-label fw-semibold" for="applyTemplate">
                                    Change Template
                                </label>
                            </div>
                            <select name="bulk_template_id" id="bulkTemplate" class="form-select">
                                <option value="">Do not change template</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?= (int)$tpl['id'] ?>">
                                        <?= h($tpl['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBulkEdit">
                        <i class="fas fa-save me-1"></i>Update Selected
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- One-action hidden form -->
<form method="post" action="view_members.php" id="singleActionForm" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="bulk_action" id="singleActionType">
    <input type="hidden" name="member_ids[]" id="singleMemberId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Auto apply filter when a dropdown value is changed
document.querySelectorAll('.advanced-box form select').forEach(function (select) {
    select.addEventListener('change', function () {
        this.form.submit();
    });
});
(function () {
    const bulkForm = document.getElementById('bulkMemberForm');
    const boxes = () => Array.from(document.querySelectorAll('.member-select'));
    const countEl = document.getElementById('selectedCount');
    const headerAll = document.getElementById('headerSelectAll');

    function selectedBoxes() {
        return boxes().filter(cb => cb.checked);
    }

    function updateCount() {
        const n = selectedBoxes().length;
        if (countEl) countEl.textContent = n;
        if (headerAll) {
            const all = boxes();
            headerAll.checked = all.length > 0 && all.every(cb => cb.checked);
            headerAll.indeterminate = n > 0 && n < all.length;
        }
    }

    function requireSelection() {
        const selected = selectedBoxes();
        if (!selected.length) {
            alert('Please select at least one member.');
            return false;
        }
        return true;
    }

    function setAll(value) {
        boxes().forEach(cb => cb.checked = value);
        updateCount();
    }

    document.getElementById('selectAllBtn')?.addEventListener('click', () => setAll(true));
    document.getElementById('clearAllBtn')?.addEventListener('click', () => setAll(false));

    headerAll?.addEventListener('change', function () {
        setAll(this.checked);
    });

    boxes().forEach(cb => cb.addEventListener('change', updateCount));

    document.getElementById('selectRangeBtn')?.addEventListener('click', function () {
        const from = parseInt(document.getElementById('rangeFrom')?.value || '0', 10);
        const to = parseInt(document.getElementById('rangeTo')?.value || '0', 10);

        if (!from && !to) {
            alert('Enter From ID and To ID.');
            return;
        }

        let min = from || 1;
        let max = to || Number.MAX_SAFE_INTEGER;

        if (min > max) [min, max] = [max, min];

        boxes().forEach(cb => {
            const id = parseInt(cb.value, 10);
            cb.checked = id >= min && id <= max;
        });

        updateCount();
    });

    function confirmBulkAction(message) {
        if (!requireSelection()) return false;
        return confirm(message);
    }

    window.confirmBulkAction = confirmBulkAction;

    document.getElementById('bulkEditBtn')?.addEventListener('click', function () {
        const selected = selectedBoxes();

        if (!selected.length) {
            alert('Please select at least one member.');
            return;
        }

        const holder = document.getElementById('bulkEditIds');
        holder.innerHTML = '';

        selected.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'member_ids[]';
            input.value = cb.value;
            holder.appendChild(input);
        });

        document.getElementById('bulkEditSelectedText').textContent =
            selected.length + ' member(s) selected.';

        document.getElementById('bulkEditRangeFrom').value =
            document.getElementById('rangeFrom')?.value || '';
        document.getElementById('bulkEditRangeTo').value =
            document.getElementById('rangeTo')?.value || '';

        const modal = new bootstrap.Modal(document.getElementById('bulkEditModal'));
        modal.show();
    });

    document.getElementById('applyOrganization')?.addEventListener('change', function () {
        document.getElementById('bulkOrgSelect').disabled = !this.checked;
        if (!this.checked) {
            document.getElementById('bulkOrgSelect').value = '';
        }
    });

    document.getElementById('bulkOrgSelect').disabled = true;

    document.getElementById('applyTemplate')?.addEventListener('change', function () {
        document.getElementById('bulkTemplate').disabled = !this.checked;
        if (!this.checked) {
            document.getElementById('bulkTemplate').value = '';
        }
    });

    document.getElementById('bulkTemplate').disabled = true;

    document.getElementById('bulkEditForm')?.addEventListener('submit', function (e) {
        const applyAny =
            document.getElementById('applyOrganization')?.checked ||
            document.getElementById('applyExpiry')?.checked ||
            document.getElementById('applyTemplate')?.checked;

        if (!applyAny) {
            e.preventDefault();
            alert('Select at least one field to update.');
            return;
        }

        if (document.getElementById('applyOrganization')?.checked &&
            !document.getElementById('bulkOrgSelect')?.value) {
            e.preventDefault();
            alert('Please select an organization.');
            return;
        }

        if (document.getElementById('applyTemplate')?.checked &&
            !document.getElementById('bulkTemplate')?.value) {
            e.preventDefault();
            alert('Please select a template.');
            return;
        }

        if (!confirm('Update the selected members?')) {
            e.preventDefault();
        }
    });

    window.archiveOne = function (id, name) {
        if (!confirm('Archive member "' + name + '"?')) return;

        const form = document.getElementById('singleActionForm');
        document.getElementById('singleActionType').value = 'archive';
        document.getElementById('singleMemberId').value = id;
        form.submit();
    };

    window.restoreOne = function (id) {
        if (!confirm('Restore this member?')) return;

        const form = document.getElementById('singleActionForm');
        document.getElementById('singleActionType').value = 'restore';
        document.getElementById('singleMemberId').value = id;
        form.submit();
    };

    updateCount();

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            const search = document.querySelector('input[name="search"]');
            if (search) {
                e.preventDefault();
                search.focus();
            }
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            window.location.href = 'add_member.php';
        }
    });
})();
</script>
</body>
</html>