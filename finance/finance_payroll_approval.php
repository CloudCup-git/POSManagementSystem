<?php
/**
 * CloudCup — Finance: Cash Flow Management
 * Page: Payroll and Employee Loans (Payslip Approvals)
 * -------------------------------------------------------------
 * Lists every payslip HR has computed and saved as a DRAFT
 * (Payroll_Page.php on the HR side), with a full earnings/
 * deductions breakdown identical to the HR payslip preview.
 * Finance reviews each one and Approves & Releases it here —
 * HR no longer has a Release action, only display.
 *
 * Also shows Active Employee Loans for context, since loan
 * installments are one of the deduction lines on each payslip.
 * -------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/config.php'; // creates $pdo (PDO) + $conn (mysqli), via includes/DB_Connect.php

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['finance', 'admin', 'manager'], true)) {
    header('Location: ../auth/Login_Page.php');
    exit;
}

$activePage  = 'cfm_approval';
$pageTitle   = 'Cash Flow Management — Payroll & Employee Loans';
$rangeQuery  = ''; // this page isn't date-range filtered; sidebar just needs the var defined
$currentUser = $_SESSION['full_name'] ?? 'Finance User';
$msg = '';

function money($n) { return '₱' . number_format((float) $n, 2); }

/* -----------------------------------------------------------
   Approve & Release a single payslip
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'approve_release') {
    $pid = (int) ($_POST['payroll_id'] ?? 0);
    if ($pid) {
        $stmt = $pdo->prepare("UPDATE payroll SET status = 'released' WHERE payroll_id = :id AND status = 'draft'");
        $stmt->execute([':id' => $pid]);
        $msg = $stmt->rowCount()
            ? 'success:Payslip approved and released to the employee.'
            : 'error:That payslip was already released (or no longer exists).';
    }
}

/* -----------------------------------------------------------
   Draft payslips awaiting approval
----------------------------------------------------------- */
$draftRows = $pdo->query("
    SELECT p.*, u.full_name
    FROM payroll p
    JOIN users u ON u.user_id = p.employee_id
    WHERE p.status = 'draft'
    ORDER BY p.period_end DESC, u.full_name
")->fetchAll();

/* -----------------------------------------------------------
   Active employee loans (context — loan_deduction on payslips
   pays these down)
----------------------------------------------------------- */
$loanRows = $pdo->query("
    SELECT l.loan_id, u.full_name, l.principal, l.monthly_installment,
           l.remaining_balance, l.status, l.created_at
    FROM loans l
    JOIN users u ON l.employee_id = u.user_id
    WHERE l.status = 'active'
    ORDER BY l.created_at DESC
")->fetchAll();
$outstandingLoans = array_sum(array_column($loanRows, 'remaining_balance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<script src="../js/sidebar-toggle.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll & Employee Loans — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<style>
  .status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
  .pill-draft{background:#f4e3d3;color:#b8703f;}
  .pill-released{background:#E6F4EA;color:#2f6f4e;}
  .breakdown-row{display:none;background:#FAFAFA;}
  .breakdown-row.open{display:table-row;}
  .breakdown-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;padding:14px 18px;font-size:13px;}
  .breakdown-grid .lbl{color:#8A8A8A;}
  .breakdown-grid .amt{text-align:right;font-weight:600;}
  .breakdown-total{border-top:1px solid #E5E5E5;margin-top:6px;padding-top:6px;font-weight:700;}
</style>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">PENDING APPROVAL</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money(array_sum(array_column($draftRows, 'net_pay'))) ?></div>
          <div class="kpi-sub"><?= count($draftRows) ?> draft payslip<?= count($draftRows) === 1 ? '' : 's' ?> waiting</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">OUTSTANDING LOANS</div></div>
            <div class="kpi-icon icon-orange">₱</div>
          </div>
          <div class="kpi-value"><?= money($outstandingLoans) ?></div>
          <div class="kpi-sub"><?= count($loanRows) ?> active employee loan<?= count($loanRows) === 1 ? '' : 's' ?></div>
        </div>
      </div>

      <div class="section-header"><h2>Payslip Approvals</h2><div class="line"></div></div>
      <div class="panel">
        <div class="panel-sub">Draft payslips computed by HR. Review the breakdown, then approve to release to the employee.</div>
        <?php if ($draftRows): ?>
        <table>
          <thead><tr><th></th><th>Employee</th><th>Period</th><th style="text-align:right;">Gross</th><th style="text-align:right;">Deductions</th><th style="text-align:right;">Net Pay</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($draftRows as $i => $p): ?>
            <tr>
              <td><button type="button" class="btn btn-ghost btn-sm" onclick="toggleBreakdown(<?= $i ?>)">Details</button></td>
              <td><?= htmlspecialchars($p['full_name']) ?></td>
              <td class="muted"><?= (new DateTime($p['period_start']))->format('M d') ?>–<?= (new DateTime($p['period_end']))->format('M d, Y') ?></td>
              <td class="num"><?= money($p['gross_pay']) ?></td>
              <td class="num"><?= money($p['total_deductions']) ?></td>
              <td class="num"><?= money($p['net_pay']) ?></td>
              <td><span class="status-pill pill-draft">Draft</span></td>
              <td>
                <form method="POST" class="js-approve-form">
                  <input type="hidden" name="act" value="approve_release">
                  <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Approve &amp; Release</button>
                </form>
              </td>
            </tr>
            <tr class="breakdown-row" id="bd-<?= $i ?>">
              <td colspan="8">
                <div class="breakdown-grid">
                  <div class="lbl">Basic Pay</div><div class="amt"><?= money($p['basic_pay']) ?></div>
                  <div class="lbl">Overtime Pay</div><div class="amt"><?= money($p['overtime_pay']) ?></div>
                  <div class="lbl">Allowance / Paid Leave</div><div class="amt"><?= money($p['allowance']) ?></div>
                  <div class="lbl" style="font-weight:700;">Gross Pay</div><div class="amt breakdown-total"><?= money($p['gross_pay']) ?></div>

                  <div class="lbl">SSS</div><div class="amt"><?= money($p['sss_employee']) ?></div>
                  <div class="lbl">PhilHealth</div><div class="amt"><?= money($p['philhealth_employee']) ?></div>
                  <div class="lbl">Pag-IBIG</div><div class="amt"><?= money($p['pagibig_employee']) ?></div>
                  <div class="lbl">Withholding Tax</div><div class="amt"><?= money($p['income_tax']) ?></div>
                  <div class="lbl">Late / Tardiness</div><div class="amt"><?= money($p['late_deduction']) ?></div>
                  <div class="lbl">Absences</div><div class="amt"><?= money($p['absence_deduction']) ?></div>
                  <div class="lbl">Loan Deduction</div><div class="amt"><?= money($p['loan_deduction']) ?></div>
                  <div class="lbl" style="font-weight:700;">Total Deductions</div><div class="amt breakdown-total"><?= money($p['total_deductions']) ?></div>

                  <div class="lbl" style="font-weight:700;font-size:14px;">Net Pay (actual salary)</div>
                  <div class="amt breakdown-total" style="font-size:14px;"><?= money($p['net_pay']) ?></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No draft payslips waiting for approval right now.</div>
        <?php endif; ?>
      </div>

      <div class="section-header" style="margin-top:24px;"><h2>Active Employee Loans</h2><div class="line"></div></div>
      <div class="panel">
        <?php if ($loanRows): ?>
        <table>
          <thead><tr><th>Employee</th><th style="text-align:right;">Principal</th><th style="text-align:right;">Monthly</th><th style="text-align:right;">Balance</th></tr></thead>
          <tbody>
            <?php foreach ($loanRows as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['full_name']) ?></td>
              <td class="num"><?= money($l['principal']) ?></td>
              <td class="num"><?= money($l['monthly_installment']) ?></td>
              <td class="num"><?= money($l['remaining_balance']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No active employee loans.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<script>
function toggleBreakdown(i) {
  document.getElementById('bd-' + i).classList.toggle('open');
}

document.querySelectorAll('.js-approve-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'Approve & release this payslip?',
      text: 'This will release it to the employee.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, approve & release',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2f6f4e',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) form.submit();
    });
  });
});

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