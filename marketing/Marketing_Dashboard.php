<?php
/**
 * CloudCup — Marketing Dashboard
 * Same visual pattern as finance.php: hero KPI cards, a ring stat,
 * a breakdown panel, trend chart, and per-campaign performance table.
 */
require __DIR__ . '/includes/marketing_data.php';

$activePage = 'dashboard';
$pageTitle  = 'Marketing — Dashboard';

$rangeLabels = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Marketing — CloudCup</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<link rel="stylesheet" href="css/admin_page.css">
<link rel="stylesheet" href="css/sidebar_admin.css">
<link rel="stylesheet" href="css/marketing.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script src="../js/sidebar-restore.js"></script>

  <?php include __DIR__ . '/includes/marketing_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/marketing_topbar.php'; ?>

    <div class="content">

      <!-- ===== Filter bar ===== -->
      <div class="filter-bar">
        <div class="preset-group">
          <?php foreach ($rangeLabels as $key => $label): ?>
            <a class="preset-btn <?= $range === $key ? 'active' : '' ?>"
               href="?range=<?= $key ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div>
        <form class="custom-range" method="get">
          <input type="hidden" name="range" value="custom">
          <input type="date" name="start" value="<?= $startDate->format('Y-m-d') ?>">
          <span>to</span>
          <input type="date" name="end" value="<?= $endDate->format('Y-m-d') ?>">
          <button type="submit" class="apply-btn">Apply</button>
        </form>
      </div>
      <div class="muted" style="font-size:13px;margin:-14px 0 20px;">
        Showing <?= $startDate->format('M d, Y') ?> – <?= $endDate->format('M d, Y') ?> · <?= $totalCampaigns ?> total campaigns
      </div>

      <!-- ===== Hero row ===== -->
      <div class="hero-grid">
        <div class="hero-card">
          <div class="hero-icon-circle">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 5.88V19.24a1.76 1.76 0 0 1-3.417.592l-2.147-6.15"></path>
              <path d="M18 13a3 3 0 1 0 0-6"></path>
              <path d="M5.436 13.683A4.001 4.001 0 0 1 7 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 0 1-1.564-.317z"></path>
            </svg>
          </div>
          <div>
            <div class="hero-label">Active Campaigns</div>
            <div class="hero-value"><?= $activeCampaigns ?> / <?= $totalCampaigns ?></div>
            <div class="hero-sub"><?= $featuredCampaign ? htmlspecialchars($featuredCampaign['title']) . ' is featured' : 'No campaign currently featured' ?></div>
          </div>
        </div>

        <div class="ring-card">
          <div class="ring-visual" style="background:conic-gradient(var(--blue-600) <?= min(100, $completionRate) ?>%, var(--page-bg) <?= min(100, $completionRate) ?>%);">
            <div class="ring-inner"><div class="ring-pct"><?= number_format($completionRate, 0) ?>%</div></div>
          </div>
          <div>
            <div class="ring-label">Video Completion Rate</div>
            <div class="ring-value"><?= $totalWatchCompletes ?> / <?= $totalWatchStarts ?></div>
            <div class="ring-sub">Completed watches / starts</div>
          </div>
        </div>

        <div class="hero-card" style="background:linear-gradient(135deg,var(--blue-700),var(--blue-600));">
          <div class="hero-icon-circle">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"></path>
              <path d="M13 13l6 6"></path>
            </svg>
          </div>
          <div>
            <div class="hero-label">Total Clicks</div>
            <div class="hero-value"><?= number_format($totalClicks) ?></div>
            <div class="hero-sub">In selected range</div>
          </div>
        </div>
      </div>

      <div class="dash-section-label">Performance</div>

      <div class="grid-2">
        <div class="panel">
          <div class="panel-title">Clicks Over Time</div>
          <?php if ($dailyClicks): ?>
            <div class="chart-box"><canvas id="clicksChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No clicks recorded in this range yet.</div>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="panel-title">Top Campaigns by Clicks</div>
          <?php if (array_sum(array_column($topCampaigns, 'clicks')) > 0): ?>
            <div class="chart-box"><canvas id="topCampaignsChart"></canvas></div>
          <?php else: ?>
            <div class="empty-state">No engagement yet in this range.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section-header">
        <h2>Campaign Performance</h2>
        <a class="btn-export" href="campaign_list.php">Manage All →</a>
        <div class="line"></div>
      </div>
      <div class="panel">
        <?php if ($campaignPerformance): ?>
          <table>
            <thead>
              <tr><th>Campaign</th><th>Type</th><th>Status</th><th style="text-align:right;">Clicks</th><th style="text-align:right;">Watch Completes</th></tr>
            </thead>
            <tbody>
              <?php foreach ($campaignPerformance as $c): ?>
                <tr>
                  <td class="thumb-title"><?= htmlspecialchars($c['title']) ?><?= $c['is_featured'] ? ' <span class="badge badge-featured">Featured</span>' : '' ?></td>
                  <td><span class="badge badge-<?= $c['media_type'] ?>"><?= ucfirst($c['media_type']) ?></span></td>
                  <td><span class="badge badge-<?= $c['is_active'] ? 'active' : 'inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                  <td style="text-align:right;font-weight:700;"><?= (int) $c['clicks'] ?></td>
                  <td style="text-align:right;" class="muted"><?= (int) $c['watch_completes'] ?> / <?= (int) $c['watch_starts'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-state">No campaigns yet. <a href="campaign_upload.php" style="color:var(--blue-700);font-weight:700;">Upload your first ad →</a></div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<script>
const dailyClicks = <?= json_encode(['labels' => array_map(fn($r) => (new DateTime($r['d']))->format('M d'), $dailyClicks), 'data' => array_map(fn($r) => (int) $r['clicks'], $dailyClicks)]) ?>;
const topCampaigns = <?= json_encode(['labels' => array_map(fn($r) => $r['title'], $topCampaigns), 'data' => array_map(fn($r) => (int) $r['clicks'], $topCampaigns)]) ?>;

if (dailyClicks.labels.length) {
  new Chart(document.getElementById('clicksChart'), {
    type: 'line',
    data: { labels: dailyClicks.labels, datasets: [{ label: 'Clicks', data: dailyClicks.data, borderColor: '#b8703f', backgroundColor: 'rgba(59,130,192,0.1)', fill: true, tension: 0.35 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
}
if (topCampaigns.labels.length) {
  new Chart(document.getElementById('topCampaignsChart'), {
    type: 'bar',
    data: { labels: topCampaigns.labels, datasets: [{ label: 'Clicks', data: topCampaigns.data, backgroundColor: '#b8703f', borderRadius: 6 }] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
  });
}
</script>
</body>
</html>