<?php
/**
 * CloudCup — Finance: Cash Flow Reconciliation
 * -------------------------------------------------------------
 * Stage 2 of the Cash Flow workflow build (see finance_cashflow.php for
 * Stage 1's entry lifecycle). Compares what the system expects for a
 * channel/day against what was actually counted/settled/confirmed:
 *   - Cash: expected = POS cash sales for the day, actual = physical count.
 *   - GCash / E-wallet: expected = POS gcash/maya sales for the day,
 *     actual = the settlement amount that landed.
 *   - Bank Transfer: no POS-side source, so both expected (recorded
 *     deposits) and actual (bank-confirmed amount) are entered manually.
 * A non-zero variance requires an explanation and notifies the Finance
 * Head; the Finance Head must Resolve it before finance_cashflow.php's
 * Close Period will allow that date range to close (see the gate added
 * to finance_cashflow.php's close_period handler).
 * -------------------------------------------------------------
 */
require __DIR__ . '/includes/finance_data.php';
require_once __DIR__ . '/../includes/cashflow_queries.php';

ensure_cashflow_tables($conn);

$activePage = 'cf_reconcile';
$pageTitle  = 'Finance — Cash Reconciliation';

$cfUser  = cashflow_current_user();
$cfStage = $cfUser ? cashflow_stage_for_user($cfUser) : null;
$isOfficer = $cfStage === CF_STAGE_FINANCE_OFFICER;
$isHead    = $cfStage === CF_STAGE_FINANCE_HEAD;
$myUserId  = $cfUser['user_id'] ?? 0;
$canRecord = $isOfficer || $isHead;

$msg = '';
$channelLabels = ['cash' => 'Cash', 'gcash_ewallet' => 'GCash / E-wallet', 'bank_transfer' => 'Bank Transfer'];
$statusLabels  = ['matched' => 'Matched', 'discrepancy' => 'Discrepancy', 'resolved' => 'Resolved'];

/* -----------------------------------------------------------
   RECORD a reconciliation (Officer or Head)
----------------------------------------------------------- */
$formHold = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'record_reconciliation') {
    if (!$canRecord) {
        $msg = 'error:Insufficient permission: only the Finance Officer or Finance Head can record a reconciliation.';
    } else {
        $channel = $_POST['channel'] ?? '';
        $date    = trim((string) ($_POST['business_date'] ?? ''));
        $actualRaw = trim((string) ($_POST['actual_amount'] ?? ''));
        $explanation = trim((string) ($_POST['explanation'] ?? ''));
        $settlementRef = trim((string) ($_POST['settlement_reference'] ?? ''));
        $expectedManualRaw = trim((string) ($_POST['expected_amount_manual'] ?? ''));

        $errors = [];
        if (!isset($channelLabels[$channel])) $errors[] = 'Please select a reconciliation channel.';
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) $errors[] = 'Please enter a valid business date.';
        if ($actualRaw === '' || !is_numeric($actualRaw)) $errors[] = 'Actual cash count is required to complete reconciliation.';
        elseif (!preg_match('/^\d+(\.\d{1,2})?$/', ltrim($actualRaw, '-'))) $errors[] = 'Amount must have at most two decimal places.';

        $attachment = null;
        if ($channel === 'bank_transfer') {
            if ($expectedManualRaw === '' || !is_numeric($expectedManualRaw)) $errors[] = 'Please enter the recorded (expected) bank amount.';
            if (empty($_FILES['proof']['name'])) {
                $errors[] = 'A receipt, invoice, or supporting attachment is required before submission for a bank reconciliation.';
            }
        }

        if (!$errors && $d) {
            $expected = $channel === 'bank_transfer' ? (float) $expectedManualRaw : cashflow_expected_amount($conn, $channel, $date);
            $actual   = round((float) $actualRaw, 2);
            $variance = round($actual - (float) $expected, 2);
            $status   = abs($variance) < 0.005 ? 'matched' : 'discrepancy';

            if ($status === 'discrepancy' && $explanation === '') {
                $errors[] = 'Please submit an explanation for this discrepancy.';
            }
        }

        if ($channel === 'bank_transfer' && !$errors && !empty($_FILES['proof']['name'])) {
            $up = cashflow_handle_upload($_FILES['proof']);
            if (!$up['ok']) { $errors[] = $up['error']; } else { $attachment = $up['path']; }
        }

        if ($errors) {
            $msg = 'error:' . implode(' ', $errors);
            $formHold = $_POST;
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO cash_flow_reconciliation
                (channel, business_date, expected_amount, actual_amount, variance, settlement_reference, proof_attachment_path, status, explanation, created_by, created_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $settlementRefToStore = $settlementRef !== '' ? $settlementRef : null;
            $explanationToStore = $explanation !== '' ? $explanation : null;
            mysqli_stmt_bind_param($stmt, 'ssdddssssis', $channel, $date, $expected, $actual, $variance, $settlementRefToStore, $attachment, $status, $explanationToStore, $myUserId, $cfUser['full_name']);

            if (!mysqli_stmt_execute($stmt)) {
                // Most likely the UNIQUE KEY (channel, business_date) — a
                // reconciliation for this channel/day already exists.
                $msg = 'error:A reconciliation for this channel and date may already exist. Review the existing record before continuing.';
            } else {
                $reconId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                cashflow_log_reconciliation_audit($conn, $reconId, $myUserId, $cfUser['full_name'], 'recorded', null, $status, ucfirst($channelLabels[$channel]) . ' reconciliation for ' . $date . ' recorded.');

                $variancePretty = ($variance >= 0 ? '+' : '−') . money(abs($variance));
                if ($status === 'matched') {
                    $msg = 'success:Cash count was recorded successfully. Expected: ' . money($expected) . '. Actual: ' . money($actual) . '. Variance: ' . $variancePretty . '.';
                } else {
                    cashflow_notify($conn, 'finance_head', $reconId, null, 'Cash discrepancy requires review — ' . $channelLabels[$channel] . ', ' . $date . ', variance ' . $variancePretty . '.');
                    $msg = 'success:A cash reconciliation discrepancy was detected. Expected: ' . money($expected) . '. Actual: ' . money($actual) . '. Variance: ' . $variancePretty . '. Please submit an explanation — the Finance Head has been notified.';
                }
            }
        }
    }
}

