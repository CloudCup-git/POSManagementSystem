<?php
session_start();
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: ../auth/Login_Page.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
$full_name = $_SESSION['full_name'] ?? 'Manager';
$initials  = strtoupper(substr($full_name, 0, 1));
$active_page = 'availability';

// ── Toggle only — no add/edit/delete/price/recipe here. Pricing, recipes,
// and new items are Admin-only (Menu_Control_Page.php); this page just lets
// a Manager mark an existing item sold-out / back-in-stock for the day. ──
$menu_action_msg = '';
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['menu_act'] ?? '') === 'toggle_item') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $newval  = (int)($_POST['is_available'] ?? 0);
    if ($item_id > 0) {
        $t = mysqli_prepare($conn, "UPDATE menu_items SET is_available=? WHERE item_id=?");
        mysqli_stmt_bind_param($t, 'ii', $newval, $item_id);
        $ok = mysqli_stmt_execute($t);
        mysqli_stmt_close($t);
        $menu_action_msg = $ok
            ? 'success:' . ($newval ? 'Item is now available.' : 'Item marked as sold out.')
            : 'error:Could not update item availability.';
    }
}

function safe_query($conn, string $sql) {
  if (!$conn) {
    error_log('SQL error: no database connection | Query: ' . $sql);
    return false;
  }
  $result = mysqli_query($conn, $sql);
  if ($result === false) {
    error_log('SQL error: ' . mysqli_error($conn) . ' | Query: ' . $sql);
    return false;
  }
  return $result;
}

$menu_items_avail   = [];
$menu_items_unavail = [];
$menu_cats_all      = [];
$menu_result_all = safe_query($conn, "SELECT * FROM menu_items ORDER BY category, item_name");
if ($menu_result_all) while ($r = mysqli_fetch_assoc($menu_result_all)) {
    if (!in_array($r['category'], $menu_cats_all)) $menu_cats_all[] = $r['category'];
    if ((int)$r['is_available'] === 1) {
        $menu_items_avail[] = $r;
    } else {
        $menu_items_unavail[] = $r;
    }
}
sort($menu_cats_all);

