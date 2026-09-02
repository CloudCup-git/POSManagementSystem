<?php
// ── AUTH GUARD ──────────────────────────────────────────────────────────────
// Inventory is a manager-level, branch-scoped operational page now —
// Manager gets full CRUD, Employee/Inventory Staff get a read-only view.
// Admin is intentionally NOT allowed here anymore (Admin has no
// operational branch to manage inventory for); an Admin session gets
// bounced back to the role picker just like any other wrong-role session.
session_start();
$_role_lc = strtolower($_SESSION['role'] ?? '');

// Staff session is set by Role_Panel.php: role/employee_id/full_name.
// Manager session is set by Login_Page.php: user_id/role/full_name.
// Both ultimately point to the same users.user_id row (orders.employee_id
// and inventory_log.employee_id are FKs to users.user_id) — just named
// differently depending on which login flow was used — so accept either
// shape instead of bouncing a valid session back to the role picker.
$is_staff_session   = isset($_SESSION['role'], $_SESSION['employee_id'], $_SESSION['full_name'])
  && in_array($_role_lc, ['employee', 'inventory_staff'], true);
$is_manager_session = isset($_SESSION['user_id'], $_SESSION['role']) && $_role_lc === 'manager';

if (!$is_staff_session && !$is_manager_session) {
  header('Location: ../auth/Role_Panel.php');
  exit;
}
require_once __DIR__ . '/../includes/DB_Connect.php';

$role      = $_SESSION['role'];
$is_admin  = $is_manager_session; // kept as $is_admin: drives the full-CRUD vs read-only view below
$full_name = $_SESSION['full_name'] ?? 'Manager';
$user_id   = (int)($_SESSION['employee_id'] ?? $_SESSION['user_id']);

// ── SOFT DELETE SUPPORT ─────────────────────────────────────────────────────
// Older installs may not have this column yet — add it on the fly the first
// time this page runs so "remove" becomes reversible (is_active=0) instead
// of a permanent DELETE, without requiring a manual migration.
$has_is_active = false;
if ($conn) {
  $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory LIKE 'is_active'");
  if ($col_check && mysqli_num_rows($col_check) > 0) {
    $has_is_active = true;
  } elseif (mysqli_query($conn, "ALTER TABLE inventory ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1")) {
    $has_is_active = true;
  }
}

// Same on-the-fly migration pattern, for the Restock modal's supplier/
// delivery-date fields on inventory_log. Nullable — older log rows (and
// non-restock change types) simply won't have these set.
if ($conn) {
  foreach ([
    'supplier_name' => "ALTER TABLE inventory_log ADD COLUMN supplier_name VARCHAR(150) NULL",
    'delivery_date' => "ALTER TABLE inventory_log ADD COLUMN delivery_date DATE NULL",
    // Groups rows that came from the same staff "Apply All Changes" batch
    // submission, so the Manager's Recent Activity panel can show them as
    // one entry instead of a flood of separate lines. Nullable — older
    // single-item log rows (and non-batch change types) simply won't have
    // it set, and are just shown ungrouped.
    'batch_id'      => "ALTER TABLE inventory_log ADD COLUMN batch_id VARCHAR(40) NULL, ADD INDEX idx_batch_id (batch_id)",
  ] as $col => $ddl) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM inventory_log LIKE '$col'");
    if (!$c || mysqli_num_rows($c) === 0) {
      mysqli_query($conn, $ddl);
    }
  }
}

// Restock requests now go through Finance approval instead of applying
// immediately — this table holds them until approved/rejected. Created
// on the fly, same as the migrations above.
if ($conn) {
  mysqli_query($conn, "CREATE TABLE IF NOT EXISTS restock_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    qty_added DECIMAL(10,2) NOT NULL,
    new_cost DECIMAL(10,2) NULL,
    supplier_name VARCHAR(150) NOT NULL,
    delivery_date DATE NOT NULL,
    payment_type ENUM('cash','credit') NOT NULL,
    note VARCHAR(200) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT NOT NULL,
    requested_by_name VARCHAR(150) NOT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_by_name VARCHAR(150) NULL,
    reviewed_at TIMESTAMP NULL,
    review_note VARCHAR(200) NULL
  )");
  // Groups requests filed together via Manager's "Bulk Restock" (checkbox
  // multi-select) so Finance can review/approve them as one submission
  // instead of one row at a time. Nullable — older single-item requests
  // (filed before this existed) simply won't have it set.
  $bc = mysqli_query($conn, "SHOW COLUMNS FROM restock_requests LIKE 'batch_id'");
  if (!$bc || mysqli_num_rows($bc) === 0) {
    mysqli_query($conn, "ALTER TABLE restock_requests ADD COLUMN batch_id VARCHAR(40) NULL, ADD INDEX idx_restock_batch_id (batch_id)");
  }
}

// ── HANDLE POST ACTIONS (admin only) ────────────────────────────────────────
$action_msg = '';

// ── Staff: Report Low Stock (AJAX, no full page reload) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'report_low_stock') {
  header('Content-Type: application/json');
  $inv_id = (int)($_POST['inventory_id'] ?? 0);

  if (!$inv_id || !$conn) {
    echo json_encode(['success' => false, 'message' => 'Invalid item or no database connection.']);
    exit;
  }

  $item = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT item_name, quantity, reorder_level FROM inventory WHERE inventory_id = $inv_id"
  ));

  if (!$item) {
    echo json_encode(['success' => false, 'message' => 'Item not found.']);
    exit;
  }

  // Avoid spamming admin — skip if there's already an unread report
  // for this item from the last 6 hours.
  $dup = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT alert_id FROM stock_alerts
         WHERE inventory_id = $inv_id AND status = 'unread'
           AND created_at >= NOW() - INTERVAL 6 HOUR LIMIT 1"
  ));

  if ($dup) {
    echo json_encode([
      'success' => true,
      'already' => true,
      'message' => 'Manager has already been notified about "' . $item['item_name'] . '".'
    ]);
    exit;
  }

  $stmt = mysqli_prepare(
    $conn,
    "INSERT INTO stock_alerts (inventory_id, item_name, quantity_at_report, reorder_level, reported_by, reporter_name)
         VALUES (?,?,?,?,?,?)"
  );
  mysqli_stmt_bind_param(
    $stmt,
    'isddis',
    $inv_id,
    $item['item_name'],
    $item['quantity'],
    $item['reorder_level'],
    $user_id,
    $full_name
  );
  mysqli_stmt_execute($stmt);

  echo json_encode(['success' => true, 'message' => 'Manager has been notified that "' . $item['item_name'] . '" is low on stock.']);
  exit;
}

