<?php
require_once __DIR__ . '/../includes/DB_Connect.php';

// Never let the browser serve this page from its cache or bfcache — see
// the matching header() call in Login_Page.php for why: it guarantees
// the redirect-if-already-authed script below actually runs on every
// single Back press, even several in a row.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Branches (public) ──────────────────────────────────────────
// Only branches marked active are shown to customers. A branch without
// coordinates still appears in the card list, but won't get a map pin
// until an Admin sets its location in Branch Management.
$public_branches = [];
if ($conn) {
  $result = mysqli_query($conn, "SELECT branch_name, address, contact_number, operating_hours, latitude, longitude FROM branches WHERE status = 'active' ORDER BY branch_name ASC");
  if ($result) while ($branch = mysqli_fetch_assoc($result)) $public_branches[] = $branch;
}
$branch_markers = array_values(array_filter(array_map(static function ($branch) {
  if ($branch['latitude'] === null || $branch['longitude'] === null) return null;
  return ['name' => $branch['branch_name'], 'address' => $branch['address'], 'hours' => $branch['operating_hours'], 'contact' => $branch['contact_number'], 'lat' => (float)$branch['latitude'], 'lng' => (float)$branch['longitude']];
}, $public_branches)));

// ── Menu (public) ──────────────────────────────────────────────
// Only items an Admin/Manager has marked available show up here — this
// mirrors exactly what Menu Control considers "on the floor" right now.
$public_menu = [];
if ($conn) {
  $result = mysqli_query($conn, "SELECT item_name, category, price, image_path FROM menu_items WHERE is_available = 1 ORDER BY category, item_name");
  if ($result) while ($item = mysqli_fetch_assoc($result)) $public_menu[] = $item;
}

// ── Careers (public) ─────────────────────────────────────────────
// Same "open" roles HR's Job Postings page manages — closing a role there
// removes it from this page automatically.
$public_jobs = [];
if ($conn) {
  $result = mysqli_query($conn, "SELECT job_id, title, department, employment_type, location FROM job_postings WHERE status = 'open' AND deleted_at IS NULL ORDER BY created_at DESC");
  if ($result) while ($job = mysqli_fetch_assoc($result)) $public_jobs[] = $job;
}

// ── Small icon helper for menu cards (same visual language as the redesign) ──
function menu_icon(string $category): string {
  $icons = [
    'Coffee'      => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/>',
    'Non-Coffee'  => '<path d="M6 3h12l-1.2 8.5a4.8 4.8 0 0 1-9.6 0L6 3Z"/><line x1="12" y1="15.5" x2="12" y2="21"/><line x1="8" y1="21" x2="16" y2="21"/>',
    'Tea'         => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 1c0 1-1 1-1 2s1 1 1 2"/><path d="M10 1c0 1-1 1-1 2s1 1 1 2"/>',
    'Frappe'      => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 1c0 1-1 1-1 2s1 1 1 2"/><path d="M10 1c0 1-1 1-1 2s1 1 1 2"/>',
    'Refreshers'  => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 1c0 1-1 1-1 2s1 1 1 2"/><path d="M10 1c0 1-1 1-1 2s1 1 1 2"/>',
    'Pastries'    => '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
    'Cakes'       => '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
    'Add-ons'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
  ];
  $d = $icons[$category] ?? '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>';
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">' . $d . '</svg>';
}

// ── Groups each raw menu category into one of the filter-pill buckets ──
function menu_filter_group(string $category): string {
  $map = [
    'Coffee'     => 'coffee',
    'Non-Coffee' => 'non-coffee',
    'Pastries'   => 'pastries',
    'Cakes'      => 'pastries',
    'Tea'        => 'tea',
    'Frappe'     => 'tea',
    'Refreshers' => 'tea',
    'Add-ons'    => 'addons',
  ];
  return $map[$category] ?? 'other';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>
  // Pressing Back after logging in used to land here — either as a fresh
  // reload, or restored straight out of the browser's bfcache (which does
  // NOT re-run this script, only fires 'pageshow' with persisted=true).
  // Handle both: if this browser is still authenticated, bounce forward
  // in ONE hop straight to the dashboard Login_Page.php last sent it to
  // (cc_authed_target, set at login) — jumping there directly instead of
  // via Login_Page.php halves how long this redirect is in flight, which
  // matters because mashing Back several times in a row can otherwise
  // interrupt a still-in-progress redirect and undo it.
  function _ccRedirectIfAuthed() {
    try {
      if (localStorage.getItem('cc_authed') === '1') {
        var target = localStorage.getItem('cc_authed_target') || '../auth/Login_Page.php';
        window.location.replace(target);
      }
    } catch (err) { /* storage unavailable */ }
  }
  _ccRedirectIfAuthed();
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) _ccRedirectIfAuthed();
  });
  // Belt-and-suspenders for browsers/back-forward implementations that
  // restore a cached page without firing 'pageshow' at all: this page
  // becoming visible again is itself a reliable enough signal to re-check.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') _ccRedirectIfAuthed();
  });
  window.addEventListener('focus', _ccRedirectIfAuthed);
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cloud Cup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/landing_page.css"/>
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet"/>
<link rel="stylesheet" href="../marketing/css/ad_popup.css"/>
</head>
<body>

