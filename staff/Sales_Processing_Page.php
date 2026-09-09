<?php
session_start();

$is_staff_session = isset($_SESSION['role'], $_SESSION['full_name'])
&& (isset($_SESSION['employee_id']) || isset($_SESSION['user_id']))
&& strtolower($_SESSION['role']) === 'employee';
$is_admin_session = isset($_SESSION['user_id'], $_SESSION['role'])
&& in_array(strtolower($_SESSION['role']), ['admin', 'manager'], true);

// Admins/managers no longer use this page — their Sales Records view now
// lives on Admin_Dashboard.php / Manager_Dashboard.php. Send them there
// instead of rendering it here.
if ($is_admin_session) {
    $__role = strtolower($_SESSION['role']);
    header('Location: ' . ($__role === 'admin' ? '../admin/Admin_Dashboard.php' : '../manager/Manager_Dashboard.php'));
    exit;
}

// Inventory Staff (and any other non-employee, non-admin role) don't get
// POS access at all — their account is scoped to Inventory only.
if (!$is_staff_session) {
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'inventory_staff') {
        header('Location: ../manager/Inventory_Management_Page.php');
    } else {
        header('Location: ../auth/Login_Page.php');
    }
    exit;
}

// An 'employee' account whose HR Position is "Inventory Staff" is scoped
// to Inventory only too — same restriction as the legacy 'inventory_staff'
// role above, just driven by HR Position instead of a separate role.
if (($_SESSION['position'] ?? '') === 'Inventory Staff') {
    header('Location: ../manager/Inventory_Management_Page.php');
    exit;
}



// ── DB Connection (graceful — $conn = false when no DB yet) ─────
require_once __DIR__ . '/../includes/DB_Connect.php';

$role        = $_SESSION['role'];
$employee_id = $_SESSION['employee_id'] ?? $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'] ?? 'Admin';
$initials    = $_SESSION['initials'] ?? strtoupper(substr($full_name, 0, 1));

// ── Shift gate: must be clocked in, and must have set a starting cash
// drawer for today, before the POS is usable ─────────────────────────
// NOTE: assumes `attendance` has a `starting_cash` column. If it doesn't
// exist yet, add it with:
//   ALTER TABLE attendance ADD COLUMN starting_cash DECIMAL(10,2) NULL;
// Checked with SHOW COLUMNS first (same pattern as the `schedules`/
// `holidays` table checks elsewhere) so a missing column just skips the
// drawer step instead of a fatal error.
$emp_id_int         = (int) ($employee_id ?? 0);
$has_clocked_in     = true;  // default open when there's no DB / no gate to enforce
$has_drawer_set     = true;
$drawer_amount      = null;
$starting_cash_col_ready = false;

if ($conn) {
    $col_chk = mysqli_query($conn, "SHOW COLUMNS FROM attendance LIKE 'starting_cash'");
    $starting_cash_col_ready = $col_chk && mysqli_num_rows($col_chk) > 0;

    // Only ask for starting_cash when the column actually exists — selecting
    // a column that isn't there yet fails the whole query.
    $select_cols = $starting_cash_col_ready ? 'time_in, starting_cash' : 'time_in';
    $att_result  = mysqli_query($conn,
        "SELECT $select_cols FROM attendance WHERE employee_id=$emp_id_int AND work_date=CURDATE()");
    $today_att   = $att_result ? mysqli_fetch_assoc($att_result) : null;

    $has_clocked_in = (bool) ($today_att['time_in'] ?? false);
    if ($starting_cash_col_ready) {
        $has_drawer_set = $has_clocked_in && $today_att['starting_cash'] !== null;
        $drawer_amount  = $today_att['starting_cash'] ?? null;
    }
}
$pos_locked = !$has_clocked_in || !$has_drawer_set;

// Live cash-in-drawer for the shift: starting float, plus every CASH
// order's tendered amount, minus the change handed back for each — net
// effect is starting_cash + that order's total, but written out this
// way to match how a real drawer actually moves (cash in when the
// customer pays, cash out when change goes back). Non-cash orders never
// touch it. Recomputed fresh (not incremented) so it can't drift out of
// sync with the orders table.
function compute_drawer_balance($conn, int $emp_id, float $starting_cash): float {
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(amount_tendered - change_due), 0) AS cash_net
         FROM orders
         WHERE employee_id = $emp_id AND payment_method = 'cash' AND status = 'completed'
           AND DATE(ordered_at) = CURDATE()"));
    return $starting_cash + (float) ($r['cash_net'] ?? 0);
}

$drawer_balance = ($conn && $has_drawer_set) ? compute_drawer_balance($conn, $emp_id_int, (float) $drawer_amount) : null;