/* -----------------------------------------------------------
   RESOLVE a discrepancy (Finance Head only)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'resolve_recon') {
    $reconId = (int) ($_POST['recon_id'] ?? 0);
    $note    = trim((string) ($_POST['resolution_note'] ?? ''));
    if (!$isHead) {
        $msg = 'error:Insufficient permission: only the Finance Head can resolve a discrepancy.';
    } elseif ($note === '') {
        $msg = 'error:Please provide a resolution note before resolving this discrepancy.';
    } else {
        $u = mysqli_prepare($conn, "UPDATE cash_flow_reconciliation SET status='resolved', resolved_by=?, resolved_by_name=?, resolved_at=NOW(), resolution_note=? WHERE recon_id=? AND status='discrepancy'");
        mysqli_stmt_bind_param($u, 'issi', $myUserId, $cfUser['full_name'], $note, $reconId);
        mysqli_stmt_execute($u);
        $changed = mysqli_stmt_affected_rows($u);
        mysqli_stmt_close($u);

        if ($changed !== 1) {
            $msg = 'error:This discrepancy was already resolved or no longer exists. Reload the latest version before making changes.';
        } else {
            cashflow_log_reconciliation_audit($conn, $reconId, $myUserId, $cfUser['full_name'], 'resolved', 'discrepancy', 'resolved', $note);
            $msg = 'success:The discrepancy was resolved successfully.';
        }
    }
}

/* -----------------------------------------------------------
   Data for the page
----------------------------------------------------------- */
$channelFilter = $_GET['channel'] ?? '';
$statusFilter  = $_GET['status'] ?? '';
$where = []; $params = []; $types = '';
if (isset($channelLabels[$channelFilter])) { $where[] = 'channel = ?'; $params[] = $channelFilter; $types .= 's'; }
if (isset($statusLabels[$statusFilter]))   { $where[] = 'status = ?';  $params[] = $statusFilter;  $types .= 's'; }
$sql = 'SELECT * FROM cash_flow_reconciliation' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY business_date DESC, recon_id DESC LIMIT 200';
if ($params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $recons = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $recons = mysqli_query($conn, $sql)->fetch_all(MYSQLI_ASSOC);
}
$openDiscrepancyCount = (int) mysqli_query($conn, "SELECT COUNT(*) c FROM cash_flow_reconciliation WHERE status='discrepancy'")->fetch_assoc()['c'];

