<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

$id = (int)($_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['organization_error'] = 'Invalid security token.';
    } else {
        $stmt = $pdo->prepare('SELECT logo, project_type FROM organizations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $organization = $stmt->fetch(PDO::FETCH_ASSOC);

        $name = trim($_POST['organization_name'] ?? '');
        $code = trim($_POST['organization_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $organizationType = trim($_POST['organization_type'] ?? 'company');
        $projectType = strtolower(trim($_POST['project_type'] ?? (string)($organization['project_type'] ?? '')));
        $status = isset($_POST['status']) ? 1 : 0;
        $logoName = $organization['logo'] ?? null;

        if (!$organization) {
            $_SESSION['organization_error'] = 'Organization not found.';
        } elseif ($name === '' || $code === '' || $email === '') {
            $_SESSION['organization_error'] = 'Organization name, code and email are required.';
        } elseif (!organization_project_type_is_valid($projectType)
            || $projectType !== strtolower((string)$organization['project_type'])) {
            $_SESSION['organization_error'] = 'Organization category is locked after creation. Create a new organization to use a different category.';
        } else {
            $dup = $pdo->prepare('SELECT id FROM organizations WHERE (organization_code = ? OR email = ?) AND id != ? LIMIT 1');
            $dup->execute([$code, $email, $id]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['organization_error'] = 'An organization with the same code or email already exists.';
            } else {
                if (!empty($_FILES['logo']['name'])) {
                    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
                    if (!$upload['success']) {
                        $_SESSION['organization_error'] = $upload['message'];
                        header('Location: edit.php?id=' . $id);
                        exit();
                    }
                    if (!empty($organization['logo'])) {
                        delete_logo_file($organization['logo']);
                    }
                    $logoName = $upload['file'];
                }

                $stmt = $pdo->prepare("UPDATE organizations SET organization_name = ?, organization_code = ?, logo = ?, address = ?, phone = ?, email = ?, website = ?, organization_type = ?, status = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$name, $code, $logoName, $address, $phone, $email, $website, $organizationType, $status, get_current_user_id($pdo), $id]);
                log_organization_activity($pdo, 'Updated organization', 'organization', 'Updated organization ' . $name, $id);
                $_SESSION['organization_message'] = 'Organization updated successfully.';
            }
        }
    }
}

header('Location: index.php');
exit();