// ── Helper: deduct N units from an inventory item, with logging + debug info ──
function deduct_supply_item($conn, $emp_id, $order_id, $inventory_id, $qty, $label, $stored_name) {
    $dbg = ['label' => $label, 'inventory_id' => $inventory_id, 'qty' => $qty];

    if ($inventory_id <= 0) {
        $dbg['result'] = "skipped: no {$label} inventory_id available";
        return [$dbg, null];
    }

    $check = mysqli_prepare($conn, "SELECT quantity FROM inventory WHERE inventory_id = ?");
    $before_qty = null;
    if ($check) {
        mysqli_stmt_bind_param($check, 'i', $inventory_id);
        mysqli_stmt_execute($check);
        $cres = mysqli_stmt_get_result($check);
        $crow = $cres ? mysqli_fetch_assoc($cres) : null;
        $before_qty = $crow['quantity'] ?? null;
        mysqli_stmt_close($check);
    }

    if ($before_qty === null) {
        $dbg['result'] = "failed: {$label} inventory_id {$inventory_id} not found in inventory table";
        return [$dbg, "{$stored_name}: {$label} inventory_id {$inventory_id} does not exist"];
    }

    $deduct_stmt = mysqli_prepare($conn,
        "UPDATE inventory SET quantity = GREATEST(0, quantity - ?) WHERE inventory_id = ?");
    if (!$deduct_stmt) {
        $dbg['result'] = 'failed: prepare error — ' . mysqli_error($conn);
        return [$dbg, "{$stored_name}: {$label} deduction prepare failed — " . mysqli_error($conn)];
    }

    mysqli_stmt_bind_param($deduct_stmt, 'di', $qty, $inventory_id);
    $ok       = mysqli_stmt_execute($deduct_stmt);
    $affected = mysqli_stmt_affected_rows($deduct_stmt);
    $err      = mysqli_stmt_error($deduct_stmt);
    mysqli_stmt_close($deduct_stmt);

    if (!$ok) {
        $dbg['result'] = 'failed: ' . $err;
        return [$dbg, "{$stored_name}: {$label} deduction failed — {$err}"];
    }

    $dbg['result'] = "ok: before={$before_qty}, deducted={$qty}, affected_rows={$affected}";

    $log_stmt = mysqli_prepare($conn,
        "INSERT INTO inventory_log (inventory_id, employee_id, change_type, qty_change, notes) VALUES (?,?,'usage',?,?)");
    if ($log_stmt) {
        $log_note = "Auto-deducted: {$qty} {$label} for order #{$order_id} ({$stored_name})";
        $neg_qty  = -$qty;
        mysqli_stmt_bind_param($log_stmt, 'iids', $inventory_id, $emp_id, $neg_qty, $log_note);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }

    return [$dbg, null];
}

// ── AJAX: Set today's starting cash drawer (change fund) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_drawer') {
    header('Content-Type: application/json');

    if (!$conn) { echo json_encode(['success' => false, 'message' => 'No database connection.']); exit; }
    if (!$starting_cash_col_ready) { echo json_encode(['success' => false, 'message' => "The starting_cash column hasn't been added to the attendance table yet."]); exit; }

    $emp_id = (int) ($employee_id ?? 0);
    if ($emp_id <= 0) { echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']); exit; }

    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT time_in FROM attendance WHERE employee_id=$emp_id AND work_date=CURDATE()"));
    if (!$chk || !$chk['time_in']) { echo json_encode(['success' => false, 'message' => 'Please time in first.']); exit; }

    $amount = (float) ($_POST['starting_cash'] ?? -1);
    if ($amount < 0) { echo json_encode(['success' => false, 'message' => 'Enter a valid amount.']); exit; }

    $s = mysqli_prepare($conn, "UPDATE attendance SET starting_cash=? WHERE employee_id=? AND work_date=CURDATE()");
    mysqli_stmt_bind_param($s, 'di', $amount, $emp_id);
    mysqli_stmt_execute($s);

    echo json_encode(['success' => true, 'starting_cash' => $amount]);
    exit;
}

