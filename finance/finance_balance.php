<?php
/**
 * CloudCup — Finance: Balance Sheet
 * Snapshot as of today (not date-range filtered like the other tabs) —
 * a balance sheet is "what do we have / owe right now", not "what
 * happened this month". The range filter bar still shows at top for
 * nav consistency, but every figure on this page ignores it.
 */
require __DIR__ . '/includes/finance_data.php';

$activePage = 'balance';
$pageTitle  = 'Finance — Balance Sheet';

/* -----------------------------------------------------------
   Extra access gate — the Balance Sheet exposes owner capital,
   draws, and retained earnings, which is more sensitive than the
   day-to-day reports above it in the sidebar. Plain 'finance' staff
   can see Revenue/COGS/OpEx/Payroll/etc., but the Balance Sheet is
   restricted to Admin/Manager (owner-level) accounts only.
----------------------------------------------------------- */
if (!in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'manager'], true)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restricted — CloudCup Finance</title>
    <link rel="stylesheet" href="../css/admin_page.css">
    <link rel="stylesheet" href="css/finance.css">
    </head>
    <body>
      <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>
      <div class="main">
        <?php include __DIR__ . '/includes/finance_topbar.php'; ?>
        <div class="content">
          <div class="panel" style="margin-top:24px; border-color:var(--red-600); background:var(--red-50);">
            <strong style="color:var(--red-600);">Balance Sheet access is restricted.</strong>
            <p class="muted" style="margin:8px 0 0;">
              This report shows owner capital, draws, and retained earnings, so it's limited to Admin and Manager
              accounts. Contact an owner or manager if you need these figures.
            </p>
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Balance Sheet — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="section-header" style="margin-top:0;"><h2>Balance Sheet</h2><a class="btn-export" href="finance_export.php?report=balance&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
      <div class="panel-sub" style="margin:-10px 0 20px 2px;">
        As of <?= $today->format('M d, Y') ?> — this page shows current standing, not the date range above.
      </div>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">TOTAL ASSETS</div></div>
            <div class="kpi-icon icon-blue">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalAssets) ?></div>
          <div class="kpi-sub">cash + inventory + fixed assets + receivables</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">TOTAL LIABILITIES</div></div>
            <div class="kpi-icon icon-red">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalLiabilities) ?></div>
          <div class="kpi-sub"><?= count($liabilityRows) ?> unpaid item<?= count($liabilityRows) === 1 ? '' : 's' ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">TOTAL EQUITY</div></div>
            <div class="kpi-icon icon-green">₱</div>
          </div>
          <div class="kpi-value"><?= money($totalEquity) ?></div>
          <div class="kpi-sub">capital − draws + retained earnings</div>
        </div>
      </div>

      <?php if (abs($balanceCheckDiff) > 0.01): ?>
      <div class="panel" style="margin-bottom:16px; border-color:var(--red-600); background:var(--red-50);">
        <strong style="color:var(--red-600);">Out of balance by <?= money(abs($balanceCheckDiff)) ?>.</strong>
        <span class="muted">Assets should equal Liabilities + Equity. This usually means the opening cash balance
        in <code>finance_settings</code> hasn't been set yet, or some historical cash movement predates this system.</span>
      </div>
      <?php endif; ?>

      <div class="grid-2b">

        <!-- ===================== ASSETS ===================== -->
        <div class="panel">
          <div class="panel-title">Assets</div>
          <table>
            <tbody>
              <tr>
                <td>Cash on hand</td>
                <td class="num"><?= money($cashOnHand) ?></td>
              </tr>
              <tr>
                <td>Inventory value</td>
                <td class="num">
                  <?= $inventoryValue !== null ? money($inventoryValue) : '<span class="muted">not available</span>' ?>
                </td>
              </tr>
              <tr>
                <td>Fixed assets (net of depreciation)</td>
                <td class="num"><?= money($totalFixedAssetsNet) ?></td>
              </tr>
              <tr>
                <td>Employee loans receivable</td>
                <td class="num"><?= money($loansReceivable) ?></td>
              </tr>
              <tr>
                <td><strong>Total Assets</strong></td>
                <td class="num"><strong><?= money($totalAssets) ?></strong></td>
              </tr>
            </tbody>
          </table>

          <?php if ($inventoryValue === null): ?>
            <div class="footnote">
              Inventory value couldn't be read — this install's <code>inventory</code> table doesn't have a
              <code>qty_on_hand</code> column (or an equivalent). Point the query in
              <code>includes/finance_data.php</code> §9b at whatever column tracks current stock quantity.
            </div>
          <?php endif; ?>

          <?php if ($assetRows): ?>
          <div class="panel-title" style="margin-top:22px;">Fixed Assets Detail</div>
          <table>
            <thead><tr><th>Asset</th><th>Purchased</th><th style="text-align:right;">Cost</th><th style="text-align:right;">Book Value</th></tr></thead>
            <tbody>
              <?php foreach ($assetRows as $a): ?>
              <tr>
                <td><?= htmlspecialchars($a['name']) ?><div class="muted" style="font-size:11.5px;"><?= htmlspecialchars($a['category']) ?></div></td>
                <td class="muted"><?= (new DateTime($a['purchase_date']))->format('M d, Y') ?></td>
                <td class="num"><?= money($a['cost']) ?></td>
                <td class="num"><?= money($a['book_value']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

        <!-- =============== LIABILITIES + EQUITY =============== -->
        <div class="panel">
          <div class="panel-title">Liabilities</div>
          <?php if ($liabilityRows): ?>
          <table>
            <thead><tr><th>Payee</th><th>Type</th><th>Due</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($liabilityRows as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['payee']) ?></td>
                <td class="muted"><?= htmlspecialchars(str_replace('_', ' ', $l['liability_type'])) ?></td>
                <td class="muted"><?= $l['due_date'] ? (new DateTime($l['due_date']))->format('M d, Y') : '—' ?></td>
                <td class="num"><?= money($l['amount']) ?></td>
              </tr>
              <?php endforeach; ?>
              <tr><td colspan="3"><strong>Total Liabilities</strong></td><td class="num"><strong><?= money($totalLiabilities) ?></strong></td></tr>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">No unpaid liabilities logged. Add supplier bills, tax accruals, or business loans via the <code>liabilities</code> table.</div>
          <?php endif; ?>

          <div class="panel-title" style="margin-top:22px;">Owner's Equity</div>
          <table>
            <tbody>
              <tr>
                <td>Owner capital (contributions)</td>
                <td class="num"><?= money($ownerCapital) ?></td>
              </tr>
              <tr>
                <td>Owner draws</td>
                <td class="num value-red">− <?= money($ownerDraws) ?></td>
              </tr>
              <tr>
                <td>Retained earnings (all-time net profit)</td>
                <td class="num <?= $retainedEarnings >= 0 ? 'value-green' : 'value-red' ?>"><?= money($retainedEarnings) ?></td>
              </tr>
              <tr>
                <td><strong>Total Equity</strong></td>
                <td class="num"><strong><?= money($totalEquity) ?></strong></td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>

      <!-- ============= CLASSIFIED BREAKDOWN + RATIOS ============= -->
      <div class="section-header"><h2>Classified Breakdown &amp; Ratios</h2><div class="line"></div></div>
      <div class="grid-2b">
        <div class="panel">
          <div class="panel-title">Current vs. Non-Current</div>
          <table>
            <tbody>
              <tr>
                <td>Current Assets <span class="muted" style="font-size:11.5px;">(cash + inventory + receivables)</span></td>
                <td class="num"><?= money($totalCurrentAssets) ?></td>
              </tr>
              <tr>
                <td>Non-Current Assets <span class="muted" style="font-size:11.5px;">(fixed assets, net)</span></td>
                <td class="num"><?= money($totalFixedAssetsNet) ?></td>
              </tr>
              <tr>
                <td>Current Liabilities <span class="muted" style="font-size:11.5px;">(due within 12 months)</span></td>
                <td class="num"><?= money($totalCurrentLiabilities) ?></td>
              </tr>
              <tr>
                <td>Long-Term Liabilities <span class="muted" style="font-size:11.5px;">(due beyond 12 months)</span></td>
                <td class="num"><?= money($totalLongTermLiabilities) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="panel">
          <div class="panel-title">Key Ratios</div>
          <table>
            <tbody>
              <tr>
                <td>Current Ratio <span class="muted" style="font-size:11.5px;">(current assets ÷ current liabilities)</span></td>
                <td class="num"><?= $currentRatio !== null ? number_format($currentRatio, 2) : '—' ?></td>
              </tr>
              <tr>
                <td>Working Capital <span class="muted" style="font-size:11.5px;">(current assets − current liabilities)</span></td>
                <td class="num <?= $workingCapital >= 0 ? 'value-green' : 'value-red' ?>"><?= money($workingCapital) ?></td>
              </tr>
              <tr>
                <td>Debt-to-Equity <span class="muted" style="font-size:11.5px;">(total liabilities ÷ total equity)</span></td>
                <td class="num"><?= $debtToEquity !== null ? number_format($debtToEquity, 2) : '—' ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footnote">
        This is a simplified balance sheet meant for owner visibility, not statutory filing — talk to an accountant
        before using it for BIR submissions. Retained earnings and cash-on-hand are computed all-time from order
        and expense history; if you were tracking finances before this system existed, set
        <code>finance_settings.opening_cash_balance</code> to your true starting cash so the numbers reconcile.
      </div>

    </div>
  </div>
</body>
</html>
