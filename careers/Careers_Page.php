<?php
require_once __DIR__ . '/../includes/DB_Connect.php';

$jobs = [];
if ($conn) {
    $res = mysqli_query($conn, "SELECT * FROM job_postings WHERE status = 'open' ORDER BY created_at DESC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $jobs[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Careers — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,700;0,800;0,900;1,700;1,800&family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@1,400;1,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/careers.css"/>
</head>
<body>

<nav>
  <div class="nav-logo"><a href="../auth/Landing_Page.php" class="logo-link"><span class="cloud">☁️</span> CLOUD CUP</a></div>
  <a href="../auth/Landing_Page.php" class="nav-cta">← Back to Home</a>
</nav>

<section class="careers-hero">
  <div class="careers-eyebrow">Join the Team</div>
  <h1>Careers at Cloud Cup</h1>
  <p>We're always looking for people who care about coffee, craft, and community. Take a look at what's open right now.</p>
</section>

<section class="careers-list">
  <?php if (!$jobs): ?>
    <div class="no-jobs">There are no open positions right now. Check back soon — new roles are posted regularly.</div>
  <?php endif; ?>

  <?php foreach ($jobs as $j): ?>
    <a class="job-card" href="Job_Details_Page.php?id=<?= (int)$j['job_id'] ?>">
      <div class="job-card-top">
        <h2><?= htmlspecialchars($j['title']) ?></h2>
        <span class="job-type"><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $j['employment_type']))) ?></span>
      </div>
      <div class="job-meta">
        <span><?= htmlspecialchars($j['department']) ?></span>
        <span>•</span>
        <span><?= htmlspecialchars($j['location']) ?></span>
      </div>
      <p class="job-snippet"><?= htmlspecialchars(mb_strimwidth($j['description'], 0, 160, '…')) ?></p>
      <span class="job-link">View details &amp; apply →</span>
    </a>
  <?php endforeach; ?>
</section>

<footer>
  <div class="footer-brand">
    <div class="logo">☁️ Cloud Cup</div>
    <p>A neighborhood coffee shop built on craftsmanship, community, and the belief that a great cup of coffee can change your entire day.</p>
  </div>
</footer>

</body>
</html>