// ── AJAX: Process checkout ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    ob_start(); // buffer any stray PHP warnings so JSON stays clean
    header('Content-Type: application/json');

    if (!$conn) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'No database connection. Set up your DB first.']);
        exit;
    }

    // Server-side shift gate — the on-screen freeze/SweetAlert is just the
    // UX layer; this is what actually stops a sale from being recorded if
    // someone bypasses the UI (e.g. resubmitting a request by hand).
    if ($pos_locked) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => !$has_clocked_in
            ? 'You need to time in before processing sales.'
            : 'Please set your starting cash drawer before processing sales.']);
        exit;
    }

    $items           = json_decode($_POST['items'] ?? '[]', true);
    $payment_method  = $_POST['payment_method']  ?? 'cash';
    $total_amount    = (float)($_POST['total_amount']    ?? 0);
    $amount_tendered = (float)($_POST['amount_tendered'] ?? 0);
    $order_type      = $_POST['order_type']      ?? 'dine_in';
    $customer_name   = trim($_POST['customer_name'] ?? '');
    $table_no        = trim($_POST['table_no']    ?? '');
    $notes           = trim($_POST['notes']       ?? '');

    if (empty($items) || $total_amount <= 0) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Empty order.']);
        exit;
    }

    // ── Guard: employee_id must be a valid integer ──────────────
    // Session may store it as 'employee_id' (staff) or 'user_id' (admin).
    // If both are missing the INSERT will fail with a FK / NOT NULL error.
    $emp_id = (int)($employee_id ?? 0);
    if ($emp_id <= 0) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Session expired or employee ID missing. Please log in again.']);
        exit;
    }

    $change_due = max(0, $amount_tendered - $total_amount);

    // ── Insert order ────────────────────────────────────────────
    $stmt = mysqli_prepare($conn,
        "INSERT INTO orders (employee_id, total_amount, payment_method, amount_tendered, change_due, status, notes, ordered_at)
         VALUES (?, ?, ?, ?, ?, 'completed', ?, NOW())");

    if (!$stmt) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . mysqli_error($conn)]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, 'idsdds',
        $emp_id, $total_amount, $payment_method, $amount_tendered, $change_due, $notes);

    if (!mysqli_stmt_execute($stmt)) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Failed to save order: ' . mysqli_stmt_error($stmt)]);
        exit;
    }

    $order_id = (int)mysqli_insert_id($conn);

    if ($order_id <= 0) {
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Order insert returned no ID. Check DB constraints.']);
        exit;
    }

    // ── Look up the "Straws" inventory item once (auto-deducted per drink) ──
    $straws_inventory_id = null;
    $straw_lookup = mysqli_query($conn, "SELECT inventory_id FROM inventory WHERE LOWER(item_name) = 'straws' LIMIT 1");
    if ($straw_lookup) {
        $straw_row = mysqli_fetch_assoc($straw_lookup);
        if ($straw_row) $straws_inventory_id = (int)$straw_row['inventory_id'];
    }

    // ── Insert order items + deduct cup inventory ────────────────
    $items_failed = [];
    $deduct_debug = [];
    foreach ($items as $item) {
        $item_id          = (int)($item['id']            ?? 0);
        $item_name        = (string)($item['name']       ?? '');
        $size_name        = (string)($item['size']       ?? '');
        $cup_inventory_id = (int)($item['cup_inv_id']    ?? 0);
        $qty              = (int)($item['qty']            ?? 1);
        $price            = (float)($item['price']        ?? 0);
        $subtotal         = $qty * $price;

        // Include size in the stored item name for readability
        $stored_name = $size_name ? $item_name . ' (' . $size_name . ')' : $item_name;

        $stmt2 = mysqli_prepare($conn,
            "INSERT INTO order_items (order_id, item_id, item_name, size_name, cup_inventory_id, quantity, unit_price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt2) {
            $items_failed[] = $item_name . ' (prepare error: ' . mysqli_error($conn) . ')';
            continue;
        }

        mysqli_stmt_bind_param($stmt2, 'iissiidd', $order_id, $item_id, $stored_name, $size_name, $cup_inventory_id, $qty, $price, $subtotal);

        if (!mysqli_stmt_execute($stmt2)) {
            $items_failed[] = $item_name . ': ' . mysqli_stmt_error($stmt2);
        }
        mysqli_stmt_close($stmt2);

        // ── Deduct cup + straw from inventory ─────────────────────
        if ($cup_inventory_id > 0) {
            [$cup_dbg, $cup_err] = deduct_supply_item($conn, $emp_id, $order_id, $cup_inventory_id, $qty, 'cup', $stored_name);
            $deduct_debug[] = $cup_dbg;
            if ($cup_err) $items_failed[] = $cup_err;

            // One straw per drink ordered, same qty as the cup.
            if ($straws_inventory_id) {
                [$straw_dbg, $straw_err] = deduct_supply_item($conn, $emp_id, $order_id, $straws_inventory_id, $qty, 'straw', $stored_name);
                $deduct_debug[] = $straw_dbg;
                if ($straw_err) $items_failed[] = $straw_err;
            } else {
                $deduct_debug[] = ['item' => $stored_name, 'result' => 'skipped straw: no inventory item named "Straws" found'];
            }
        } else {
            $deduct_debug[] = ['item' => $stored_name, 'result' => 'skipped: no cup_inventory_id sent from frontend (item has no linked cup size)'];
        }

        // ── Deduct recipe ingredients (coffee beans, milk, syrup, etc.) ──
        // Each recipe_ingredients row already stores qty_per_unit in the same
        // unit as its linked inventory row (g, ml, pcs, ...), so no unit
        // conversion is needed here — just scale by the quantity ordered.
        $ring_stmt = mysqli_prepare($conn,
            "SELECT ri.inventory_id, ri.qty_per_unit, inv.item_name AS ing_name
             FROM recipe_ingredients ri
             JOIN inventory inv ON inv.inventory_id = ri.inventory_id
             WHERE ri.item_id = ?");
        if ($ring_stmt) {
            mysqli_stmt_bind_param($ring_stmt, 'i', $item_id);
            mysqli_stmt_execute($ring_stmt);
            $ring_res = mysqli_stmt_get_result($ring_stmt);
            $recipe_rows = $ring_res ? mysqli_fetch_all($ring_res, MYSQLI_ASSOC) : [];
            mysqli_stmt_close($ring_stmt);

            if (empty($recipe_rows)) {
                $deduct_debug[] = ['item' => $stored_name, 'result' => 'skipped ingredients: no recipe_ingredients rows found for this item_id'];
            } else {
                foreach ($recipe_rows as $ing) {
                    $ing_deduct_qty = (float)$ing['qty_per_unit'] * $qty;
                    [$ing_dbg, $ing_err] = deduct_supply_item(
                        $conn, $emp_id, $order_id, (int)$ing['inventory_id'],
                        $ing_deduct_qty, $ing['ing_name'], $stored_name
                    );
                    $deduct_debug[] = $ing_dbg;
                    if ($ing_err) $items_failed[] = $ing_err;
                }
            }
        }
    }

    // Return success even if some items failed (order header saved),
    // but include a warning so the cashier knows.
    $warning = !empty($items_failed)
        ? ' Warning: some items failed to save — ' . implode('; ', $items_failed)
        : null;

    // The just-inserted order is already 'completed' in the table, so this
    // picks up its own cash movement too — no separate increment needed.
    $new_drawer_balance = $has_drawer_set ? compute_drawer_balance($conn, $emp_id, (float) $drawer_amount) : null;

    ob_end_clean(); // discard any stray output before sending JSON
    echo json_encode(array_filter([
        'success'        => true,
        'order_id'       => $order_id,
        'change_due'     => $change_due,
        'total_amount'   => $total_amount,
        'payment_method' => $payment_method,
        'customer_name'  => $customer_name,
        'table_no'       => $table_no,
        'order_type'     => $order_type,
        'cashier'        => $full_name,
        'items'          => $items,
        'warning'        => $warning,
        'deduct_debug'   => $deduct_debug,
        'drawer_balance' => $new_drawer_balance,
    ], fn($v) => $v !== null));
    exit;
}