// ── Staff: Apply Batch Stock Adjustments (AJAX, no full page reload) ───────
// Manager gets full CRUD via the 'edit' action below. Staff/Inventory Staff
// only get to touch `quantity` — never cost_per_unit, reorder_level, name,
// category, or unit — since cost_per_unit feeds the Balance Sheet's
// inventory asset value and reorder_level/category are operational
// decisions, not stock counts.
//
// Staff stage as many quantity adjustments as they like in the "pending
// changes" tray (client-side, in inventory_management.js) before submitting
// them all here in one request. Each item still gets its own inventory_log
// row with its own reason — the audit trail is unchanged — but every row in
// the same submission shares a batch_id, so the Manager's Recent Activity
// panel can group them as one "submission" instead of a flood of separate
// lines. All-or-nothing: every item is validated up front, and nothing is
// written unless the whole batch checks out, so a mistake on one item never
// leaves the batch half-applied.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'batch_adjust_qty') {
  header('Content-Type: application/json');

  if (!$is_staff_session) {
    echo json_encode(['success' => false, 'message' => 'Only staff accounts can use this action.']);
    exit;
  }
  if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'No database connection.']);
    exit;
  }

  $items = json_decode($_POST['items'] ?? '', true);
  if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No pending changes to apply.']);
    exit;
  }
  if (count($items) > 100) {
    echo json_encode(['success' => false, 'message' => 'Too many items in one batch (max 100) — apply some now and the rest separately.']);
    exit;
  }

  $reason_labels = [
    'delivery'  => 'Received Delivery',
    'recount'   => 'Recount Correction',
    'damaged'   => 'Damaged/Spoiled',
    'other'     => 'Other',
  ];

  // ── Validate every staged row first — nothing is written until the
  // whole batch checks out. ──
  $clean    = [];
  $errors   = [];
  $seen_ids = [];
  foreach ($items as $row) {
    // Field names here must match the pendingAdjustments entry shape built
    // client-side in inventory_management.js (new_qty / reason_key) — not
    // the old single-item POST field names (new_quantity / reason).
    $inv_id      = (int)($row['inventory_id'] ?? 0);
    $new_qty     = isset($row['new_qty']) && $row['new_qty'] !== '' ? (float)$row['new_qty'] : null;
    $reason_key  = trim($row['reason_key'] ?? '');
    $reason_note = trim($row['note'] ?? '');

    if (!$inv_id) {
      $errors[] = 'One of the staged items is missing its item reference.';
      continue;
    }
    if (isset($seen_ids[$inv_id])) {
      $errors[] = 'Duplicate entry for the same item in this batch.';
      continue;
    }
    $seen_ids[$inv_id] = true;

    $item = mysqli_fetch_assoc(mysqli_query(
      $conn,
      "SELECT item_name, unit, quantity FROM inventory WHERE inventory_id = $inv_id"
    ));
    if (!$item) {
      $errors[] = 'An item in this batch no longer exists — remove it and try again.';
      continue;
    }
    if ($new_qty === null || $new_qty < 0 || $new_qty > 9999) {
      $errors[] = '"' . $item['item_name'] . '": enter a valid quantity (0–9999).';
      continue;
    }
    if (!isset($reason_labels[$reason_key])) {
      $errors[] = '"' . $item['item_name'] . '": please select a reason.';
      continue;
    }

    $old_qty = (float)$item['quantity'];
    $diff    = $new_qty - $old_qty;
    if ($diff == 0) {
      $errors[] = '"' . $item['item_name'] . '": new quantity matches the current quantity — remove it from the batch.';
      continue;
    }

    $clean[] = [
      'inv_id'       => $inv_id,
      'item_name'    => $item['item_name'],
      'new_qty'      => $new_qty,
      'diff'         => $diff,
      'reason_label' => $reason_labels[$reason_key],
      'note'         => $reason_note,
    ];
  }

  if ($errors) {
    $shown = array_slice($errors, 0, 3);
    $extra = count($errors) - count($shown);
    echo json_encode([
      'success' => false,
      'message' => implode(' ', $shown) . ($extra > 0 ? " (+$extra more)" : ''),
    ]);
    exit;
  }

  // 8 random bytes as hex — unique enough to group a batch without needing
  // a separate batches table.
  $batch_id = bin2hex(random_bytes(8));

  mysqli_begin_transaction($conn);
  $ok  = true;
  $upd = mysqli_prepare($conn, 'UPDATE inventory SET quantity = ? WHERE inventory_id = ?');
  $log = mysqli_prepare($conn, 'INSERT INTO inventory_log (inventory_id, employee_id, change_type, qty_change, notes, batch_id) VALUES (?,?,?,?,?,?)');

  foreach ($clean as $c) {
    $ct   = $c['diff'] > 0 ? 'restock' : 'adjustment';
    $note = $c['reason_label'] . ($c['note'] !== '' ? ' — ' . $c['note'] : '') . ' (reported by ' . $full_name . ')';

    mysqli_stmt_bind_param($upd, 'di', $c['new_qty'], $c['inv_id']);
    mysqli_stmt_bind_param($log, 'iisdss', $c['inv_id'], $user_id, $ct, $c['diff'], $note, $batch_id);

    if (!mysqli_stmt_execute($upd) || !mysqli_stmt_execute($log)) {
      $ok = false;
      break;
    }
  }

  if ($ok) {
    mysqli_commit($conn);
    $n = count($clean);
    echo json_encode([
      'success' => true,
      'message' => $n . ' item' . ($n > 1 ? 's' : '') . ' updated and reported to your Manager.',
      'applied' => $n,
    ]);
  } else {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Something went wrong applying the batch. No changes were saved — try again.']);
  }
  exit;
}

