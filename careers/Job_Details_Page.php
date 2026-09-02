<?php
require_once __DIR__ . '/../includes/DB_Connect.php';

$job_id = (int)($_GET['id'] ?? 0);
$job = null;
if ($conn && $job_id > 0) {
    $s = mysqli_prepare($conn, "SELECT * FROM job_postings WHERE job_id = ? AND status = 'open'");
    mysqli_stmt_bind_param($s, 'i', $job_id);
    mysqli_stmt_execute($s);
    $job = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>(function(){try{var t=localStorage.getItem('cloudcup-theme')||'light';document.documentElement.classList.toggle('dark-mode',t==='dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="../css/theme.css"/>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $job ? htmlspecialchars($job['title']) . ' — Cloud Cup' : 'Position Not Found — Cloud Cup' ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/careers.css"/>
</head>
<body>

<nav>
  <div class="nav-logo"><a href="../auth/Landing_Page.php" class="logo-link"><span class="cloud">☁️</span> CLOUD CUP</a></div>
  <a href="../auth/Landing_Page.php#careers" class="nav-cta">Back to Job Offers</a>
</nav>

<?php if (!$job): ?>
  <section class="careers-hero">
    <h1>Position not found</h1>
    <p>This posting may have closed. <a href="../auth/Landing_Page.php#careers">See all current openings</a>.</p>
  </section>
<?php else: ?>
  <section class="job-detail">
    <div class="job-detail-header">
      <span class="job-type"><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $job['employment_type']))) ?></span>
      <h1><?= htmlspecialchars($job['title']) ?></h1>
      <div class="job-meta">
        <span><?= htmlspecialchars($job['department']) ?></span>
        <span>•</span>
        <span><?= htmlspecialchars($job['location']) ?></span>
      </div>
    </div>

    <div class="job-detail-columns">
      <div class="job-detail-body">
        <h3>About the Role</h3>
        <p><?= nl2br(htmlspecialchars($job['description'])) ?></p>

        <?php if (!empty($job['requirements'])): ?>
          <h3>What We're Looking For</h3>
          <ul class="job-detail-list">
            <?php foreach (array_filter(array_map('trim', explode("\n", $job['requirements']))) as $line): ?>
              <li><?= htmlspecialchars($line) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="job-benefits-card">
        <h3>Benefits</h3>
        <div class="job-benefits-sub"><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $job['employment_type']))) ?> · <?= htmlspecialchars($job['location']) ?></div>
        <?php if (!empty($job['benefits'])): ?>
          <ul class="job-detail-list">
            <?php foreach (array_filter(array_map('trim', explode("\n", $job['benefits']))) as $line): ?>
              <li><?= htmlspecialchars($line) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <a href="Apply_Page.php?id=<?= (int)$job['job_id'] ?>" class="apply-cta">Apply for This Role</a>
      </div>
    </div>
  </section>
<?php endif; ?>

<footer>
  <div class="footer-grid">
    <div class="footer-brand">
      <div class="logo">☁️ Cloud Cup</div>
      <p>A neighborhood coffee shop built on craftsmanship, community, and the belief that a great cup of coffee can change your entire day.</p>
    </div>
    <div class="footer-col">
      <h4>About</h4>
      <ul>
        <li><a href="../auth/Landing_Page.php#home">Home</a></li>
        <li><a href="../auth/Landing_Page.php#about">About</a></li>
        <li><a href="../auth/Landing_Page.php#menu">Menu</a></li>
        <li><a href="../auth/Landing_Page.php#info">Info</a></li>
        <li><a href="../auth/Landing_Page.php#careers">Careers</a></li>
        <li><a href="../auth/Login_Page.php">Login</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Contact</h4>
      <ul>
        <li><a href="mailto:hello@cloudcup.com">hello@cloudcup.com</a></li>
        <li><a href="tel:+10000000000">(000) 000-0000</a></li>
        <li>123 Main St, Your City</li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>© 2026 Cloud Cup. All rights reserved.</p>
    <p style="color:rgba(255,255,255,0.2)">☁️ Made with love, served with soul.</p>
  </div>
</footer>

<script src="../js/theme-toggle.js"></script>
</body>
</html>