// ── Icon helper: inline SVG (stroke-based, inherits currentColor) ──
// Kept identical to Menu_Control_Page.php so category icons match.
function icon(string $name, int $size = 16): string {
    $paths = [
        'coffee'      => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/>',
        'milk'        => '<path d="M8 2h8"/><path d="M9 2v6.5a3 3 0 0 1-.5 1.7L6 14a5 5 0 0 0-1 3v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3a5 5 0 0 0-1-3l-2.5-3.8A3 3 0 0 1 15 8.5V2"/>',
        'tea'         => '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 1c0 1-1 1-1 2s1 1 1 2"/><path d="M10 1c0 1-1 1-1 2s1 1 1 2"/>',
        'citrus'      => '<circle cx="12" cy="12" r="10"/><path d="M14.6 9.4a4 4 0 1 0-5.2 5.2"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M2 12h2"/><path d="M20 12h2"/>',
        'croissant'   => '<path d="M3 17c0-1 1-9 9-13 8 4 9 12 9 13-1.5-1-3-1-4 0s-2.5 1-4 0-2.5-1-4 0-2.5 1-4 0-3.5-1-6-1Z"/>',
        'cake'        => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16h16"/><path d="M12 4v3"/><path d="M9 4a1.5 1.5 0 1 1 3 0c0 1-1.5 2-1.5 2S9 5 9 4Z"/><path d="M2 21h20"/>',
        'plus'        => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'bag'         => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'frappe'      => '<path d="M6 8h12l-1.4 11.2A2 2 0 0 1 14.6 21H9.4a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8V5.5a3 3 0 0 1 6 0V8"/><line x1="9.5" y1="12" x2="14.5" y2="12"/><line x1="10" y1="16" x2="14" y2="16"/>',
        'layers'      => '<path d="m12 2 9 5-9 5-9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'tag'         => '<path d="M12.6 2.6 21 11l-8.4 8.4a2 2 0 0 1-2.8 0L3 12.6V4a1.4 1.4 0 0 1 1.4-1.4h8.2Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/>',
        'utensils'    => '<path d="M3 2v7c0 1.1.9 2 2 2s2-.9 2-2V2"/><path d="M5 11v11"/><path d="M19 2c-2 1-3 3-3 6v3a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V2Z"/><path d="M19 13v9"/>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg class="svg-icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px;margin-right:5px">'.$d.'</svg>';
}

$cat_icons = [
    'Coffee'      => 'coffee',  'Non-Coffee' => 'milk',     'Tea'         => 'tea',
    'Refreshers'  => 'citrus',  'Pastries'   => 'croissant','Cakes'       => 'cake',
    'Food'        => 'croissant','Add-on'    => 'plus',     'Add-ons'     => 'plus',
    'Merchandise' => 'bag',    'Frappe'     => 'frappe',   'Combos'      => 'layers',
    'Promo'       => 'tag',
];

// ── Render one item card — toggle button only, no Edit ──────────
function render_availability_card(array $mi): void {
    $mi_img = trim($mi['image_path'] ?? '');
    $avail  = (bool)$mi['is_available'];
?>
        <div class="menu-item-card <?= $avail ? '' : 'unavail' ?>" data-cat="<?= htmlspecialchars($mi['category']) ?>">
          <div class="menu-item-img">
            <?php if ($mi_img): ?>
              <img src="<?= htmlspecialchars($mi_img) ?>" alt="<?= htmlspecialchars($mi['item_name']) ?>"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
              <span style="display:none;align-items:center;justify-content:center;width:100%;height:100%;color:var(--text-light);font-size:24px">☕</span>
            <?php else: ?>
              <span style="font-size:28px">☕</span>
            <?php endif; ?>
          </div>
          <div class="menu-item-body">
            <div class="menu-item-name"><?= htmlspecialchars($mi['item_name']) ?></div>
            <div class="menu-item-meta">
              <span class="menu-cat-pill"><?= htmlspecialchars($mi['category']) ?></span>
              <span class="menu-item-price">₱<?= number_format($mi['price'], 2) ?></span>
            </div>
            <div class="menu-item-actions">
              <form method="POST" style="display:inline" onsubmit="return confirmToggleAvailability(event, this, <?= $avail ? 'false' : 'true' ?>, '<?= addslashes($mi['item_name']) ?>')">
                <input type="hidden" name="menu_act" value="toggle_item">
                <input type="hidden" name="item_id" value="<?= $mi['item_id'] ?>">
                <input type="hidden" name="is_available" value="<?= $avail ? 0 : 1 ?>">
                <button type="submit" class="menu-btn menu-btn-toggle <?= $avail ? 'on' : 'off' ?>">
                  <?= $avail ? '✓ Available' : '✗ Sold Out' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Item Availability — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/menu_control.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .blurred-backdrop {
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
    /* No Add Item button on this page — Management/Finance sections stay
       identical to Menu Control's CSS, this just hides the leftover class
       if it's ever reused. */
    .btn-add-item { display: none; }
  </style>
</head>
<body>

<?php
if (file_exists('../manager/Sidebar_Manager.php')) {
  require_once '../manager/Sidebar_Manager.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Manager.php</code> in this folder. The page will still load without it.'
     . '</div>';
}
?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Item Availability</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="widget" style="margin-top:0">
      <div class="widget-header" style="align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
          <div class="widget-title">Item Availability</div>
          <div class="widget-title-sub">Mark items sold out or back in stock for the POS — pricing, recipes, and new items are managed by Admin under Menu Control</div>
        </div>
      </div>

      <?php if ($menu_action_msg): ?>
      <?php [$mt, $mm] = explode(':', $menu_action_msg, 2); ?>
      <div class="menu-msg <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
      <?php endif; ?>

      <div class="menu-cat-tabs" id="menuCatTabs">
        <button class="menu-cat-btn active" onclick="filterMenuCat('all', this)"><?= icon('grid', 14) ?>All</button>
        <?php foreach ($menu_cats_all as $mc):
          $icon_name = $cat_icons[$mc] ?? 'utensils';
        ?>
        <button class="menu-cat-btn" onclick="filterMenuCat('<?= addslashes($mc) ?>', this)"><?= icon($icon_name, 14) ?><?= htmlspecialchars($mc) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="menu-grid" id="menuGrid">
        <?php if (empty($menu_items_avail)): ?>
        <p style="color:var(--text-light);padding:24px;text-align:center;grid-column:1/-1">No available menu items right now.</p>
        <?php else: foreach ($menu_items_avail as $mi): render_availability_card($mi); endforeach; endif; ?>
      </div>
    </div>

    <div class="widget unavail-widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">
            Sold Out / Hidden Items
            <span class="unavail-count-pill"><?= count($menu_items_unavail) ?></span>
          </div>
          <div class="widget-title-sub">Hidden from the POS — toggle back on when ready</div>
        </div>
      </div>

      <div class="menu-grid" id="menuGridUnavail">
        <?php if (empty($menu_items_unavail)): ?>
        <p class="unavail-empty" style="grid-column:1/-1">Nothing hidden right now — everything is available.</p>
        <?php else: foreach ($menu_items_unavail as $mi): render_availability_card($mi); endforeach; endif; ?>
      </div>
    </div>

  </div>
</div>

<script>
// ── Category filter — same behavior as Menu_Control_Page.php ──
function filterMenuCat(cat, btn) {
  document.querySelectorAll('#menuCatTabs .menu-cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#menuGrid .menu-item-card, #menuGridUnavail .menu-item-card').forEach(card => {
    card.style.display = (cat === 'all' || card.dataset.cat === cat) ? '' : 'none';
  });
}
</script>

<script>
const MENU_ACTION_MSG = <?= json_encode($menu_action_msg) ?>;
if (MENU_ACTION_MSG) {
  const sep  = MENU_ACTION_MSG.indexOf(':');
  const type = sep === -1 ? MENU_ACTION_MSG : MENU_ACTION_MSG.slice(0, sep);
  const text = sep === -1 ? ''               : MENU_ACTION_MSG.slice(sep + 1);
  if (type === 'success') {
    Swal.fire({
      icon: 'success',
      title: 'Updated!',
      text: text,
      timer: 1600,
      timerProgressBar: true,
      showConfirmButton: false,
      customClass: { container: 'blurred-backdrop' }
    });
  }
}
</script>

<script>
// SweetAlert2 confirmation for toggling availability
function confirmToggleAvailability(e, form, willBeAvailable, itemName) {
  e.preventDefault();
  Swal.fire({
    title: willBeAvailable ? 'Make available?' : 'Mark as sold out?',
    html: willBeAvailable
      ? '<b>' + itemName + '</b> will become orderable on the POS.'
      : 'Staff will no longer be able to order <b>' + itemName + '</b>.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: willBeAvailable ? 'Yes, make available' : 'Yes, mark sold out',
    cancelButtonText: 'Cancel',
    confirmButtonColor: willBeAvailable ? '#2f6f4e' : '#a6650f',
    cancelButtonColor: '#9c9184',
    reverseButtons: true
  }).then((result) => {
    if (result.isConfirmed) {
      Swal.fire({
        title: willBeAvailable ? 'Making available…' : 'Marking sold out…',
        html: 'Updating…',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: { container: 'blurred-backdrop' },
        didOpen: () => Swal.showLoading()
      });
      form.submit();
    }
  });
  return false;
}
</script>

</body>
</html>