if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  // ── Add Item ──
  if ($act === 'add') {
    $item_name     = trim($_POST['item_name']    ?? '');
    $category      = trim($_POST['category']     ?? '');
    $unit          = trim($_POST['unit']         ?? '');
    $quantity      = (float)($_POST['quantity']  ?? 0);
    $reorder_level = (float)($_POST['reorder']   ?? 0);
    $cost_per_unit = (isset($_POST['cost']) && $_POST['cost'] !== '') ? (float)$_POST['cost'] : null;
    if ($item_name && $unit) {
      // Block duplicates -- case-insensitive match on item_name.
      $dup_stmt = mysqli_prepare(
        $conn,
        'SELECT inventory_id FROM inventory WHERE LOWER(item_name) = LOWER(?) LIMIT 1'
      );
      mysqli_stmt_bind_param($dup_stmt, 's', $item_name);
      mysqli_stmt_execute($dup_stmt);
      $dup_res    = mysqli_stmt_get_result($dup_stmt);
      $dup_exists = $dup_res ? mysqli_fetch_assoc($dup_res) : null;

      if ($dup_exists) {
        $action_msg = 'error:"' . htmlspecialchars($item_name) . '" already exists in inventory. Edit the existing item instead of adding a duplicate.';
      } else {
        $stmt = mysqli_prepare(
          $conn,
          'INSERT INTO inventory (item_name, category, unit, quantity, reorder_level, cost_per_unit) VALUES (?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($stmt, 'sssddd', $item_name, $category, $unit, $quantity, $reorder_level, $cost_per_unit);
        mysqli_stmt_execute($stmt);
        $new_id = (int)mysqli_insert_id($conn);
        // log it
        $log = mysqli_prepare(
          $conn,
          'INSERT INTO inventory_log (inventory_id, employee_id, change_type, qty_change, notes) VALUES (?,?,?,?,?)'
        );
        $note = 'Initial stock added by admin';
        $ct   = 'restock';
        mysqli_stmt_bind_param($log, 'iisds', $new_id, $user_id, $ct, $quantity, $note);
        mysqli_stmt_execute($log);
        $action_msg = 'success:Item added successfully.';
      }
    } else {
      $action_msg = 'error:Item name and unit are required.';
    }
  }

  // ── Restock Item (Manager only) — SUBMIT FOR APPROVAL ──
  // Manager no longer applies the restock directly. This only files a
  // restock_requests row (status='pending'). The quantity/cost update,
  // inventory_log entry, and the matching Finance record (cash expense
  // or accounts-payable liability) only happen once Finance approves it
  // on finance_restock_approvals.php — see that file for the apply logic.
  if ($act === 'restock') {
    $inv_id        = (int)($_POST['inventory_id'] ?? 0);
    $qty_added     = isset($_POST['qty_added']) && $_POST['qty_added'] !== '' ? (float)$_POST['qty_added'] : null;
    $new_cost      = (isset($_POST['cost']) && $_POST['cost'] !== '') ? (float)$_POST['cost'] : null;
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $payment_type  = in_array($_POST['payment_type'] ?? '', ['cash', 'credit'], true) ? $_POST['payment_type'] : null;
    $note          = trim($_POST['note'] ?? '');

    if (!$inv_id || $qty_added === null || $qty_added <= 0) {
      $action_msg = 'error:Enter a valid quantity to add.';
    } elseif ($supplier_name === '') {
      $action_msg = 'error:Supplier name is required.';
    } elseif ($delivery_date === '' || !DateTime::createFromFormat('Y-m-d', $delivery_date)) {
      $action_msg = 'error:A valid delivery date is required.';
    } elseif (!$payment_type) {
      $action_msg = 'error:Please select how the supplier will be paid (Cash or Credit).';
    } else {
      $item = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT item_name FROM inventory WHERE inventory_id = $inv_id"
      ));
      if (!$item) {
        $action_msg = 'error:Item not found.';
      } else {
        $stmt = mysqli_prepare(
          $conn,
          'INSERT INTO restock_requests
             (inventory_id, item_name, qty_added, new_cost, supplier_name, delivery_date, payment_type, note, status, requested_by, requested_by_name)
           VALUES (?,?,?,?,?,?,?,?,\'pending\',?,?)'
        );
        // Params, in order: inventory_id(i) item_name(s) qty_added(d) new_cost(d)
        // supplier_name(s) delivery_date(s) payment_type(s) note(s) requested_by(i) requested_by_name(s)
        mysqli_stmt_bind_param(
          $stmt, 'isddssssis',
          $inv_id, $item['item_name'], $qty_added, $new_cost, $supplier_name, $delivery_date, $payment_type, $note, $user_id, $full_name
        );
        mysqli_stmt_execute($stmt);
        $action_msg = 'success:Restock request for "' . htmlspecialchars($item['item_name']) . '" submitted. Waiting for Finance approval — stock won\'t update until then.';
      }
    }
  }

  // ── Bulk Restock (Manager only) — file several restock requests in one
  // submission, sharing a batch_id so Finance can approve them together.
  // Same "submit for approval, don't touch stock yet" model as the
  // single-item Restock above — every row lands as status='pending' and
  // only Finance applying it on finance_restock_approvals.php touches
  // `inventory`. All-or-nothing: every item is validated up front, and
  // nothing is written unless the whole batch checks out.
  if ($act === 'bulk_restock') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $payment_type  = in_array($_POST['payment_type'] ?? '', ['cash', 'credit'], true) ? $_POST['payment_type'] : null;
    $note          = trim($_POST['note'] ?? '');
    $items         = json_decode($_POST['items'] ?? '', true);

    if ($supplier_name === '') {
      $action_msg = 'error:Supplier name is required.';
    } elseif ($delivery_date === '' || !DateTime::createFromFormat('Y-m-d', $delivery_date)) {
      $action_msg = 'error:A valid delivery date is required.';
    } elseif (!$payment_type) {
      $action_msg = 'error:Please select how the supplier will be paid (Cash or Credit).';
    } elseif (!is_array($items) || empty($items)) {
      $action_msg = 'error:Select at least one item to restock.';
    } elseif (count($items) > 50) {
      $action_msg = 'error:Too many items in one batch (max 50) — submit some now and the rest separately.';
    } else {
      $clean    = [];
      $errors   = [];
      $seen_ids = [];
      foreach ($items as $row) {
        $inv_id    = (int)($row['inventory_id'] ?? 0);
        $qty_added = isset($row['qty_added']) && $row['qty_added'] !== '' ? (float)$row['qty_added'] : null;
        $new_cost  = isset($row['cost']) && $row['cost'] !== '' ? (float)$row['cost'] : null;

        if (!$inv_id) { $errors[] = 'One of the selected items is missing its item reference.'; continue; }
        if (isset($seen_ids[$inv_id])) { $errors[] = 'Duplicate entry for the same item in this batch.'; continue; }
        $seen_ids[$inv_id] = true;

        $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT item_name FROM inventory WHERE inventory_id = $inv_id"));
        if (!$item) { $errors[] = 'An item in this batch no longer exists — remove it and try again.'; continue; }
        if ($qty_added === null || $qty_added <= 0) { $errors[] = '"' . $item['item_name'] . '": enter a valid quantity to add.'; continue; }

        $clean[] = ['inv_id' => $inv_id, 'item_name' => $item['item_name'], 'qty_added' => $qty_added, 'new_cost' => $new_cost];
      }

      if ($errors) {
        $shown = array_slice($errors, 0, 3);
        $extra = count($errors) - count($shown);
        $action_msg = 'error:' . implode(' ', $shown) . ($extra > 0 ? " (+$extra more)" : '');
      } else {
        $batch_id = bin2hex(random_bytes(8));
        mysqli_begin_transaction($conn);
        $ok   = true;
        $stmt = mysqli_prepare(
          $conn,
          'INSERT INTO restock_requests
             (inventory_id, item_name, qty_added, new_cost, supplier_name, delivery_date, payment_type, note, status, requested_by, requested_by_name, batch_id)
           VALUES (?,?,?,?,?,?,?,?,\'pending\',?,?,?)'
        );
        foreach ($clean as $c) {
          mysqli_stmt_bind_param(
            $stmt, 'isddssssiss',
            $c['inv_id'], $c['item_name'], $c['qty_added'], $c['new_cost'], $supplier_name, $delivery_date, $payment_type, $note, $user_id, $full_name, $batch_id
          );
          if (!mysqli_stmt_execute($stmt)) { $ok = false; break; }
        }

        if ($ok) {
          mysqli_commit($conn);
          $n = count($clean);
          $action_msg = 'success:' . $n . ' restock request' . ($n > 1 ? 's' : '') . ' submitted as one batch. Waiting for Finance approval — stock won\'t update until then.';
        } else {
          mysqli_rollback($conn);
          $action_msg = 'error:Something went wrong submitting the batch. No requests were saved — try again.';
        }
      }
    }
  }

  // ── Edit Item ──
  if ($act === 'edit') {
    $inv_id        = (int)$_POST['inventory_id'];
    $item_name     = trim($_POST['item_name']    ?? '');
    $category      = trim($_POST['category']     ?? '');
    $unit          = trim($_POST['unit']         ?? '');
    $reorder_level = (float)($_POST['reorder']   ?? 0);
    $cost_per_unit = (isset($_POST['cost']) && $_POST['cost'] !== '') ? (float)$_POST['cost'] : null;
    if ($inv_id && $item_name && $unit) {
      // Quantity is intentionally NOT editable here — inventory staff owns
      // stock counts via the Adjust Stock modal (one item at a time), and
      // reports changes to the manager. Editing here only touches item info.
      $stmt = mysqli_prepare(
        $conn,
        'UPDATE inventory SET item_name=?, category=?, unit=?, reorder_level=?, cost_per_unit=? WHERE inventory_id=?'
      );
      mysqli_stmt_bind_param($stmt, 'sssddi', $item_name, $category, $unit, $reorder_level, $cost_per_unit, $inv_id);
      mysqli_stmt_execute($stmt);

      $action_msg = 'success:Item updated successfully.';
    }
  }

  // ── Remove Item (soft delete) ──
  if ($act === 'delete') {
    $inv_id = (int)$_POST['inventory_id'];
    if ($inv_id) {
      if ($has_is_active) {
        mysqli_query($conn, "UPDATE inventory SET is_active = 0 WHERE inventory_id=$inv_id");
        $action_msg = 'success:Item removed. Find it under Removed Items to restore it anytime.';
      } else {
        // Fallback for the rare case the is_active column couldn't be
        // created (e.g. no ALTER privilege) — keep the old behavior
        // rather than silently doing nothing.
        mysqli_query($conn, "DELETE FROM inventory WHERE inventory_id=$inv_id");
        $action_msg = 'success:Item removed.';
      }
    }
  }

  // ── Restore Item ──
  if ($act === 'restore') {
    $inv_id = (int)$_POST['inventory_id'];
    if ($inv_id && $has_is_active) {
      mysqli_query($conn, "UPDATE inventory SET is_active = 1 WHERE inventory_id=$inv_id");
      $action_msg = 'success:Item restored.';
    }
  }
}

// ── STAFF: log usage ─────────────────────────────────────────────────────────
// Removed — only Admin may log usage/waste/restock now (see admin-only
// 'add'/'edit'/'delete' handlers above). Staff inventory access is view-only.

// ── FETCH INVENTORY ──────────────────────────────────────────────────────────
$search   = trim($_GET['q']        ?? '');
$status_f = trim($_GET['status']   ?? '');
$cat_f    = trim($_GET['category'] ?? '');
$sort     = trim($_GET['sort']     ?? 'item_name');

$allowed_sorts = ['item_name', 'quantity', 'updated_at'];
if (!in_array($sort, $allowed_sorts)) $sort = 'item_name';

$where   = '1=1';
$params  = [];
$types   = '';

if ($has_is_active) $where .= ' AND is_active = 1';

