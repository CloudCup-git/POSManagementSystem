<?php
/**
 * CloudCup — Public endpoint. No login required. Called via fetch()
 * from the landing page popup to log a click or video watch event.
 * Accepts JSON body: { campaign_id, event_type, watch_seconds?, session_id? }
 */
require __DIR__ . '/../includes/DB_Connect.php';
header('Content-Type: application/json');

$allowedEvents = ['click', 'watch_start', 'watch_25', 'watch_50', 'watch_75', 'watch_complete'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$campaignId  = (int) ($input['campaign_id'] ?? 0);
$eventType   = $input['event_type'] ?? '';
$watchSeconds = isset($input['watch_seconds']) ? (float) $input['watch_seconds'] : null;
$sessionId   = substr((string) ($input['session_id'] ?? ''), 0, 64) ?: null;

if (!$conn || $campaignId <= 0 || !in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO marketing_engagements (campaign_id, event_type, watch_seconds, session_id) VALUES (?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, 'isds', $campaignId, $eventType, $watchSeconds, $sessionId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => (bool) $ok]);