<div id="scroll-progress" aria-hidden="true"></div>

<header>
  <nav>
    <div class="logo">
      <svg viewBox="0 0 32 32" fill="none"><path d="M9 20c-3.3 0-6-2.7-6-6s2.7-6 6-6c.6-3.4 3.6-6 7.2-6 3.7 0 6.8 2.7 7.3 6.3 2.6.4 4.5 2.7 4.5 5.4 0 3-2.4 5.4-5.4 5.4H9z" fill="#ffffff"/></svg>
      Cloud Cup
    </div>
    <ul class="nav-links">
      <li><a href="#about">About</a></li>
      <li><a href="#menu">Menu</a></li>
      <li><a href="#info">Visit</a></li>
      <li><a href="#branches">Branches</a></li>
      <li><a href="#careers">Careers</a></li>
      <li><a href="#contact">Contact</a></li>
    </ul>
    <a href="../auth/Login_Page.php" class="btn btn-outline" style="padding:9px 20px;font-size:13.5px;">Login</a>
  </nav>
</header>

<section class="hero">
  <div class="blob blob-1" aria-hidden="true"></div>
  <div class="blob blob-2" aria-hidden="true"></div>
  <svg class="steam-cloud" style="top:60px; left:6%; width:70px;" viewBox="0 0 64 40" fill="#ffffffb0"><path d="M14 30c-7 0-13-5.8-13-13S7 4 14 4c1.3-7.4 7.8-13 15.6-13"/></svg>
  <svg class="steam-cloud" style="top:140px; right:8%; width:44px; animation-duration:11s; animation-delay:1.2s;" viewBox="0 0 64 40" fill="#ffffff90"><path d="M14 30c-7 0-13-5.8-13-13S7 4 14 4c1.3-7.4 7.8-13 15.6-13"/></svg>
  <svg class="bean bean-1" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2C7 2 3 6.5 3 12s4 10 9 10 9-4.5 9-10S17 2 12 2Z" fill="#1d161033"/><path d="M12 4c-3 3-3 13 0 16" stroke="#1d161055" stroke-width="1.4" stroke-linecap="round"/></svg>
  <svg class="bean bean-2" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2C7 2 3 6.5 3 12s4 10 9 10 9-4.5 9-10S17 2 12 2Z" fill="#b8703f40"/><path d="M12 4c-3 3-3 13 0 16" stroke="#4E756E66" stroke-width="1.4" stroke-linecap="round"/></svg>
  <svg class="bean bean-3" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2C7 2 3 6.5 3 12s4 10 9 10 9-4.5 9-10S17 2 12 2Z" fill="#1d161022"/><path d="M12 4c-3 3-3 13 0 16" stroke="#1d161044" stroke-width="1.4" stroke-linecap="round"/></svg>

  <!-- realistic floating coffee beans (real photos, cut out) -->
  <img class="bean-real bean-r1" src="../images/hero-coffee-beans.png" alt="" aria-hidden="true" loading="lazy">
  <img class="bean-real bean-r2" src="../images/hero-coffee-beans-flip.png" alt="" aria-hidden="true" loading="lazy">
  <img class="bean-real bean-r3" src="../images/hero-coffee-beans.png" alt="" aria-hidden="true" loading="lazy">
  <img class="bean-real bean-r4" src="../images/hero-coffee-beans-flip.png" alt="" aria-hidden="true" loading="lazy">

  <!-- realistic floating ice cubes (real photos, cut out) -->
  <img class="ice-cube ice-1" src="../images/hero-ice-cube-a.png" alt="" aria-hidden="true" loading="lazy">
  <img class="ice-cube ice-2" src="../images/hero-ice-cube-b.png" alt="" aria-hidden="true" loading="lazy">
  <img class="ice-cube ice-3" src="../images/hero-ice-cube-a.png" alt="" aria-hidden="true" loading="lazy">

  <div class="hero-inner">
    <div class="eyebrow">Freshly brewed every morning</div>
    <h1>Coffee with its<br><em>head in the clouds</em></h1>
    <p class="lede">Small-batch beans, roasted weekly, poured by people who actually care whether your Tuesday is going okay.</p>
  </div>
