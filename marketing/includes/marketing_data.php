<?php
/**
 * CloudCup — Marketing data layer
 * Handles the date-range filter and all queries backing
 * Marketing_Dashboard.php. Mirrors finance/includes/finance_data.php's
 * approach: compute a range, then pull everything the page needs.
 */
require_once __DIR__ . '/../config.php';
marketing_require_login();

/* ---------------------------------------------------------------
   Date range (Today / This Week / This Month / This Year / Custom)
   --------------------------------------------------------------- */
$range = $_GET['range'] ?? 'month';
$today = new DateTime('today');

switch ($range) {
    case 'today':
        $startDate = (clone $today);
        $endDate   = (clone $today);
        break;
    case 'week':
        $startDate = (clone $today)->modify('monday this week');
        $endDate   = (clone $today);
        break;
    case 'year':
        $startDate = new DateTime($today->format('Y') . '-01-01');
        $endDate   = (clone $today);
        break;
    case 'custom':
        $startDate = !empty($_GET['start']) ? new DateTime($_GET['start']) : (clone $today)->modify('first day of this month');
        $endDate   = !empty($_GET['end'])   ? new DateTime($_GET['end'])   : (clone $today);
        break;
    case 'month':
    default:
        $range     = 'month';
        $startDate = (clone $today)->modify('first day of this month');
        $endDate   = (clone $today);
        break;
}
$startStr = $startDate->format('Y-m-d 00:00:00');
$endStr   = $endDate->format('Y-m-d 23:59:59');
$rangeQuery = http_build_query(['range' => $range, 'start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d')]);

/* ---------------------------------------------------------------
   Headline stats
   --------------------------------------------------------------- */
$totalCampaigns = (int) $pdo->query("SELECT COUNT(*) FROM marketing_campaigns")->fetchColumn();
$activeCampaigns = (int) $pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE is_active = 1")->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE is_featured = 1 AND is_active = 1 LIMIT 1");
$stmt->execute();
$featuredCampaign = $stmt->fetch() ?: null;

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM marketing_engagements
     WHERE event_type = 'click' AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$startStr, $endStr]);
$totalClicks = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM marketing_engagements
     WHERE event_type = 'watch_start' AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$startStr, $endStr]);
$totalWatchStarts = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM marketing_engagements
     WHERE event_type = 'watch_complete' AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$startStr, $endStr]);
$totalWatchCompletes = (int) $stmt->fetchColumn();

$completionRate = $totalWatchStarts > 0 ? ($totalWatchCompletes / $totalWatchStarts) * 100 : 0;

/* ---------------------------------------------------------------
   Per-campaign performance (clicks + watch completion within range)
   --------------------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT c.campaign_id, c.title, c.media_type, c.is_featured, c.is_active, c.created_at,
            SUM(CASE WHEN e.event_type = 'click' THEN 1 ELSE 0 END) AS clicks,
            SUM(CASE WHEN e.event_type = 'watch_start' THEN 1 ELSE 0 END) AS watch_starts,
            SUM(CASE WHEN e.event_type = 'watch_complete' THEN 1 ELSE 0 END) AS watch_completes
     FROM marketing_campaigns c
     LEFT JOIN marketing_engagements e
            ON e.campaign_id = c.campaign_id AND e.created_at BETWEEN ? AND ?
     GROUP BY c.campaign_id
     ORDER BY c.created_at DESC"
);
$stmt->execute([$startStr, $endStr]);
$campaignPerformance = $stmt->fetchAll();

/* ---------------------------------------------------------------
   Daily click trend (for the line chart)
   --------------------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS clicks
     FROM marketing_engagements
     WHERE event_type = 'click' AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at)
     ORDER BY d ASC"
);
$stmt->execute([$startStr, $endStr]);
$dailyClicks = $stmt->fetchAll();

/* ---------------------------------------------------------------
   Top performing campaigns by clicks (for the bar chart)
   --------------------------------------------------------------- */
$topCampaigns = $campaignPerformance;
usort($topCampaigns, fn($a, $b) => $b['clicks'] <=> $a['clicks']);
$topCampaigns = array_slice($topCampaigns, 0, 6);