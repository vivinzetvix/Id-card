<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['organization_error'] = 'Invalid security token.';
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['organization_error'] = 'Invalid organization selected.';
    header('Location: index.php');
    exit();
}

$stmt = $pdo->prepare('SELECT logo FROM organizations WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$organization = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($_FILES['logo']['name'])) {
    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
    if (!$upload['success']) {
        $_SESSION['organization_error'] = $upload['message'];
    } else {
        if (!empty($organization['logo'])) {
            delete_logo_file($organization['logo']);
        }
        $pdo->prepare('UPDATE organizations SET logo = ?, updated_by = ? WHERE id = ?')->execute([$upload['file'], get_current_user_id($pdo), $id]);
        log_organization_activity($pdo, 'Updated organization logo', 'organization', 'Updated organization logo for ' . $id, $id);
        $_SESSION['organization_message'] = 'Logo updated successfully.';
    }
}

header('Location: view.php?id=' . $id);
exit();
