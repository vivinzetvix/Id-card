<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/template_mgmt_helpers.php';

require_login();
$authUser = get_auth_user($pdo);
require_permission($pdo, 'Templates', 'Edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: templates.php');
    exit();
}

$templateId = (int)($_POST['template_id'] ?? 0);
$tpl = template_fetch_by_id($pdo, $templateId);
if (!$tpl || !template_user_can_manage($pdo, $authUser, $tpl)) {
    $_SESSION['error'] = 'Template not found or access denied.';
    header('Location: templates.php');
    exit();
}

$result = template_set_default($pdo, $templateId);
if ($result['success']) {
    template_log_audit(
        $pdo,
        (int)($authUser['id'] ?? 0),
        (int)($tpl['organization_id'] ?? 0) ?: null,
        'Set default template',
        "Template ID: {$templateId}"
    );
    $_SESSION['message'] = 'Default template updated for this organization.';
} else {
    $_SESSION['error'] = $result['error'] ?? 'Failed to set default template.';
}

header('Location: templates.php');
exit();