// ── STAFF: Fetch menu (empty arrays if no DB yet) ────────────────
$menu_by_cat      = [];
$categories       = [];
$has_menu_items   = false;
$next_order_no    = 1;
$menu_items_by_id = [];
$best_sellers     = [];

// ── Fetch cup sizes for POS size selector ───────────────────────
$cup_sizes = [];
if ($conn) {
    $sz_res = mysqli_query($conn, "SELECT * FROM cup_sizes ORDER BY sort_order ASC");
    if ($sz_res) while ($sz = mysqli_fetch_assoc($sz_res)) $cup_sizes[] = $sz;
}
// Categories whose drinks have a size option (exclude food/pastries/cakes/promo/combos)
$SIZED_CATEGORIES = ['Coffee', 'Non-Coffee', 'Tea', 'Refreshers', 'Frappe'];

if ($conn) {
    $menu_result = mysqli_query($conn,
        "SELECT * FROM menu_items WHERE is_available = 1 ORDER BY category, item_name");
    $seen_names = [];
    while ($row = mysqli_fetch_assoc($menu_result)) {
        $key = strtolower(trim($row['item_name']));
        if (isset($seen_names[$key])) continue;
        $seen_names[$key] = true;
        $menu_by_cat[$row['category']][] = $row;
        $menu_items_by_id[(int)$row['item_id']] = $row;
    }
    // Standard category order — kept in sync with Menu_Control_Page.php's
    // $menu_cats_standard list. Tabs are always shown in this fixed order,
    // and (like the Menu Management page) every standard category appears
    // as a tab even if it has no items yet, instead of silently dropping
    // categories such as Frappe / Combos / Promo when they're empty.
    $CATEGORY_ORDER = ['Coffee', 'Non-Coffee', 'Tea', 'Frappe', 'Pastries', 'Cakes', 'Refreshers', 'Add-ons', 'Combos', 'Promo'];
    $categories = array_values(array_unique(array_merge($CATEGORY_ORDER, array_keys($menu_by_cat))));
    $has_menu_items = !empty($menu_by_cat);

    $queue_row     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) + 1 AS next_no FROM orders"));
    $next_order_no = (int)($queue_row['next_no'] ?? 1);

    // ── DSS: suggest the top-selling available item(s) at the top of the menu ──
    // Ranks items by total quantity sold across completed orders, then keeps
    // only the ones that are still on the available menu.
    $bs_res = mysqli_query($conn,
        "SELECT oi.item_id, SUM(oi.quantity) AS total_qty
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.order_id
         WHERE o.status = 'completed'
         GROUP BY oi.item_id
         ORDER BY total_qty DESC
         LIMIT 20");
    if ($bs_res) {
        while ($bs_row = mysqli_fetch_assoc($bs_res)) {
            $iid = (int)$bs_row['item_id'];
            if (!isset($menu_items_by_id[$iid])) continue; // skip items no longer on the menu
            $best_sellers[] = $menu_items_by_id[$iid] + ['total_qty' => (int)$bs_row['total_qty']];
            if (count($best_sellers) >= 3) break; // top 3 is plenty for a suggestion strip
        }
    }
}

// ── Local image map (fallback if DB image_path is empty) ────
$local_images = [
    'iced americano'    => '../images/IcedAmericano.jpg',
    'iced cappuccino'   => '../images/IcedCappuccino.jpg',
    'iced cafe mocha'   => '../images/IcedCafeMocha.jpg',
    'iced mocha'        => '../images/IcedCafeMocha.jpg',
    'hot choco'         => '../images/HotChoco.jpg',
    'hot chocolate'     => '../images/HotChoco.jpg',
    'green tea'         => '../images/GreenTea.jpg',
    'lemon iced tea'    => '../images/LemonIcedTea.jpg',
    'lemon tea'         => '../images/LemonIcedTea.jpg',
    'oat milk'          => '../images/Oat_Milk.jpg',
    'vanilla syrup'     => '../images/VanillaSyrup.jpg',
    'extra espresso'    => '../images/ExtraEspresso.jpg',
    'espresso shot'     => '../images/ExtraEspresso.jpg',
    'croissant'         => '../images/Croissant.jpg',
    'blueberry muffin'  => '../images/BlueberryMuffin.jpg',
    'muffin'            => '../images/BlueberryMuffin.jpg',
    'cheesecake'        => '../images/CheeseCakeSlice.jpg',
    'cheese cake'       => '../images/CheeseCakeSlice.jpg',
    'coffee beans'      => '../images/CoffeeBeans.jpg',
    'coffee bean'       => '../images/CoffeeBeans.jpg',
];

