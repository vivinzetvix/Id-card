<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_helpers.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Members', 'Edit');

$memberId = (int)($_POST['member_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])
    || $memberId <= 0) {
    $_SESSION['member_error'] = 'Invalid request.';
    header('Location: view_members.php');
    exit();
}

$member = fetch_member_for_user($pdo, $authUser, $memberId, true);
if (!$member || empty($member['deleted_at'])) {
    $_SESSION['member_error'] = 'Member not found, access denied, or not archived.';
    header('Location: view_members.php' . (!empty($_POST['show_archived']) ? '?show_archived=1' : ''));
    exit();
}

$result = restore_member($pdo, $memberId);
if ($result['success']) {
    member_log_audit(
        $pdo,
        (int)($authUser['id'] ?? 0),
        (int)($member['organization_id'] ?? 0) ?: null,
        'Member Restored',
        'Restored member: ' . $member['name'] . ' (ID: ' . $memberId . ')'
    );
    $_SESSION['member_message'] = 'Member restored successfully.';
} else {
    $_SESSION['member_error'] = $result['error'] ?? 'Failed to restore member.';
}

header('Location: view_members.php' . (!empty($_POST['show_archived']) ? '?show_archived=1' : ''));
exit();
