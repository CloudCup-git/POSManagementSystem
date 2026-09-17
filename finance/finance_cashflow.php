<?php
/**
 * CloudCup — Finance: Cash Flow Overview
 * -------------------------------------------------------------
 * Stage 1 of the Cash Flow workflow build: manual cash-in / cash-out
 * entries with a Draft -> Under Review -> Approved -> Posted -> Closed
 * lifecycle (plus Returned for Revision / Rejected side branches), a
 * Finance Officer / Finance Head split (see includes/cashflow_auth.php),
 * an append-only audit trail (cash_flow_audit, also mirrored into the
 * shared activity_log so it shows up in finance_activity_log.php for
 * free), and notifications for the other party on every transition.
 *
 * Posting an Approved entry writes into the EXISTING operating_expenses
 * (cash-out) / owner_equity_transactions (cash-in) tables — see
 * includes/cashflow_queries.php's cashflow_post_entry() — so Balance
 * Sheet / OpEx / Revenue pick it up with zero changes to those pages.
 *
 * Deferred to a later stage (see the approved plan): GCash/bank
 * settlement matching, the physical cash-count reconciliation screen,
 * and formal concurrency/load testing at scale.
 * -------------------------------------------------------------
 */
require __DIR__ . '/includes/finance_data.php'; // $pdo, $conn, $cashIn, $cashOutExpenses, $netCashFlow, $rangeQuery, $currentUser, money()
require_once __DIR__ . '/../includes/cashflow_queries.php';

ensure_cashflow_tables($conn);

$activePage = 'cashflow';
$pageTitle  = 'Finance — Cash Flow';

$cfUser  = cashflow_current_user();
$cfStage = $cfUser ? cashflow_stage_for_user($cfUser) : null;
$isOfficer = $cfStage === CF_STAGE_FINANCE_OFFICER;
$isHead    = $cfStage === CF_STAGE_FINANCE_HEAD;
$myUserId  = $cfUser['user_id'] ?? 0;