// ── Resolve an image path relative to this page (staff/) ──
// Handles: absolute URLs (http/https), already-correct "../images/..." paths,
// and bare/incorrectly-rooted paths coming from the DB (e.g. "img/x.jpg",
// "images/x.jpg", or just "x.jpg") by pointing them at ../images/.
function resolve_img(string $path): string {
    if ($path === '') return '';
    if (preg_match('#^(https?:)?//#i', $path)) return $path;      // absolute URL
    if (str_starts_with($path, '../images/')) return $path;          // already correct
    $filename = basename($path);
    return '../images/' . $filename;
}

// ── Icon helper: inline SVG (stroke-based, inherits currentColor) ──
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
        'utensils'    => '<path d="M3 2v7c0 1.1.9 2 2 2s2-.9 2-2V2"/><path d="M5 11v11"/><path d="M19 2c-2 1-3 3-3 6v3a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V2Z"/><path d="M19 13v9"/>',
        'cart'        => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.94a2 2 0 0 0 2 1.61h9.58a2 2 0 0 0 2-1.61l1.4-7.39H5.12"/>',
        'package'     => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/>',
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17.01"/>',
        'bar-chart'   => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'receipt'     => '<path d="M4 2v20l2.5-1.5L9 22l2.5-1.5L14 22l2.5-1.5L19 22V2l-2.5 1.5L14 2l-2.5 1.5L9 2 6.5 3.5Z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="13" y2="15"/>',
        'check'       => '<polyline points="20 6 9 17 4 12"/>',
        'printer'     => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'banknote'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01"/><path d="M18 12h.01"/>',
        'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'smartphone'  => '<rect x="6" y="2" width="12" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
        'wallet'      => '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
        'frappe'      => '<path d="M6 8h12l-1.4 11.2A2 2 0 0 1 14.6 21H9.4a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8V5.5a3 3 0 0 1 6 0V8"/><line x1="9.5" y1="12" x2="14.5" y2="12"/><line x1="10" y1="16" x2="14" y2="16"/>',
        'layers'      => '<path d="m12 2 9 5-9 5-9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'tag'         => '<path d="M12.6 2.6 21 11l-8.4 8.4a2 2 0 0 1-2.8 0L3 12.6V4a1.4 1.4 0 0 1 1.4-1.4h8.2Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg class="svg-icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$d.'</svg>';
}

