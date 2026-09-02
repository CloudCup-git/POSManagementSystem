<?php
/**
 * CloudCup — Campaign management actions (feature / toggle active / archive).
 * POST-only, called from forms on campaign_list.php.
 */
require __DIR__ . '/config.php';
marketing_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaign_list.php');
    exit;
}

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$action     = $_POST['action'] ?? '';

if ($campaignId <= 0 || !in_array($action, ['feature', 'toggle_active', 'archive'], true)) {
    header('Location: campaign_list.php');
    exit;
}

switch ($action) {
    case 'feature':
        // Only one campaign can be featured at a time.
        $pdo->beginTransaction();
        $pdo->exec("UPDATE marketing_campaigns SET is_featured = 0");
        $stmt = $pdo->prepare("UPDATE marketing_campaigns SET is_featured = 1, is_active = 1 WHERE campaign_id = ?");
        $stmt->execute([$campaignId]);
        $pdo->commit();
        break;

    case 'toggle_active':
        $stmt = $pdo->prepare("SELECT is_active FROM marketing_campaigns WHERE campaign_id = ?");
        $stmt->execute([$campaignId]);
        $isCurrentlyActive = (bool) $stmt->fetchColumn();
        $newActiveState = $isCurrentlyActive ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE marketing_campaigns SET is_active = ? WHERE campaign_id = ?");
        $stmt->execute([$newActiveState, $campaignId]);

        header('Location: campaign_list.php?' . ($newActiveState ? 'activated=1' : 'deactivated=1'));
        exit;

    case 'archive':
        // Soft-delete: keep the row and its files on disk, just hide it from the
        // live list and pull it out of rotation. Nothing is permanently removed.
        $stmt = $pdo->prepare("UPDATE marketing_campaigns SET is_archived = 1, is_active = 0 WHERE campaign_id = ?");
        $stmt->execute([$campaignId]);

        header('Location: campaign_list.php?archived=1');
        exit;
}

header('Location: campaign_list.php');
exit;