$msg = '';
$categoriesIn  = ['Owner Contribution', 'Miscellaneous Income', 'Sales Adjustment', 'Other'];
$categoriesOut = ['Utilities', 'Rent', 'Supplies', 'Petty Cash', 'Miscellaneous Expense', 'Other'];
$methodLabels  = ['cash' => 'Cash', 'gcash_ewallet' => 'GCash / E-wallet', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', 'other' => 'Other'];
$statusLabels  = [
    'draft' => 'Draft', 'under_review' => 'Under Review', 'returned' => 'Returned for Revision',
    'rejected' => 'Rejected', 'approved' => 'Approved', 'posted' => 'Posted', 'closed' => 'Closed',
];

/* -----------------------------------------------------------
   Duplicate-warning hold: when a possible duplicate is detected, the
   submitted values are kept here so the form can be re-shown with a
   "Save anyway" confirmation instead of silently blocking or silently
   allowing it.
----------------------------------------------------------- */
$duplicateHold = null;

/* -----------------------------------------------------------
   CREATE / UPDATE a draft entry (Finance Officer only)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['act'] ?? '', ['create', 'update'], true)) {
    if (!$isOfficer) {
        $msg = 'error:Insufficient permission: only a Finance Officer can create or edit a cash-flow entry.';
    } else {
        [$valid, $errors] = cashflow_validate_entry($conn, $_POST);
        $submitMode = ($_POST['submit_mode'] ?? 'draft') === 'submit' ? 'submit' : 'draft';
        $entryId    = (int) ($_POST['entry_id'] ?? 0);
        $refInput   = trim((string) ($_POST['reference_number'] ?? ''));

        if (!$valid) {
            $msg = 'error:' . implode(' ', $errors);
            $duplicateHold = $_POST;
        } elseif ($refInput !== '' && ($_POST['confirm_duplicate'] ?? '') !== '1'
            && ($dup = cashflow_check_possible_duplicate($conn, $refInput, (float) $_POST['amount'], $_POST['transaction_date'], $_POST['payment_method']))
            && (int) $dup['entry_id'] !== $entryId
        ) {
            $msg = 'error:A duplicate entry may already exist with the same reference number, amount, date, and payment method (' . cashflow_reference_number((int) $dup['entry_id']) . ', status: ' . ($statusLabels[$dup['status']] ?? $dup['status']) . '). Review it, then click Save anyway if this is a different transaction.';
            $duplicateHold = $_POST;
        } else {
            $amount = round((float) $_POST['amount'], 2);
            $status = $submitMode === 'submit' ? CF_STATUS_UNDER_REVIEW : CF_STATUS_DRAFT;

            if ($_POST['act'] === 'create') {
                $stmt = mysqli_prepare($conn, "INSERT INTO cash_flow_entries
                    (reference_number, entry_type, category, amount, payment_method, transaction_date, description, status, created_by, created_by_name)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                $refToStore = $refInput !== '' ? $refInput : null;
                mysqli_stmt_bind_param($stmt, 'sssdssssis', $refToStore, $_POST['entry_type'], $_POST['category'], $amount, $_POST['payment_method'], $_POST['transaction_date'], $_POST['description'], $status, $myUserId, $cfUser['full_name']);
                mysqli_stmt_execute($stmt);
                $newId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                if ($refToStore === null) {
                    $auto = cashflow_reference_number($newId);
                    $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET reference_number = ? WHERE entry_id = ?");
                    mysqli_stmt_bind_param($u, 'si', $auto, $newId);
                    mysqli_stmt_execute($u);
                    mysqli_stmt_close($u);
                    $refToStore = $auto;
                }
                $ref = cashflow_reference_number($newId);

                $attachNote = '';
                if (!empty($_FILES['attachment']['name'])) {
                    $up = cashflow_handle_upload($_FILES['attachment']);
                    if ($up['ok']) {
                        $au = mysqli_prepare($conn, "UPDATE cash_flow_entries SET attachment_path = ? WHERE entry_id = ?");
                        mysqli_stmt_bind_param($au, 'si', $up['path'], $newId);
                        mysqli_stmt_execute($au);
                        mysqli_stmt_close($au);
                    } else {
                        $attachNote = ' (Attachment not saved: ' . $up['error'] . ')';
                    }
                }

                cashflow_log_audit($conn, $newId, $myUserId, $cfUser['full_name'], $submitMode === 'submit' ? 'submitted' : 'saved_draft', null, $status, null, $amount, ($submitMode === 'submit' ? 'Submitted' : 'Saved as draft') . ' ' . $ref . '.');

                if ($submitMode === 'submit') {
                    cashflow_notify($conn, 'finance_head', $newId, null, 'A new cash-flow request ' . $ref . ' (' . $_POST['entry_type'] . ', ' . money($amount) . ') requires your review.');
                    $msg = 'success:Cash-flow entry ' . $ref . ' was submitted to the Finance Head for review.' . $attachNote;
                } else {
                    $msg = 'success:Cash-flow entry ' . $ref . ' was saved as Draft.' . $attachNote;
                }
            } else { // update
                $sel = mysqli_prepare($conn, "SELECT * FROM cash_flow_entries WHERE entry_id = ?");
                mysqli_stmt_bind_param($sel, 'i', $entryId);
                mysqli_stmt_execute($sel);
                $existing = mysqli_stmt_get_result($sel)->fetch_assoc();
                mysqli_stmt_close($sel);

                if (!$existing || (int) $existing['created_by'] !== $myUserId || !in_array($existing['status'], [CF_STATUS_DRAFT, CF_STATUS_RETURNED], true)) {
                    $msg = 'error:This record was changed by another user, or is no longer editable. Reload the latest version before making changes.';
                } else {
                    $oldStatus = $existing['status'];
                    $newStatus = $submitMode === 'submit' ? CF_STATUS_UNDER_REVIEW : $oldStatus;
                    $refToStore = $refInput !== '' ? $refInput : $existing['reference_number'];

                    $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET
                        reference_number=?, entry_type=?, category=?, amount=?, payment_method=?, transaction_date=?, description=?, status=?
                        WHERE entry_id = ? AND status = ?");
                    mysqli_stmt_bind_param($u, 'sssdssssis', $refToStore, $_POST['entry_type'], $_POST['category'], $amount, $_POST['payment_method'], $_POST['transaction_date'], $_POST['description'], $newStatus, $entryId, $oldStatus);
                    mysqli_stmt_execute($u);
                    $changed = mysqli_stmt_affected_rows($u);
                    mysqli_stmt_close($u);

                    $ref = cashflow_reference_number($entryId);
                    if ($changed !== 1) {
                        $msg = 'error:The record was changed by another user. Reload the latest version before making changes.';
                    } else {
                        $attachNote = '';
                        if (!empty($_FILES['attachment']['name'])) {
                            $up = cashflow_handle_upload($_FILES['attachment']);
                            if ($up['ok']) {
                                $au = mysqli_prepare($conn, "UPDATE cash_flow_entries SET attachment_path = ? WHERE entry_id = ?");
                                mysqli_stmt_bind_param($au, 'si', $up['path'], $entryId);
                                mysqli_stmt_execute($au);
                                mysqli_stmt_close($au);
                            } else {
                                $attachNote = ' (Attachment not saved: ' . $up['error'] . ')';
                            }
                        }

                        cashflow_log_audit($conn, $entryId, $myUserId, $cfUser['full_name'], $submitMode === 'submit' ? 'resubmitted' : 'updated_draft', $oldStatus, $newStatus, (float) $existing['amount'], $amount, ($submitMode === 'submit' ? 'Resubmitted' : 'Updated') . ' ' . $ref . '.');
                        if ($submitMode === 'submit') {
                            cashflow_notify($conn, 'finance_head', $entryId, null, 'Cash-flow request ' . $ref . ' was resubmitted and requires your review.');
                            $msg = 'success:Cash-flow entry ' . $ref . ' was resubmitted to the Finance Head for review.' . $attachNote;
                        } else {
                            $msg = 'success:Cash-flow entry ' . $ref . ' was saved.' . $attachNote;
                        }
                    }
                }
            }
        }
    }
}

/* -----------------------------------------------------------
   SUBMIT an existing Draft/Returned entry as-is (no edits)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'submit_entry') {
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    if (!$isOfficer) {
        $msg = 'error:Insufficient permission: only a Finance Officer can submit a cash-flow entry.';
    } else {
        $sel = mysqli_prepare($conn, "SELECT * FROM cash_flow_entries WHERE entry_id = ? AND created_by = ?");
        mysqli_stmt_bind_param($sel, 'ii', $entryId, $myUserId);
        mysqli_stmt_execute($sel);
        $entry = mysqli_stmt_get_result($sel)->fetch_assoc();
        mysqli_stmt_close($sel);

        $decision = ($entry['status'] ?? '') === CF_STATUS_RETURNED ? 'resubmit' : 'submit';
        $next = $entry ? cashflow_next_status($entry['status'], $decision) : null;

        if (!$entry || !$next) {
            $msg = 'error:This record was already submitted or changed. Reload the latest version before making changes.';
        } else {
            $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET status = ? WHERE entry_id = ? AND status = ?");
            mysqli_stmt_bind_param($u, 'sis', $next, $entryId, $entry['status']);
            mysqli_stmt_execute($u);
            $changed = mysqli_stmt_affected_rows($u);
            mysqli_stmt_close($u);
            $ref = cashflow_reference_number($entryId);

            if ($changed !== 1) {
                $msg = 'error:The record was changed by another user. Reload the latest version before making changes.';
            } else {
                cashflow_log_audit($conn, $entryId, $myUserId, $cfUser['full_name'], $decision === 'resubmit' ? 'resubmitted' : 'submitted', $entry['status'], $next, (float) $entry['amount'], (float) $entry['amount'], ($decision === 'resubmit' ? 'Resubmitted' : 'Submitted') . ' ' . $ref . '.');
                cashflow_notify($conn, 'finance_head', $entryId, null, 'Cash-flow request ' . $ref . ' requires your review.');
                $msg = 'success:Cash-flow entry ' . $ref . ' was submitted to the Finance Head for review.';
            }
        }
    }
}

/* -----------------------------------------------------------
   Finance Head decisions: approve / reject / return / post
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['act'] ?? '', ['approve', 'reject', 'return', 'post_entry'], true)) {
    $act      = $_POST['act'];
    $entryId  = (int) ($_POST['entry_id'] ?? 0);
    $note     = trim((string) ($_POST['review_note'] ?? ''));
    $decision = ['approve' => 'approve', 'reject' => 'reject', 'return' => 'return', 'post_entry' => 'post'][$act];

    if (!$isHead) {
        $msg = 'error:Insufficient permission: only the Finance Head can ' . ($act === 'post_entry' ? 'post' : $act) . ' this request.';
    } elseif (in_array($act, ['reject', 'return'], true) && $note === '') {
        $msg = 'error:Please provide a reason before ' . ($act === 'reject' ? 'rejecting' : 'returning') . ' this entry.';
    } else {
        $sel = mysqli_prepare($conn, "SELECT * FROM cash_flow_entries WHERE entry_id = ?");
        mysqli_stmt_bind_param($sel, 'i', $entryId);
        mysqli_stmt_execute($sel);
        $entry = mysqli_stmt_get_result($sel)->fetch_assoc();
        mysqli_stmt_close($sel);

        $next = $entry ? cashflow_next_status($entry['status'], $decision) : null;

        if (!$entry || !$next) {
            $msg = 'error:The approval action was not completed. The record remains ' . ($statusLabels[$entry['status'] ?? ''] ?? 'in its previous state') . '.';
        } elseif (in_array($act, ['approve', 'post_entry'], true) && cashflow_is_self_approval($entry, $myUserId)) {
            $msg = 'error:Insufficient permission: you cannot approve or post a request you created yourself.';
        } else {
            $ref = cashflow_reference_number($entryId);

            if ($act === 'post_entry') {
                try {
                    mysqli_begin_transaction($conn);
                    $postNote = cashflow_post_entry($conn, $entry, $myUserId);
                    $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET status = ?, posted_at = NOW() WHERE entry_id = ? AND status = ?");
                    mysqli_stmt_bind_param($u, 'sis', $next, $entryId, $entry['status']);
                    mysqli_stmt_execute($u);
                    $changed = mysqli_stmt_affected_rows($u);
                    mysqli_stmt_close($u);
                    if ($changed !== 1) throw new Exception('changed by another user');
                    mysqli_commit($conn);

                    cashflow_log_audit($conn, $entryId, $myUserId, $cfUser['full_name'], 'posted', $entry['status'], $next, (float) $entry['amount'], (float) $entry['amount'], 'Posted ' . $ref . '. ' . $postNote);
                    cashflow_notify($conn, 'finance_officer', $entryId, (int) $entry['created_by'], 'Your request ' . $ref . ' was posted.');
                    $msg = 'success:The cash-flow record ' . $ref . ' was posted successfully. ' . $postNote;
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $msg = 'error:The server did not confirm this action. Refresh the page before trying again.';
                }
            } else {
                $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ? WHERE entry_id = ? AND status = ?");
                $reviewNoteToStore = $note !== '' ? $note : null;
                mysqli_stmt_bind_param($u, 'sissis', $next, $myUserId, $cfUser['full_name'], $reviewNoteToStore, $entryId, $entry['status']);
                mysqli_stmt_execute($u);
                $changed = mysqli_stmt_affected_rows($u);
                mysqli_stmt_close($u);

                if ($changed !== 1) {
                    $msg = 'error:The approval action was not completed. The record remains ' . ($statusLabels[$entry['status']] ?? $entry['status']) . '.';
                } else {
                    $actionWord = ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned_for_revision'][$act];
                    cashflow_log_audit($conn, $entryId, $myUserId, $cfUser['full_name'], $actionWord, $entry['status'], $next, (float) $entry['amount'], (float) $entry['amount'], ucfirst(str_replace('_', ' ', $actionWord)) . ' ' . $ref . ($note !== '' ? ': ' . $note : '') . '.');

                    if ($act === 'approve') {
                        cashflow_notify($conn, 'finance_officer', $entryId, (int) $entry['created_by'], 'Your request ' . $ref . ' was approved.');
                        $msg = 'success:Cash-flow entry ' . $ref . ' was approved by the Finance Head.';
                    } elseif ($act === 'return') {
                        cashflow_notify($conn, 'finance_officer', $entryId, (int) $entry['created_by'], 'Your request ' . $ref . ' was returned for revision: ' . $note);
                        $msg = 'success:Cash-flow entry ' . $ref . ' was returned to the Finance Officer for revision.';
                    } else {
                        cashflow_notify($conn, 'finance_officer', $entryId, (int) $entry['created_by'], 'Your request ' . $ref . ' was rejected: ' . $note);
                        $msg = 'success:Cash-flow entry ' . $ref . ' was rejected.';
                    }
                }
            }
        }
    }
}

/* -----------------------------------------------------------
   CLOSE a date range of Posted entries (Finance Head)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'close_period') {
    $fromD = $_POST['close_from'] ?? '';
    $toD   = $_POST['close_to'] ?? '';
    if (!$isHead) {
        $msg = 'error:Insufficient permission: only the Finance Head can lock and close a period.';
    } elseif (!$fromD || !$toD) {
        $msg = 'error:Please select both a start and end date for the period you want to close.';
    } elseif (cashflow_has_open_discrepancy($conn, $fromD, $toD) > 0) {
        $msg = 'error:This period has unresolved cash reconciliation discrepancies. The Finance Head must resolve them before the period can be closed.';
    } else {
        $sel = mysqli_prepare($conn, "SELECT entry_id, amount, status FROM cash_flow_entries WHERE status = 'posted' AND transaction_date BETWEEN ? AND ?");
        mysqli_stmt_bind_param($sel, 'ss', $fromD, $toD);
        mysqli_stmt_execute($sel);
        $rows = mysqli_stmt_get_result($sel)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($sel);

        if (!$rows) {
            $msg = 'error:No posted entries were found in that date range.';
        } else {
            $count = 0;
            foreach ($rows as $r) {
                $u = mysqli_prepare($conn, "UPDATE cash_flow_entries SET status = 'closed' WHERE entry_id = ? AND status = 'posted'");
                mysqli_stmt_bind_param($u, 'i', $r['entry_id']);
                mysqli_stmt_execute($u);
                if (mysqli_stmt_affected_rows($u) === 1) {
                    $count++;
                    cashflow_log_audit($conn, (int) $r['entry_id'], $myUserId, $cfUser['full_name'], 'closed', 'posted', 'closed', (float) $r['amount'], (float) $r['amount'], 'Period closed by Finance Head.');
                }
                mysqli_stmt_close($u);
            }
            cashflow_notify($conn, 'finance_officer', (int) $rows[0]['entry_id'], null, 'The period ' . $fromD . ' to ' . $toD . ' is closed. New entries and edits are not allowed for those dates.');
            $msg = 'success:The selected cash-flow period (' . $fromD . ' to ' . $toD . ') is now closed and locked. ' . $count . ' record(s) locked.';
        }
    }
}

/* -----------------------------------------------------------
   Data for the page
----------------------------------------------------------- */
$statusFilter = $_GET['status'] ?? '';
$where = [];
$params = []; $types = '';
if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
    $where[] = 'status = ?'; $params[] = $statusFilter; $types .= 's';
}
if ($isOfficer && !$isHead) {
    $where[] = 'created_by = ?'; $params[] = $myUserId; $types .= 'i';
}
$sql = 'SELECT * FROM cash_flow_entries' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 200';
if ($params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $entries = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $entries = mysqli_query($conn, $sql)->fetch_all(MYSQLI_ASSOC);
}

