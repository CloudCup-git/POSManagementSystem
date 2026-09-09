<?php
/**
 * CloudCup — Admin Dashboard data layer.
 * Pulls everything the redesigned dashboard needs straight from the
 * live database (orders, order_items, inventory, branches, users).
 * Shared by Admin_Page.php and Admin_Dashboard.php so both entry
 * points always show the same numbers.
 *
 * Expects $conn (mysqli|false) to already be set by DB_Connect.php.
 */

if (!function_exists('safe_query')) {
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
}
if (!function_exists('safe_fetch_assoc')) {
  function safe_fetch_assoc($result): array {
    if ($result === false) return [];
    $row = mysqli_fetch_assoc($result);
    return $row ?: [];
  }
}
if (!function_exists('cc_pct_change')) {
  function cc_pct_change(float $now, float $prev): array {
    if ($prev == 0) return ['pct' => $now > 0 ? 100.0 : 0.0, 'up' => $now >= 0];
    $p = (($now - $prev) / $prev) * 100;
    return ['pct' => round(abs($p), 1), 'up' => $p >= 0];
  }
}
if (!function_exists('cc_order_id')) {
  function cc_order_id($id): string { return '#ORD-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }
}
if (!function_exists('cc_status_label')) {
  function cc_status_label(string $status): string {
    $status = strtolower($status);
    return match ($status) {
      'completed' => 'Completed',
      'pending'   => 'Pending',
      'cancelled' => 'Cancelled',
      'preparing' => 'Preparing',
      default     => ucfirst($status),
    };
  }
}
if (!function_exists('cc_status_class')) {
  // 'good' pill (green) for completed, 'prep' pill (amber) for anything else
  function cc_status_class(string $status): string {
    return strtolower($status) === 'completed' ? '' : 'prep';
  }
}

/**
 * Pulls revenue/orders/chart/top-item/recent-orders data for one
 * dashboard period ('today' | 'month' | 'year'), plus the % change
 * against the equivalent prior period.
 */
if (!function_exists('cc_dashboard_period')) {
  function cc_dashboard_period($conn, string $period): array {
    $today = new DateTime('today');

    switch ($period) {
      case 'month':
        $label      = 'This Month';
        $start      = new DateTime($today->format('Y-m-01'));
        $end        = clone $today;
        $prevStart  = (clone $start)->modify('-1 month');
        $prevEnd    = (clone $prevStart)->modify('+' . ((int)$today->format('j') - 1) . ' days');
        break;
      case 'year':
        $label      = 'This Year';
        $start      = new DateTime($today->format('Y-01-01'));
        $end        = clone $today;
        $prevStart  = (clone $start)->modify('-1 year');
        $daysIntoYear = (int)$today->format('z'); // 0-based day-of-year
        $prevEnd    = (clone $prevStart)->modify('+' . $daysIntoYear . ' days');
        break;
      case 'today':
      default:
        $period     = 'today';
        $label      = 'Today';
        $start      = clone $today;
        $end        = clone $today;
        $prevStart  = (clone $today)->modify('-1 day');
        $prevEnd    = clone $prevStart;
        break;
    }

    $s  = $start->format('Y-m-d');
    $e  = $end->format('Y-m-d');
    $ps = $prevStart->format('Y-m-d');
    $pe = $prevEnd->format('Y-m-d');

    // ── revenue + orders for the period vs. the prior comparable period ──
    $cur = safe_fetch_assoc(safe_query($conn,
      "SELECT COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS orders
       FROM orders
       WHERE status = 'completed' AND DATE(ordered_at) BETWEEN '$s' AND '$e'"));
    $prev = safe_fetch_assoc(safe_query($conn,
      "SELECT COALESCE(SUM(total_amount),0) AS revenue
       FROM orders
       WHERE status = 'completed' AND DATE(ordered_at) BETWEEN '$ps' AND '$pe'"));

    $revenue     = (float)($cur['revenue'] ?? 0);
    $ordersCount = (int)($cur['orders'] ?? 0);
    $change      = cc_pct_change($revenue, (float)($prev['revenue'] ?? 0));

    // ── chart buckets ─────────────────────────────────────────────
    $chartLabels = [];
    $chartValues = [];

    if ($period === 'today') {
      // 2-hour buckets across the day
      $bucketed = [];
      $res = safe_query($conn,
        "SELECT FLOOR(HOUR(ordered_at)/2)*2 AS bucket, COALESCE(SUM(total_amount),0) AS total
         FROM orders WHERE status='completed' AND DATE(ordered_at) = '$s'
         GROUP BY bucket");
      if ($res) while ($r = mysqli_fetch_assoc($res)) $bucketed[(int)$r['bucket']] = (float)$r['total'];
      for ($h = 0; $h <= 22; $h += 2) {
        $suffix = $h < 12 ? 'AM' : 'PM';
        $disp   = $h % 12 === 0 ? 12 : $h % 12;
        $chartLabels[] = $disp . $suffix;
        $chartValues[] = $bucketed[$h] ?? 0;
      }
    } elseif ($period === 'month') {
      $bucketed = [];
      $res = safe_query($conn,
        "SELECT DAY(ordered_at) AS d, COALESCE(SUM(total_amount),0) AS total
         FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN '$s' AND '$e'
         GROUP BY d");
      if ($res) while ($r = mysqli_fetch_assoc($res)) $bucketed[(int)$r['d']] = (float)$r['total'];
      $daysSoFar = (int)$today->format('j');
      for ($d = 1; $d <= $daysSoFar; $d++) {
        $chartLabels[] = (string)$d;
        $chartValues[] = $bucketed[$d] ?? 0;
      }
    } else { // year
      $bucketed = [];
      $res = safe_query($conn,
        "SELECT MONTH(ordered_at) AS m, COALESCE(SUM(total_amount),0) AS total
         FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN '$s' AND '$e'
         GROUP BY m");
      if ($res) while ($r = mysqli_fetch_assoc($res)) $bucketed[(int)$r['m']] = (float)$r['total'];
      $monthsSoFar = (int)$today->format('n');
      $monthNames  = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      for ($m = 1; $m <= $monthsSoFar; $m++) {
        $chartLabels[] = $monthNames[$m];
        $chartValues[] = $bucketed[$m] ?? 0;
      }
    }
    // Chart needs at least one bar so the layout doesn't collapse
    if (empty($chartValues)) { $chartLabels = [$label]; $chartValues = [0]; }

    // ── top selling item for the period ─────────────────────────
    $top_row = safe_fetch_assoc(safe_query($conn,
      "SELECT oi.item_name, SUM(oi.quantity) AS sold, SUM(oi.subtotal) AS revenue
       FROM order_items oi
       JOIN orders o ON o.order_id = oi.order_id
       WHERE o.status='completed' AND DATE(o.ordered_at) BETWEEN '$s' AND '$e'
       GROUP BY oi.item_name
       ORDER BY sold DESC LIMIT 1"));
    $top = $top_row ? [
      'name'    => $top_row['item_name'],
      'sold'    => (int)$top_row['sold'],
      'revenue' => (float)$top_row['revenue'],
    ] : null;

    // ── most recent orders in this period ───────────────────────
    $orders = [];
    $res = safe_query($conn,
      "SELECT o.order_id, o.total_amount, o.payment_method, o.status, o.ordered_at,
              COALESCE(u.full_name, 'Unknown') AS cashier,
              GROUP_CONCAT(oi.item_name SEPARATOR ', ') AS items_list
       FROM orders o
       LEFT JOIN users u ON u.user_id = o.employee_id
       LEFT JOIN order_items oi ON oi.order_id = o.order_id
       WHERE DATE(o.ordered_at) BETWEEN '$s' AND '$e'
       GROUP BY o.order_id
       ORDER BY o.ordered_at DESC
       LIMIT 6");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
      $items = $r['items_list'] ?: '—';
      if (mb_strlen($items) > 34) $items = mb_substr($items, 0, 34) . '…';
      $orders[] = [
        'id'      => cc_order_id($r['order_id']),
        'cashier' => $r['cashier'],
        'item'    => $items,
        'method'  => $r['payment_method'] ? strtoupper($r['payment_method']) : '—',
        'amount'  => (float)$r['total_amount'],
        'status'  => cc_status_label($r['status']),
        'statusClass' => cc_status_class($r['status']),
      ];
    }

    return [
      'label'        => $label,
      'revenue'      => $revenue,
      'change'       => $change['pct'],
      'changeUp'     => $change['up'],
      'chartLabels'  => $chartLabels,
      'chartValues'  => $chartValues,
      'ordersCount'  => $ordersCount,
      'orders'       => $orders,
      'top'          => $top,
    ];
  }
}

/**
 * Overview widgets that don't need to swap per Today/Month/Year toggle —
 * shown once on the dashboard as a steady "this month at a glance" view.
 * Pulled in from the old standalone Reports_Page.php, which duplicated
 * the dashboard and got folded into it here instead.
 */
if (!function_exists('cc_dashboard_top_items')) {
  function cc_dashboard_top_items($conn, int $limit = 8): array {
    $start = (new DateTime('first day of this month'))->format('Y-m-d');
    $end   = (new DateTime('today'))->format('Y-m-d');
    $items = [];
    $res = safe_query($conn,
      "SELECT oi.item_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
       FROM order_items oi
       JOIN orders o ON o.order_id = oi.order_id
       WHERE o.status='completed' AND DATE(o.ordered_at) BETWEEN '$start' AND '$end'
       GROUP BY oi.item_name ORDER BY qty DESC LIMIT $limit");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
      $items[] = ['item_name' => $r['item_name'], 'qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']];
    }
    return $items;
  }
}

if (!function_exists('cc_dashboard_payment_methods')) {
  function cc_dashboard_payment_methods($conn): array {
    $start = (new DateTime('first day of this month'))->format('Y-m-d');
    $end   = (new DateTime('today'))->format('Y-m-d');
    $methods = [];
    $res = safe_query($conn,
      "SELECT payment_method, COUNT(*) AS cnt, SUM(total_amount) AS total
       FROM orders WHERE status='completed' AND DATE(ordered_at) BETWEEN '$start' AND '$end'
       GROUP BY payment_method ORDER BY total DESC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
      $methods[] = ['method' => $r['payment_method'] ?: 'Unknown', 'cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
    }
    return $methods;
  }
}

/** Full low-stock roster (item/stock/reorder/status) — the dashboard's alert bar only names the top 3. */
if (!function_exists('cc_dashboard_low_stock_list')) {
  function cc_dashboard_low_stock_list($conn, int $limit = 8): array {
    $res = safe_query($conn,
      "SELECT item_name, quantity, reorder_level, unit FROM inventory
       WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT $limit");
    $out = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    return $out;
  }
}

/** Real branch list — no fabricated revenue, since orders aren't linked to a branch in this schema. */
if (!function_exists('cc_dashboard_branches')) {
  function cc_dashboard_branches($conn): array {
    $branches = [];
    $res = safe_query($conn, "SELECT * FROM branches ORDER BY status = 'active' DESC, branch_name ASC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
      $branches[] = [
        'name'    => $r['branch_name'] ?? 'Unnamed branch',
        // 'address' always exists on the base branches table; guarded anyway in case of a partial schema.
        'address' => !empty($r['address']) ? $r['address'] : 'No address on file',
        // 'operating_hours' only exists after running database/migrations/2026_08_24_branch_management.sql —
        // isset() (via ??) instead of ?: so an entirely-missing column doesn't raise a PHP warning.
        'hours'   => $r['operating_hours'] ?? null,
        'open'    => ($r['status'] ?? 'active') === 'active',
      ];
    }
    return $branches;
  }
}

/** Recent activity feed: latest completed orders + low-stock alerts, same logic the old dashboard used. */
if (!function_exists('cc_dashboard_activity')) {
  function cc_dashboard_activity($conn): array {
    $activity = [];

    $act_orders = safe_query($conn,
      "SELECT o.order_id, o.total_amount, o.status, o.ordered_at, u.full_name AS cashier
       FROM orders o
       LEFT JOIN users u ON u.user_id = o.employee_id
       ORDER BY o.ordered_at DESC LIMIT 5");
    if ($act_orders) while ($r = mysqli_fetch_assoc($act_orders)) {
      $oid = cc_order_id($r['order_id']);
      $msg = strtolower($r['status']) === 'completed'
        ? "<b>{$oid}</b> completed" . ($r['cashier'] ? " by " . htmlspecialchars($r['cashier']) : '') . " — ₱" . number_format((float)$r['total_amount'], 2)
        : "<b>{$oid}</b> " . htmlspecialchars(cc_status_label($r['status']));
      $activity[] = ['text' => $msg, 'ts' => $r['ordered_at']];
    }

    $act_inv = safe_query($conn,
      "SELECT item_name, quantity, reorder_level FROM inventory
       WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 3");
    if ($act_inv) while ($r = mysqli_fetch_assoc($act_inv)) {
      $activity[] = [
        'text' => '<b>' . htmlspecialchars($r['item_name']) . '</b> stock low — ' . $r['quantity'] . ' unit(s) remaining (min ' . $r['reorder_level'] . ')',
        'ts'   => null,
      ];
    }

    $out = [];
    foreach ($activity as $a) {
      if ($a['ts']) {
        $diff = time() - strtotime($a['ts']);
        if ($diff < 60)        $time_txt = 'just now';
        elseif ($diff < 3600)  $time_txt = floor($diff / 60) . ' min ago';
        elseif ($diff < 86400) $time_txt = floor($diff / 3600) . ' hr' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
        else                   $time_txt = date('M j', strtotime($a['ts']));
      } else {
        $time_txt = 'alert';
      }
      $out[] = ['text' => $a['text'], 'time' => $time_txt];
    }
    return $out;
  }
}