if ($search !== '') {
  $like     = '%' . mysqli_real_escape_string($conn, $search) . '%';
  $where   .= " AND item_name LIKE '$like'";
}
if ($cat_f !== '') {
  $cat_esc = mysqli_real_escape_string($conn, $cat_f);
  $where  .= " AND category = '$cat_esc'";
}
if ($status_f === 'out')  $where .= ' AND quantity = 0';
if ($status_f === 'low')  $where .= ' AND quantity > 0 AND quantity <= reorder_level';
if ($status_f === 'ok')   $where .= ' AND quantity > reorder_level';

// Distinct categories for the filter dropdown
$cat_res = mysqli_query($conn, "SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL AND category <> ''" . ($has_is_active ? ' AND is_active = 1' : '') . " ORDER BY category ASC");
$all_categories = [];
if ($cat_res) {
  while ($r = mysqli_fetch_assoc($cat_res)) $all_categories[] = $r['category'];
}

// Categories from the categories table for the form dropdowns
$form_categories = [];
$_cc_cols = mysqli_query($conn, "SHOW COLUMNS FROM categories");
$_cc_col_list = [];
if ($_cc_cols) while ($_cc = mysqli_fetch_assoc($_cc_cols)) $_cc_col_list[] = $_cc['Field'];
$_cc_name_col = '';
foreach (['name', 'category_name', 'cat_name', 'category'] as $_c) {
  if (in_array($_c, $_cc_col_list)) {
    $_cc_name_col = $_c;
    break;
  }
}
if ($_cc_name_col) {
  $fc_res = mysqli_query($conn, "SELECT `$_cc_name_col` FROM categories WHERE `$_cc_name_col` IS NOT NULL AND `$_cc_name_col` <> '' AND `$_cc_name_col` <> 'Uncategorized' ORDER BY `$_cc_name_col` ASC");
  if ($fc_res) while ($r = mysqli_fetch_assoc($fc_res)) $form_categories[] = $r[$_cc_name_col];
}
// Fallback: use inventory categories if categories table is empty or missing
if (empty($form_categories)) $form_categories = array_values(array_filter($all_categories, fn($c) => $c !== 'Uncategorized'));

$page    = max(1, (int)($_GET['page'] ?? 1));
$per_pg  = 15;
$offset  = ($page - 1) * $per_pg;

$total_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM inventory WHERE $where");
$total_rows = 0;
if ($total_res) {
  $total_rows = (int)(mysqli_fetch_assoc($total_res)['c'] ?? 0);
} else {
  // Query failed — likely the inventory table doesn't exist yet
  error_log('Inventory count query failed: ' . mysqli_error($conn));
}
$total_pages = max(1, (int)ceil($total_rows / $per_pg));

$items_res = mysqli_query(
  $conn,
  "SELECT * FROM inventory WHERE $where ORDER BY $sort ASC LIMIT $per_pg OFFSET $offset"
);
$items = [];
if ($items_res) {
  while ($row = mysqli_fetch_assoc($items_res)) $items[] = $row;
} else {
  error_log('Inventory items query failed: ' . mysqli_error($conn));
}