$pendingReviewCount = (int) mysqli_query($conn, "SELECT COUNT(*) c FROM cash_flow_entries WHERE status = 'under_review'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="../js/tab_session_guard.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Flow — CloudCup Finance</title>
<link rel="stylesheet" href="../css/admin_page.css">
<link rel="stylesheet" href="css/finance.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  .status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
  .pill-draft{background:#EEE3D0;color:#6b6156;}
  .pill-under_review{background:#f4e3d3;color:#b8703f;}
  .pill-returned{background:#FDEBD0;color:#B9770E;}
  .pill-rejected{background:#FDE8E8;color:#C0392B;}
  .pill-approved{background:#E6F4EA;color:#2f6f4e;}
  .pill-posted{background:#E6F4EA;color:#1a7f45;}
  .pill-closed{background:#E9ECEF;color:#495057;}
  .cf-type-in{color:#2f6f4e;font-weight:600;}
  .cf-type-out{color:#C0392B;font-weight:600;}
  .cf-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
  .cf-form-grid label{display:block;font-size:12px;font-weight:600;color:var(--muted-text,#6b6156);margin-bottom:4px;}
  .cf-form-grid input, .cf-form-grid select, .cf-form-grid textarea{width:100%;padding:8px 10px;border:1px solid var(--border,#e9e3d8);border-radius:8px;font-size:13.5px;}
  .cf-actions-row{display:flex;gap:8px;flex-wrap:wrap;}
  .cf-actions-row form{display:inline-block;}
  .cf-btn{border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;}
  .cf-btn-approve{background:#2f6f4e;color:#fff;}
  .cf-btn-reject{background:#C0392B;color:#fff;}
  .cf-btn-return{background:#B9770E;color:#fff;}
  .cf-btn-post{background:#2C5FA8;color:#fff;}
  .cf-btn-submit{background:var(--caramel,#b8703f);color:#fff;}
  .cf-btn[disabled]{opacity:.6;cursor:default;}
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
            <div><div class="kpi-label">NET CASH FLOW (CASH)</div></div>
            <div class="kpi-icon <?= $netCashFlow >= 0 ? 'icon-green' : 'icon-red' ?>">₱</div>
          </div>
          <div class="kpi-value <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><?= money($netCashFlow) ?></div>
          <div class="kpi-sub">cash in <?= money($cashIn) ?> − cash out <?= money($cashOutExpenses) ?></div>
        </div>
        <?php if ($isHead): ?>
        <div class="kpi-card">
          <div class="kpi-top">
            <div><div class="kpi-label">AWAITING YOUR REVIEW</div></div>
            <div class="kpi-icon icon-orange">!</div>
          </div>
          <div class="kpi-value"><?= $pendingReviewCount ?></div>
          <div class="kpi-sub">Cash-flow requests under review</div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
      <div class="alert-strip" style="margin-bottom:16px;">
        <div class="alert-card <?= $kind === 'success' ? '' : 'danger' ?>">
          <div class="alert-icon"><i data-lucide="<?= $kind === 'success' ? 'circle-check' : 'circle-alert' ?>"></i></div>
          <div><?= htmlspecialchars($text) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="section-header"><h2>Cash Flow</h2><a class="btn-export" href="finance_export.php?report=cashflow&<?= htmlspecialchars($rangeQuery) ?>">⭳ Export to Excel</a><div class="line"></div></div>
      <div class="panel" style="margin-bottom:16px;">
        <div class="panel-sub" style="margin-bottom:16px;">Cash actually moving in and out of the register — separate from GCash/card/bank transactions, which settle outside the drawer.</div>
        <table>
          <thead><tr><th>Flow</th><th style="text-align:right;">Amount</th></tr></thead>
          <tbody>
            <tr><td>Cash sales received + posted cash-in entries</td><td class="num value-green"><?= money($cashIn) ?></td></tr>
            <tr><td>Cash paid out for expenses (incl. posted cash-out entries)</td><td class="num value-red">− <?= money($cashOutExpenses) ?></td></tr>
            <tr><td><strong>Net cash movement</strong></td><td class="num <?= $netCashFlow >= 0 ? 'value-green' : 'value-red' ?>"><strong><?= money($netCashFlow) ?></strong></td></tr>
          </tbody>
        </table>
      </div>

      <?php if (!$cfUser || (!$isOfficer && !$isHead)): ?>
        <div class="staff-banner">
          <i data-lucide="info"></i> You're viewing Cash Flow Entries in read-only mode. Creating, submitting, or reviewing entries requires the Finance Officer or Finance Head position.
        </div>
      <?php endif; ?>

      <div class="section-header"><h2>Cash Flow Entries</h2>
        <?php if ($isOfficer): ?>
        <button type="button" class="btn-primary" onclick="document.getElementById('cfNewEntryPanel').style.display='block'; document.getElementById('cfNewEntryPanel').scrollIntoView({behavior:'smooth'});">+ New Entry</button>
        <?php endif; ?>
        <div class="line"></div>
      </div>

      <?php if ($isOfficer): $fv = $duplicateHold ?: []; ?>
      <div class="panel" id="cfNewEntryPanel" style="margin-bottom:16px; <?= $duplicateHold ? '' : 'display:none;' ?>">
        <div class="panel-title"><?= !empty($fv['entry_id']) ? 'Edit Entry' : 'New Cash Flow Entry' ?></div>
        <form method="POST" id="cfEntryForm" enctype="multipart/form-data">
          <input type="hidden" name="act" value="<?= !empty($fv['entry_id']) ? 'update' : 'create' ?>">
          <input type="hidden" name="entry_id" value="<?= htmlspecialchars($fv['entry_id'] ?? '') ?>">
          <?php if ($duplicateHold): ?>
          <input type="hidden" name="confirm_duplicate" value="0" id="cfConfirmDup">
          <input type="hidden" name="submit_mode" value="<?= htmlspecialchars($fv['submit_mode'] ?? 'draft') ?>" id="cfHeldSubmitMode">
          <?php endif; ?>
          <div class="cf-form-grid">
            <div>
              <label>Transaction Type</label>
              <select name="entry_type" id="cfEntryType" required>
                <option value="cash_in" <?= ($fv['entry_type'] ?? '') === 'cash_in' ? 'selected' : '' ?>>Cash In</option>
                <option value="cash_out" <?= ($fv['entry_type'] ?? '') === 'cash_out' ? 'selected' : '' ?>>Cash Out</option>
              </select>
            </div>
            <div>
              <label>Amount (₱)</label>
              <input type="number" step="0.01" min="0.01" name="amount" value="<?= htmlspecialchars($fv['amount'] ?? '') ?>" required>
            </div>
            <div>
              <label>Category / Source</label>
              <select name="category" required>
                <?php foreach (array_unique(array_merge($categoriesIn, $categoriesOut)) as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= ($fv['category'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Payment Method</label>
              <select name="payment_method" required>
                <?php foreach ($methodLabels as $mv => $ml): ?>
                <option value="<?= $mv ?>" <?= ($fv['payment_method'] ?? '') === $mv ? 'selected' : '' ?>><?= htmlspecialchars($ml) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Transaction Date</label>
              <input type="date" name="transaction_date" value="<?= htmlspecialchars($fv['transaction_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div>
              <label>Reference Number (optional)</label>
              <input type="text" name="reference_number" value="<?= htmlspecialchars($fv['reference_number'] ?? '') ?>" placeholder="e.g. OR-2026-001">
            </div>
            <div style="grid-column:1 / -1;">
              <label>Description / Purpose</label>
              <textarea name="description" rows="2" required><?= htmlspecialchars($fv['description'] ?? '') ?></textarea>
            </div>
            <div style="grid-column:1 / -1;">
              <label>Supporting Attachment (optional — receipt, invoice; PDF/JPG/PNG, max 5MB)</label>
              <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
            </div>
          </div>
          <div class="cf-actions-row">
            <button type="submit" name="submit_mode" value="draft" class="cf-btn cf-btn-submit" style="background:var(--muted-text,#6b6156);">Save as Draft</button>
            <button type="submit" name="submit_mode" value="submit" class="cf-btn cf-btn-submit">Save &amp; Submit for Review</button>
            <?php if ($duplicateHold): ?>
            <button type="button" class="cf-btn cf-btn-return" onclick="document.getElementById('cfConfirmDup').value='1'; document.getElementById('cfEntryForm').submit();">Save anyway (<?= ($fv['submit_mode'] ?? 'draft') === 'submit' ? 'Submit for Review' : 'Save as Draft' ?>)</button>
            <?php endif; ?>
            <button type="button" class="cf-btn" style="background:#eee;" onclick="document.getElementById('cfNewEntryPanel').style.display='none';">Cancel</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($isHead): ?>
      <div class="panel" style="margin-bottom:16px;">
        <div class="panel-title">Close a Period</div>
        <div class="panel-sub" style="margin-bottom:10px;">Locks every Posted entry in the selected date range. No new entries or edits are allowed for those dates afterward.</div>
        <form method="POST" class="cf-actions-row" onsubmit="return confirm('Close and lock every Posted entry in this date range? This cannot be undone from here.');">
          <input type="hidden" name="act" value="close_period">
          <input type="date" name="close_from" required style="padding:6px 10px;border:1px solid var(--border,#e9e3d8);border-radius:8px;">
          <input type="date" name="close_to" required style="padding:6px 10px;border:1px solid var(--border,#e9e3d8);border-radius:8px;">
          <button type="submit" class="cf-btn cf-btn-post">Close Period</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-sub">
          Filter: <a href="?status=">All</a>
          <?php foreach ($statusLabels as $sv => $sl): ?> · <a href="?status=<?= $sv ?>"><?= $sl ?></a><?php endforeach; ?>
        </div>
        <?php if ($entries): ?>
        <table>
          <thead>
            <tr><th>Reference</th><th>Type</th><th>Category</th><th style="text-align:right;">Amount</th><th>Method</th><th>Date</th><th>Status</th><th>Created By</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $e): $ref = cashflow_reference_number((int) $e['entry_id']); ?>
            <tr>
              <td><?= htmlspecialchars($e['reference_number'] ?: $ref) ?></td>
              <td><span class="<?= $e['entry_type'] === 'cash_in' ? 'cf-type-in' : 'cf-type-out' ?>"><?= $e['entry_type'] === 'cash_in' ? 'Cash In' : 'Cash Out' ?></span></td>
              <td><?= htmlspecialchars($e['category']) ?></td>
              <td class="num"><?= money($e['amount']) ?></td>
              <td><?= htmlspecialchars($methodLabels[$e['payment_method']] ?? $e['payment_method']) ?></td>
              <td class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($e['transaction_date']))) ?></td>
              <td><span class="status-pill pill-<?= $e['status'] ?>"><?= htmlspecialchars($statusLabels[$e['status']] ?? $e['status']) ?></span></td>
              <td class="muted"><?= htmlspecialchars($e['created_by_name']) ?></td>
              <td>
                <div class="cf-actions-row">
                <?php if ($isOfficer && (int) $e['created_by'] === $myUserId && in_array($e['status'], ['draft', 'returned'], true)): ?>
                  <form method="POST" class="js-submit-form"><input type="hidden" name="act" value="submit_entry"><input type="hidden" name="entry_id" value="<?= $e['entry_id'] ?>"><button type="submit" class="cf-btn cf-btn-submit"><?= $e['status'] === 'returned' ? 'Resubmit' : 'Submit' ?></button></form>
                <?php endif; ?>
                <?php if ($isHead && $e['status'] === 'under_review'): ?>
                  <form method="POST" class="js-approve-form"><input type="hidden" name="act" value="approve"><input type="hidden" name="entry_id" value="<?= $e['entry_id'] ?>"><button type="submit" class="cf-btn cf-btn-approve">Approve</button></form>
                  <button type="button" class="cf-btn cf-btn-return js-return-btn" data-id="<?= $e['entry_id'] ?>">Return</button>
                  <button type="button" class="cf-btn cf-btn-reject js-reject-btn" data-id="<?= $e['entry_id'] ?>">Reject</button>
                <?php endif; ?>
                <?php if ($isHead && $e['status'] === 'approved'): ?>
                  <form method="POST" class="js-post-form"><input type="hidden" name="act" value="post_entry"><input type="hidden" name="entry_id" value="<?= $e['entry_id'] ?>"><button type="submit" class="cf-btn cf-btn-post">Post</button></form>
                <?php endif; ?>
                <?php if ($e['review_note']): ?><div class="muted" style="font-size:11px;margin-top:4px;">Note: <?= htmlspecialchars($e['review_note']) ?></div><?php endif; ?>
                <?php if ($e['attachment_path']): ?><div style="font-size:11px;margin-top:4px;"><a href="../<?= htmlspecialchars($e['attachment_path']) ?>" target="_blank" rel="noopener">📎 Attachment</a></div><?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="empty-state">No cash-flow entries match the current filter.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>

<script>
document.querySelectorAll('.js-approve-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('button');
    Swal.fire({
      title: 'Approve this cash-flow entry?', icon: 'question', showCancelButton: true,
      confirmButtonText: 'Yes, approve', cancelButtonText: 'Cancel', confirmButtonColor: '#2f6f4e', reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) { btn.disabled = true; btn.textContent = 'Approving…'; form.submit(); }
    });
  });
});
document.querySelectorAll('.js-post-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('button');
    Swal.fire({
      title: 'Post this entry?', text: 'This records the financial impact immediately and cannot be edited afterward.',
      icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, post', cancelButtonText: 'Cancel', confirmButtonColor: '#2C5FA8', reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) { btn.disabled = true; btn.textContent = 'Posting…'; form.submit(); }
    });
  });
});
document.querySelectorAll('.js-submit-form').forEach(function (form) {
  form.addEventListener('submit', function () {
    var btn = form.querySelector('button');
    btn.disabled = true; btn.textContent = 'Submitting…';
  });
});
function cfPostDecision(act, entryId, note) {
  var form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML =
    '<input type="hidden" name="act" value="' + act + '">' +
    '<input type="hidden" name="entry_id" value="' + entryId + '">' +
    '<input type="hidden" name="review_note" value="' + (note || '').replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
}
document.querySelectorAll('.js-reject-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-id');
    Swal.fire({
      title: 'Reject this entry?', input: 'text', inputPlaceholder: 'Reason (required)',
      icon: 'warning', showCancelButton: true, confirmButtonText: 'Reject', cancelButtonText: 'Cancel', confirmButtonColor: '#C0392B', reverseButtons: true,
      inputValidator: function (value) { if (!value) return 'A rejection reason is required.'; }
    }).then(function (result) { if (result.isConfirmed) cfPostDecision('reject', id, result.value); });
  });
});
document.querySelectorAll('.js-return-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-id');
    Swal.fire({
      title: 'Return this entry for revision?', input: 'text', inputPlaceholder: 'What needs to change? (required)',
      icon: 'warning', showCancelButton: true, confirmButtonText: 'Return', cancelButtonText: 'Cancel', confirmButtonColor: '#B9770E', reverseButtons: true,
      inputValidator: function (value) { if (!value) return 'A correction note is required.'; }
    }).then(function (result) { if (result.isConfirmed) cfPostDecision('return', id, result.value); });
  });
});
document.getElementById('cfEntryForm')?.addEventListener('submit', function () {
  this.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
});
<?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
Swal.fire({
  icon: '<?= $kind === 'success' ? 'success' : 'error' ?>',
  title: '<?= $kind === 'success' ? 'Success' : 'Error' ?>',
  text: <?= json_encode($text) ?>,
  timer: 4500, timerProgressBar: true, toast: true, position: 'top-end', showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>