$fv = $formHold ?: [];
$peekChannel = $fv['channel'] ?? ($_GET['peek_channel'] ?? 'cash');
$peekDate    = $fv['business_date'] ?? ($_GET['peek_date'] ?? date('Y-m-d'));
if (!isset($channelLabels[$peekChannel])) $peekChannel = 'cash';
$dCheck = DateTime::createFromFormat('Y-m-d', $peekDate);
if (!$dCheck || $dCheck->format('Y-m-d') !== $peekDate) $peekDate = date('Y-m-d');
$peekExpected = $peekChannel === 'bank_transfer' ? null : cashflow_expected_amount($conn, $peekChannel, $peekDate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Reconciliation — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  .status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
  .pill-matched{background:#E6F4EA;color:#2f6f4e;}
  .pill-discrepancy{background:#FDE8E8;color:#C0392B;}
  .pill-resolved{background:#E9ECEF;color:#495057;}
  .cf-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
  .cf-form-grid label{display:block;font-size:12px;font-weight:600;color:var(--muted-text,#6b6156);margin-bottom:4px;}
  .cf-form-grid input, .cf-form-grid select{width:100%;padding:8px 10px;border:1px solid var(--border,#e9e3d8);border-radius:8px;font-size:13.5px;}
  .cf-actions-row{display:flex;gap:8px;flex-wrap:wrap;}
  .cf-btn{border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;}
  .cf-btn-submit{background:var(--caramel,#b8703f);color:#fff;}
  .cf-btn-resolve{background:#2C5FA8;color:#fff;}
  .cf-expected-box{background:#FAFAFA;border:1px solid var(--border,#e9e3d8);border-radius:8px;padding:8px 10px;font-size:13.5px;color:var(--muted-text,#6b6156);}
  .empty-state{padding:24px;text-align:center;color:var(--muted-text,#6b6156);}
</style>
</head>
<body>

  <?php include __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/includes/finance_topbar.php'; ?>

    <div class="content">

      <div class="kpi-grid" style="margin-bottom:16px;">
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">OPEN DISCREPANCIES</div></div>
            <div class="kpi-icon icon-red">!</div>
          </div>
          <div class="kpi-value"><?= $openDiscrepancyCount ?></div>
          <div class="kpi-sub">Must be resolved before their period can close</div>
        </div>
      </div>

      <?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
      <div class="alert-strip" style="margin-bottom:16px;">
        <div class="alert-card <?= $kind === 'success' ? '' : 'danger' ?>">
          <div class="alert-icon"><i data-lucide="<?= $kind === 'success' ? 'circle-check' : 'circle-alert' ?>"></i></div>
          <div><?= htmlspecialchars($text) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$canRecord): ?>
        <div class="staff-banner">
          <i data-lucide="info"></i> You're viewing Cash Reconciliation in read-only mode. Recording a reconciliation requires the Finance Officer or Finance Head position.
        </div>
      <?php endif; ?>

      <?php if ($canRecord): ?>
      <div class="section-header"><h2>Record a Reconciliation</h2><div class="line"></div></div>
      <div class="panel" style="margin-bottom:16px;">
        <form method="POST" enctype="multipart/form-data" id="cfrForm">
          <input type="hidden" name="act" value="record_reconciliation">
          <div class="cf-form-grid">
            <div>
              <label>Channel</label>
              <select name="channel" id="cfrChannel" onchange="cfrUpdatePeek()">
                <?php foreach ($channelLabels as $cv => $cl): ?>
                <option value="<?= $cv ?>" <?= $peekChannel === $cv ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Business Date</label>
              <input type="date" name="business_date" id="cfrDate" value="<?= htmlspecialchars($peekDate) ?>" onchange="cfrUpdatePeek()">
            </div>
            <div id="cfrExpectedWrap" style="<?= $peekChannel === 'bank_transfer' ? 'display:none;' : '' ?>">
              <label>Expected (system-computed)</label>
              <div class="cf-expected-box">₱<?= number_format((float) ($peekExpected ?? 0), 2) ?></div>
            </div>
            <div id="cfrExpectedManualWrap" style="<?= $peekChannel === 'bank_transfer' ? '' : 'display:none;' ?>">
              <label>Expected / Recorded Amount</label>
              <input type="number" step="0.01" name="expected_amount_manual" value="<?= htmlspecialchars($fv['expected_amount_manual'] ?? '') ?>">
            </div>
            <div>
              <label>Actual Amount (count / settlement / bank confirmation)</label>
              <input type="number" step="0.01" name="actual_amount" value="<?= htmlspecialchars($fv['actual_amount'] ?? '') ?>" required>
            </div>
            <div>
              <label>Settlement Reference (optional)</label>
              <input type="text" name="settlement_reference" value="<?= htmlspecialchars($fv['settlement_reference'] ?? '') ?>">
            </div>
            <div style="grid-column:1 / -1;">
              <label>Explanation (required only if there's a variance)</label>
              <input type="text" name="explanation" value="<?= htmlspecialchars($fv['explanation'] ?? '') ?>">
            </div>
            <div style="grid-column:1 / -1;" id="cfrProofWrap">
              <label>Proof Attachment (PDF/JPG/PNG, max 5MB — required for Bank Transfer)</label>
              <input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png">
            </div>
          </div>
          <div class="cf-actions-row">
            <button type="submit" class="cf-btn cf-btn-submit">Record Reconciliation</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="section-header"><h2>Reconciliation History</h2><div class="line"></div></div>
      <div class="panel">
        <div class="panel-sub">
          Channel: <a href="?">All</a><?php foreach ($channelLabels as $cv => $cl): ?> · <a href="?channel=<?= $cv ?>"><?= $cl ?></a><?php endforeach; ?>
          &nbsp;|&nbsp; Status:<?php foreach ($statusLabels as $sv => $sl): ?> · <a href="?status=<?= $sv ?>"><?= $sl ?></a><?php endforeach; ?>
        </div>
        <?php if ($recons): ?>
        <table>
          <thead><tr><th>Date</th><th>Channel</th><th style="text-align:right;">Expected</th><th style="text-align:right;">Actual</th><th style="text-align:right;">Variance</th><th>Status</th><th>Explanation</th><th>Recorded By</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($recons as $r): ?>
            <tr>
              <td class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($r['business_date']))) ?></td>
              <td><?= htmlspecialchars($channelLabels[$r['channel']] ?? $r['channel']) ?></td>
              <td class="num"><?= money($r['expected_amount']) ?></td>
              <td class="num"><?= money($r['actual_amount']) ?></td>
              <td class="num" style="color:<?= abs($r['variance']) < 0.005 ? '#2f6f4e' : '#C0392B' ?>;"><?= ($r['variance'] >= 0 ? '+' : '−') . money(abs($r['variance'])) ?></td>
              <td><span class="status-pill pill-<?= $r['status'] ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td class="muted" style="max-width:220px;"><?= htmlspecialchars($r['explanation'] ?: '—') ?><?php if ($r['resolution_note']): ?><div style="font-size:11px;">Resolved: <?= htmlspecialchars($r['resolution_note']) ?></div><?php endif; ?></td>
              <td class="muted"><?= htmlspecialchars($r['created_by_name']) ?></td>
              <td>
                <div class="cf-actions-row">
                <?php if ($r['proof_attachment_path']): ?><a href="../<?= htmlspecialchars($r['proof_attachment_path']) ?>" target="_blank" rel="noopener" style="font-size:11px;">📎 Proof</a><?php endif; ?>
                <?php if ($isHead && $r['status'] === 'discrepancy'): ?>
                  <button type="button" class="cf-btn cf-btn-resolve js-resolve-btn" data-id="<?= $r['recon_id'] ?>">Resolve</button>
                <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No reconciliation records match the current filter.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<script>
var cfrExpected = <?= json_encode($peekChannel === 'bank_transfer' ? null : (float) ($peekExpected ?? 0)) ?>;
function cfrUpdatePeek() {
  var ch = document.getElementById('cfrChannel').value;
  var dt = document.getElementById('cfrDate').value;
  window.location.href = '?peek_channel=' + encodeURIComponent(ch) + '&peek_date=' + encodeURIComponent(dt) + '#cfrForm';
}
document.getElementById('cfrChannel')?.addEventListener('change', function () {
  document.getElementById('cfrExpectedWrap').style.display = this.value === 'bank_transfer' ? 'none' : '';
  document.getElementById('cfrExpectedManualWrap').style.display = this.value === 'bank_transfer' ? '' : 'none';
});
document.querySelectorAll('.js-resolve-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-id');
    Swal.fire({
      title: 'Resolve this discrepancy?', input: 'text', inputPlaceholder: 'Resolution note (required)',
      icon: 'question', showCancelButton: true, confirmButtonText: 'Resolve', cancelButtonText: 'Cancel', confirmButtonColor: '#2C5FA8', reverseButtons: true,
      inputValidator: function (value) { if (!value) return 'A resolution note is required.'; }
    }).then(function (result) {
      if (!result.isConfirmed) return;
      var form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = '<input type="hidden" name="act" value="resolve_recon">' +
        '<input type="hidden" name="recon_id" value="' + id + '">' +
        '<input type="hidden" name="resolution_note" value="' + result.value.replace(/"/g, '&quot;') + '">';
      document.body.appendChild(form);
      form.submit();
    });
  });
});
<?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
Swal.fire({
  icon: '<?= $kind === 'success' ? 'success' : 'error' ?>',
  title: '<?= $kind === 'success' ? 'Success' : 'Error' ?>',
  text: <?= json_encode($text) ?>,
  timer: 5000, timerProgressBar: true, toast: true, position: 'top-end', showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>
