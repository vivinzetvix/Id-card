<?php

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_helpers.php';

require_login();

$authUser = get_auth_user($pdo);

if (!$authUser) {
    $_SESSION['member_error'] = 'Authentication required.';
    header('Location: view_members.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Only POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['member_error'] = 'Invalid request.';
    header('Location: view_members.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');

if (
    $csrfToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $csrfToken)
) {
    $_SESSION['member_error'] = 'Invalid security token. Please refresh the page.';
    header('Location: view_members.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Action
|--------------------------------------------------------------------------
*/
$action = trim((string)($_POST['bulk_action'] ?? ''));

$allowedActions = [
    'archive',
    'restore',
    'print'
];

if (!in_array($action, $allowedActions, true)) {
    $_SESSION['member_error'] = 'Invalid bulk action.';
    header('Location: view_members.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Member IDs
|--------------------------------------------------------------------------
*/
$rawIds = $_POST['member_ids'] ?? [];

if (!is_array($rawIds)) {
    $rawIds = [$rawIds];
}

$ids = [];

foreach ($rawIds as $id) {

    $id = filter_var($id, FILTER_VALIDATE_INT);

    if ($id !== false && $id > 0) {
        $ids[] = (int)$id;
    }
}

$ids = array_values(array_unique($ids));

if (empty($ids)) {
    $_SESSION['member_error'] =
        'Please select at least one member.';
    header('Location: view_members.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/
if ($action === 'archive') {

    require_permission(
        $pdo,
        'Members',
        'Delete'
    );

} elseif ($action === 'restore') {

    require_permission(
        $pdo,
        'Members',
        'Edit'
    );

} elseif ($action === 'print') {

    require_permission(
        $pdo,
        'Members',
        'Print'
    );
}

/*
|--------------------------------------------------------------------------
| PRINT
|--------------------------------------------------------------------------
|
| Archived members should not be printable.
|
*/
if ($action === 'print') {

    $validIds = [];

    foreach ($ids as $memberId) {

        $member = fetch_member_for_user(
            $pdo,
            $authUser,
            $memberId,
            false
        );

        if ($member && empty($member['deleted_at'])) {
            $validIds[] = $memberId;
        }
    }

    if (empty($validIds)) {

        $_SESSION['member_error'] =
            'No active members were selected for printing.';

        header('Location: view_members.php');
        exit();
    }

    header(
        'Location: ../card/bulk_print.php?ids=' .
        implode(',', $validIds)
    );

    exit();
}

/*
|--------------------------------------------------------------------------
| ARCHIVE / RESTORE
|--------------------------------------------------------------------------
*/
$processed = 0;
$skipped = 0;
$errors = [];

try {

    $pdo->beginTransaction();

    foreach ($ids as $memberId) {

        /*
         * For restore we must be able to fetch archived members.
         */
        $includeArchived = ($action === 'restore');

        $member = fetch_member_for_user(
            $pdo,
            $authUser,
            $memberId,
            $includeArchived
        );

        if (!$member) {

            $errors[] =
                "Member #{$memberId}: member not found or access denied.";

            continue;
        }

        $currentlyArchived =
            !empty($member['deleted_at']);

        /*
         * ARCHIVE
         */
        if ($action === 'archive') {

            if ($currentlyArchived) {
                $skipped++;
                continue;
            }

            /*
             * IMPORTANT:
             * Soft delete only.
             * Do NOT DELETE FROM id_members.
             */
            $stmt = $pdo->prepare(
                'UPDATE id_members
                 SET deleted_at = NOW()
                 WHERE id = ?
                   AND deleted_at IS NULL'
            );

            $stmt->execute([
                $memberId
            ]);

            if ($stmt->rowCount() === 1) {

                member_log_audit(
                    $pdo,
                    (int)($authUser['id'] ?? 0),
                    (int)($member['organization_id'] ?? 0) ?: null,
                    'Member Archived',
                    'Bulk archived member: ' .
                    ($member['name'] ?? 'Unknown') .
                    ' (ID: ' .
                    $memberId .
                    ')'
                );

                $processed++;

            } else {

                $errors[] =
                    "Member #{$memberId}: could not be archived.";
            }
        }

        /*
         * RESTORE
         */
        elseif ($action === 'restore') {

            if (!$currentlyArchived) {
                $skipped++;
                continue;
            }

            /*
             * Restore only.
             */
            $stmt = $pdo->prepare(
                'UPDATE id_members
                 SET deleted_at = NULL
                 WHERE id = ?
                   AND deleted_at IS NOT NULL'
            );

            $stmt->execute([
                $memberId
            ]);

            if ($stmt->rowCount() === 1) {

                member_log_audit(
                    $pdo,
                    (int)($authUser['id'] ?? 0),
                    (int)($member['organization_id'] ?? 0) ?: null,
                    'Member Restored',
                    'Bulk restored member: ' .
                    ($member['name'] ?? 'Unknown') .
                    ' (ID: ' .
                    $memberId .
                    ')'
                );

                $processed++;

            } else {

                $errors[] =
                    "Member #{$memberId}: could not be restored.";
            }
        }
    }

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Do not expose database details to the user.
     */
    error_log(
        'Bulk member action failed: ' .
        $e->getMessage()
    );

    $_SESSION['member_error'] =
        'Unable to complete the bulk action. Please try again.';

    header(
        'Location: ' .
        ($action === 'restore'
            ? 'view_members.php?show_archived=1'
            : 'view_members.php')
    );

    exit();
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/
if ($processed > 0) {

    if ($action === 'archive') {

        $_SESSION['member_message'] =
            $processed .
            ' member(s) archived successfully.';

    } elseif ($action === 'restore') {

        $_SESSION['member_message'] =
            $processed .
            ' member(s) restored successfully.';
    }
}

if ($skipped > 0) {

    $skipMessage =
        $skipped .
        ' member(s) were skipped because they were already in the requested status.';

    if (!empty($_SESSION['member_message'])) {

        $_SESSION['member_message'] .=
            ' ' . $skipMessage;

    } else {

        $_SESSION['member_message'] =
            $skipMessage;
    }
}

if (!empty($errors)) {

    $_SESSION['member_error'] =
        implode(' ', $errors);
}

/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/
if ($action === 'restore') {

    header(
        'Location: view_members.php?show_archived=1'
    );

} else {

    header(
        'Location: view_members.php'
    );
}

exit();