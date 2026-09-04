<?php
require_once __DIR__ . '/../admin/Permissions.php';
require_hr_login();
require_permission('manage_employees'); // admin + manager, same gate as Employee Records
require_once __DIR__ . '/../includes/DB_Connect.php';
require_once __DIR__ . '/../includes/HR_Reference_Data.php';

// Reads hr_positions only — this page grants nothing and changes nothing.
// It renders the reporting structure defined by
// hr_positions.reports_to_position_id.

$active_page = 'hr_org_chart';

// ── Branch filter ────────────────────────────────────────────────
// Head-office positions (is_store_level = 0) sit above the branches, so
// their headcount is never filtered — only store-level counts narrow.
$branches   = [];
$branch_res = $conn ? mysqli_query($conn,
    "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name") : false;
if ($branch_res) while ($b = mysqli_fetch_assoc($branch_res)) $branches[] = $b;

$branch_f = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
$valid_branch_ids = array_column($branches, 'branch_id');
if ($branch_f && !in_array((string)$branch_f, array_map('strval', $valid_branch_ids), true)) {
    $branch_f = 0;
}

// ── Headcount per position ───────────────────────────────────────
// Counts active employees only. Store-level counts respect the branch
// filter; head-office counts always show the full number.
$headcount = [];
if ($conn) {
    $sql = "SELECT e.position_id, COUNT(*) AS n
              FROM employees e
              JOIN users u        ON u.user_id     = e.employee_id
              JOIN hr_positions p ON p.position_id = e.position_id
             WHERE e.employment_status = 'active'
               AND u.status = 'active'";
    if ($branch_f) {
        $sql .= " AND (p.is_store_level = 0 OR u.branch_id = " . (int)$branch_f . ")";
    }
    $sql .= " GROUP BY e.position_id";

    $hres = mysqli_query($conn, $sql);
    if ($hres === false) {
        error_log('Org_Chart_Page headcount query failed: ' . mysqli_error($conn));
    } else {
        while ($h = mysqli_fetch_assoc($hres)) $headcount[(int)$h['position_id']] = (int)$h['n'];
    }
}

$positions = hr_all_positions($conn);
$roots     = hr_root_positions($conn);

/**
 * Renders a position and everything beneath it as nested <ul>s.
 * $depth guards against a cycle surviving a hand-edit of the table —
 * the FK stops self-reference but not a longer A -> B -> A loop.
 */
function org_node(array $pos, array $headcount, $conn, int $depth = 0): string
{
    if ($depth > 8) return '';

    $count = $headcount[$pos['position_id']] ?? 0;
    $cls   = 'org-box' . ($pos['is_store_level'] ? '' : ' org-box-corp')
                       . ($pos['reports_to_position_id'] === null ? ' org-box-root' : '');

    $html  = '<li>';
    $html .= '<div class="' . $cls . '">';
    $html .= '<div class="org-title">' . htmlspecialchars($pos['position_name']) . '</div>';
    $html .= '<div class="org-meta">' . htmlspecialchars($pos['department']) . '</div>';
    $html .= '<div class="org-count' . ($count ? '' : ' org-count-empty') . '">'
           . ($count ? $count . ' ' . ($count === 1 ? 'person' : 'people') : 'Vacant')
           . '</div>';
    $html .= '</div>';

    $children = hr_direct_reports($pos['position_id'], $conn);
    if ($children) {
        $html .= '<ul>';
        foreach ($children as $child) $html .= org_node($child, $headcount, $conn, $depth + 1);
        $html .= '</ul>';
    }
    $html .= '</li>';
    return $html;
}