$cat_icons = [
    'Coffee'      => 'coffee',  'Non-Coffee' => 'milk',     'Tea'         => 'tea',
    'Refreshers'  => 'citrus',  'Pastries'   => 'croissant','Cakes'       => 'cake',
    'Food'        => 'croissant','Add-on'    => 'plus',     'Add-ons'     => 'plus',
    'Merchandise' => 'bag',    'Frappe'     => 'frappe',   'Combos'      => 'layers',
    'Promo'       => 'tag',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sales Processing — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/sales_processing.css"/>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>
<body<?= $pos_locked ? ' class="pos-frozen"' : '' ?>>

<?php $active_page = 'emp_sales'; require_once '../staff/Sidebar_Employee.php'; ?>

<div class="main">

  <?php if (!$conn): ?>
  <div class="db-warning">
    <?= icon('alert', 18) ?> <strong>No database connected.</strong> See <code>DB_Connect.php</code> to configure your database.
  </div>
  <?php endif; ?>

  <div class="topbar">
    <div class="topbar-title" style="display:flex;align-items:center;gap:14px;">
      <span>Point of Sales</span>
    </div>
    <div class="topbar-right">
      <?php if ($starting_cash_col_ready && $has_drawer_set): ?>
        <div class="topbar-drawer" title="Cash currently in the drawer (starting float + cash sales)">
          <?= icon('receipt', 14) ?> Drawer: ₱<span id="drawerAmountDisplay"><?= number_format((float) $drawer_balance, 2) ?></span>
        </div>
      <?php endif; ?>
      <div class="topbar-cashier"><span class="cashier-dot"></span> <?= htmlspecialchars($full_name) ?></div>
    </div>
  </div>

  <div class="pos-layout">

    <!-- LEFT: MENU -->
    <div class="pos-menu">
      <div class="pos-search">
        <span class="icon"><?= icon('search', 15) ?></span>
        <input type="text" id="searchInput" placeholder="Search items by name…" oninput="filterItems()"/>
      </div>

      <?php if (!empty($best_sellers)): ?>
      <div class="dss-best-sellers" id="bestSellerSection" style="margin-bottom:16px">
        <div class="pos-section-title" style="display:flex;align-items:center;gap:6px;color:#a6650f">
          <?= icon('bar-chart', 15) ?> Best Sellers <span style="font-weight:400;color:var(--text-light);font-size:12px">— suggested for you</span>
        </div>
        <div class="items-grid">
          <?php foreach ($best_sellers as $bidx => $item):
            $img   = trim($item['image_path'] ?? '');
            if (!$img) {
                $lookup = strtolower(trim($item['item_name']));
                foreach ($local_images as $key => $path) {
                    if (str_contains($lookup, $key)) { $img = $path; break; }
                }
            }
            $img   = htmlspecialchars(resolve_img($img));
            $name  = htmlspecialchars($item['item_name']);
            $price = number_format($item['price'], 2);
            $id    = (int)$item['item_id'];
            $cat   = $item['category'];
          ?>
          <div class="item-card best-seller-card"
               data-name="<?= strtolower($name) ?>"
               data-cat="<?= htmlspecialchars($cat) ?>"
               style="position:relative;border:1px solid #a6650f;box-shadow:0 0 0 1px rgba(245,158,11,.15)"
               onclick="handleItemClick(<?= $id ?>, '<?= addslashes($item['item_name']) ?>', <?= $item['price'] ?>, '<?= addslashes($img) ?>', '<?= addslashes($cat) ?>')">
            <span style="position:absolute;top:6px;left:6px;z-index:1;background:#a6650f;color:#fff;font-size:10px;font-weight:700;letter-spacing:.02em;padding:2px 7px;border-radius:20px;">
              <?= $bidx === 0 ? '🔥 #1 SELLING' : 'TOP SELLING' ?>
            </span>
            <div class="item-card-img">
              <?php if ($img): ?>
                <img src="<?= $img ?>" alt="<?= $name ?>"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                <span style="display:none;align-items:center;justify-content:center;width:100%;height:100%;color:var(--text-light)"><?= icon('coffee', 36) ?></span>
              <?php else: ?>
                <span style="color:var(--text-light)"><?= icon('coffee', 36) ?></span>
              <?php endif; ?>
            </div>
            <div class="item-card-body">
              <div class="item-card-name"><?= $name ?></div>
              <div class="item-card-price">₱<?= $price ?></div>
            </div>
            <div class="item-card-add">+</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="cat-tabs" id="catTabs">
        <div class="cat-tab active" data-cat="All" onclick="filterCat(this)"><?= icon('grid', 14) ?> All</div>
        <?php foreach ($categories as $cat):
          $icon_name = $cat_icons[$cat] ?? 'utensils';
        ?>
        <div class="cat-tab" data-cat="<?= htmlspecialchars($cat) ?>" onclick="filterCat(this)">
          <?= icon($icon_name, 14) ?> <?= htmlspecialchars($cat) ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php foreach ($menu_by_cat as $cat => $items): ?>
      <div class="cat-section" data-section="<?= htmlspecialchars($cat) ?>">
        <div class="pos-section-title"><?= htmlspecialchars($cat) ?></div>
        <div class="items-grid">
          <?php foreach ($items as $item):
            $img   = trim($item['image_path'] ?? '');
            if (!$img) {
                $lookup = strtolower(trim($item['item_name']));
                foreach ($local_images as $key => $path) {
                    if (str_contains($lookup, $key)) { $img = $path; break; }
                }
            }
            $img   = htmlspecialchars(resolve_img($img));
            $name  = htmlspecialchars($item['item_name']);
            $price = number_format($item['price'], 2);
            $id    = (int)$item['item_id'];
          ?>
          <div class="item-card"
               data-name="<?= strtolower($name) ?>"
               data-cat="<?= htmlspecialchars($cat) ?>"
               onclick="handleItemClick(<?= $id ?>, '<?= addslashes($item['item_name']) ?>', <?= $item['price'] ?>, '<?= addslashes($img) ?>', '<?= addslashes($cat) ?>')">
            <div class="item-card-img">
              <?php if ($img): ?>
                <img src="<?= $img ?>" alt="<?= $name ?>"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                <span style="display:none;align-items:center;justify-content:center;width:100%;height:100%;color:var(--text-light)"><?= icon('coffee', 36) ?></span>
              <?php else: ?>
                <span style="color:var(--text-light)"><?= icon('coffee', 36) ?></span>
              <?php endif; ?>
            </div>
            <div class="item-card-body">
              <div class="item-card-name"><?= $name ?></div>
              <div class="item-card-price">₱<?= $price ?></div>
            </div>
            <div class="item-card-add">+</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($has_menu_items)): ?>
      <div style="text-align:center;padding:60px 20px;color:var(--text-light)">
        <div style="margin-bottom:12px;color:var(--text-light);display:flex;justify-content:center"><?= icon('utensils', 48) ?></div>
        <p><?= $conn ? 'No menu items found.<br>Add items in the Admin panel first.' : 'Connect your database to load the menu.' ?></p>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: ORDER PANEL -->
    <div class="pos-order">
      <div class="order-header">
        <h2>Current Order</h2>
        <div class="order-tabs" id="orderTypeTabs">
         <div class="order-tab active" data-type="dine_in">Dine In</div>
         <div class="order-tab" data-type="takeout">Takeout</div>
        </div>
        <div class="order-info">
          <input type="text" id="customerName" placeholder="Customer name (optional)"/>
          <div class="table-no-wrap" id="tableNoWrap">
            <input type="text" id="tableNo" placeholder="Table No."/>
            <span class="queue-badge" id="queueBadge" style="display:none">
              Queue #<strong id="queueNum"><?= $next_order_no ?></strong>
            </span>
          </div>
        </div>
      </div>

      <div class="order-items-wrap">
        <div class="order-items" id="cartItems"></div>
        <div class="cart-empty" id="cartEmpty" style="display:flex">
          <div class="empty-icon"><?= icon('cart', 48) ?></div>
          <p>No items yet.<br>Click a menu item to add it.</p>
        </div>
      </div>

      <div class="order-footer">
        <div class="order-breakdown">
          <div class="breakdown-row" id="subtotalRow"><span>Subtotal (0 items)</span><span>₱0.00</span></div>
          <div class="breakdown-row" id="taxRow"><span>Tax (3%)</span><span>₱0.00</span></div>
          <div class="breakdown-row"><span>Discount</span><span style="color:var(--success)">− ₱0.00</span></div>
          <div class="breakdown-row total" id="totalRow"><span>Total</span><span>₱0.00</span></div>
        </div>

        <div class="tendered-row" id="tenderedRow">
          <div class="tendered-header">
            <label>Cash Tendered</label>
            <span class="tendered-hint" id="changeDisplay">Enter amount received</span>
          </div>
          <div class="tendered-input-wrap">
            <span class="peso-prefix">₱</span>
            <input type="number" id="tenderedInput" placeholder="0.00" min="0" step="0.01" oninput="calcChange()"/>
          </div>
          <div class="quick-cash" id="quickCash"></div>
        </div>

        <div class="online-pay-note-row" id="onlinePayNoteRow">
          <span id="onlinePayNoteText"></span>
        </div>

        <div class="payment-methods" id="paymentMethods">
          <div class="pay-btn active" data-method="cash"><span class="pay-icon"><?= icon('banknote', 18) ?></span>Cash</div>
          <div class="pay-btn" data-method="gcash"><span class="pay-icon"><?= icon('smartphone', 18) ?></span>GCash</div>
        </div>

        <button class="btn-charge" id="btnCharge" disabled onclick="doCheckout()">
          <span id="btnChargeLabel">No items in cart</span>
          <span class="btn-charge-sub" id="btnChargeSub">Add items to begin</span>
        </button>
      </div>
    </div>
  </div>
