<?php
/**
 * CloudCup — Finance: Cash Flow Management
 * Page: Payroll Processing
 * -------------------------------------------------------------
 * The batch/distribution step: shows the totals for every draft
 * payslip currently pending (what would go out if processed
 * right now — gross, each deduction type summed, employer cost,
 * net cash to distribute) and lets Finance process the whole
 * batch in one action, releasing every draft payslip at once.
 *
 * For approving/releasing payslips ONE AT A TIME with a full
 * per-employee breakdown, use finance_payroll_approval.php
 * ("Payroll and Employee Loans") instead — this page is for
 * the bulk distribution run.
 * -------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin', 'manager'], true)) {
    header('Location: ../auth/Login_Page.php');
    exit;
}

$activePage  = 'cfm_processing';
$pageTitle   = 'Cash Flow Management — Payroll Processing';
$rangeQuery  = '';
$currentUser = $_SESSION['full_name'] ?? 'Finance User';
$msg = '';

function money($n) { return '₱' . number_format((float) $n, 2); }

/* -----------------------------------------------------------
   Process (release) every draft payslip in the current batch
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'process_batch') {
    $ids = array_map('intval', $_POST['payroll_ids'] ?? []);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE payroll SET status = 'released' WHERE status = 'draft' AND payroll_id IN ($placeholders)");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        $msg = $count
            ? "success:Processed and distributed $count payslip" . ($count === 1 ? '' : 's') . '.'
            : 'error:Nothing to process — those payslips were already released.';
    } else {
        $msg = 'error:No pending payslips in this batch.';
    }
}

/* -----------------------------------------------------------
   Current batch: every draft payslip still pending
----------------------------------------------------------- */
$batchRows = $pdo->query("
    SELECT p.*, u.full_name
    FROM payroll p
    JOIN users u ON u.user_id = p.employee_id
    WHERE p.status = 'draft'
    ORDER BY u.full_name
")->fetchAll();

$sum = function (string $col) use ($batchRows) {
    return array_sum(array_column($batchRows, $col));
};

$totalGross            = $sum('gross_pay');
$totalSss              = $sum('sss_employee');
$totalPhilhealth       = $sum('philhealth_employee');
$totalPagibig          = $sum('pagibig_employee');
$totalTax              = $sum('income_tax');
$totalLate             = $sum('late_deduction');
$totalAbsence          = $sum('absence_deduction');
$totalLoan             = $sum('loan_deduction');
$totalDeductions       = $sum('total_deductions');
$totalNet              = $sum('net_pay');
$totalEmployerSss      = $sum('employer_sss');
$totalEmployerPhic     = $sum('employer_philhealth');
$totalEmployerPagibig  = $sum('employer_pagibig');
$totalEmployerCost     = $totalEmployerSss + $totalEmployerPhic + $totalEmployerPagibig;
$totalPayrollCost      = $totalGross + $totalEmployerCost; // what it actually costs the business
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll Processing — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  @keyframes btnProcessGlow{
    0%,100%{ box-shadow:0 4px 10px rgba(63,93,95,.28), 0 0 0 0 rgba(98,142,144,.35); }
    50%{ box-shadow:0 4px 10px rgba(63,93,95,.28), 0 0 0 6px rgba(98,142,144,0); }
  }
  .btn-process-batch{
    position:relative; overflow:hidden; isolation:isolate;
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px;
    border:none; border-radius:9px;
    background:linear-gradient(135deg, var(--navy-800), var(--blue-600));
    background-size:160% 160%;
    background-position:0% 50%;
    color:#fff; font-size:13px; font-weight:700; letter-spacing:.2px;
    font-family:'Inter', sans-serif;
    cursor:pointer;
    box-shadow:0 4px 10px rgba(63,93,95,.28);
    transition:transform .18s ease, box-shadow .18s ease, background-position .5s ease;
    animation:btnProcessGlow 2.6s ease-in-out infinite;
  }
  .btn-process-batch:hover{
    transform:translateY(-1px);
    background-position:100% 50%;
    box-shadow:0 7px 16px rgba(63,93,95,.38);
    animation-play-state:paused;
  }
  .btn-process-batch:active{
    transform:translateY(0) scale(.97);
    box-shadow:0 4px 10px rgba(63,93,95,.3);
  }
  .btn-process-batch:disabled{
    opacity:.5; cursor:not-allowed; animation:none; transform:none;
    box-shadow:none; background:var(--text-muted);
  }
  .btn-process-batch .shine{
    position:absolute; top:0; left:-60%; width:35%; height:100%;
    background:linear-gradient(120deg, transparent, rgba(255,255,255,.4), transparent);
    transform:skewX(-20deg);
    transition:left .65s ease;
    pointer-events:none;
  }
  .btn-process-batch:hover .shine{ left:130%; }
  .btn-process-icon{ transition:transform .25s ease; flex-shrink:0; }
  .btn-process-batch:hover .btn-process-icon{ transform:translateX(3px); }

  /* ---------- Payroll Processing: KPI hero layout ---------- */
  .payroll-kpi-row{
    display:grid;
    grid-template-columns:1.3fr 1fr;
    gap:16px;
    margin-bottom:24px;
  }
  @media (max-width:900px){ .payroll-kpi-row{ grid-template-columns:1fr; } }

  .payroll-hero{
    position:relative; overflow:hidden;
    background:linear-gradient(135deg, var(--navy-800), var(--blue-600));
    border-radius:var(--radius);
    padding:26px 28px;
    color:#fff;
    display:flex; flex-direction:column; justify-content:center;
  }
  .payroll-hero::after{
    content:'₱'; position:absolute; right:14px; bottom:-26px;
    font-size:150px; font-weight:800; line-height:1;
    color:rgba(255,255,255,.07);
    font-family:'Inter', sans-serif;
    pointer-events:none;
  }
  .payroll-hero-label{
    font-size:12.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    color:rgba(255,255,255,.7); margin-bottom:10px;
  }
  .payroll-hero-value{
    font-family:'Inter', sans-serif; font-size:42px; font-weight:800; line-height:1.1;
  }
  .payroll-hero-sub{
    margin-top:10px; font-size:13px; font-weight:600; color:rgba(255,255,255,.75);
    position:relative; z-index:1;
  }

  .payroll-kpi-stack{
    display:flex; flex-direction:column; gap:10px;
  }
  .payroll-kpi-row-item{
    flex:1;
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:12px;
    padding:14px 16px;
    display:flex; align-items:center; gap:14px;
  }
  .payroll-kpi-row-icon{
    width:36px; height:36px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:15px;
  }
  .payroll-kpi-row-text{ flex:1; min-width:0; }
  .payroll-kpi-row-label{
    font-size:11.5px; font-weight:700; color:var(--text-muted); letter-spacing:.02em;
  }
  .payroll-kpi-row-value{
    font-family:'Inter', sans-serif; font-size:19px; font-weight:700; color:var(--navy-900);
  }
  .payroll-kpi-row-note{ font-size:11px; color:var(--text-muted); font-weight:600; margin-top:1px; }
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="payroll-kpi-row">
        <div class="payroll-hero">
          <div class="payroll-hero-label">Net Cash to Distribute</div>
          <div class="payroll-hero-value"><?= money($totalNet) ?></div>
          <div class="payroll-hero-sub"><?= count($batchRows) ?> employee<?= count($batchRows) === 1 ? '' : 's' ?> in this batch, ready to release</div>
        </div>
        <div class="payroll-kpi-stack">
          <div class="payroll-kpi-row-item">
            <div class="payroll-kpi-row-icon icon-orange">₱</div>
            <div class="payroll-kpi-row-text">
              <div class="payroll-kpi-row-label">TOTAL GROSS WAGES</div>
              <div class="payroll-kpi-row-value"><?= money($totalGross) ?></div>
            </div>
          </div>
          <div class="payroll-kpi-row-item">
            <div class="payroll-kpi-row-icon icon-red">₱</div>
            <div class="payroll-kpi-row-text">
              <div class="payroll-kpi-row-label">TOTAL DEDUCTIONS</div>
              <div class="payroll-kpi-row-value"><?= money($totalDeductions) ?></div>
            </div>
          </div>
          <div class="payroll-kpi-row-item">
            <div class="payroll-kpi-row-icon icon-orange">₱</div>
            <div class="payroll-kpi-row-text">
              <div class="payroll-kpi-row-label">TOTAL COST TO BUSINESS</div>
              <div class="payroll-kpi-row-value"><?= money($totalPayrollCost) ?></div>
              <div class="payroll-kpi-row-note">wages + employer SSS/PhilHealth/Pag-IBIG</div>
            </div>
          </div>
        </div>
      </div>

      <div class="section-header"><h2>Batch Breakdown — All Pending Payslips</h2><div class="line"></div></div>
      <div class="panel">
        <div class="panel-sub">Combined deduction totals across every draft payslip. This is what gets distributed when you process the batch below.</div>
        <table>
          <thead><tr><th>Line</th><th style="text-align:right;">Amount</th></tr></thead>
          <tbody>
            <tr><td>SSS (employee)</td><td class="num"><?= money($totalSss) ?></td></tr>
            <tr><td>PhilHealth (employee)</td><td class="num"><?= money($totalPhilhealth) ?></td></tr>
            <tr><td>Pag-IBIG (employee)</td><td class="num"><?= money($totalPagibig) ?></td></tr>
            <tr><td>Withholding Tax</td><td class="num"><?= money($totalTax) ?></td></tr>
            <tr><td>Late / Tardiness</td><td class="num"><?= money($totalLate) ?></td></tr>
            <tr><td>Absences</td><td class="num"><?= money($totalAbsence) ?></td></tr>
            <tr><td>Loan Deductions</td><td class="num"><?= money($totalLoan) ?></td></tr>
            <tr style="font-weight:700;border-top:2px solid #E5E5E5;"><td>Total Deductions</td><td class="num"><?= money($totalDeductions) ?></td></tr>
            <tr><td class="muted">Employer SSS/PhilHealth/Pag-IBIG contributions (not deducted from employee, added to business cost)</td><td class="num"><?= money($totalEmployerCost) ?></td></tr>
          </tbody>
        </table>
      </div>

      <div class="section-header" style="margin-top:24px;"><h2>Employees in This Batch</h2><div class="line"></div></div>
      <div class="panel">
        <?php if ($batchRows): ?>
        <form method="POST" id="processBatchForm">
          <input type="hidden" name="act" value="process_batch">
          <table>
            <thead><tr><th>Employee</th><th>Period</th><th style="text-align:right;">Gross</th><th style="text-align:right;">Deductions</th><th style="text-align:right;">Net Pay</th></tr></thead>
            <tbody>
              <?php foreach ($batchRows as $p): ?>
              <tr>
                <td>
                  <?= htmlspecialchars($p['full_name']) ?>
                  <input type="hidden" name="payroll_ids[]" value="<?= $p['payroll_id'] ?>">
                </td>
                <td class="muted"><?= (new DateTime($p['period_start']))->format('M d') ?>–<?= (new DateTime($p['period_end']))->format('M d, Y') ?></td>
                <td class="num"><?= money($p['gross_pay']) ?></td>
                <td class="num"><?= money($p['total_deductions']) ?></td>
                <td class="num"><?= money($p['net_pay']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-top:16px;text-align:right;">
            <button type="submit" class="btn-process-batch"<?= $totalNet <= 0 ? ' disabled' : '' ?>>
              <span class="shine"></span>
              <svg class="btn-process-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l16 8-16 8 4-8-4-8z"/></svg>
              Process &amp; Distribute Batch (<?= money($totalNet) ?>)
            </button>
          </div>
        </form>
        <?php else: ?>
          <div class="empty-state">No pending payslips to process right now.</div>
        <?php endif; ?>
      </div>

      <div class="footnote">
        Processing marks every payslip above as <strong>released</strong> — same effect as approving them individually on the
        "Payroll and Employee Loans" page, just done as one batch. Employer-side contributions are business cost and are not
        subtracted from what employees receive.
      </div>

    </div>
  </div>

<script>
var processBatchForm = document.getElementById('processBatchForm');
if (processBatchForm) {
  processBatchForm.addEventListener('submit', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'Process this batch?',
      text: 'Process and distribute wages for all <?= count($batchRows) ?> employee<?= count($batchRows) === 1 ? '' : 's' ?> in this batch? This releases every payslip listed below.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, process & distribute',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2f6f4e',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) processBatchForm.submit();
    });
  });
}

<?php if ($msg): [$type, $text] = explode(':', $msg, 2); ?>
Swal.fire({
  icon: '<?= $type === 'success' ? 'success' : 'error' ?>',
  title: '<?= $type === 'success' ? 'Success' : 'Error' ?>',
  text: <?= json_encode($text) ?>,
  timer: 3000,
  timerProgressBar: true,
  toast: true,
  position: 'top-end',
  showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>