</section>

<section class="about" id="about">
  <div class="about-grid">
    <div class="about-art reveal reveal-left">
      <img src="../images/CoffeeBeans.jpg" alt="Coffee beans at Cloud Cup" loading="lazy">
      <div class="about-art-tint" aria-hidden="true"></div>
      <div class="ring" style="width:220px;height:220px;top:-40px;right:-50px;"></div>
      <div class="ring" style="width:120px;height:120px;bottom:30px;left:-20px;"></div>
    </div>
    <div class="reveal reveal-right">
      <div class="eyebrow">Our story</div>
      <h2>Started with one espresso machine and a very stubborn opinion about milk.</h2>
      <p>Cloud Cup opened as a single counter in 2019, roasting in small batches so every bag gets used within two weeks of arriving. No syrups pretending to be flavor — just good beans, dialed in properly.</p>
      <p>Today it's the same standard, just more hands on deck: the same recipe book, the same 6am roast checks, the same regulars who don't need to order out loud anymore.</p>
      <div class="fact-row">
        <div><div class="fact-num" data-count="2019">0</div><div class="fact-label">Founded</div></div>
        <div><div class="fact-num" data-count="<?= (int)count($public_menu) ?>">0</div><div class="fact-label">Items on the menu</div></div>
        <div><div class="fact-num" data-count="<?= (int)count($public_branches) ?>">0</div><div class="fact-label">Locations</div></div>
      </div>
    </div>
  </div>
</section>

<section class="menu" id="menu">
  <div class="menu-head reveal">
    <div class="eyebrow" style="justify-content:center; display:flex;">What we offer</div>
    <h2>A short list, done properly</h2>
    <p>No twelve-page menu — just the drinks and bites we've actually dialed in.</p>
  </div>
  <?php if ($public_menu): ?>
  <div class="menu-filters">
    <button type="button" class="menu-filter-btn active" data-filter="all">All</button>
    <button type="button" class="menu-filter-btn" data-filter="coffee">Coffee</button>
    <button type="button" class="menu-filter-btn" data-filter="non-coffee">Non-Coffee</button>
    <button type="button" class="menu-filter-btn" data-filter="pastries">Pastries</button>
    <button type="button" class="menu-filter-btn" data-filter="tea">Tea & Refreshers</button>
    <button type="button" class="menu-filter-btn" data-filter="addons">Add-ons</button>
  </div>
  <?php endif; ?>
  <div class="menu-grid">
    <?php if (!$public_menu): ?>
      <div class="menu-empty">Our menu is being updated — check back shortly.</div>
    <?php else: foreach ($public_menu as $i => $item):
      $img = trim($item['image_path'] ?? '');
      $filterGroup = menu_filter_group($item['category']); ?>
      <div class="menu-card reveal reveal-d<?= min(3, ($i % 3) + 1) ?>" data-filter="<?= htmlspecialchars($filterGroup) ?>">
        <?php if ($img): ?>
          <div class="menu-card-photo">
            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" loading="lazy">
          </div>
        <?php else: ?>
          <div class="icon-wrap"><?= menu_icon($item['category']) ?></div>
        <?php endif; ?>
        <h3><?= htmlspecialchars($item['item_name']) ?></h3>
        <p><?= htmlspecialchars($item['category']) ?></p>
        <span class="price">₱<?= number_format((float)$item['price'], 2) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</section>


