<?php
/**
 * CloudCup — Public endpoint. No login required (visitors on the
 * landing page call this anonymously). Returns the single active +
 * featured campaign, or {"campaign": null} if none is set.
 */
require __DIR__ . '/../includes/DB_Connect.php';
header('Content-Type: application/json');

if (!$conn) {
    http_response_code(500);
    echo json_encode(['campaign' => null, 'error' => 'db_unavailable']);
    exit;
}

$sql = "SELECT campaign_id, title, description, media_type, file_path, thumbnail_path, cta_label, cta_url
        FROM marketing_campaigns
        WHERE is_featured = 1 AND is_active = 1
        LIMIT 1";
$result = mysqli_query($conn, $sql);
$campaign = $result ? mysqli_fetch_assoc($result) : null;

if ($campaign) {
    // Paths are stored relative to /marketing/ — rewrite to be usable from /auth/.
    $campaign['file_path'] = '../marketing/' . $campaign['file_path'];
    if ($campaign['thumbnail_path']) {
        $campaign['thumbnail_path'] = '../marketing/' . $campaign['thumbnail_path'];
    }
}

echo json_encode(['campaign' => $campaign ?: null]);