$total_positions = count($positions);
$total_filled    = array_sum($headcount);
$vacant          = 0;
foreach ($positions as $p) if (empty($headcount[$p['position_id']])) $vacant++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Org Chart — Cloud Cup HR</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="../css/hr_module.css"/>
  <style>
    /* Vertical tree built from nested lists. The connectors are drawn with
       borders on ::before / ::after rather than SVG, so the whole thing
       reflows on its own when a position is added to the table. */
    .org-scroll { overflow-x: auto; padding: 8px 4px 24px; }
    .org-tree, .org-tree ul { list-style: none; margin: 0; padding: 0; }
    .org-tree { display: flex; justify-content: center; min-width: max-content; }
    .org-tree ul { display: flex; justify-content: center; padding-top: 24px; }
    .org-tree li {
      position: relative;
      padding: 24px 10px 0;
      text-align: center;
    }

    /* Vertical stub rising from each child up to the horizontal rail */
    .org-tree li::before {
      content: ''; position: absolute; top: 0; left: 50%;
      width: 1px; height: 24px; background: rgba(44,92,130,.22);
    }
    /* Horizontal rail spanning the sibling group */
    .org-tree li::after {
      content: ''; position: absolute; top: 0; height: 1px;
      background: rgba(44,92,130,.22);
      left: 0; right: 0;
    }
    .org-tree li:first-child::after { left: 50%; }
    .org-tree li:last-child::after  { right: 50%; }
    .org-tree li:only-child::after  { display: none; }
    /* The root has nothing above it to connect to */
    .org-tree > li { padding-top: 0; }
    .org-tree > li::before, .org-tree > li::after { display: none; }
    /* Vertical stub dropping from a parent down to its children's rail */
    .org-tree ul::before {
      content: ''; position: absolute; top: 0; left: 50%;
      width: 1px; height: 24px; background: rgba(44,92,130,.22);
    }
    .org-tree ul { position: relative; }

    .org-box {
      display: inline-block; min-width: 148px; max-width: 190px;
      background: #fff; border: 1px solid rgba(44,92,130,.14);
      border-radius: 11px; padding: 11px 13px;
      box-shadow: 0 1px 3px rgba(22,51,77,.05);
      text-align: left;
    }
    /* Head-office positions read differently from store positions at a
       glance — the two branches of the chart are governed separately. */
    .org-box-corp { background: rgba(59,130,192,.05); border-style: dashed; }
    .org-box-root { border-color: var(--caramel); border-width: 1.5px; }

    .org-title { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.3; }
    .org-meta  { font-size: 11px; color: var(--text-light); margin-top: 3px; }
    .org-count {
      display: inline-block; font-size: 10.5px; font-weight: 600;
      letter-spacing: .02em; margin-top: 7px; padding: 2px 8px;
      border-radius: 99px; background: rgba(34,197,94,.1); color: var(--success);
    }
    .org-count-empty { background: rgba(245,158,11,.1); color: var(--warning); }

    .org-legend { display: flex; gap: 18px; flex-wrap: wrap; font-size: 12px; color: var(--text-light); margin-bottom: 4px; }
    .org-legend span { display: flex; align-items: center; gap: 6px; }
    .org-swatch { width: 13px; height: 13px; border-radius: 4px; border: 1px solid rgba(44,92,130,.14); background: #fff; }
    .org-swatch-corp { background: rgba(59,130,192,.05); border-style: dashed; }

    .org-filter { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .org-filter select { padding: 8px 12px; border-radius: 9px; border: 1px solid rgba(44,92,130,.15); font-family: inherit; font-size: 13px; }

    @media (max-width: 900px) {
      .org-box { min-width: 128px; }
      .org-title { font-size: 12px; }
    }
  </style>
</head>
<body>

<?php require_once '../HR/Sidebar_HR.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <h1>Org Chart</h1>
    </div>
    <div class="topbar-right"><div class="topbar-date"><?= date('F j, Y') ?></div></div>
  </div>

  <div class="content">
    <?php if (empty($positions)): ?>
      <div class="msg-banner error">
        No positions found. Run <code>migrations/2026_08_27_hr_positions.sql</code> to create and populate the <code>hr_positions</code> table.
      </div>
    <?php else: ?>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Defined Positions</div>
        <div class="kpi-value"><?= $total_positions ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Filled Seats</div>
        <div class="kpi-value"><?= $total_filled ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Positions With No One</div>
        <div class="kpi-value"><?= $vacant ?></div>
      </div>
    </div>

    <div class="widget">
      <div class="widget-header">
        <div class="widget-title">Reporting Structure</div>
        <?php if ($branches): ?>
        <form method="GET" class="org-filter">
          <label for="branch" style="font-size:12.5px;color:var(--text-light)">Headcount for</label>
          <select name="branch" id="branch" onchange="this.form.submit()">
            <option value="0">All branches</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['branch_id'] ?>" <?= $branch_f === (int)$b['branch_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>

      <div class="org-legend">
        <span><i class="org-swatch"></i> Store level — one per branch</span>
        <span><i class="org-swatch org-swatch-corp"></i> Head office — one across the company</span>
      </div>

      <?php if ($branch_f): ?>
        <div class="msg-banner" style="background:rgba(59,130,192,.08);color:#2c5f86">
          Showing store-level headcount for one branch. Head-office counts stay company-wide.
        </div>
      <?php endif; ?>

      <div class="org-scroll">
        <ul class="org-tree">
          <?php foreach ($roots as $root) echo org_node($root, $headcount, $conn); ?>
        </ul>
      </div>

      <?php
        // A position whose parent was deleted (FK is ON DELETE SET NULL)
        // becomes a second root and would otherwise vanish from the tree.
        $orphans = [];
        foreach ($roots as $r) if ($r['position_id'] !== 1) $orphans[] = $r['position_name'];
        if (count($roots) > 1):
      ?>
        <div class="msg-banner error" style="margin:16px 0 0">
          More than one position reports to nobody: <?= htmlspecialchars(implode(', ', $orphans)) ?>.
          Set a <code>reports_to_position_id</code> for these in <code>hr_positions</code>, or they will keep rendering as separate trees.
        </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>
</div>

<script src="../js/msg_banner_autodismiss.js"></script>
</body>
</html>
