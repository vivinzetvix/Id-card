<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

require_admin_access($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['organization_error'] = 'Invalid security token.';
    } else {
        $name = trim($_POST['organization_name'] ?? '');
        $code = trim($_POST['organization_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $organizationType = trim($_POST['organization_type'] ?? 'company');
        $projectType = strtolower(trim($_POST['project_type'] ?? 'corporate'));
        $status = isset($_POST['status']) ? 1 : 0;

        if ($name === '' || $code === '' || $email === '') {
            $_SESSION['organization_error'] = 'Organization name, code and email are required.';
        } elseif (!organization_project_type_is_valid($projectType)) {
            $_SESSION['organization_error'] = 'Please select either Residence or Corporate as the organization category.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM organizations WHERE organization_code = ? OR email = ? LIMIT 1');
            $stmt->execute([$code, $email]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['organization_error'] = 'An organization with the same code or email already exists.';
            } else {
                $logo = null;
                if (!empty($_FILES['logo']['name'])) {
                    $upload = upload_organization_logo($_FILES['logo'], __DIR__ . '/assets/uploads/logo');
                    if (!$upload['success']) {
                        $_SESSION['organization_error'] = $upload['message'];
                        header('Location: add.php');
                        exit();
                    }
                    $logo = $upload['file'];
                }

                $stmt = $pdo->prepare("INSERT INTO organizations (organization_name, organization_code, logo, address, phone, email, website, organization_type, project_type, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $code, $logo, $address, $phone, $email, $website, $organizationType, $projectType, $status, get_current_user_id($pdo), get_current_user_id($pdo)]);
                log_organization_activity($pdo, 'Created organization', 'organization', 'Created organization ' . $name, $pdo->lastInsertId());
                $_SESSION['organization_message'] = 'Organization added successfully.';
            }
        }
    }
}

header('Location: index.php');
exit();