// ── STATS ────────────────────────────────────────────────────────────────────
$stat = mysqli_query($conn, "
    SELECT
      COUNT(*)                                                       AS total,
      SUM(quantity > reorder_level)                                  AS in_stock,
      SUM(quantity > 0 AND quantity <= reorder_level)                AS low_stock,
      SUM(quantity = 0)                                              AS out_of_stock
    FROM inventory
    WHERE 1=1" . ($has_is_active ? ' AND is_active = 1' : '') . "
");
$stats = $stat ? mysqli_fetch_assoc($stat) : ['total' => 0, 'in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0];

// ── ALERTS ───────────────────────────────────────────────────────────────────
$out_res = mysqli_query($conn, "SELECT item_name FROM inventory WHERE quantity = 0" . ($has_is_active ? ' AND is_active = 1' : '') . " LIMIT 3");
$out_items = [];
if ($out_res) while ($r = mysqli_fetch_assoc($out_res)) $out_items[] = $r['item_name'];

$low_res = mysqli_query(
  $conn,
  "SELECT item_name FROM inventory WHERE quantity > 0 AND quantity <= reorder_level" . ($has_is_active ? ' AND is_active = 1' : '') . " LIMIT 3"
);
$low_items = [];
if ($low_res) while ($r = mysqli_fetch_assoc($low_res)) $low_items[] = $r['item_name'];

// ── REMOVED ITEMS (soft-deleted) ────────────────────────────────────────────
// Shown in their own panel at the bottom, admin-only, so removed items can
// be found and restored instead of being gone for good.
$removed_items = [];
if ($is_admin && $has_is_active) {
  $rem_res = mysqli_query($conn, "SELECT * FROM inventory WHERE is_active = 0 ORDER BY item_name ASC");
  if ($rem_res) while ($r = mysqli_fetch_assoc($rem_res)) $removed_items[] = $r;
}

// ── RESTOCK REQUESTS (Manager only) — recent, so Manager can see what's
//    still pending Finance approval, and what got approved/rejected. ──────
$restock_requests = [];
if ($is_admin && $conn) {
  $rr_res = mysqli_query($conn, "SELECT * FROM restock_requests ORDER BY requested_at DESC LIMIT 15");
  if ($rr_res) while ($r = mysqli_fetch_assoc($rr_res)) $restock_requests[] = $r;
}

// ── RECENT STAFF ACTIVITY (Manager only) — staff stock adjustments,
//    grouped by batch_id so a single "Apply All Changes" submission shows
//    as one entry with its items nested underneath, instead of the
//    Manager having to piece together N separate inventory_log rows. ──────
$recent_batches = [];
if ($is_admin && $conn) {
  $bcol = mysqli_query($conn, "SHOW COLUMNS FROM inventory_log LIKE 'batch_id'");
  if ($bcol && mysqli_num_rows($bcol) > 0) {
    $act_res = mysqli_query($conn, "
      SELECT il.batch_id, il.inventory_id, il.qty_change, il.notes, il.created_at,
             i.item_name, i.unit,
             COALESCE(u.full_name, 'Staff') AS staff_name
      FROM inventory_log il
      LEFT JOIN inventory i ON i.inventory_id = il.inventory_id
      LEFT JOIN users u ON u.user_id = il.employee_id
      WHERE il.batch_id IS NOT NULL AND il.batch_id <> ''
      ORDER BY il.created_at DESC
      LIMIT 150
    ");
    if ($act_res) {
      while ($r = mysqli_fetch_assoc($act_res)) {
        $bid = $r['batch_id'];
        if (!isset($recent_batches[$bid])) {
          $recent_batches[$bid] = [
            'staff_name' => $r['staff_name'],
            'created_at' => $r['created_at'],
            'items'      => [],
          ];
        }
        $recent_batches[$bid]['items'][] = $r;
      }
    }
    // Rows came back newest-first, so PHP's insertion-order-preserving
    // array keeps batches newest-first too — just cap how many we show.
    $recent_batches = array_slice($recent_batches, 0, 12, true);
  }
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function stock_status(float $qty, float $reorder): array
{
  if ($qty == 0)          return ['s-out', '● Out of Stock', '', 0];
  if ($qty <= $reorder)   return ['s-low', '● Low Stock',    '', 0];
  return                         ['s-ok',  '● In Stock',     '', 0];
}

// Category glyph shown in the Item column's icon chip.
function category_icon_paths(?string $cat): string
{
  switch ($cat) {
    case 'Baking':
      return '<path d="M4 3h16l-1.5 15.5a2 2 0 01-2 1.5H7.5a2 2 0 01-2-1.5L4 3z"/><path d="M4 3l8 5 8-5"/>';
    case 'Tea':
      return '<path d="M11 20A7 7 0 019.8 6.1C15.5 5 17 4 20 4c0 5-1 6.4-2 8-1.6 2.6-4 3.5-7 8z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/>';
    case 'Syrups':
      return '<path d="M12 2s7 8.5 7 13a7 7 0 11-14 0c0-4.5 7-13 7-13z"/>';
    case 'Coffee':
      return '<path d="M9 4c-3 2-5 6-5 9a8 8 0 0016 0c0-3-2-7-5-9"/><path d="M9 4c1.5 3 1.5 8 0 12"/>';
    default:
      return '<circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/>';
  }
}

// Maps a stock_status() class to the small status dot color shown next to
// the stock figure (sage = healthy, amber = low, rust = out of stock).
function stock_dot_class(string $s_class): string
{
  if ($s_class === 's-low') return 'amber';
  if ($s_class === 's-out') return 'rust';
  return 'sage';
}

$active_page = 'inventory';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inventory Management — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Blurs whatever's behind the Add/Edit/Remove/Restore loading & confirmation pop-ups */
    .blurred-backdrop {
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
  </style>
</head>

<body>
  <script src="../js/sidebar-restore.js"></script>

  <?php
  // ── SIDEBAR ──────────────────────────────────────────────────────────────────
  // Admin gets full sidebar; staff gets a minimal one
  if ($is_admin && file_exists('../manager/Sidebar_Manager.php')):
    require_once '../manager/Sidebar_Manager.php';
  else: ?>
    <?php
    // Inventory-only view (no Sales/POS link) applies to the legacy
    // 'inventory_staff' role AND to a plain 'employee' whose HR Position
    // is "Inventory Staff" — same restriction, two ways to arrive at it.
    $_is_inventory_only = ($_role_lc === 'inventory_staff')
      || (($_SESSION['position'] ?? '') === 'Inventory Staff');
    ?>
    <aside class="sidebar">
      <div class="sidebar-logo">
        <span class="sidebar-logo-text">Cloud<span>Cup</span></span>
        <span class="sidebar-logo-cup" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g class="cc-steam">
            <path d="M9 1.5c0 1-1 1-1 2s1 1 1 2" stroke="rgba(255,255,255,.55)" stroke-width="1.3" stroke-linecap="round"/>
            <path d="M13 1.5c0 1-1 1-1 2s1 1 1 2" stroke="rgba(255,255,255,.55)" stroke-width="1.3" stroke-linecap="round"/>
          </g>
          <rect class="cc-cup-fill" x="5.2" y="9.2" width="10.6" height="8.6" rx="1.2"/>
          <path d="M4 8h13v7a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8z" stroke="var(--gold)" stroke-width="1.6" fill="none"/>
          <path d="M17 10.2h1.4a2.3 2.3 0 0 1 0 4.6H17" stroke="var(--gold)" stroke-width="1.6" fill="none"/>
          <line x1="6" y1="19" x2="12" y2="19" stroke="var(--gold)" stroke-width="1.4" stroke-linecap="round" opacity=".5"/>
        </svg>
      </span>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">My Station</div>
        <?php if (!$_is_inventory_only): ?>
        <a href="../staff/Sales_Processing_Page.php" class="nav-item" data-label="Sales / POS"><span class="icon"><i data-lucide="shopping-cart"></i></span><span class="nav-label"> Sales / POS</span></a>
        <?php endif; ?>
        <a href="Inventory_Management_Page.php" class="nav-item active" data-label="Inventory"><span class="icon"><i data-lucide="package"></i></span><span class="nav-label"> Inventory</span></a>
      </div>
      <div class="sidebar-footer">
        <div class="user-card">
          <div class="user-avatar"><?= htmlspecialchars(strtoupper(substr($full_name, 0, 1))) ?></div>
          <div class="user-info">
            <strong><?= htmlspecialchars($full_name) ?></strong>
            <span><?= $_is_inventory_only ? 'Inventory Staff' : 'Staff' ?></span>
          </div>
          <a href="../auth/Logout_Page.php" class="logout-btn" title="Logout" style="text-decoration:none"><i data-lucide="log-out"></i></a>
        </div>
      </div>
    </aside>
  <?php endif; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="6" x2="20" y2="6" />
            <line x1="4" y1="18" x2="20" y2="18" />
          </svg></button>
        <div class="topbar-title">Inventory Management</div>
      </div>
      <div class="topbar-right">
        <?php if ($is_admin): ?>

          <button class="btn-primary" onclick="openModal('add')"><i data-lucide="plus"></i> Add Item</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="content">

      <?php /* action_msg now shown via SweetAlert2 — see script at bottom */ ?>

      <?php if (!$is_admin): ?>
        <div class="staff-banner">
          <i data-lucide="info"></i> <strong>Staff view:</strong> You can adjust stock quantities (with a reason) and report low stock. Item details, cost, and reorder levels are managed by your Manager.
        </div>
      <?php endif; ?>

      <!-- ALERTS -->
      <?php if ($out_items || $low_items): ?>
        <div class="alert-strip">
          <?php if ($out_items): ?>
            <div class="alert-card danger">
              <div class="alert-icon"><i data-lucide="circle-alert"></i></div>
              <div class="alert-body">
                <strong><?= count($out_items) ?> Item<?= count($out_items) > 1 ? 's' : '' ?> Out of Stock</strong>
                <span><?= htmlspecialchars(implode(', ', $out_items)) ?></span>
              </div>
            </div>
          <?php endif;
          if ($low_items): ?>
            <div class="alert-card warning">
              <div class="alert-icon"><i data-lucide="triangle-alert"></i></div>
              <div class="alert-body">
                <strong><?= (int)$stats['low_stock'] ?> Low Stock</strong>
                <span><?= htmlspecialchars(implode(', ', $low_items)) ?></span>
              </div>
            </div>
          <?php endif; ?>
          <div class="alert-card info">
            <div class="alert-icon"><i data-lucide="clipboard-list"></i></div>
            <div class="alert-body">
              <strong><?= (int)($stats['low_stock'] + $stats['out_of_stock']) ?> Items Need Attention</strong>
              <span>Review and reorder as needed</span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- STATS -->
      <div class="stat-ledger">
        <div class="stat-seg">
          <div class="stat-seg-label"><span class="stat-dot" style="background:var(--mc-gold)"></span>Total Items</div>
          <div class="stat-seg-num"><?= (int)$stats['total'] ?></div>
        </div>
        <div class="stat-seg">
          <div class="stat-seg-label"><span class="stat-dot" style="background:var(--mc-sage)"></span>In Stock</div>
          <div class="stat-seg-num"><?= (int)$stats['in_stock'] ?></div>
        </div>
        <div class="stat-seg">
          <div class="stat-seg-label"><span class="stat-dot" style="background:var(--mc-amber)"></span>Low Stock</div>
          <div class="stat-seg-num"><?= (int)$stats['low_stock'] ?></div>
        </div>
        <div class="stat-seg stat-seg-last">
          <div class="stat-seg-label"><span class="stat-dot" style="background:var(--mc-rust)"></span>Out of Stock</div>
          <div class="stat-seg-num"><?= (int)$stats['out_of_stock'] ?></div>
        </div>
      </div>

      <!-- TOOLBAR + TABLE -->
      <div class="panel">
        <div class="panel-toolbar">
          <form method="GET" action="" id="filter-form">
            <div class="toolbar">
              <div class="search-wrap">
                <span class="icon"><i data-lucide="search"></i></span>
                <input type="text" name="q" id="search-input" placeholder="Search inventory items…"
                  value="<?= htmlspecialchars($search) ?>" autocomplete="off" />
              </div>
              <div class="toolbar-filters">
              <div class="dd" data-dd="status">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_f) ?>">
                <div class="dd-trigger">
                  <span class="dd-current"><?= $status_f === 'ok' ? 'In Stock' : ($status_f === 'low' ? 'Low Stock' : ($status_f === 'out' ? 'Out of Stock' : 'All Status')) ?></span>
                  <span class="chev"></span>
                </div>
                <div class="dd-menu">
                  <div class="dd-option<?= $status_f === '' ? ' selected' : '' ?>" data-value="" data-label="All Status"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>All Status</div>
                  <div class="dd-option<?= $status_f === 'ok' ? ' selected' : '' ?>" data-value="ok" data-label="In Stock"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg><span class="swatch" style="background:var(--mc-sage)"></span>In Stock</div>
                  <div class="dd-option<?= $status_f === 'low' ? ' selected' : '' ?>" data-value="low" data-label="Low Stock"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg><span class="swatch" style="background:var(--mc-amber)"></span>Low Stock</div>
                  <div class="dd-option<?= $status_f === 'out' ? ' selected' : '' ?>" data-value="out" data-label="Out of Stock"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg><span class="swatch" style="background:var(--mc-rust)"></span>Out of Stock</div>
                </div>
              </div>

              <div class="dd" data-dd="category">
                <input type="hidden" name="category" value="<?= htmlspecialchars($cat_f) ?>">
                <div class="dd-trigger">
                  <span class="dd-current"><?= $cat_f !== '' ? htmlspecialchars($cat_f) : 'All Categories' ?></span>
                  <span class="chev"></span>
                </div>
                <div class="dd-menu">
                  <div class="dd-option<?= $cat_f === '' ? ' selected' : '' ?>" data-value="" data-label="All Categories"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>All Categories</div>
                  <?php foreach ($all_categories as $c): ?>
                    <div class="dd-option<?= $cat_f === $c ? ' selected' : '' ?>" data-value="<?= htmlspecialchars($c) ?>" data-label="<?= htmlspecialchars($c) ?>"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg><?= htmlspecialchars($c) ?></div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="dd" data-dd="sort">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <div class="dd-trigger">
                  <span class="dd-label-prefix">Sort by:&nbsp;</span>
                  <span class="dd-current"><?= $sort === 'quantity' ? 'Stock (Low first)' : ($sort === 'updated_at' ? 'Last Updated' : 'Name') ?></span>
                  <span class="chev"></span>
                </div>
                <div class="dd-menu">
                  <div class="dd-option<?= $sort === 'item_name' ? ' selected' : '' ?>" data-value="item_name" data-label="Name"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Name</div>
                  <div class="dd-option<?= $sort === 'quantity' ? ' selected' : '' ?>" data-value="quantity" data-label="Stock (Low first)"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Stock (Low first)</div>
                  <div class="dd-option<?= $sort === 'updated_at' ? ' selected' : '' ?>" data-value="updated_at" data-label="Last Updated"><svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Last Updated</div>
                </div>
              </div>

              <div class="ml-auto"></div>
              <button type="submit" class="btn-search"><i data-lucide="search"></i> Search</button>
              </div>
            </div>
          </form>

        </div>

        <!-- TABLE -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <?php if ($is_admin): ?><th style="width:32px"><input type="checkbox" id="bulkSelectAll" title="Select all for bulk restock"></th><?php endif; ?>
                <th>Item</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Stock</th>
                <th>Unit Cost</th>
                <th>Status</th>
                <th>Last Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($items)): ?>
                <tr>
                  <td colspan="9" style="text-align:center;padding:40px;color:var(--text-light);">No inventory items found.</td>
                </tr>
                <?php else: foreach ($items as $item):
                  [$s_class, $s_label, $f_class, $f_pct] = stock_status((float)$item['quantity'], (float)$item['reorder_level']);
                  $row_class = $s_class === 's-low' ? 'row-low' : ($s_class === 's-out' ? 'row-out' : '');
                  $qty_color = $s_class === 's-low' ? 'color:var(--warning)' : ($s_class === 's-out' ? 'color:var(--danger)' : '');
                ?>
                  <tr class="<?= $row_class ?>">
                    <?php if ($is_admin): ?>
                    <td>
                      <input type="checkbox" class="bulk-restock-check"
                        data-id="<?= $item['inventory_id'] ?>"
                        data-name="<?= htmlspecialchars($item['item_name']) ?>"
                        data-unit="<?= htmlspecialchars($item['unit']) ?>"
                        data-cost="<?= $item['cost_per_unit'] !== null ? $item['cost_per_unit'] : '' ?>"
                        onchange="onBulkCheckChange()">
                    </td>
                    <?php endif; ?>
                    <td>
                      <div class="item-cell">
                        <div class="item-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= category_icon_paths($item['category'] ?? null) ?></svg></div>
                        <div>
                          <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                          <div class="item-sku">ID-<?= str_pad($item['inventory_id'], 3, '0', STR_PAD_LEFT) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><span class="category-badge"><?= htmlspecialchars($item['category'] ?? '—') ?></span></td>
                    <td><?= htmlspecialchars($item['unit']) ?></td>
                    <td>
                      <div class="stock-wrap">
                        <span class="stock-dot <?= stock_dot_class($s_class) ?>"></span>
                        <span class="stock-num" style="<?= $qty_color ?>"><?= $item['quantity'] + 0 ?> <?= htmlspecialchars($item['unit']) ?></span>
                      </div>
                    </td>
                    <td><?= $item['cost_per_unit'] !== null ? '₱' . number_format($item['cost_per_unit'], 2) . '/' . htmlspecialchars($item['unit']) : '—' ?></td>
                    <td><span class="stock-status <?= $s_class ?>"><?= $s_label ?></span></td>
                    <td><?= date('M d, Y', strtotime($item['updated_at'])) ?></td>
                    <td>
                      <div class="action-group">
                        <?php if ($is_admin): ?>
                          <div class="action-btn" title="Restock"
                            onclick="openRestockModal(<?= htmlspecialchars(json_encode($item)) ?>)"><i data-lucide="truck"></i></div>
                          <div class="action-btn" title="Edit"
                            onclick="openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)"><i data-lucide="pencil"></i></div>
                          <div class="action-btn danger" title="Remove"
                            onclick="confirmRemoveItem(<?= $item['inventory_id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>')"><i data-lucide="trash-2"></i></div>
                        <?php else: ?>
                          <button type="button" class="action-btn" title="Adjust Stock"
                            onclick="openStaffAdjustModal(<?= (int)$item['inventory_id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', <?= (float)$item['quantity'] ?>, '<?= htmlspecialchars(addslashes($item['unit'])) ?>')">
                            <i data-lucide="pencil"></i>
                          </button>
                          <?php if ($s_class === 's-low' || $s_class === 's-out'): ?>
                          <button type="button" class="report-low-btn"
                            onclick="reportLowStock(<?= $item['inventory_id'] ?>, this)">
                            <i data-lucide="bell-ring"></i> Report Low Stock
                          </button>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>

          <!-- PAGINATION -->
          <?php $start = ($page - 1) * $per_pg + 1;
          $end = min($page * $per_pg, $total_rows); ?>
          <div class="pagination">
            <div class="pagination-info">Showing <?= $start ?>–<?= $end ?> of <?= $total_rows ?> items</div>
            <div class="page-btns">
              <?php if ($page > 1): ?>
                <a class="page-btn" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&category=<?= urlencode($cat_f) ?>&sort=<?= urlencode($sort) ?>">‹</a>
              <?php endif; ?>
              <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a class="page-btn <?= $i === $page ? 'active' : '' ?>"
                  href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&category=<?= urlencode($cat_f) ?>&sort=<?= urlencode($sort) ?>"><?= $i ?></a>
              <?php endfor; ?>
              <?php if ($page < $total_pages): ?>
                <a class="page-btn" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status_f) ?>&category=<?= urlencode($cat_f) ?>&sort=<?= urlencode($sort) ?>">›</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div><!-- /.panel -->

      <!-- BULK RESTOCK BAR — appears once 1+ items are checked in the table
           above; mirrors the staff pending-tray pattern but for Manager's
           restock selection. Nothing is submitted until the modal's
           "Submit Batch for Approval" button is pressed. -->
      <?php if ($is_admin): ?>
      <div class="pending-tray" id="bulkRestockBar" style="display:none">
        <div class="pending-tray-header" style="cursor:default">
          <i data-lucide="truck"></i>
          <span id="bulkRestockBarCount">0 items selected</span>
        </div>
        <div class="pending-tray-footer">
          <button type="button" class="btn-cancel" onclick="clearBulkSelection()">Clear Selection</button>
          <button type="button" class="btn-save" onclick="openBulkRestockModal()">Bulk Restock (<span id="bulkRestockBarBtnCount">0</span>)</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── RESTOCK REQUESTS — Manager's own submissions, awaiting or
           already reviewed by Finance. Read-only here; approve/reject
           happens on the Finance side. ── -->
      <?php if ($is_admin): ?>
      <div class="panel" style="margin-top:20px">
        <div class="panel-toolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:15px;color:var(--text-dark,#241f19)">
            <i data-lucide="truck" style="width:16px;height:16px;color:var(--text-light)"></i>
            Restock Requests
          </div>
          <span style="font-size:12px;color:var(--text-light)">Waits for Finance approval before stock updates</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th><th>Qty</th><th>Supplier</th><th>Delivery</th><th>Payment</th><th>Status</th><th>Requested</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($restock_requests)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-light)">No restock requests yet.</td></tr>
              <?php else: foreach ($restock_requests as $rr):
                $pill_bg = ['pending' => '#f4e3d3', 'approved' => '#E6F4EA', 'rejected' => '#FDE8E8'][$rr['status']];
                $pill_fg = ['pending' => '#b8703f', 'approved' => '#2f6f4e', 'rejected' => '#C0392B'][$rr['status']];
              ?>
                <tr>
                  <td><?= htmlspecialchars($rr['item_name']) ?></td>
                  <td>+<?= $rr['qty_added'] + 0 ?></td>
                  <td><?= htmlspecialchars($rr['supplier_name']) ?></td>
                  <td><?= date('M d, Y', strtotime($rr['delivery_date'])) ?></td>
                  <td><?= $rr['payment_type'] === 'cash' ? 'Cash' : 'Credit' ?></td>
                  <td><span style="background:<?= $pill_bg ?>;color:<?= $pill_fg ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px"><?= ucfirst($rr['status']) ?></span></td>
                  <td><?= date('M d, Y', strtotime($rr['requested_at'])) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── RECENT STAFF ACTIVITY — staff stock adjustments, grouped by
           the batch they were submitted in, so a manager can see who
           changed what and why without digging through raw logs. ── -->
      <?php if ($is_admin): ?>
      <div class="panel" style="margin-top:20px">
        <div class="panel-toolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:15px;color:var(--text-dark,#241f19)">
            <i data-lucide="activity" style="width:16px;height:16px;color:var(--text-light)"></i>
            Recent Staff Activity
          </div>
          <span style="font-size:12px;color:var(--text-light)">Stock adjustments submitted by Inventory Staff</span>
        </div>
        <div class="activity-list">
          <?php if (empty($recent_batches)): ?>
            <div style="text-align:center;padding:32px;color:var(--text-light);font-size:13px">No staff activity yet.</div>
          <?php else: foreach ($recent_batches as $batch):
            $n = count($batch['items']);
          ?>
            <div class="activity-batch">
              <div class="activity-batch-head">
                <div class="activity-batch-who">
                  <i data-lucide="user-round"></i>
                  <strong><?= htmlspecialchars($batch['staff_name']) ?></strong>&nbsp;submitted a stock adjustment — <?= $n ?> item<?= $n > 1 ? 's' : '' ?>
                </div>
                <span class="activity-batch-time"><?= date('M d, g:i A', strtotime($batch['created_at'])) ?></span>
              </div>
              <div class="activity-batch-items">
                <?php foreach ($batch['items'] as $it):
                  $qc    = (float)$it['qty_change'];
                  $label = preg_replace('/\s*\(reported by .*?\)\s*$/', '', $it['notes']);
                ?>
                  <div class="activity-item-row">
                    <i data-lucide="package"></i>
                    <span class="activity-item-name"><?= htmlspecialchars($it['item_name'] ?? ('Item #' . $it['inventory_id'])) ?></span>
                    <span class="activity-item-change" style="color:<?= $qc > 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $qc > 0 ? '+' : '' ?><?= $qc + 0 ?> <?= htmlspecialchars($it['unit'] ?? '') ?></span>
                    <span class="activity-item-reason"><?= htmlspecialchars($label) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── REMOVED ITEMS (soft-deleted) — kept in their own panel so removed
           items are never mixed into active stock counts, and can be found
           and restored quickly instead of being gone for good. ── -->
      <?php if ($is_admin && $has_is_active): ?>
      <div class="panel" style="margin-top:20px">
        <div class="panel-toolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:15px;color:var(--text-dark,#241f19)">
            <i data-lucide="trash-2" style="width:16px;height:16px;color:var(--text-light)"></i>
            Removed Items
            <span style="background:#faf8f4;color:var(--text-light);font-size:12px;font-weight:600;padding:2px 9px;border-radius:20px"><?= count($removed_items) ?></span>
          </div>
          <span style="font-size:12px;color:var(--text-light)">Excluded from stock counts — restore anytime</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Stock</th>
                <th>Unit Cost</th>
                <th>Last Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($removed_items)): ?>
                <tr>
                  <td colspan="7" style="text-align:center;padding:32px;color:var(--text-light)">Nothing removed right now.</td>
                </tr>
              <?php else: foreach ($removed_items as $item): ?>
                <tr style="opacity:.65">
                  <td>
                    <div class="item-cell">
                      <div class="item-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= category_icon_paths($item['category'] ?? null) ?></svg></div>
                      <div>
                        <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                        <div class="item-sku">ID-<?= str_pad($item['inventory_id'], 3, '0', STR_PAD_LEFT) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="category-badge"><?= htmlspecialchars($item['category'] ?? '—') ?></span></td>
                  <td><?= htmlspecialchars($item['unit']) ?></td>
                  <td><span class="stock-num"><?= $item['quantity'] + 0 ?> <?= htmlspecialchars($item['unit']) ?></span></td>
                  <td><?= $item['cost_per_unit'] !== null ? '₱' . number_format($item['cost_per_unit'], 2) . '/' . htmlspecialchars($item['unit']) : '—' ?></td>
                  <td><?= date('M d, Y', strtotime($item['updated_at'])) ?></td>
                  <td>
                    <div class="action-group">
                      <div class="action-btn" title="Restore"
                        onclick="confirmRestoreItem(<?= $item['inventory_id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>')"><i data-lucide="rotate-ccw"></i></div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ── MODALS ─────────────────────────────────────────────────────────────── -->

  <?php if ($is_admin): ?>
    <!-- ADD MODAL -->
    <div class="modal-overlay" id="modal-add">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">Add Inventory Item</div>
          <button class="modal-close" onclick="closeModal('modal-add')">✕</button>
        </div>
        <form method="POST" action="" id="form-add">
          <input type="hidden" name="act" value="add" />
          <div class="form-group">
            <label>Item Name *</label>
            <input type="text" name="item_name" required placeholder="e.g. Espresso Beans" />
          </div>
          <div class="form-group">
            <label>Category</label>
            <select name="category">
              <option value="">— Select category —</option>
              <?php foreach ($form_categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Unit *</label>
              <input type="text" name="unit" required placeholder="kg, L, pcs…" />
            </div>
            <div class="form-group">
              <label>Initial Quantity</label>
              <input type="number" name="quantity" step="1" min="0" max="9999" maxlength="4" placeholder="0" oninput="this.value=this.value.slice(0,4)" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Reorder Level (Min. Stock)</label>
              <input type="number" name="reorder" step="1" min="0" max="9999" maxlength="4" placeholder="0" oninput="this.value=this.value.slice(0,4)" />
            </div>
            <div class="form-group">
              <label>Cost per Unit (₱)</label>
              <input type="number" name="cost" step="1" min="0" max="9999" maxlength="4" placeholder="Optional" oninput="this.value=this.value.slice(0,4)" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-add')">Cancel</button>
            <button type="submit" class="btn-save">Add Item</button>
          </div>
        </form>
      </div>
    </div>

    <!-- RESTOCK MODAL (Manager only) — adds to existing quantity, can also
         update cost_per_unit for this delivery, and records supplier +
         delivery date for the audit trail / COGS-adjacent reporting. -->
    <div class="modal-overlay" id="modal-restock">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">Restock — <span id="restock-item-name"></span></div>
          <button class="modal-close" onclick="closeModal('modal-restock')">✕</button>
        </div>
        <form method="POST" action="" id="form-restock">
          <input type="hidden" name="act" value="restock" />
          <input type="hidden" name="inventory_id" id="restock-id" />
          <div class="form-group">
            <label>Current Quantity</label>
            <input type="text" id="restock-current" disabled />
          </div>
          <div class="form-group">
            <label>Quantity to Add *</label>
            <input type="number" name="qty_added" id="restock-qty" step="1" min="1" max="9999" maxlength="4" required oninput="this.value=this.value.slice(0,4)" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Supplier *</label>
              <input type="text" name="supplier_name" id="restock-supplier" required maxlength="150" placeholder="e.g. ABC Coffee Traders" />
            </div>
            <div class="form-group">
              <label>Delivery Date *</label>
              <input type="date" name="delivery_date" id="restock-date" required />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Cost per Unit (₱)</label>
              <input type="number" name="cost" id="restock-cost" step="1" min="0" max="9999" maxlength="4" placeholder="Leave blank to keep current price" oninput="this.value=this.value.slice(0,4)" />
            </div>
            <div class="form-group">
              <label>Payment Type *</label>
              <select name="payment_type" id="restock-payment" required>
                <option value="">— Select —</option>
                <option value="cash">Cash</option>
                <option value="credit">Credit (Utang / Accounts Payable)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Note (optional)</label>
            <input type="text" name="note" id="restock-note" placeholder="Any extra detail" maxlength="200" />
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-restock')">Cancel</button>
            <button type="submit" class="btn-save">Submit for Approval</button>
          </div>
        </form>
      </div>
    </div>

    <!-- BULK RESTOCK MODAL (Manager only) — files a restock_requests row for
         each checked item in one go, all sharing a batch_id, so Finance can
         approve the whole delivery at once instead of one item at a time.
         Supplier/date/payment are shared across the batch (one delivery,
         one supplier); quantity and cost stay per-item since those differ
         per product. -->
    <div class="modal-overlay" id="modal-bulk-restock">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title">Bulk Restock — <span id="bulk-restock-count"></span> items</div>
          <button class="modal-close" onclick="closeModal('modal-bulk-restock')">✕</button>
        </div>
        <form id="form-bulk-restock" onsubmit="return false;">
          <div class="form-row">
            <div class="form-group">
              <label>Supplier *</label>
              <input type="text" id="bulk-restock-supplier" required maxlength="150" placeholder="e.g. ABC Coffee Traders" />
            </div>
            <div class="form-group">
              <label>Delivery Date *</label>
              <input type="date" id="bulk-restock-date" required />
            </div>
          </div>
          <div class="form-group">
            <label>Payment Type *</label>
            <select id="bulk-restock-payment" required>
              <option value="">— Select —</option>
              <option value="cash">Cash</option>
              <option value="credit">Credit (Utang / Accounts Payable)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Note (optional, applies to whole batch)</label>
            <input type="text" id="bulk-restock-note" placeholder="Any extra detail" maxlength="200" />
          </div>

          <div class="form-group">
            <label>Items in this batch</label>
            <div id="bulk-restock-items" style="max-height:260px;overflow-y:auto;border:1px solid var(--border,#e9e3d8);border-radius:10px;padding:8px"></div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-bulk-restock')">Cancel</button>
            <button type="button" class="btn-save" onclick="submitBulkRestock()">Submit Batch for Approval</button>
          </div>
        </form>
      </div>
    </div>
    <div class="modal-overlay" id="modal-edit">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">Edit Item</div>
          <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
        </div>
        <form method="POST" action="" id="form-edit">
          <input type="hidden" name="act" value="edit" />
          <input type="hidden" name="inventory_id" id="edit-id" />
          <div class="form-group">
            <label>Item Name *</label>
            <input type="text" name="item_name" id="edit-name" required />
          </div>
          <div class="form-group">
            <label>Category</label>
            <select name="category" id="edit-category">
              <option value="">— Select category —</option>
              <?php foreach ($form_categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Unit *</label>
            <input type="text" name="unit" id="edit-unit" required />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Reorder Level</label>
              <input type="number" name="reorder" id="edit-reorder" step="1" min="0" max="9999" maxlength="4" oninput="this.value=this.value.slice(0,4)" />
            </div>
            <div class="form-group">
              <label>Cost per Unit (₱)</label>
              <input type="number" name="cost" id="edit-cost" step="1" min="0" max="9999" maxlength="4" oninput="this.value=this.value.slice(0,4)" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-edit')">Cancel</button>
            <button type="submit" class="btn-save">Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <!-- REMOVE FORM (hidden — soft delete, sets is_active=0) -->
    <form method="POST" id="form-delete" action="">
      <input type="hidden" name="act" value="delete" />
      <input type="hidden" name="inventory_id" id="delete-id" />
    </form>

    <!-- RESTORE FORM (hidden — sets is_active=1) -->
    <form method="POST" id="form-restore" action="">
      <input type="hidden" name="act" value="restore" />
      <input type="hidden" name="inventory_id" id="restore-id" />
    </form>

  <?php else: ?>

    <!-- STAFF ADJUST-STOCK MODAL — quantity + reason only. No name, category,
         unit, reorder level, or cost: those stay Manager-only (cost feeds the
         Balance Sheet's inventory asset value, so it needs to stay controlled). -->
    <div class="modal-overlay" id="modal-staff-adjust">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">Adjust Stock — <span id="staff-adjust-item-name"></span></div>
          <button class="modal-close" onclick="closeModal('modal-staff-adjust')">✕</button>
        </div>
        <form id="form-staff-adjust">
          <div class="form-group">
            <label>Current Quantity</label>
            <input type="text" id="staff-adjust-current" disabled />
          </div>
          <div class="form-group">
            <label>New Quantity *</label>
            <input type="number" name="new_quantity" id="staff-adjust-qty" step="1" min="0" max="9999" maxlength="4" required oninput="this.value=this.value.slice(0,4)" />
          </div>
          <div class="form-group">
            <label>Reason *</label>
            <select name="reason" id="staff-adjust-reason" required>
              <option value="">— Select reason —</option>
              <option value="delivery">Received Delivery</option>
              <option value="recount">Recount Correction</option>
              <option value="damaged">Damaged/Spoiled</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Note (optional)</label>
            <input type="text" name="note" id="staff-adjust-note" placeholder="Any extra detail for your Manager…" maxlength="200" />
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('modal-staff-adjust')">Cancel</button>
            <button type="submit" class="btn-save" id="staff-adjust-save-btn">Add to Pending Changes</button>
          </div>
        </form>
      </div>
    </div>

    <!-- PENDING CHANGES TRAY — items staff has staged locally (quantity +
         reason each) but not yet submitted. Nothing here touches the
         database until "Apply All Changes" is pressed; edit or remove
         entries freely until then. -->
    <div class="pending-tray" id="pendingTray" style="display:none">
      <div class="pending-tray-header" onclick="toggleTray()">
        <i data-lucide="clipboard-list"></i>
        <span id="pendingTrayCount">0 items pending</span>
        <i data-lucide="chevron-up" class="pending-tray-chevron" id="pendingTrayChevron"></i>
      </div>
      <div class="pending-tray-body" id="pendingTrayBody"></div>
      <div class="pending-tray-footer">
        <button type="button" class="btn-cancel" onclick="clearTray()">Clear All</button>
        <button type="button" class="btn-save" id="applyBatchBtn" onclick="applyBatchChanges()">Apply All Changes (<span id="applyBatchCount">0</span>)</button>
      </div>
    </div>

  <?php endif; ?>

  <!-- PHP injects the role flag so JS can branch without inline PHP in the .js file -->
  <script>
    const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
  </script>
  <?php if ($action_msg): ?>
    <?php list($type, $msg) = explode(':', $action_msg, 2); ?>
    <script>
      Swal.fire({
        icon: <?php echo json_encode($type === 'success' ? 'success' : 'error'); ?>,
        title: <?php echo json_encode($type === 'success' ? 'Success!' : 'Oops!'); ?>,
        text: <?php echo json_encode($msg); ?>,
        confirmButtonColor: '#b8703f',
        customClass: { container: 'blurred-backdrop' },
        <?php echo $type === 'success' ? "timer: 1800, timerProgressBar: true," : ""; ?>
      });
    </script>
  <?php endif; ?>
  <script src="../js/inventory_management.js"></script>
  <script src="../js/lucide-init.js"></script>
</body>

</html>