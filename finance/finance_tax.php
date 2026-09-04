<?php
/**
 * CloudCup — Finance: Annual Tax
 * Not range-filtered like the other reports — tax is a yearly
 * obligation, so this page is driven by the Tax Year picker below
 * instead of the date-range filter bar the other reports use.
 */
require __DIR__ . '/includes/finance_data.php';
require __DIR__ . '/includes/tax_data.php';

$activePage = 'tax';
$pageTitle  = 'Finance — Annual Tax';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Annual Tax — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="section-header" style="margin-top:0;">
        <h2>Annual Tax</h2>
        <div class="line"></div>
        <a class="btn-export" href="finance_export.php?report=tax&year=<?= (int) $taxYear ?>">⭳ Export to Excel</a>
      </div>
      <div class="panel-sub" style="margin:-10px 0 20px 2px;">
        The tax the shop pays annually — Percentage Tax (ETP), computed per calendar year and independent of the
        date-range filter the other reports use.
      </div>

      <form method="get" style="margin-bottom:18px;">
        <label class="muted" style="font-size:12.5px; font-weight:700; margin-right:8px;">Tax Year</label>
        <select name="year" onchange="this.form.submit()" style="padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:13px;">
          <?php foreach ($taxYearOptions as $y): ?>
            <option value="<?= (int) $y ?>" <?= (int) $y === $taxYear ? 'selected' : '' ?>><?= (int) $y ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">ANNUAL GROSS SALES</div></div>
            <div class="kpi-icon icon-blue">₱</div>
          </div>
          <div class="kpi-value"><?= money($annualGross) ?></div>
          <div class="kpi-sub">calendar year <?= (int) $taxYear ?></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">ANNUAL TAX DUE (3%)</div></div>
            <div class="kpi-icon icon-red">₱</div>
          </div>
          <div class="kpi-value value-red"><?= money($taxDue) ?></div>
          <div class="kpi-sub">Percentage Tax, NIRC Sec. 116</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-title">Monthly Gross Sales — <?= (int) $taxYear ?></div>
        <table>
          <thead><tr><th>Month</th><th style="text-align:right;">Gross Sales</th></tr></thead>
          <tbody>
            <?php foreach ($monthlyRevenue as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['label']) ?></td>
              <td class="num"><?= money($m['total']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td><strong>Annual Total</strong></td><td class="num"><strong><?= money($annualGross) ?></strong></td></tr>
          </tbody>
        </table>
      </div>

      <div class="footnote">
        Computed as 3% of annual gross sales (Percentage Tax, NIRC Sec. 116, as amended by the CREATE Act) — there is
        no deduction subtracted from gross sales under this tax. Percentage Tax is legally filed and remitted
        quarterly via BIR Form 2551Q, not annually; the figure above rolls the year's quarters up for planning
        visibility only. This is a planning estimate for owner/finance visibility, not a BIR filing — confirm the
        applicable rate and any VAT-registration requirement with an accountant before remitting.
      </div>

    </div>
  </div>
</body>
</html>
