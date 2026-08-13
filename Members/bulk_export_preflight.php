<?php
/**
 * Bulk Export / Print Preflight Endpoint
 * Accepts GET/POST member_ids (array or CSV) and optional target template_id.
 * Checks whether all selected members have required data values for their target template.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/member_helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$authUser = get_auth_user($pdo);
if (!$authUser) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$rawIds = $_REQUEST['member_ids'] ?? '';
$targetTemplateId = (int)($_REQUEST['template_id'] ?? 0);

$memberIds = [];
if (is_array($rawIds)) {
    $memberIds = array_map('intval', $rawIds);
} else {
    $memberIds = array_filter(array_map('intval', explode(',', (string)$rawIds)));
}

if (empty($memberIds)) {
    echo json_encode(['error' => 'No member IDs provided']);
    exit();
}

$incompleteMembers = [];
$readyCount = 0;

foreach ($memberIds as $mId) {
    if ($mId <= 0) continue;

    // Fetch member
    $stmt = $pdo->prepare('SELECT id, name, unique_id, template_id FROM id_members WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$mId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) continue;

    $tplId = $targetTemplateId > 0 ? $targetTemplateId : (int)($m['template_id'] ?? 0);
    if ($tplId <= 0) {
        $incompleteMembers[] = [
            'member_id' => $mId,
            'member_name' => $m['name'],
            'unique_id' => $m['unique_id'],
            'template_name' => 'None assigned',
            'missing_required' => [['key' => 'template_id', 'label' => 'No template assigned']],
        ];
        continue;
    }

    $tStmt = $pdo->prepare('SELECT name FROM card_templates WHERE id = ?');
    $tStmt->execute([$tplId]);
    $tplName = $tStmt->fetchColumn() ?: ('Template #' . $tplId);

    $compat = member_check_template_compatibility($pdo, $mId, $tplId);

    if (!empty($compat['missing_required'])) {
        $missing = [];
        foreach ($compat['missing_required'] as $k => $def) {
            $missing[] = [
                'key' => $k,
                'label' => $def['field_label'] ?? $k,
            ];
        }
        $incompleteMembers[] = [
            'member_id' => $mId,
            'member_name' => $m['name'],
            'unique_id' => $m['unique_id'],
            'template_name' => $tplName,
            'missing_required' => $missing,
        ];
    } else {
        $readyCount++;
    }
}

echo json_encode([
    'success' => true,
    'total_checked' => count($memberIds),
    'ready_count' => $readyCount,
    'incomplete_count' => count($incompleteMembers),
    'incomplete_members' => $incompleteMembers,
]);
