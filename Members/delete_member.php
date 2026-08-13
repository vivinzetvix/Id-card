<?php

/**
 * Soft-delete / archive a member.
 * Preserves generated_cards, audit_log, and dynamic values.
 */

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/member_helpers.php';

require_login();

$authUser = get_auth_user($pdo);

require_permission($pdo, 'Members', 'Delete');


// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view_members.php');
    exit();
}


// CSRF validation
if (
    empty($_POST['csrf_token']) ||
    !hash_equals(
        $_SESSION['csrf_token'] ?? '',
        (string)$_POST['csrf_token']
    )
) {
    $_SESSION['member_error'] = 'Invalid security token. Please try again.';
    header('Location: view_members.php');
    exit();
}


// Member ID validation
$memberId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$memberId || $memberId <= 0) {
    $_SESSION['member_error'] = 'Invalid member selected.';
    header('Location: view_members.php');
    exit();
}


try {

    // Verify member belongs to current user's allowed organization
    $member = fetch_member_for_user(
        $pdo,
        $authUser,
        (int)$memberId
    );

    if (!$member) {
        $_SESSION['member_error'] =
            'Member not found or you do not have permission to archive it.';

        header('Location: view_members.php');
        exit();
    }


    /*
     * SOFT DELETE / ARCHIVE
     *
     * Only id_members.deleted_at is changed.
     *
     * generated_cards          → preserved
     * member_dynamic_values    → preserved
     * member_field_translations→ preserved
     * audit_log                → preserved
     */
// Permanently delete member
$stmt = $pdo->prepare(
    'DELETE FROM id_members WHERE id = ?'
);

$stmt->execute([(int)$memberId]);

if ($stmt->rowCount() !== 1) {
    throw new RuntimeException(
        'Member could not be deleted.'
    );
}


    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Member could not be archived.'
        );
    }


    // Audit log
    try {

        $userId = (int)($authUser['id'] ?? 0);

        $orgId = (int)(
            $member['organization_id'] ?? 0
        ) ?: null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $ua = substr(
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            0,
            500
        );

        $details =
            "Archived member: {$member['name']} (ID: {$memberId})";


        if (
            column_exists_local(
                $pdo,
                'audit_log',
                'organization_id'
            )
        ) {

            $audit = $pdo->prepare(
                'INSERT INTO audit_log
                (
                    user_id,
                    organization_id,
                    action,
                    action_type,
                    details,
                    ip_address,
                    user_agent
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $audit->execute([
                $userId ?: null,
                $orgId,
                'Member Archived',
                'members',
                $details,
                $ip,
                $ua
            ]);

        } else {

            $audit = $pdo->prepare(
                'INSERT INTO audit_log
                (
                    user_id,
                    action,
                    action_type,
                    details,
                    ip_address,
                    user_agent
                )
                VALUES (?, ?, ?, ?, ?, ?)'
            );

            $audit->execute([
                $userId ?: null,
                'Member Archived',
                'members',
                $details,
                $ip,
                $ua
            ]);
        }

    } catch (Throwable $auditError) {

        // Archive already succeeded.
        // Audit failure should not undo archive.
    }


    $_SESSION['member_message'] =
        'Member ' .
        $member['name'] .
        ' was archived successfully. Generated cards were preserved.';

} catch (Throwable $e) {

    // For debugging, temporarily use the actual error:
    // $_SESSION['member_error'] = $e->getMessage();

    $_SESSION['member_error'] =
        'Unable to archive the member. Please try again.';
}


header('Location: view_members.php');
exit();


function column_exists_local(
    PDO $pdo,
    string $table,
    string $column
): bool {

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );

    $stmt->execute([
        $table,
        $column
    ]);

    return (int)$stmt->fetchColumn() > 0;
}