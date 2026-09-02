<?php
// ── Acknowledge a staff "low stock" report (Admin only) ──────────────────
session_start();
require_once 'DB_Connect.php';

header('Content-Type: application/json');

$is_admin_session = isset($_SESSION['user_id'], $_SESSION['role'])
                     && strtolower($_SESSION['role']) === 'admin';

if (!$is_admin_session) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'No database connection.']);
    exit;
}

$alert_id = (int)($_POST['alert_id'] ?? 0);
$mark_all = ($_POST['mark_all'] ?? '') === '1';

if ($mark_all) {
    mysqli_query($conn, "UPDATE stock_alerts SET status='acknowledged', acknowledged_at=NOW() WHERE status='unread'");
    echo json_encode(['success' => true]);
    exit;
}

if ($alert_id > 0) {
    $stmt = mysqli_prepare($conn,
        "UPDATE stock_alerts SET status='acknowledged', acknowledged_at=NOW() WHERE alert_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $alert_id);
    mysqli_stmt_execute($stmt);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Missing alert_id.']);
