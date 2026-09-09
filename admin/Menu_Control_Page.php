<?php
session_start();
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../auth/Login_Page.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';
$full_name = $_SESSION['full_name'] ?? 'Admin';
$initials  = strtoupper(substr($full_name, 0, 1));
$active_page = 'menu';

// ── Helper: case-insensitive duplicate name check ─────────────
// Returns true if another menu item already has this exact name
// (trimmed, case-insensitive). $exclude_id lets edits ignore themselves.
// ── Helper: replace a menu item's recipe_ingredients rows ─────
// Reads parallel arrays $_POST['ing_inventory_id'][] / $_POST['ing_qty'][],
// wipes any existing rows for this item_id, then re-inserts the current
// set. Skips rows with no ingredient selected or a qty <= 0, so empty
// leftover rows from the form don't get saved.
function save_recipe_ingredients($conn, int $item_id, array $inv_ids, array $qtys): void {
    $del = mysqli_prepare($conn, "DELETE FROM recipe_ingredients WHERE item_id = ?");
    mysqli_stmt_bind_param($del, 'i', $item_id);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    $ins = mysqli_prepare($conn,
        "INSERT INTO recipe_ingredients (item_id, inventory_id, qty_per_unit) VALUES (?,?,?)");
    foreach ($inv_ids as $i => $inv_id) {
        $inv_id = (int)$inv_id;
        $qty    = (float)($qtys[$i] ?? 0);
        if ($inv_id <= 0 || $qty <= 0) continue;
        mysqli_stmt_bind_param($ins, 'iid', $item_id, $inv_id, $qty);
        mysqli_stmt_execute($ins);
    }
    mysqli_stmt_close($ins);
}

function menu_name_exists($conn, string $name, int $exclude_id = 0): bool {
    if (!$conn || $name === '') return false;
    $s = mysqli_prepare($conn,
        "SELECT item_id FROM menu_items WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?)) AND item_id != ? LIMIT 1");
    mysqli_stmt_bind_param($s, 'si', $name, $exclude_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    return $r && mysqli_fetch_assoc($r) ? true : false;
}

// ── Helper: handle an uploaded menu photo ─────────────────────
// Returns ['ok'=>true,'path'=>string|null] on success (path is null if no
// file was chosen — caller should keep the existing image in that case),
// or ['ok'=>false] if a file was chosen but was invalid.
function menu_upload_image(array $file): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'path' => null];
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'path' => null];
    }
    $destDir = __DIR__ . '/../images/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = 'menu_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
        return ['ok' => false, 'path' => null];
    }
    return ['ok' => true, 'path' => '../images/' . $filename];
}