<section class="info" id="info">
  <div class="info-head reveal">
    <div class="eyebrow" style="justify-content:center; display:flex;">Come say hi</div>
    <h2>Everything you need to visit</h2>
    <p>Hours, location, and the fastest way to skip the line.</p>
  </div>
  <div class="info-grid">
    <div class="info-card reveal reveal-d1">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <h3>Open daily</h3>
      <p><?php if ($public_branches && $public_branches[0]['operating_hours']): ?><?= htmlspecialchars($public_branches[0]['operating_hours']) ?><?php else: ?>See individual branch hours below<?php endif; ?></p>
    </div>
    <div class="info-card reveal reveal-d2">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
      <h3>Find us</h3>
      <p><?= (int)count($public_branches) ?: 'A few' ?> spot<?= count($public_branches) === 1 ? '' : 's' ?> around town — <a href="#branches" style="text-decoration:underline; color:var(--sage-dk); font-weight:600;">see all branches ↓</a></p>
    </div>
    <div class="info-card reveal reveal-d3">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19V7a2 2 0 0 1 2-2h9l5 5v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M9 3v4h6"/></svg>
      <h3>We're hiring</h3>
      <p><?= (int)count($public_jobs) ?> open role<?= count($public_jobs) === 1 ? '' : 's' ?> right now — <a href="#careers" style="text-decoration:underline; color:var(--sage-dk); font-weight:600;">see openings ↓</a></p>
    </div>
  </div>
</section>

<section class="branches" id="branches">
  <div class="branches-head reveal">
    <div class="eyebrow" style="justify-content:center; display:flex;">Find your Cloud Cup</div>
    <h2><?= (int)count($public_branches) ?: 'Our' ?> counter<?= count($public_branches) === 1 ? '' : 's' ?>, one standard</h2>
    <p>Drop by your nearest Cloud Cup for a freshly brewed cup.</p>
  </div>
  <div class="branches-layout">
    <div class="branches-grid">
      <?php if (!$public_branches): ?>
        <div class="branches-empty">Our branch details will be available soon.</div>
      <?php else: foreach ($public_branches as $branch): ?>
        <div class="branch-card reveal reveal-d1 public-branch-card"
             data-lat="<?= htmlspecialchars((string)$branch['latitude']) ?>"
             data-lng="<?= htmlspecialchars((string)$branch['longitude']) ?>">
          <div class="pin">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
          </div>
          <h3><?= htmlspecialchars($branch['branch_name']) ?></h3>
          <div class="branch-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
            <span><?= htmlspecialchars($branch['address'] ?: 'Address coming soon') ?></span>
          </div>
          <?php if ($branch['operating_hours']): ?>
          <div class="branch-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span><?= htmlspecialchars($branch['operating_hours']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($branch['contact_number']): ?>
          <div class="branch-row">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 2.9a2 2 0 0 1-.4 2.1L8 10a16 16 0 0 0 6 6l1.3-1.4a2 2 0 0 1 2.1-.4c.9.4 1.9.6 2.9.7a2 2 0 0 1 1.7 2.1z"/></svg>
            <span><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $branch['contact_number'])) ?>" style="color:inherit"><?= htmlspecialchars($branch['contact_number']) ?></a></span>
          </div>
          <?php endif; ?>
          <?php if ($branch['latitude'] !== null && $branch['longitude'] !== null): ?>
            <strong class="branch-distance">Distance: enable GPS</strong>
            <button class="branch-navigate" type="button">Navigate <span class="arrow">→</span></button>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="branches-map-wrap reveal reveal-right">
      <div id="branches-map"></div>
      <?php if (!$branch_markers): ?><div class="map-pending"><?= $public_branches ? 'Map locations will be added soon.' : 'Branches will appear here once added.' ?></div><?php endif; ?>
    </div>
  </div>
</section>