</div>

<div class="pos-clock" id="posClock" aria-hidden="true"
     style="position:fixed;bottom:10px;left:50%;transform:translateX(-50%);
            font-size:11px;font-weight:600;letter-spacing:.02em;color:#2f6690;
            background:rgba(255,255,255,.9);padding:4px 12px;border-radius:20px;
            box-shadow:0 1px 6px rgba(11,30,51,.08);z-index:50;pointer-events:none"></div>

<!-- GENERIC CONFIRM MODAL -->
<div class="modal-overlay" id="confirmModal">
  <div class="confirm-modal" id="confirmBox">
    <div class="confirm-icon" id="confirmIcon"><?= icon('trash', 38) ?></div>
    <div class="confirm-title" id="confirmTitle">Remove item?</div>
    <div class="confirm-message" id="confirmMessage">Are you sure?</div>
    <div class="modal-actions">
      <button class="modal-btn modal-btn-ghost" onclick="closeConfirmModal()">Cancel</button>
      <button class="modal-btn modal-btn-danger" id="confirmActionBtn">Yes, Remove</button>
    </div>
  </div>
</div>

<!-- ORDER CONFIRMATION MODAL -->
<div class="modal-overlay" id="orderConfirmModal">
  <div class="confirm-modal order-confirm-box">
    <div class="confirm-icon"><?= icon('receipt', 38) ?></div>
    <div class="confirm-title">Confirm Order</div>
    <div class="order-confirm-list" id="orderConfirmList"></div>
    <div class="order-confirm-summary" id="orderConfirmSummary"></div>
    <div class="modal-actions">
      <button class="modal-btn modal-btn-ghost" onclick="closeOrderConfirmModal()">Go Back</button>
      <button class="modal-btn modal-btn-primary" onclick="handleOrderConfirmed()">Confirm &amp; Charge</button>
    </div>
  </div>
</div>

<!-- SCAN TO PAY MODAL -->
<div class="modal-overlay" id="scanPayModal">
  <div class="confirm-modal order-confirm-box" style="text-align:center">
    <div class="confirm-title">Scan to Pay</div>
    <div class="online-pay-provider" id="scanProvider"></div>
    <div class="qr-box" id="scanQrBox" style="margin:14px auto;"></div>
    <div class="pay-number" id="scanPayNumber"></div>
    <div class="pay-amount-due" style="margin-top:10px">Amount due: <strong id="scanAmountDue"></strong></div>
    <div class="online-pay-note"><?= icon('alert', 13) ?> Demo QR / number for preview purposes only</div>
    <div class="modal-actions">
      <button class="modal-btn modal-btn-ghost" onclick="closeScanPayModal()">Cancel</button>
      <button class="modal-btn modal-btn-primary" onclick="confirmPaymentReceived()"><?= icon('check', 14) ?> Payment Received</button>
    </div>
  </div>
</div>

