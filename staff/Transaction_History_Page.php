<?php
/**
 * CloudCup — My Transaction History (Staff)
 * -------------------------------------------------------------
 * Read-only view of a cashier's own past sales — the staff-scoped
 * counterpart to Admin's Sales_Records_Page.php. Same filters,
 * expandable item rows, and pagination pattern, but every query is
 * pinned to orders.employee_id = the logged-in employee, so staff
 * can only ever see transactions they personally rang up.
 */
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$is_staff_session = isset($_SESSION['role'], $_SESSION['full_name'])
    && (isset($_SESSION['employee_id']) || isset($_SESSION['user_id']))
    && strtolower($_SESSION['role']) === 'employee';

// Same routing as Sales_Processing_Page.php: admins/managers already have
// the full Sales Records view in the Admin panel, and inventory_staff
// never had POS access — so neither role belongs on this page.
if (!$is_staff_session) {
    if (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'manager'], true)) {
        header('Location: ../admin/Sales_Records_Page.php');
    } elseif (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'inventory_staff') {
        header('Location: ../admin/Inventory_Management_Page.php');
    } else {
        header('Location: ../auth/Login_Page.php');
    }
    exit;
}

require_once __DIR__ . '/../includes/DB_Connect.php';

$employee_id = (int) ($_SESSION['employee_id'] ?? $_SESSION['user_id']);
$full_name   = $_SESSION['full_name'] ?? 'Employee';
$active_page = 'emp_history';

// ── FILTERS + QUERY ─────────────────────────────────────────────
$sales       = [];
$sales_total = 0;
$total_pages = 1;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$per_page    = 20;
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to']   ?? '');
$method      = trim($_GET['method']    ?? '');
$search      = trim($_GET['q']         ?? '');
$stats       = [];