<section class="careers" id="careers">
  <div class="careers-head reveal">
    <div>
      <div class="eyebrow">We're hiring</div>
      <h2>Join the crew behind the counter</h2>
    </div>
    <p>Flexible shifts, free coffee (obviously), and a team that actually likes each other. No experience necessary — we train.</p>
  </div>
  <div class="roles">
    <?php if (!$public_jobs): ?>
      <div class="careers-empty">No open roles right now — check back soon, new ones are posted regularly.</div>
    <?php else: foreach ($public_jobs as $i => $job): ?>
      <div class="ticket reveal reveal-d<?= min(3, ($i % 3) + 1) ?>">
        <div class="ticket-top">
          <span class="ticket-eyebrow">Role <?= str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) ?></span>
          <span class="ticket-status">Open</span>
        </div>
        <div class="ticket-title"><?= htmlspecialchars($job['title']) ?></div>
        <div class="ticket-details">
          <div class="ticket-row"><span>Location</span><span><?= htmlspecialchars($job['location']) ?></span></div>
          <div class="ticket-row"><span>Schedule</span><span><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $job['employment_type']))) ?></span></div>
          <div class="ticket-row"><span>Department</span><span><?= htmlspecialchars($job['department']) ?></span></div>
        </div>
        <a href="../careers/Job_Details_Page.php?id=<?= (int)$job['job_id'] ?>" class="btn btn-navy">Apply →</a>
      </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<section class="contact" id="contact">
  <div class="contact-grid">
    <div class="reveal reveal-left">
      <div class="eyebrow">Get in touch</div>
      <h2>Questions, catering, or just want to say hi?</h2>
      <p class="lede">We check this inbox every morning over the first pour. Real people reply — no bots, no ticket numbers.</p>

      <div class="contact-detail">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19V7a2 2 0 0 1 2-2h9l5 5v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M9 3v4h6"/></svg>
        <div><strong>Email</strong><span><a href="mailto:hello@cloudcup.com" style="color:inherit">hello@cloudcup.com</a></span></div>
      </div>
      <?php if ($public_branches && $public_branches[0]['contact_number']): ?>
      <div class="contact-detail">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 2.9a2 2 0 0 1-.4 2.1L8 10a16 16 0 0 0 6 6l1.3-1.4a2 2 0 0 1 2.1-.4c.9.4 1.9.6 2.9.7a2 2 0 0 1 1.7 2.1z"/></svg>
        <div><strong>Phone</strong><span><?= htmlspecialchars($public_branches[0]['contact_number']) ?></span></div>
      </div>
      <?php endif; ?>
      <?php if ($public_branches): ?>
      <div class="contact-detail">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
        <div><strong>Main counter</strong><span><?= htmlspecialchars($public_branches[0]['address'] ?: $public_branches[0]['branch_name']) ?></span></div>
      </div>
      <?php endif; ?>

      <div class="social">
        <a href="#" aria-label="Instagram"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg></a>
        <a href="#" aria-label="Facebook"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 9h3V6h-3a3 3 0 0 0-3 3v2H9v3h2v6h3v-6h3l1-3h-4V9a1 1 0 0 1 1-1z"/></svg></a>
        <a href="#" aria-label="Twitter"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 5.8c-.7.3-1.5.6-2.3.7a4 4 0 0 0 1.8-2.2 8 8 0 0 1-2.5 1 4 4 0 0 0-6.9 3.6A11.4 11.4 0 0 1 3.7 4.6a4 4 0 0 0 1.2 5.3 4 4 0 0 1-1.8-.5v.1a4 4 0 0 0 3.2 3.9 4 4 0 0 1-1.8.1 4 4 0 0 0 3.7 2.8A8 8 0 0 1 2 18.4a11.4 11.4 0 0 0 6.2 1.8c7.4 0 11.5-6.2 11.5-11.5v-.5A8.2 8.2 0 0 0 22 5.8z"/></svg></a>
      </div>
    </div>

    <form id="contact-form" class="reveal reveal-right">
      <div class="field">
        <label for="name">Name</label>
        <input id="name" type="text" placeholder="Jordan Lee" required>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" type="email" placeholder="jordan@email.com" required>
      </div>
      <div class="field">
        <label for="msg">Message</label>
        <textarea id="msg" placeholder="What's on your mind?" required></textarea>
      </div>
      <button type="submit" class="btn btn-sage" id="send-btn"><span class="btn-label">Send message →</span></button>
    </form>
  </div>
</section>

<footer>
  <div class="logo">☁ Cloud Cup</div>
  <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap;">
    <a href="../auth/Landing_Page.php" style="color:inherit">Home</a>
    <a href="#careers" style="color:inherit">Careers</a>
    <a href="../auth/Login_Page.php" style="color:inherit">Login</a>
  </div>
  <div>© 2026 Cloud Cup Coffee Co. · Poured with care.</div>
</footer>