$menu_action_msg = '';
if ($conn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['menu_act'] ?? '';

    if ($act === 'add_item') {
        $iname    = trim($_POST['m_name']     ?? '');
        $icat     = trim($_POST['m_category'] ?? '');
        $iprice   = (float)($_POST['m_price'] ?? 0);
        $iavail   = isset($_POST['m_available']) ? 1 : 0;
        if (!$iname || !$icat || $iprice <= 0) {
            $menu_action_msg = 'error:Name, category and price are required.';
        } elseif (menu_name_exists($conn, $iname)) {
            $menu_action_msg = 'error:"' . $iname . '" is already on the menu. Choose a different name.';
        } else {
            $upload = menu_upload_image($_FILES['m_image_file'] ?? []);
            if (!$upload['ok']) {
                $menu_action_msg = 'error:Photo upload failed. Use a JPG, PNG, GIF, or WEBP under 5MB.';
            } else {
                $iimg = $upload['path'] ?? '';
                $s = mysqli_prepare($conn,
                    "INSERT INTO menu_items (item_name, category, price, image_path, is_available) VALUES (?,?,?,?,?)");
                mysqli_stmt_bind_param($s, 'ssdsi', $iname, $icat, $iprice, $iimg, $iavail);
                mysqli_stmt_execute($s);
                $new_item_id = mysqli_insert_id($conn);
                save_recipe_ingredients($conn, $new_item_id,
                    $_POST['ing_inventory_id'] ?? [], $_POST['ing_qty'] ?? []);
                $menu_action_msg = 'success:Menu item added.';
            }
        }
    // NOTE: 'toggle_item' intentionally removed from here — day-to-day
    // availability toggling is Manager-only now (manager/Item_Availability_Page.php).
    // Admin still sets an item's initial availability when adding/editing it
    // (via the checkbox below), but can't flip it on/off from this list view.
    } elseif ($act === 'edit_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $iname   = trim($_POST['m_name']     ?? '');
        $icat    = trim($_POST['m_category'] ?? '');
        $iprice  = (float)($_POST['m_price'] ?? 0);
        $iimg_current = trim($_POST['m_image_current'] ?? '');
        $iavail  = isset($_POST['m_available']) ? 1 : 0;
        if (!$item_id || !$iname || !$icat || $iprice <= 0) {
            $menu_action_msg = 'error:Name, category and price are required.';
        } elseif (menu_name_exists($conn, $iname, $item_id)) {
            $menu_action_msg = 'error:"' . $iname . '" is already used by another menu item.';
        } else {
            $upload = menu_upload_image($_FILES['m_image_file'] ?? []);
            if (!$upload['ok']) {
                $menu_action_msg = 'error:Photo upload failed. Use a JPG, PNG, GIF, or WEBP under 5MB.';
            } else {
                $iimg = $upload['path'] ?? $iimg_current;
                $s = mysqli_prepare($conn,
                    "UPDATE menu_items SET item_name=?, category=?, price=?, image_path=?, is_available=? WHERE item_id=?");
                mysqli_stmt_bind_param($s, 'ssdsii', $iname, $icat, $iprice, $iimg, $iavail, $item_id);
                mysqli_stmt_execute($s);
                save_recipe_ingredients($conn, $item_id,
                    $_POST['ing_inventory_id'] ?? [], $_POST['ing_qty'] ?? []);
                $menu_action_msg = 'success:Item updated.';
            }
        }
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

$menu_items_all    = [];
$menu_items_avail  = [];
$menu_items_unavail = [];
$menu_cats_all     = [];
$menu_result_all = safe_query($conn, "SELECT * FROM menu_items ORDER BY category, item_name");
if ($menu_result_all) while ($r = mysqli_fetch_assoc($menu_result_all)) {
    $menu_items_all[] = $r;
    if (!in_array($r['category'], $menu_cats_all)) $menu_cats_all[] = $r['category'];
    if ((int)$r['is_available'] === 1) {
        $menu_items_avail[] = $r;
    } else {
        $menu_items_unavail[] = $r;
    }
}

$menu_names_js = [];
foreach ($menu_items_all as $mi) {
    $menu_names_js[] = ['id' => (int)$mi['item_id'], 'name' => $mi['item_name']];
}

// ── Ingredients: pull the full inventory list (for the picker) and the
// existing recipe_ingredients rows for each menu item (for the Edit modal).
$inventory_picker = [];
$inv_result = safe_query($conn, "SELECT inventory_id, item_name, unit FROM inventory WHERE is_active=1 ORDER BY item_name");
if ($inv_result) while ($r = mysqli_fetch_assoc($inv_result)) {
    $inventory_picker[] = ['id' => (int)$r['inventory_id'], 'name' => $r['item_name'], 'unit' => $r['unit']];
}

// Group existing recipe_ingredients by item_id so each menu item card can
// carry its own ingredient list into the Edit modal via data-item.
$recipe_by_item = [];
$ri_result = safe_query($conn,
    "SELECT ri.item_id, ri.inventory_id, ri.qty_per_unit, inv.item_name AS ing_name, inv.unit AS ing_unit
     FROM recipe_ingredients ri JOIN inventory inv ON inv.inventory_id = ri.inventory_id");
if ($ri_result) while ($r = mysqli_fetch_assoc($ri_result)) {
    $recipe_by_item[(int)$r['item_id']][] = [
        'inventory_id' => (int)$r['inventory_id'],
        'qty_per_unit' => (float)$r['qty_per_unit'],
        'name'         => $r['ing_name'],
        'unit'         => $r['ing_unit'],
    ];
}

// Standard category list — shown as filter tabs and as Add/Edit Item
// suggestions even before any item exists in them yet. Any additional
// category already used by an existing item (but not in this list) is
// still appended, so nothing already on the menu ever disappears.
$menu_cats_standard = ['Coffee', 'Non-Coffee', 'Tea', 'Frappe', 'Pastries', 'Cakes', 'Refreshers', 'Add-ons', 'Combos', 'Promo'];
$menu_cats_all = array_values(array_unique(array_merge($menu_cats_standard, $menu_cats_all)));

// ── Icon helper: inline SVG (stroke-based, inherits currentColor) ──
// Kept identical to Sales_Processing_Page.php so both pages show the
// exact same category icons.
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

// ── Helper: render one menu item card ──────────────────────────
// Shared by both the "available" grid and the "unavailable" grid so the
// markup (and the Edit/Toggle wiring) only lives in one place.
function render_menu_item_card(array $mi): void {
    $mi_img = trim($mi['image_path'] ?? '');
    $avail  = (bool)$mi['is_available'];
    // data-item carries the full record as JSON for the Edit modal. It's
    // rendered as a single, properly-escaped HTML attribute (not inlined
    // into an onclick="" JS call), so special characters in the item name
    // or category can't break the markup or silently disable the button.
    $mi['ingredients'] = $GLOBALS['recipe_by_item'][(int)$mi['item_id']] ?? [];
    $item_json = htmlspecialchars(json_encode($mi), ENT_QUOTES, 'UTF-8');
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
              <span class="menu-btn menu-btn-toggle <?= $avail ? 'on' : 'off' ?>" style="cursor:default;pointer-events:none" title="Set by Manager under Item Availability">
                <?= $avail ? '✓ Available' : '✗ Hidden' ?>
              </span>
              <button type="button" class="menu-btn menu-btn-edit" data-item='<?= $item_json ?>'>Edit</button>
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
  <title>Menu Control — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/menu_control.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Blurs whatever's behind the Save/Add-Item loading & success pop-ups */
    .blurred-backdrop {
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
  </style>
</head>
<body>

<script src="../js/sidebar-toggle.js"></script>
<?php
if (file_exists('../admin/Sidebar_Admin.php')) {
  require_once '../admin/Sidebar_Admin.php';
} else {
  echo '<div style="background:#f4e3d3;border-bottom:1px solid #a6650f;padding:10px 32px;font-size:13px;color:#a6650f">'
     . '<strong>Sidebar not found.</strong> Expected <code>Sidebar_Admin.php</code> in this folder. The page will still load without it.'
     . '</div>';
}
?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Menu Control</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="widget" style="margin-top:0">
      <div class="widget-header" style="align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
          <div class="widget-title">Menu Management</div>
          <div class="widget-title-sub">Add, edit, or remove items visible to staff on the POS</div>
        </div>
        <button class="btn-add-item" onclick="openMenuModal()">+ Add Item</button>
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
        <p style="color:var(--text-light);padding:24px;text-align:center;grid-column:1/-1">No available menu items yet. Add or re-enable one!</p>
        <?php else: foreach ($menu_items_avail as $mi): render_menu_item_card($mi); endforeach; endif; ?>
      </div>
    </div>

    <!-- ── Unavailable items — kept in their own container so hidden items
         are never mixed in with what staff can currently order, and can be
         quickly found and switched back on. ── -->
    <div class="widget unavail-widget">
      <div class="widget-header">
        <div>
          <div class="widget-title">
            Unavailable Items
            <span class="unavail-count-pill"><?= count($menu_items_unavail) ?></span>
          </div>
          <div class="widget-title-sub">Hidden from the POS — toggle back on when ready</div>
        </div>
      </div>

      <div class="menu-grid" id="menuGridUnavail">
        <?php if (empty($menu_items_unavail)): ?>
        <p class="unavail-empty" style="grid-column:1/-1">Nothing hidden right now — everything is available.</p>
        <?php else: foreach ($menu_items_unavail as $mi): render_menu_item_card($mi); endforeach; endif; ?>
      </div>
    </div>

    <div class="modal-overlay-admin" id="menuModal" onclick="if(event.target===this)closeMenuModal()">
      <div class="modal-admin-box">
        <div class="modal-admin-header">
          <span id="menuModalTitle">Add Menu Item</span>
          <button class="modal-close-btn" onclick="closeMenuModal()">✕</button>
        </div>
        <form method="POST" id="menuModalForm" enctype="multipart/form-data">
          <input type="hidden" name="menu_act" id="menuModalAct" value="add_item">
          <input type="hidden" name="item_id" id="menuModalItemId" value="">
          <input type="hidden" name="m_image_current" id="mm_image_current" value="">

          <div class="form-row">
            <div class="form-group-admin">
              <label>Item Name *</label>
              <input type="text" name="m_name" id="mm_name" required placeholder="e.g. Caffe Latte" autocomplete="off">
              <span class="dup-warn-text" id="mm_name_warn">⚠ This item already exists on the menu</span>
            </div>
            <div class="form-group-admin">
              <label>Category *</label>
              <input type="text" name="m_category" id="mm_category" required list="cat-list" placeholder="e.g. Coffee">
              <datalist id="cat-list">
                <?php foreach ($menu_cats_all as $mc): ?>
                <option value="<?= htmlspecialchars($mc) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group-admin">
              <label>Base Price (₱) *</label>
              <input type="number" name="m_price" id="mm_price" required min="1" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group-admin">
              <label>Item Photo</label>
              <input type="file" name="m_image_file" id="mm_image_file" accept="image/jpeg,image/png,image/gif,image/webp">
              <div id="mm_image_preview_wrap" style="display:none;margin-top:8px">
                <img id="mm_image_preview" src="" alt="Preview" style="max-width:110px;max-height:80px;border-radius:8px;border:1px solid #e9e3d8;object-fit:cover">
              </div>
            </div>
          </div>
          <div class="form-group-admin">
            <label class="checkbox-label">
              <input type="checkbox" name="m_available" id="mm_available" checked>
              Available on POS (staff can order this item)
            </label>
            <p style="font-size:12px;color:var(--text-light,#888);margin-top:4px">
              Sets the item's starting availability. Day-to-day sold-out/back-in-stock toggling is handled by the branch Manager under Item Availability.
            </p>
          </div>

          <div class="form-group-admin">
            <label>Ingredients Needed (per 1 serving)</label>
            <div id="mm_ingredients_list"></div>
            <button type="button" class="modal-btn-ghost-admin" id="mm_add_ingredient_btn" style="margin-top:6px">+ Add Ingredient</button>
            <p style="font-size:12px;color:var(--text-light,#888);margin-top:6px">
              Qty is per 1 unit sold, in the ingredient's own inventory unit (e.g. 0.2 for 0.2 L of milk).
              Cups and straws are handled automatically — no need to add them here.
            </p>
          </div>

          <div class="modal-admin-actions">
            <button type="button" class="modal-btn-ghost-admin" onclick="closeMenuModal()">Cancel</button>
            <button type="submit" class="modal-btn-primary-admin" id="menuModalSubmit">Add Item</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<script>const EXISTING_MENU_ITEMS = <?= json_encode($menu_names_js) ?>;</script>
<script>const INVENTORY_ITEMS = <?= json_encode($inventory_picker) ?>;</script>
<script src="../js/menu_control.js"></script>

<script>
// Result of the last Add Item / Save Changes submission (set server-side).
// Format: "success:<message>" or "error:<message>", or '' if this was a
// plain page load with no form submission.
const MENU_ACTION_MSG = <?= json_encode($menu_action_msg) ?>;

// Show a blurred, animated loading pop-up the instant Add Item / Save
// Changes is submitted, so there's clear feedback while the page saves
// and reloads (this only fires once the browser's own required-field
// validation has passed, since that runs before 'submit' is dispatched).
document.getElementById('menuModalForm').addEventListener('submit', function () {
  Swal.fire({
    title: 'Saving changes…',
    html: 'Updating the menu…',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: { container: 'blurred-backdrop' },
    didOpen: () => Swal.showLoading()
  });
});

// Once the page reloads after a successful save, briefly confirm it with
// a small animated, auto-dismissing pop-up (also blurs the background).
if (MENU_ACTION_MSG) {
  const sep  = MENU_ACTION_MSG.indexOf(':');
  const type = sep === -1 ? MENU_ACTION_MSG : MENU_ACTION_MSG.slice(0, sep);
  const text = sep === -1 ? ''               : MENU_ACTION_MSG.slice(sep + 1);
  if (type === 'success') {
    Swal.fire({
      icon: 'success',
      title: 'Menu Updated!',
      text: text,
      timer: 1800,
      timerProgressBar: true,
      showConfirmButton: false,
      customClass: { container: 'blurred-backdrop' }
    });
  }
}
</script>

</body>
</html>