if ($conn) {
    // employee_id is always the logged-in staff's own int id — every
    // other filter is user input, so those still go through
    // mysqli_real_escape_string same as Admin's Sales_Records_Page.php.
    $where = "o.employee_id = " . $employee_id;
    if ($date_from !== '') $where .= " AND DATE(o.ordered_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to   !== '') $where .= " AND DATE(o.ordered_at) <= '" . mysqli_real_escape_string($conn, $date_to)   . "'";
    if ($method    !== '') $where .= " AND o.payment_method = '"    . mysqli_real_escape_string($conn, $method)    . "'";
    if ($search    !== '') $where .= " AND o.order_id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'";

    $count_res   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders o WHERE $where");
    $sales_total = (int) (mysqli_fetch_assoc($count_res)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($sales_total / $per_page));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per_page;

    $res = mysqli_query($conn,
        "SELECT o.order_id, o.total_amount, o.payment_method, o.amount_tendered,
                o.change_due, o.status, o.order_type, o.notes, o.ordered_at
         FROM orders o
         WHERE $where
         ORDER BY o.ordered_at DESC
         LIMIT $per_page OFFSET $offset");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $items_res = mysqli_query($conn,
                "SELECT item_name, quantity, unit_price, subtotal
                 FROM order_items WHERE order_id = " . (int) $r['order_id']);
            $r['items'] = [];
            if ($items_res) while ($it = mysqli_fetch_assoc($items_res)) $r['items'][] = $it;
            $sales[] = $r;
        }
    }

    // Summary stats — same shape as Admin's version, scoped to this employee.
    $stats_res = mysqli_query($conn,
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount),0) AS total_revenue,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_total,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_total,
                COALESCE(SUM(CASE WHEN payment_method='maya'  THEN total_amount ELSE 0 END),0) AS maya_total,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_total
         FROM orders o WHERE $where");
    $stats = mysqli_fetch_assoc($stats_res) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Transaction History — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/sales_processing.css"/>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

  <style>
    /* ==========================================================
       Transaction History — page-scoped redesign
       Palette drawn from Cloud Cup's own espresso/cream identity
       rather than a generic UI kit. Scoped to this page only —
       does not touch admin_page.css / sales_processing.css.
    ========================================================== */
    :root{
      --th-espresso:#2B1710;
      --th-espresso-2:#3E241A;
      --th-cream:#FBF6EE;
      --th-cream-2:#F4EBDB;
      --th-caramel:#B8763E;
      --th-caramel-dark:#96602F;
      --th-sage:#4F8571;
      --th-sage-dark:#3B6B59;
      --th-blue:#3E74B8;
      --th-plum:#7A5EA8;
      --th-red:#B4503D;
      --th-ink:#2B1710;
      --th-ink-soft:#8C7C6E;
      --th-line:#EAE0CE;
      --th-shadow: 0 1px 2px rgba(43,23,16,.04);
    }

    .content{ font-family:'Inter', sans-serif; }

    /* ---- Topbar polish ---- */
    .topbar-left h1{
      font-family:'Fraunces', serif;
      font-weight:600;
      letter-spacing:-0.01em;
      color:var(--th-ink);
    }
    .topbar-date{
      background:var(--th-cream-2);
      border:1px solid var(--th-line);
      padding:8px 14px;
      border-radius:100px;
      color:var(--th-ink-soft);
      font-size:12.5px;
      font-weight:500;
    }

    /* ---- Widget shell ---- */
    .widget{
      background:var(--th-cream);
      border:1px solid var(--th-line);
      border-radius:18px;
      box-shadow:var(--th-shadow);
      animation: th-rise .5s cubic-bezier(.22,.61,.36,1) both;
    }
    @keyframes th-rise{
      from{ opacity:0; transform:translateY(10px); }
      to{ opacity:1; transform:translateY(0); }
    }
    .widget-header{ padding:22px 26px 12px; }
    .widget-title{
      font-family:'Fraunces', serif;
      font-size:20px;
      font-weight:600;
      color:var(--th-ink);
    }

    /* ---- Stat cards ---- */
    .sales-stats-row{
      display:grid;
      grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
      gap:14px;
      padding:24px 26px 6px;
      max-width:820px;
    }
    .stat-card{
      position:relative;
      background:#fff;
      border:1px solid var(--th-line);
      border-radius:14px;
      padding:16px 18px;
      overflow:hidden;
      opacity:0;
      animation: th-pop .45s cubic-bezier(.22,.61,.36,1) both;
      transition:border-color .25s ease, transform .25s ease, box-shadow .25s ease;
    }
    .stat-card:hover{
      transform:translateY(-3px);
      border-color:var(--th-caramel);
      box-shadow:0 10px 22px -14px rgba(43,23,16,.35);
    }
    .stat-card::before{
      content:'';
      position:absolute; inset:0 auto auto 0;
      width:3px; height:100%;
      background:var(--th-caramel);
      opacity:.55;
    }
    .stat-card--revenue{
      background:var(--th-espresso);
      border-color:var(--th-espresso);
      color:#fff;
    }
    .stat-card--revenue::before{ background:var(--th-caramel); opacity:1; width:100%; height:3px; inset:0 0 auto 0; }
    .stat-card--revenue .stat-label{ color:rgba(255,255,255,.65); }
    .stat-card--revenue .stat-value{ color:#fff; }
    .stat-label{
      font-size:11.5px;
      color:var(--th-ink-soft);
      font-weight:500;
      margin-bottom:6px;
      display:flex; align-items:center; gap:6px;
    }
    .stat-label svg{ width:13px; height:13px; opacity:.7; }
    .stat-value{
      font-family:'IBM Plex Mono', monospace;
      font-size:22px;
      font-weight:600;
      color:var(--th-ink);
      letter-spacing:-0.01em;
      font-variant-numeric: tabular-nums;
    }
    @keyframes th-pop{
      from{ opacity:0; transform:translateY(8px) scale(.98); }
      to{ opacity:1; transform:translateY(0) scale(1); }
    }
    .stat-card:nth-child(1){ animation-delay:.03s; }
    .stat-card:nth-child(2){ animation-delay:.08s; }
    .stat-card:nth-child(3){ animation-delay:.13s; }
    .stat-card:nth-child(4){ animation-delay:.18s; }

    /* ---- Toolbar / filters ---- */
    .sales-filter-bar{
      display:flex;
      flex-direction:column;
      gap:12px;
      padding:16px 20px;
      margin:18px 26px 0;
      border:1.5px solid var(--th-line);
      border-radius:14px;
    }
    .th-row{ display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-start; gap:10px; row-gap:12px; }
    .th-divider{ width:1px; align-self:stretch; min-height:28px; background:var(--th-line); margin:0 2px; }
    .th-spacer{ flex:1 1 40px; min-width:8px; }

    .search-wrap{
      display:flex; align-items:center; gap:8px;
      background:#fff;
      border:1.5px solid var(--th-line);
      border-radius:10px;
      padding:0 12px;
      height:40px;
      flex:1 1 220px;
      min-width:180px;
      max-width:420px;
      transition:border-color .2s ease, box-shadow .2s ease;
    }
    .search-wrap:focus-within{
      border-color:var(--th-caramel);
      box-shadow:0 0 0 3px rgba(184,118,62,.14);
    }
    .search-wrap svg, .search-wrap i{ color:var(--th-ink-soft); flex-shrink:0; }
    .search-wrap input{
      border:0; outline:0; background:transparent;
      font-size:13.5px; color:var(--th-ink); width:100%; height:100%;
      font-family:'Inter',sans-serif;
    }
    .search-wrap input::placeholder{ color:#B7A996; }

    /* Date range — grouped pill instead of two bare inputs */
    .th-date-range{
      display:flex; align-items:center; gap:6px;
      background:#fff;
      border:1.5px solid var(--th-line);
      border-radius:10px;
      padding:0 12px;
      height:40px;
      transition:border-color .2s ease, box-shadow .2s ease;
    }
    .th-date-range:has(.th-datepicker[open]){
      border-color:var(--th-caramel);
      box-shadow:0 0 0 3px rgba(184,118,62,.14);
    }
    .th-date-range > svg{ color:var(--th-ink-soft); flex-shrink:0; width:15px; height:15px; }
    .th-date-sep{ color:#C9BBA6; font-size:13px; }

    /* Custom themed calendar — replaces the native <input type=date> picker */
    .th-datepicker{ position:relative; height:100%; display:flex; align-items:center; }
    .th-datepicker summary{
      list-style:none; cursor:pointer;
      height:100%; display:flex; align-items:center;
      font-size:13px; font-family:'IBM Plex Mono', monospace;
      color:var(--th-ink); padding:0 4px; border-radius:6px;
      transition:background .15s ease;
    }
    .th-datepicker summary::-webkit-details-marker{ display:none; }
    .th-datepicker summary:hover{ background:var(--th-cream-2); }
    .th-datepicker .th-dp-label.is-empty{ color:#B7A996; }

    .th-dp-panel{
      position:absolute; top:calc(100% + 8px); left:0; z-index:30;
      width:266px;
      background:#fff;
      border:1.5px solid var(--th-line);
      border-radius:12px;
      padding:14px;
      box-shadow:0 16px 32px -14px rgba(43,23,16,.3);
      animation: th-menu-in .16s ease both;
    }
    .th-dp-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .th-dp-head-title{ font-family:'Fraunces', serif; font-weight:600; font-size:14px; color:var(--th-ink); }
    .th-dp-nav{
      display:inline-flex; align-items:center; justify-content:center;
      width:26px; height:26px; border-radius:7px; border:1px solid var(--th-line);
      background:#fff; color:var(--th-ink-soft); cursor:pointer;
      transition:all .15s ease;
    }
    .th-dp-nav:hover{ border-color:var(--th-caramel); color:var(--th-caramel-dark); background:#FFF8EF; }
    .th-dp-nav svg{ width:13px; height:13px; }
    .th-dp-weekdays, .th-dp-days{
      display:grid; grid-template-columns:repeat(7, 1fr); gap:2px;
    }
    .th-dp-weekdays div{
      text-align:center; font-size:10px; font-weight:600; color:var(--th-ink-soft);
      padding-bottom:6px;
    }
    .th-dp-days button{
      border:0; background:transparent; cursor:pointer;
      width:100%; aspect-ratio:1; border-radius:8px;
      font-size:12.5px; font-family:'IBM Plex Mono', monospace; color:var(--th-ink);
      transition:background .15s ease, color .15s ease;
    }
    .th-dp-days button.is-outside{ color:#D8CBB7; }
    .th-dp-days button:hover{ background:var(--th-cream-2); }
    .th-dp-days button.is-today{ box-shadow:inset 0 0 0 1.5px var(--th-caramel); }
    .th-dp-days button.is-selected{ background:var(--th-espresso); color:#fff; }
    .th-dp-foot{
      display:flex; justify-content:space-between; align-items:center;
      margin-top:10px; padding-top:10px; border-top:1px solid var(--th-line);
    }
    .th-dp-foot button{
      border:0; background:transparent; cursor:pointer;
      font-size:12px; font-weight:600; color:var(--th-caramel-dark);
      font-family:'Inter', sans-serif; padding:4px 6px; border-radius:6px;
    }
    .th-dp-foot button:hover{ background:#FFF8EF; }

    /* Quick range dropdown — replaces the three separate preset buttons */
    .th-quickrange{ position:relative; height:40px; }
    .th-quickrange summary{
      list-style:none;
      display:flex; align-items:center; gap:7px;
      height:40px; padding:0 13px;
      border:1.5px solid var(--th-line);
      background:#fff;
      color:var(--th-ink-soft);
      font-size:12.5px; font-weight:500;
      border-radius:10px;
      cursor:pointer;
      transition:all .18s ease;
      user-select:none;
    }
    .th-quickrange summary::-webkit-details-marker{ display:none; }
    .th-quickrange summary:hover{
      border-color:var(--th-caramel);
      color:var(--th-caramel-dark);
      background:#FFF8EF;
    }
    .th-quickrange summary svg{ width:13px; height:13px; }
    .th-quickrange-caret{ transition:transform .2s ease; margin-left:1px; }
    .th-quickrange[open] summary{ border-color:var(--th-caramel); color:var(--th-caramel-dark); }
    .th-quickrange[open] .th-quickrange-caret{ transform:rotate(180deg); }
    .th-quickrange-menu{
      position:absolute; top:calc(100% + 6px); left:0; z-index:20;
      display:flex; flex-direction:column;
      min-width:150px;
      background:#fff;
      border:1.5px solid var(--th-line);
      border-radius:10px;
      padding:5px;
      box-shadow:0 14px 28px -12px rgba(43,23,16,.28);
      animation: th-menu-in .16s ease both;
    }
    @keyframes th-menu-in{
      from{ opacity:0; transform:translateY(-4px) scale(.98); }
      to{ opacity:1; transform:translateY(0) scale(1); }
    }
    .th-quickrange-menu button{
      border:0; background:transparent; text-align:left;
      font-size:13px; font-family:'Inter', sans-serif; color:var(--th-ink);
      padding:8px 10px; border-radius:7px; cursor:pointer;
      transition:background .15s ease;
    }
    .th-quickrange-menu button:hover{ background:var(--th-cream-2); color:var(--th-caramel-dark); }

    /* Payment segmented control */
    .th-segmented{
      display:inline-flex;
      align-items:center;
      height:40px;
      background:#fff;
      border:1.5px solid var(--th-line);
      border-radius:10px;
      padding:3px;
      gap:2px;
      box-sizing:border-box;
    }
    .th-segmented input{ position:absolute; opacity:0; pointer-events:none; }
    .th-segmented label{
      display:inline-flex; align-items:center; gap:6px;
      height:100%;
      padding:0 13px;
      font-size:12.5px;
      font-weight:500;
      color:var(--th-ink-soft);
      border-radius:7px;
      cursor:pointer;
      transition:all .2s ease;
      user-select:none;
    }
    .th-segmented label:hover{ color:var(--th-ink); }
    .th-segmented input:checked + label{
      background:var(--th-espresso);
      color:#fff;
      box-shadow:0 2px 6px -2px rgba(43,23,16,.4);
    }
    .th-segmented label svg{ width:13px; height:13px; }

    .filter-actions{ display:flex; gap:8px; }
    .th-btn{
      display:inline-flex; align-items:center; gap:7px;
      height:40px; padding:0 18px;
      border-radius:10px;
      font-size:13.5px; font-weight:600;
      cursor:pointer; border:1.5px solid transparent;
      transition:transform .15s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
      font-family:'Inter', sans-serif;
      text-decoration:none;
    }
    .th-btn svg{ width:14px; height:14px; }
    .btn-filter{
      background:var(--th-caramel);
      color:#fff;
      box-shadow:0 8px 16px -8px rgba(184,118,62,.6);
    }
    .btn-filter:hover{ background:var(--th-caramel-dark); transform:translateY(-1px); }
    .btn-filter:active{ transform:translateY(0) scale(.96); box-shadow:0 4px 8px -4px rgba(184,118,62,.5); }
    .btn-clear{
      background:transparent;
      border-color:var(--th-line);
      color:var(--th-ink-soft);
    }
    .btn-clear:hover{ border-color:var(--th-red); color:var(--th-red); background:#FBEEEA; }
    .btn-clear:active{ transform:scale(.96); }

    /* ---- Table ---- */
    .sales-table-wrap{ padding:18px 26px 26px; }
    .sales-table{ width:100%; border-collapse:separate; border-spacing:0; }
    .sales-table thead th{
      text-align:left;
      font-size:11.5px;
      font-weight:600;
      color:var(--th-ink-soft);
      padding:10px 14px;
      border-bottom:1.5px solid var(--th-line);
      letter-spacing:.01em;
    }
    .sales-row{
      cursor:pointer;
      opacity:0;
      animation: th-row-in .4s ease both;
      transition:background .15s ease;
    }
    .sales-row td{
      padding:14px;
      border-bottom:1px solid var(--th-line);
      font-size:13.5px;
      color:var(--th-ink);
      position:relative;
    }
    .sales-row td:first-child{ border-left:3px solid transparent; transition:border-color .2s ease; }
    .sales-row:hover{ background:var(--th-cream-2); }
    .sales-row:hover td:first-child{ border-left-color:var(--th-caramel); }
    .sales-row.is-active{ background:#FFF8EF; }
    .sales-row.is-active td:first-child{ border-left-color:var(--th-caramel); }
    @keyframes th-row-in{
      from{ opacity:0; transform:translateY(6px); }
      to{ opacity:1; transform:translateY(0); }
    }

    .th-order-cell{ display:flex; align-items:center; gap:8px; }
    .th-chevron{ color:var(--th-ink-soft); transition:transform .3s cubic-bezier(.22,.61,.36,1); flex-shrink:0; width:14px; height:14px; }
    .sales-row td strong{ font-family:'IBM Plex Mono', monospace; font-weight:600; }

    .items-preview{ color:var(--th-ink-soft); font-size:13px; }
    .more-badge{
      display:inline-block;
      background:var(--th-cream-2);
      border:1px solid var(--th-line);
      color:var(--th-caramel-dark);
      font-size:11px; font-weight:600;
      padding:1px 7px; border-radius:100px; margin-left:4px;
    }
    .muted{ color:var(--th-ink-soft); }

    .pay-badge{
      display:inline-flex; align-items:center; gap:5px;
      font-size:11px; font-weight:700; letter-spacing:.02em;
      padding:4px 10px; border-radius:100px;
    }
    .pay-badge--cash{ background:#EAF3EE; color:var(--th-sage-dark); }
    .pay-badge--card{ background:#FBF0E4; color:var(--th-caramel-dark); }
    .pay-badge--gcash{ background:#E9F0FB; color:var(--th-blue); }
    .pay-badge--maya{ background:#F1ECF8; color:var(--th-plum); }
    .pay-badge--unspecified{ background:#F3EEE7; color:var(--th-ink-soft); }

    .status-badge{
      display:inline-block;
      font-size:11px; font-weight:600;
      padding:4px 10px; border-radius:100px;
      background:#EAF3EE; color:var(--th-sage-dark);
      border:1px solid rgba(79,133,113,.2);
    }
    .status-badge--pending{ background:#FBF0E4; color:var(--th-caramel-dark); border-color:rgba(184,118,62,.2); }
    .status-badge--cancelled, .status-badge--voided, .status-badge--refunded{
      background:#FBEEEA; color:var(--th-red); border-color:rgba(180,80,61,.2);
    }

    /* Expandable detail row — smooth grid-based height transition */
    .items-detail-row td{ padding:0; border:0; }
    .th-expand-wrap{
      display:grid;
      grid-template-rows:0fr;
      transition:grid-template-rows .35s cubic-bezier(.22,.61,.36,1);
      background:var(--th-cream-2);
    }
    .items-detail-row.open .th-expand-wrap{ grid-template-rows:1fr; }
    .th-expand-inner{ overflow:hidden; min-height:0; }
    .items-detail-table{ width:100%; border-collapse:collapse; margin:4px 14px 14px; width:calc(100% - 28px); }
    .items-detail-table thead th{
      text-align:left; font-size:10.5px; font-weight:600; color:var(--th-ink-soft);
      padding:8px 10px; border-bottom:1px solid var(--th-line);
    }
    .items-detail-table td{
      padding:8px 10px; font-size:12.5px; color:var(--th-ink);
      border-bottom:1px solid rgba(234,224,206,.6);
      font-family:'IBM Plex Mono', monospace;
    }
    .items-detail-table td:first-child{ font-family:'Inter', sans-serif; }

    /* ---- Pagination ---- */
    .pagination{
      display:flex; align-items:center; justify-content:space-between;
      margin-top:18px; padding-top:16px; border-top:1px solid var(--th-line);
    }
    .pagination-info{ font-size:12.5px; color:var(--th-ink-soft); }
    .page-btns{ display:flex; gap:6px; }
    .page-btn{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:34px; height:34px; padding:0 6px;
      border-radius:9px; border:1.5px solid var(--th-line);
      color:var(--th-ink-soft); font-size:13px; font-weight:600;
      text-decoration:none; transition:all .18s ease;
      font-family:'IBM Plex Mono', monospace;
    }
    .page-btn:hover{ border-color:var(--th-caramel); color:var(--th-caramel-dark); transform:translateY(-1px); }
    .page-btn.active{ background:var(--th-espresso); border-color:var(--th-espresso); color:#fff; }

    /* Empty state */
    .sales-table-wrap > div[style*="text-align:center"]{
      animation: th-pop .4s ease both;
    }

    @media (prefers-reduced-motion: reduce){
      *{ animation-duration:.001ms !important; transition-duration:.001ms !important; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/Sidebar_Employee.php'; ?>
<script src="../js/lucide-init.js"></script>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Transaction History</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-date"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <?= date('F j, Y') ?></div>
    </div>
  </div>

  <div class="content">

    <div class="widget">

      <?php if (!empty($stats)): ?>
      <div class="sales-stats-row">
        <div class="stat-card">
          <div class="stat-label"><i data-lucide="receipt"></i> Total Orders</div>
          <div class="stat-value" data-count="<?= (int) $stats['total_orders'] ?>" data-prefix="">0</div>
        </div>
        <div class="stat-card stat-card--revenue">
          <div class="stat-label"><i data-lucide="trending-up"></i> Total Sales</div>
          <div class="stat-value" data-count="<?= (float) $stats['total_revenue'] ?>" data-prefix="₱" data-decimals="2">₱0.00</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i data-lucide="banknote"></i> Cash</div>
          <div class="stat-value" data-count="<?= (float) $stats['cash_total'] ?>" data-prefix="₱" data-decimals="2">₱0.00</div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i data-lucide="smartphone"></i> GCash</div>
          <div class="stat-value" data-count="<?= (float) $stats['gcash_total'] ?>" data-prefix="₱" data-decimals="2">₱0.00</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- FILTER BAR -->
      <form method="GET" action="" class="sales-filter-bar" id="thFilterForm">
        <div class="th-row">
          <div class="search-wrap">
            <i data-lucide="search" style="width:14px;height:14px"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search order #…"/>
          </div>

          <div class="th-date-range">
            <i data-lucide="calendar-days"></i>
            <details class="th-datepicker" id="thDpFrom">
              <summary><span class="th-dp-label<?= $date_from === '' ? ' is-empty' : '' ?>"><?= $date_from !== '' ? date('M j, Y', strtotime($date_from)) : 'From' ?></span></summary>
              <div class="th-dp-panel"></div>
            </details>
            <span class="th-date-sep">→</span>
            <details class="th-datepicker" id="thDpTo">
              <summary><span class="th-dp-label<?= $date_to === '' ? ' is-empty' : '' ?>"><?= $date_to !== '' ? date('M j, Y', strtotime($date_to)) : 'To' ?></span></summary>
              <div class="th-dp-panel"></div>
            </details>
            <input type="hidden" name="date_from" id="thDateFrom" value="<?= htmlspecialchars($date_from) ?>"/>
            <input type="hidden" name="date_to" id="thDateTo" value="<?= htmlspecialchars($date_to) ?>"/>
          </div>

          <details class="th-quickrange">
            <summary><i data-lucide="clock"></i>Quick range<i data-lucide="chevron-down" class="th-quickrange-caret"></i></summary>
            <div class="th-quickrange-menu">
              <button type="button" data-preset="today">Today</button>
              <button type="button" data-preset="7d">Last 7 days</button>
              <button type="button" data-preset="month">This month</button>
            </div>
          </details>

          <div class="th-divider" aria-hidden="true"></div>

          <div class="th-segmented" role="group" aria-label="Payment method">
            <input type="radio" name="method" id="m-all" value="" <?= $method === '' ? 'checked' : '' ?> onchange="this.form.submit()"/>
            <label for="m-all">All</label>
            <?php foreach (['cash' => 'banknote', 'gcash' => 'smartphone'] as $m => $icon): ?>
              <input type="radio" name="method" id="m-<?= $m ?>" value="<?= $m ?>" <?= $method === $m ? 'checked' : '' ?> onchange="this.form.submit()"/>
              <label for="m-<?= $m ?>"><i data-lucide="<?= $icon ?>"></i><?= ucfirst($m) ?></label>
            <?php endforeach; ?>
          </div>

          <div class="th-spacer"></div>

          <div class="filter-actions">
            <button type="submit" class="th-btn btn-filter"><i data-lucide="sliders-horizontal"></i>Filter</button>
            <a href="Transaction_History_Page.php" class="th-btn btn-clear"><i data-lucide="x"></i>Clear</a>
          </div>
        </div>
      </form>

      <!-- TRANSACTIONS TABLE -->
      <div class="sales-table-wrap">
        <?php if (empty($sales)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--th-ink-soft)">
          <i data-lucide="receipt" style="width:48px;height:48px"></i>
          <p style="margin-top:12px">No transactions found.</p>
        </div>
        <?php else: ?>
        <table class="sales-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Date &amp; time</th>
              <th>Items</th>
              <th>Type</th>
              <th>Payment</th>
              <th style="text-align:right">Total</th>
              <th style="text-align:right">Tendered</th>
              <th style="text-align:right">Change</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php $rowIndex = 0; foreach ($sales as $sale): $pm = $sale['payment_method'] ?: 'unspecified'; ?>
            <tr class="sales-row" style="animation-delay: <?= min($rowIndex * 30, 300) ?>ms" onclick="thToggleRow(this)">
              <td>
                <div class="th-order-cell">
                  <i data-lucide="chevron-down" class="th-chevron"></i>
                  <strong>#<?= (int) $sale['order_id'] ?></strong>
                </div>
              </td>
              <td><?= date('M d, Y g:i A', strtotime($sale['ordered_at'])) ?></td>
              <td class="items-preview">
                <?php
                  $names = array_map(fn($i) => $i['quantity'] . '× ' . $i['item_name'], $sale['items']);
                  echo htmlspecialchars(implode(', ', array_slice($names, 0, 3)));
                  if (count($names) > 3) echo ' <span class="more-badge">+' . (count($names) - 3) . ' more</span>';
                ?>
              </td>
              <td class="muted"><?= ucfirst(str_replace('_', ' ', $sale['order_type'])) ?></td>
              <td>
                <span class="pay-badge pay-badge--<?= htmlspecialchars($pm) ?>">
                  <?= strtoupper(htmlspecialchars($pm)) ?>
                </span>
              </td>
              <td style="text-align:right"><strong>₱<?= number_format((float) $sale['total_amount'], 2) ?></strong></td>
              <td style="text-align:right">₱<?= number_format((float) $sale['amount_tendered'], 2) ?></td>
              <td style="text-align:right">₱<?= number_format((float) $sale['change_due'], 2) ?></td>
              <td><span class="status-badge status-badge--<?= htmlspecialchars($sale['status']) ?>"><?= ucfirst(htmlspecialchars($sale['status'])) ?></span></td>
            </tr>
            <!-- Expandable items row -->
            <tr class="items-detail-row">
              <td colspan="9">
                <div class="th-expand-wrap">
                  <div class="th-expand-inner">
                    <table class="items-detail-table">
                      <thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Subtotal</th></tr></thead>
                      <tbody>
                        <?php foreach ($sale['items'] as $it): ?>
                        <tr>
                          <td><?= htmlspecialchars($it['item_name']) ?></td>
                          <td><?= (int) $it['quantity'] ?></td>
                          <td>₱<?= number_format((float) $it['unit_price'], 2) ?></td>
                          <td>₱<?= number_format((float) $it['subtotal'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($sale['notes']): ?>
                        <tr><td colspan="4" style="color:var(--th-ink-soft);font-style:italic">Note: <?= htmlspecialchars($sale['notes']) ?></td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </td>
            </tr>
            <?php $rowIndex++; endforeach; ?>
          </tbody>
        </table>

        <!-- PAGINATION -->
        <?php $start = ($page - 1) * $per_page + 1; $end = min($page * $per_page, $sales_total); ?>
        <div class="pagination">
          <div class="pagination-info">Showing <?= $start ?>–<?= $end ?> of <?= $sales_total ?> transactions</div>
          <div class="page-btns">
            <?php if ($page > 1): ?>
              <a class="page-btn" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>">‹</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
              <a class="page-btn <?= $i === $page ? 'active' : '' ?>"
                 href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
              <a class="page-btn" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= urlencode($method) ?>">›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script src="../js/sales_records_admin.js"></script>
<script>
  // Overrides the shared admin toggle with a smooth, animated version
  // scoped to this page (declared after sales_records_admin.js so it wins).
  function thToggleRow(rowEl){
    const detail = rowEl.nextElementSibling;
    if (!detail || !detail.classList.contains('items-detail-row')) return;
    const opening = !detail.classList.contains('open');
    detail.classList.toggle('open', opening);
    rowEl.classList.toggle('is-active', opening);
    const chevron = rowEl.querySelector('.th-chevron');
    if (chevron) chevron.style.transform = opening ? 'rotate(180deg)' : 'rotate(0deg)';
  }
  // Keep old name pointed here too, in case anything else references it.
  window.toggleOrderItems = thToggleRow;

  // Quick date-range presets (inside the Quick range dropdown)
  (function(){
    const fromEl = document.getElementById('thDateFrom');
    const toEl   = document.getElementById('thDateTo');
    const form   = document.getElementById('thFilterForm');
    const fmt = d => d.toISOString().slice(0, 10);

    document.querySelectorAll('.th-quickrange-menu button[data-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        const today = new Date();
        let from = new Date(today), to = new Date(today);
        if (btn.dataset.preset === 'today') {
          // from = to = today
        } else if (btn.dataset.preset === '7d') {
          from.setDate(from.getDate() - 6);
        } else if (btn.dataset.preset === 'month') {
          from = new Date(today.getFullYear(), today.getMonth(), 1);
        }
        fromEl.value = fmt(from);
        toEl.value = fmt(to);
        form.submit();
      });
    });

    // Close the dropdown when clicking outside it
    document.addEventListener('click', (e) => {
      document.querySelectorAll('.th-quickrange[open], .th-datepicker[open]').forEach(d => {
        if (!d.contains(e.target)) d.removeAttribute('open');
      });
    });
  })();

  // Custom themed calendar dropdowns — replace the native <input type=date> picker
  (function(){
    const WEEKDAYS = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    const parse = s => { const [y,m,d] = s.split('-').map(Number); return new Date(y, m-1, d); };
    const label = d => MONTHS[d.getMonth()].slice(0,3) + ' ' + d.getDate() + ', ' + d.getFullYear();

    function setupPicker(detailsId, hiddenId){
      const details = document.getElementById(detailsId);
      if (!details) return;
      const panel = details.querySelector('.th-dp-panel');
      const labelEl = details.querySelector('.th-dp-label');
      const hidden = document.getElementById(hiddenId);

      let selected = hidden.value ? parse(hidden.value) : null;
      let viewDate = selected ? new Date(selected) : new Date();

      function render(){
        const year = viewDate.getFullYear(), month = viewDate.getMonth();
        const firstOfMonth = new Date(year, month, 1);
        const startOffset = firstOfMonth.getDay();
        const gridStart = new Date(year, month, 1 - startOffset);
        const today = new Date();

        let daysHtml = '';
        for (let i = 0; i < 42; i++){
          const cellDate = new Date(gridStart);
          cellDate.setDate(gridStart.getDate() + i);
          const outside = cellDate.getMonth() !== month;
          const isToday = cellDate.toDateString() === today.toDateString();
          const isSelected = selected && cellDate.toDateString() === selected.toDateString();
          const cls = ['', outside ? 'is-outside' : '', isToday ? 'is-today' : '', isSelected ? 'is-selected' : ''].join(' ').trim();
          daysHtml += `<button type="button" class="${cls}" data-date="${fmt(cellDate)}">${cellDate.getDate()}</button>`;
        }

        panel.innerHTML = `
          <div class="th-dp-head">
            <button type="button" class="th-dp-nav" data-nav="-1"><i data-lucide="chevron-left"></i></button>
            <span class="th-dp-head-title">${MONTHS[month]} ${year}</span>
            <button type="button" class="th-dp-nav" data-nav="1"><i data-lucide="chevron-right"></i></button>
          </div>
          <div class="th-dp-weekdays">${WEEKDAYS.map(w => `<div>${w}</div>`).join('')}</div>
          <div class="th-dp-days">${daysHtml}</div>
          <div class="th-dp-foot">
            <button type="button" data-action="clear">Clear</button>
            <button type="button" data-action="today">Today</button>
          </div>
        `;
        if (window.lucide) lucide.createIcons();

        panel.querySelectorAll('.th-dp-days button').forEach(btn => {
          btn.addEventListener('click', () => {
            selected = parse(btn.dataset.date);
            hidden.value = btn.dataset.date;
            labelEl.textContent = label(selected);
            labelEl.classList.remove('is-empty');
            details.removeAttribute('open');
          });
        });
        panel.querySelectorAll('.th-dp-nav').forEach(btn => {
          btn.addEventListener('click', () => {
            viewDate.setMonth(viewDate.getMonth() + parseInt(btn.dataset.nav, 10));
            render();
          });
        });
        panel.querySelector('[data-action="clear"]').addEventListener('click', () => {
          selected = null;
          hidden.value = '';
          labelEl.textContent = hiddenId === 'thDateFrom' ? 'From' : 'To';
          labelEl.classList.add('is-empty');
          details.removeAttribute('open');
        });
        panel.querySelector('[data-action="today"]').addEventListener('click', () => {
          selected = new Date();
          viewDate = new Date(selected);
          hidden.value = fmt(selected);
          labelEl.textContent = label(selected);
          labelEl.classList.remove('is-empty');
          details.removeAttribute('open');
        });
      }

      details.addEventListener('toggle', () => { if (details.open) render(); });
    }

    setupPicker('thDpFrom', 'thDateFrom');
    setupPicker('thDpTo', 'thDateTo');
  })();

  // Gentle count-up animation for the stat cards on load
  (function(){
    document.querySelectorAll('.stat-value[data-count]').forEach((el, i) => {
      const target = parseFloat(el.dataset.count) || 0;
      const prefix = el.dataset.prefix || '';
      const decimals = parseInt(el.dataset.decimals || '0', 10);
      const duration = 650;
      const delay = 80 + i * 60;
      const start = performance.now() + delay;

      function frame(now){
        const elapsed = now - start;
        if (elapsed < 0) { requestAnimationFrame(frame); return; }
        const t = Math.min(1, elapsed / duration);
        const eased = 1 - Math.pow(1 - t, 3);
        const val = target * eased;
        el.textContent = prefix + val.toLocaleString('en-US', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        });
        if (t < 1) requestAnimationFrame(frame);
      }
      requestAnimationFrame(frame);
    });
  })();
</script>
</body>
</html>