<script src="../marketing/js/ad_popup.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- header shadow on scroll ---------- */
  var header = document.querySelector('header');
  var progressBar = document.getElementById('scroll-progress');
  var onScroll = function(){
    header.classList.toggle('scrolled', window.scrollY > 8);
    if(progressBar){
      var doc = document.documentElement;
      var max = (doc.scrollHeight - doc.clientHeight) || 1;
      var pct = Math.min(100, Math.max(0, (window.scrollY / max) * 100));
      progressBar.style.width = pct + '%';
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- hero parallax: blobs & steam drift toward the cursor ---------- */
  if(!reduceMotion){
    var heroSection = document.querySelector('.hero');
    if(heroSection){
      var parallaxEls = heroSection.querySelectorAll('.blob, .steam-cloud');
      heroSection.addEventListener('mousemove', function(e){
        var rect = heroSection.getBoundingClientRect();
        var relX = (e.clientX - rect.left) / rect.width - 0.5;
        var relY = (e.clientY - rect.top) / rect.height - 0.5;
        parallaxEls.forEach(function(el, i){
          var strength = el.classList.contains('blob') ? 26 : 14;
          var dir = i % 2 === 0 ? 1 : -1;
          el.style.transform = 'translate(' + (relX * strength * dir) + 'px,' + (relY * strength * dir) + 'px)';
        });
      });
      heroSection.addEventListener('mouseleave', function(){
        parallaxEls.forEach(function(el){ el.style.transform = ''; });
      });
    }
  }

  /* ---------- 3D tilt on cards ---------- */
  if(!reduceMotion && window.matchMedia('(hover: hover)').matches){
    var tiltCards = document.querySelectorAll('.menu-card, .branch-card, .info-card, .ticket');
    tiltCards.forEach(function(card){
      card.addEventListener('mousemove', function(e){
        if(e.target.closest('.btn')){
          // flatten the card while the cursor is on a button — a rotated 3D
          // plane's hit area isn't a perfect rectangle, so tiny mouse jitter
          // near its tilted edge was flickering the hover state on/off
          card.style.transform = 'translateY(-6px)';
          return;
        }
        var rect = card.getBoundingClientRect();
        var relX = (e.clientX - rect.left) / rect.width - 0.5;
        var relY = (e.clientY - rect.top) / rect.height - 0.5;
        var rotY = relX * 8;
        var rotX = relY * -8;
        card.style.transform = 'translateY(-6px) perspective(600px) rotateX(' + rotX + 'deg) rotateY(' + rotY + 'deg)';
      });
      card.addEventListener('mouseleave', function(){
        card.style.transform = '';
      });
    });
  }

  /* ---------- button click ripple ---------- */
  document.querySelectorAll('.btn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      if(reduceMotion) return;
      var rect = btn.getBoundingClientRect();
      var ripple = document.createElement('span');
      var size = Math.max(rect.width, rect.height);
      ripple.className = 'ripple';
      ripple.style.width = ripple.style.height = size + 'px';
      ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
      ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
      btn.appendChild(ripple);
      ripple.addEventListener('animationend', function(){ ripple.remove(); });
    });
  });

  /* ---------- menu category filters ---------- */
  var menuFilterBtns = document.querySelectorAll('.menu-filter-btn');
  var menuCards = document.querySelectorAll('.menu-card[data-filter]');
  var menuGrid = document.querySelector('.menu-grid');

  var applyMenuFilter = function(target, animate){
    menuCards.forEach(function(card, i){
      var matches = target === 'all' || card.getAttribute('data-filter') === target;
      card.classList.toggle('is-hidden', !matches);
      card.classList.remove('is-entering');
      card.style.animationDelay = '';
      if(matches && animate){
        card.style.animationDelay = (i % 12) * 30 + 'ms';
        card.classList.add('is-entering');
      }
    });
  };

  menuFilterBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      if(btn.classList.contains('active')) return;
      menuFilterBtns.forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      var target = btn.getAttribute('data-filter');

      if(reduceMotion || !menuGrid){
        applyMenuFilter(target, false);
        return;
      }

      // Crossfade the whole grid as one unit: fade out, swap which cards
      // are in the DOM flow while invisible, then fade back in with a
      // staggered entrance — avoids the old cards flashing/reflowing
      // mid-transition that happened when each card animated on its own.
      menuGrid.classList.add('is-filtering');
      window.setTimeout(function(){
        applyMenuFilter(target, true);
        void menuGrid.offsetWidth; // force reflow before fading back in
        menuGrid.classList.remove('is-filtering');
      }, 180);
    });
  });

  /* ---------- scroll-triggered reveals ---------- */
  var revealEls = document.querySelectorAll('.reveal');
  if('IntersectionObserver' in window && !reduceMotion){
    var revealObserver = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting){
          entry.target.classList.add('in-view');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });
    revealEls.forEach(function(el){ revealObserver.observe(el); });
  } else {
    revealEls.forEach(function(el){ el.classList.add('in-view'); });
  }

  /* ---------- animated stat counters (about section) ---------- */
  var counters = document.querySelectorAll('.fact-num[data-count]');
  var countUp = function(el){
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    var suffix = el.getAttribute('data-suffix') || '';
    if(reduceMotion){
      el.textContent = target.toLocaleString() + suffix;
      return;
    }
    var start = null;
    var duration = 1400;
    var step = function(ts){
      if(!start) start = ts;
      var progress = Math.min((ts - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.round(target * eased).toLocaleString() + (progress === 1 ? suffix : '');
      if(progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  if(counters.length){
    if('IntersectionObserver' in window){
      var counterObserver = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          if(entry.isIntersecting){
            countUp(entry.target);
            counterObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.5 });
      counters.forEach(function(el){ counterObserver.observe(el); });
    } else {
      counters.forEach(countUp);
    }
  }

  /* ---------- scrollspy: highlight active nav link ---------- */
  var sections = Array.prototype.slice.call(document.querySelectorAll('section[id]'));
  var navLinks = document.querySelectorAll('.nav-links a');
  if(sections.length){
    var ticking = false;
    var updateActiveLink = function(){
      /* Keep this in sync with the CSS "scroll-margin-top" on section[id] —
         that's the real offset the browser uses when it jumps to a section,
         so the highlighted nav link has to use the same number or it lags
         behind by a section right after a click. */
      var offset = parseFloat(getComputedStyle(sections[0]).scrollMarginTop) || (header.offsetHeight + 8);
      var scrollPos = window.scrollY + offset + 2;
      var current = sections[0];
      for(var i = 0; i < sections.length; i++){
        if(sections[i].offsetTop <= scrollPos){
          current = sections[i];
        }
      }
      var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
      if(nearBottom){ current = sections[sections.length - 1]; }

      navLinks.forEach(function(link){
        link.classList.toggle('active', link.getAttribute('href') === '#' + current.id);
      });
      ticking = false;
    };
    var onScrollSpy = function(){
      if(!ticking){
        requestAnimationFrame(updateActiveLink);
        ticking = true;
      }
    };
    window.addEventListener('scroll', onScrollSpy, { passive: true });
    window.addEventListener('resize', onScrollSpy);
    updateActiveLink();
  }

  /* ---------- magnetic tilt on job tickets ---------- */
  /* (merged into the "3D tilt on cards" block above — .ticket is already
     included there, and that block pauses while hovering .btn so the
     Apply button doesn't shift under the cursor) */

  /* ---------- contact form: lightweight validation + success state ---------- */
  var form = document.getElementById('contact-form');
  if(form){
    var btn = document.getElementById('send-btn');
    var label = btn.querySelector('.btn-label');
    var originalLabel = label.textContent;

    form.addEventListener('submit', function(e){
      e.preventDefault();
      if(btn.classList.contains('sent')) return;

      var valid = true;
      form.querySelectorAll('[required]').forEach(function(input){
        var field = input.closest('.field');
        var filled = input.value.trim().length > 0;
        if(input.type === 'email' && filled){
          filled = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim());
        }
        if(!filled){
          valid = false;
          field.classList.remove('shake');
          void field.offsetWidth;
          field.classList.add('shake');
        }
      });
      if(!valid) return;

      btn.classList.add('sent');
      label.textContent = 'Sent — talk soon ✓';
      setTimeout(function(){
        btn.classList.remove('sent');
        label.textContent = originalLabel;
        form.reset();
      }, 2600);
    });
  }

  /* ---------- live branches map + geolocation distance + navigate confirm ---------- */
  var branchMarkers = <?= json_encode($branch_markers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var mapEl = document.getElementById('branches-map');
  if (mapEl) {
    var branchesMap = L.map('branches-map', { scrollWheelZoom: false }).setView([14.5995, 120.9842], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19
    }).addTo(branchesMap);
    branchesMap.addControl(L.control.zoom({ position: 'topright' }));

    var escapeHtml = function (value) { return String(value || '').replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]); }); };
    var cafeMarkerHtml = '<div class="cafe-map-marker" aria-label="Cloud Cup branch"><svg viewBox="0 0 120 116" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.5"><rect x="10" y="30" width="100" height="10" rx="5" fill="white"/><circle cx="60" cy="21" r="15" fill="white"/><g transform="translate(60 21) rotate(28)" stroke-width="3.6"><ellipse rx="7" ry="10.5"/><path d="M0 -8.5 C3.2 -3 -3.2 3 0 8.5"/></g><path d="M10 41 a10 10 0 0 0 20 0"/><path d="M30 41 a10 10 0 0 0 20 0"/><path d="M50 41 a10 10 0 0 0 20 0"/><path d="M70 41 a10 10 0 0 0 20 0"/><path d="M90 41 a10 10 0 0 0 20 0"/><line x1="10" y1="41" x2="8.5" y2="58"/><line x1="30" y1="41" x2="29.3" y2="58"/><line x1="50" y1="41" x2="49.7" y2="58"/><line x1="70" y1="41" x2="70.3" y2="58"/><line x1="90" y1="41" x2="90.7" y2="58"/><line x1="110" y1="41" x2="111.5" y2="58"/><rect x="14" y="58" width="92" height="52" rx="2"/><rect x="21" y="64" width="44" height="30" rx="2"/><circle cx="38" cy="69" r="1.5" fill="currentColor"/><circle cx="43" cy="69" r="1.5" fill="currentColor"/><circle cx="48" cy="69" r="1.5" fill="currentColor"/><path d="M31 77 h14 v7.5 a7 7 0 0 1 -14 0 z"/><path d="M45 79 h3.8 a2.8 2.8 0 0 1 0 4.6 h-3.8"/><rect x="24" y="100" width="14" height="5" rx="1.5"/><rect x="42" y="100" width="18" height="5" rx="1.5"/><rect x="71" y="64" width="32" height="44"/><line x1="87" y1="64" x2="87" y2="108"/><line x1="71" y1="89" x2="103" y2="89"/><line x1="79" y1="71" x2="79" y2="79"/><line x1="95" y1="71" x2="95" y2="79"/></g></svg></div>';
    var cafeIcon = L.divIcon({ className: '', html: cafeMarkerHtml, iconSize: [44, 44], iconAnchor: [22, 44], popupAnchor: [0, -40] });
    var bounds = [];
    branchMarkers.forEach(function (branch) {
      L.marker([branch.lat, branch.lng], { icon: cafeIcon })
        .addTo(branchesMap)
        .bindPopup('<strong>' + escapeHtml(branch.name) + '</strong><br><span>' + escapeHtml(branch.address) + '</span>');
      bounds.push([branch.lat, branch.lng]);
    });
    if (bounds.length) branchesMap.fitBounds(bounds, { padding: [55, 55], maxZoom: 14 });

    var kmBetween = function (a, b, c, d) { var r = 6371, q = Math.PI / 180, x = (c - a) * q, y = (d - b) * q; var h = Math.sin(x / 2) ** 2 + Math.cos(a * q) * Math.cos(c * q) * Math.sin(y / 2) ** 2; return 2 * r * Math.asin(Math.sqrt(h)); };
    var userLocation = null;
    var updateDistances = function () {
      if (!userLocation) return;
      document.querySelectorAll('.public-branch-card[data-lat]').forEach(function (card) {
        var el = card.querySelector('.branch-distance');
        if (!el) return;
        var km = kmBetween(userLocation.lat, userLocation.lng, Number(card.dataset.lat), Number(card.dataset.lng));
        el.textContent = 'Distance: ' + (km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km away');
      });
    };
    if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (pos) { userLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude }; updateDistances(); }, function () {});

    document.querySelectorAll('.branch-navigate').forEach(function (button) {
      button.addEventListener('click', async function () {
        var card = button.closest('.public-branch-card');
        var name = card.querySelector('h3').textContent;
        var result = await Swal.fire({ title: 'Navigate to ' + name + '?', text: 'Your preferred maps app will open with driving directions.', icon: 'question', showCancelButton: true, confirmButtonText: 'Open directions', cancelButtonText: 'Cancel', confirmButtonColor: '#4E756E' });
        if (!result.isConfirmed) return;
        var destination = card.dataset.lat + ',' + card.dataset.lng;
        var origin = userLocation ? '&origin=' + userLocation.lat + ',' + userLocation.lng : '';
        window.open('https://www.google.com/maps/dir/?api=1' + origin + '&destination=' + destination + '&travelmode=driving', '_blank', 'noopener');
      });
    });
  }
})();
</script>
</body>
</html>