<!-- RECEIPT MODAL -->
<div class="modal-overlay" id="receiptModal">
  <div class="receipt-modal" id="receiptBox">
    <div class="printer-wrap" id="printerWrap">
      <div class="printer-chassis">
        <div class="printer-top-row">
          <span class="printer-label">POS PRINTER · READY</span>
          <span class="printer-status-dot" id="printerStatusDot"></span>
        </div>
        <div class="panel-buttons">
          <span class="btn-dot"></span>
          <span class="btn-dot"></span>
          <span class="btn-dot"></span>
        </div>
      </div>
      <div class="mouth-lip"></div>
      <div class="mouth-slot"></div>

      <div class="receipt-window" id="receiptWindow">
        <div class="receipt-content" id="receiptContent">
          <div class="receipt-header">
            <div style="text-align:center;margin-bottom:10px">
              <span class="success-badge"><?= icon('check', 13) ?> Payment Successful</span>
            </div>
            <div class="receipt-logo">Cloud<span>Cup</span></div>
            <div class="receipt-tagline">Thank you for your visit!</div>
          </div>
          <div class="receipt-order-type-wrap">
            <span class="receipt-order-type" id="receiptOrderType"></span>
          </div>
          <div class="receipt-table-badge" id="receiptTableBadge" style="display:none">
            <div style="flex:1;text-align:center">
              <div class="tbl-label">Table No.</div>
              <div class="tbl-num" id="receiptTableNum"></div>
            </div>
            <div class="tbl-divider" id="receiptBannerNameWrap">
              <div class="tbl-label">Customer</div>
              <div class="tbl-customer" id="receiptBannerName"></div>
            </div>
          </div>
          <hr class="receipt-divider"/>
          <div class="receipt-meta" id="receiptMeta"></div>
          <hr class="receipt-divider"/>
          <table class="receipt-items">
            <thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead>
            <tbody id="receiptItems"></tbody>
          </table>
          <hr class="receipt-divider"/>
          <div class="receipt-totals" id="receiptTotals"></div>
          <div class="receipt-footer">
            <strong><?= icon('coffee', 14) ?> Enjoy your order!</strong>
            We'd love to see you again soon.
            <div class="powered">Powered by Cloud Cup POS</div>
          </div>
          <div class="modal-actions modal-actions-inline" id="modalActions">
            <button class="modal-btn modal-btn-ghost" onclick="printReceipt()"><?= icon('printer', 14) ?> Print</button>
            <button class="modal-btn modal-btn-primary" onclick="closeReceipt()">New Order</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- SIZE PICKER MODAL -->
<div class="modal-overlay" id="sizePickerModal">
  <div class="confirm-modal size-picker-box">
    <div class="size-picker-header">
      <div class="size-picker-thumb" id="sizePickerThumb"></div>
      <div>
        <div class="size-picker-name" id="sizePickerName"></div>
        <div class="size-picker-base" id="sizePickerBase"></div>
      </div>
    </div>
    <div class="size-picker-title">Choose a Size</div>
    <div class="size-btn-group" id="sizeBtnGroup"></div>
    <div class="modal-actions" style="margin-top:16px">
      <button class="modal-btn modal-btn-ghost" onclick="closeSizePickerModal()">Cancel</button>
      <button class="modal-btn modal-btn-primary" id="sizePickerConfirm" onclick="confirmSizePicker()">Add to Order</button>
    </div>
  </div>
</div>

<!-- PHP injects the queue number so JS can use it without inline PHP in .js file -->
<script>const TAKEOUT_QUEUE_NO = <?= $next_order_no ?>;</script>
<script>
// Cup sizes injected by PHP
const CUP_SIZES = <?= json_encode($cup_sizes) ?>;
const SIZED_CATEGORIES = <?= json_encode($SIZED_CATEGORIES) ?>;
</script>
<script src="../js/sales_processing.js?v=2"></script>
<script src="../js/lucide-init.js"></script>

<!-- SweetAlert2 confirmation for Logout -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if ($pos_locked): ?>
<!-- Shift gate: must time in, then set a starting cash drawer, before the POS unlocks -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php if (!$has_clocked_in): ?>
    Swal.fire({
      title: 'Time In Required',
      html: 'You need to time in first before you can use the POS.',
      icon: 'warning',
      allowOutsideClick: false,
      allowEscapeKey: false,
      confirmButtonText: 'Go to Attendance',
      confirmButtonColor: '#b8703f'
    }).then(function () {
      window.location.href = '../HR/Attendance_Page.php';
    });
  <?php elseif (!$starting_cash_col_ready): ?>
    // No column to gate on yet — just a heads-up, POS stays usable.
    Swal.fire({
      title: 'Drawer setup unavailable',
      text: "The starting_cash column hasn't been added to the attendance table yet, so this step is skipped for now.",
      icon: 'info',
      confirmButtonColor: '#b8703f'
    });
  <?php else: ?>
    askForDrawerAmount();
  <?php endif; ?>
});

function askForDrawerAmount() {
  Swal.fire({
    title: 'Starting Cash Drawer',
    html: "Before you start your shift, enter the cash you're putting in the drawer for change.",
    icon: 'question',
    input: 'number',
    inputAttributes: { min: 0, step: '0.01', placeholder: '0.00' },
    inputValidator: function (value) {
      if (value === '' || value === null || parseFloat(value) < 0) return 'Please enter a valid amount.';
    },
    allowOutsideClick: false,
    allowEscapeKey: false,
    confirmButtonText: 'Start Shift',
    confirmButtonColor: '#b8703f',
    showLoaderOnConfirm: true,
    preConfirm: function (value) {
      return fetch('Sales_Processing_Page.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=set_drawer&starting_cash=' + encodeURIComponent(value)
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw new Error(data.message || 'Something went wrong.');
        return data;
      })
      .catch(function (err) {
        Swal.showValidationMessage('Error: ' + err.message);
      });
    }
  }).then(function (result) {
    if (result.isConfirmed && result.value) {
      Swal.fire({
        title: 'Shift started!',
        text: 'Drawer set to ₱' + parseFloat(result.value.starting_cash).toFixed(2) + '.',
        icon: 'success',
        confirmButtonColor: '#b8703f',
        timer: 1800,
        timerProgressBar: true,
        showConfirmButton: false
      }).then(function () { location.reload(); });
    }
  });
}
</script>
<?php endif; ?>
</body>
</html>