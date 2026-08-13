<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../includes/card_renderer.php';

require_login();
$authUser = get_auth_user($pdo);
if (!$authUser) {
    die('Unauthorized');
}

$cardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cardId <= 0) {
    die('Invalid card ID. Please go back and try again.');
}

$sql = "SELECT g.image_path, g.member_id, g.template_id, g.created_at, g.organization_id AS card_organization_id,
        m.organization_id AS member_organization_id, m.name as member_name, m.unique_id, m.deleted_at,
        COALESCE(g.organization_id, m.organization_id) AS effective_organization_id,
        o.organization_name
        FROM generated_cards g
        LEFT JOIN id_members m ON g.member_id = m.id
        LEFT JOIN organizations o ON COALESCE(g.organization_id, m.organization_id) = o.id
        WHERE g.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$cardId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    die('Card not found. It may have been deleted.');
}

if (!user_can_access_organization($authUser, $card['effective_organization_id'] ?? null)) {
    die('You do not have permission to download this card.');
}

$memberId = (int)($card['member_id'] ?? 0);
$templateId = (int)($card['template_id'] ?? 0);
$includeBack = !isset($_GET['front_only']);
$mirror = isset($_GET['mirror']) && (string)$_GET['mirror'] !== '0';

$safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($card['unique_id'] ?: $card['member_id'] ?: 'card'));
$filename = 'ID_Card_' . $safeName . '_' . date('Ymd') . '.html';

// Prefer a self-contained HTML rebuild so Downloads folder works offline
if ($memberId > 0 && $templateId > 0 && empty($card['deleted_at'])) {
    try {
        $html = card_renderer_portable_document($pdo, $memberId, $templateId, $includeBack, $mirror);
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($html));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $html;
        exit();
    } catch (Throwable $e) {
        // Fall through to legacy stored file
    }
}

// Legacy fallback: serve stored file (may break offline if relative assets)
if (empty($card['image_path'])) {
    die('Card image not found. Please regenerate the card.');
}

$relative = ltrim(str_replace('\\', '/', (string)$card['image_path']), '/');
$filePath = realpath(__DIR__ . '/../' . $relative);
$baseRoot = realpath(__DIR__ . '/..');
if (!$filePath || !$baseRoot || !str_starts_with($filePath, $baseRoot) || !is_file($filePath)) {
    die('Card file not found on disk. Please regenerate the card.');
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
if (in_array($ext, ['html', 'htm'], true)) {
    $html = (string)file_get_contents($filePath);
    $html = card_renderer_embed_assets($html, $baseRoot);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($html));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $html;
    exit();
}

$outName = 'ID_Card_' . $safeName . '_' . date('Ymd') . '.' . $ext;
$mime = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $outName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($filePath);
exit();
