<?php
/**
 * Procurement — Finance Hub (consolidation pass)
 * -------------------------------------------------------------
 * Combines all seven Finance procurement pages into one tabbed page,
 * purely for navigation — every gate, POST handler, and query below is
 * copied verbatim from its original file (now a redirect stub here).
 * Officer (PROC_STAGE_FINANCE_OFFICER) sees: Restock Review
 * (Finance_Officer_Review.php), Payment Queue (Finance_Payment_Queue.php).
 * Head (PROC_STAGE_FINANCE_MANAGER) sees: Final Approval
 * (Finance_Head_Final_Approval.php), Purchase Orders (Issue_Purchase_Order.php
 * + Create_RFQ_Page.php + RFQ_Canvass_Page.php, nested under one tab with
 * their own Direct/Send RFQ/Canvass sub-toggle since those three already
 * form one coherent decision), Payment Confirmation (Payment_Confirmation.php).
 * `act=decide` collided between the Officer's and Head's review pages —
 * the Head's copy is renamed to `act=head_decide` here; nothing else
 * about either handler changed. See docs/procurement/STATUS.md.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';
require_once __DIR__ . '/../includes/supplier_queries.php';
require_once __DIR__ . '/../includes/procurement_rfq_queries.php';

$user = procurement_require_stage([PROC_STAGE_FINANCE_OFFICER, PROC_STAGE_FINANCE_MANAGER]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);
ensure_supplier_tables($conn);
ensure_procurement_rfq_tables($conn);

$is_officer = $user['stage'] === PROC_STAGE_FINANCE_OFFICER;
$is_head    = $user['stage'] === PROC_STAGE_FINANCE_MANAGER;
$user_id    = $user['user_id'];
$full_name  = $user['full_name'];

$msg = '';
$BUDGET_LABELS          = ['within_budget' => 'Within Budget', 'over_budget' => 'Over Budget'];
$PAYMENT_METHODS        = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'check' => 'Check', 'gcash' => 'GCash'];
$PAYMENT_METHOD_LABELS  = $PAYMENT_METHODS;

$__act = $_POST['act'] ?? '';
$__tab_by_act = [
    'decide' => 'restock_review', 'record_payment' => 'payment_queue',
    'head_decide' => 'final_approval', 'issue' => 'purchase_orders',
    'send_rfq' => 'purchase_orders', 'award' => 'purchase_orders', 'cancel_rfq' => 'purchase_orders',
    'confirm_payment' => 'payment_confirmation',
];
$active_tab = $_GET['tab'] ?? ($__tab_by_act[$__act] ?? ($is_officer ? 'restock_review' : 'final_approval'));

$active_subtab = 'direct';
if (isset($_GET['rfq_id']) || $__act === 'award' || $__act === 'cancel_rfq') $active_subtab = 'canvass';
elseif (isset($_GET['request_id']) || $__act === 'send_rfq') $active_subtab = 'send_rfq';

// ════════════════════════════════════════════════════════════════════
// OFFICER TAB: Restock Review (Finance_Officer_Review.php)
// ════════════════════════════════════════════════════════════════════

if ($is_officer && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = $_POST['decision'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    $cost         = ($_POST['estimated_cost'] ?? '') !== '' ? (float) $_POST['estimated_cost'] : null;
    $budget       = $_POST['budget_status'] ?? '';
    $quotation    = trim($_POST['quotation_reference'] ?? '');
    if (mb_strlen($quotation) > 100) $quotation = mb_substr($quotation, 0, 100);
    $budget = array_key_exists($budget, $BUDGET_LABELS) ? $budget : null;

    if (!in_array($decision, ['approve', 'reject', 'return'], true)) {
        $msg = 'error:Invalid decision.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_FINANCE_OFFICER) {
            $msg = 'error:This request is no longer pending your review — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot act on your own request.';
        } elseif (in_array($decision, ['reject', 'return'], true) && $note === '') {
            $msg = 'error:A note is required when rejecting or returning a request.';
        } elseif ($decision === 'approve' && ($cost === null || $cost <= 0 || $budget === null)) {
            $msg = 'error:Enter an estimated cost and select a budget status before approving.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, $decision);

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That decision is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    'UPDATE procurement_requests
                     SET status = ?, last_actor_id = ?, estimated_cost = ?, budget_status = ?, quotation_reference = ?, finance_notes = ?
                     WHERE request_id = ? AND status = ?'
                );
                mysqli_stmt_bind_param($upd, 'sidsssis', $next, $user_id, $cost, $budget, $quotation, $note, $request_id, $old_status);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    $action_label = ['approve' => 'finance_officer_reviewed', 'reject' => 'rejected', 'return' => 'returned_for_revision'][$decision];
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, $action_label, $old_status, $next, $note !== '' ? $note : null);
                    $msg = 'success:Request ' . ($decision === 'approve' ? 'reviewed and forwarded' : ($decision === 'reject' ? 'rejected' : 'returned for revision')) . '.';
                } else {
                    $msg = 'error:This request was already decided or changed — refresh and try again.';
                }
            }
        }
    }
}

$restock_queue = [];
if ($is_officer) {
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name,
                r.quotation_amount, r.shipping_fee, r.quotation_attachment_path, r.quotation_notes, s.name AS supplier_name
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         LEFT JOIN suppliers s ON s.supplier_id = r.supplier_id
         WHERE r.status = ?
         ORDER BY r.created_at ASC'
    );
    $__status = PROC_STATUS_STORE_MANAGER_APPROVED;
    mysqli_stmt_bind_param($stmt, 's', $__status);
    mysqli_stmt_execute($stmt);
    $restock_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($restock_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

// ════════════════════════════════════════════════════════════════════
// OFFICER TAB: Payment Queue (Finance_Payment_Queue.php)
// ════════════════════════════════════════════════════════════════════

if ($is_officer && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'record_payment') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $invoice    = trim((string) ($_POST['invoice_reference'] ?? ''));
    $method     = $_POST['payment_method'] ?? '';
    $amount     = ($_POST['payment_amount'] ?? '') !== '' ? (float) $_POST['payment_amount'] : null;
    $note       = trim((string) ($_POST['payment_notes'] ?? ''));
    if (mb_strlen($invoice) > 100) $invoice = mb_substr($invoice, 0, 100);
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
    $method = array_key_exists($method, $PAYMENT_METHODS) ? $method : null;

    if ($invoice === '' || $method === null || $amount === null || $amount <= 0) {
        $msg = 'error:Enter an invoice reference, payment method, and a valid amount before recording payment.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_FINANCE_OFFICER) {
            $msg = 'error:This request is no longer awaiting payment — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot act on your own request.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, 'approve');

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That action is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    'UPDATE procurement_requests
                     SET status = ?, last_actor_id = ?, invoice_reference = ?, payment_method = ?, payment_amount = ?, payment_recorded_by = ?, payment_recorded_at = NOW(), payment_notes = ?
                     WHERE request_id = ? AND status = ?'
                );
                mysqli_stmt_bind_param($upd, 'sissdisis', $next, $user_id, $invoice, $method, $amount, $user_id, $note, $request_id, $old_status);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, 'payment_recorded', $old_status, $next,
                        "Invoice $invoice, " . $PAYMENT_METHODS[$method] . ', ₱' . number_format($amount, 2) . ($note !== '' ? " — $note" : ''));
                    $msg = 'success:Payment recorded. Awaiting Finance Head disbursement confirmation.';
                } else {
                    $msg = 'error:This request was already updated — refresh and try again.';
                }
            }
        }
    }
}

$payment_queue = [];
if ($is_officer) {
    $__status = PROC_STATUS_RECEIVED_VERIFIED;
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name,
                po.po_id, po.po_number, po.supplier_name, po.total_cost, po.expected_delivery_date
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.status = ?
         ORDER BY r.created_at ASC'
    );
    mysqli_stmt_bind_param($stmt, 's', $__status);
    mysqli_stmt_execute($stmt);
    $payment_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($payment_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_ordered, unit_cost, line_total FROM procurement_po_items WHERE po_id = ? ORDER BY po_item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['po_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

// ════════════════════════════════════════════════════════════════════
// HEAD TAB: Final Approval (Finance_Head_Final_Approval.php)
// ════════════════════════════════════════════════════════════════════

if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'head_decide') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $decision   = $_POST['decision'] ?? '';
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    if (!in_array($decision, ['approve', 'reject', 'return'], true)) {
        $msg = 'error:Invalid decision.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_FINANCE_MANAGER) {
            $msg = 'error:This request is no longer pending your review — someone may have already acted on it.';
        } elseif (procurement_is_self_approval($req, $user_id)) {
            $msg = 'error:You cannot act on your own request.';
        } elseif (in_array($decision, ['reject', 'return'], true) && $note === '') {
            $msg = 'error:A note is required when rejecting or returning a request.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, $decision);

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That decision is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    'UPDATE procurement_requests SET status = ?, last_actor_id = ?
                     WHERE request_id = ? AND status = ?'
                );
                mysqli_stmt_bind_param($upd, 'siis', $next, $user_id, $request_id, $old_status);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    $action_label = ['approve' => 'final_approved', 'reject' => 'rejected', 'return' => 'returned_for_revision'][$decision];
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, $action_label, $old_status, $next, $note !== '' ? $note : null);
                    $msg = 'success:Request ' . ($decision === 'approve' ? 'given final approval — a Purchase Order can now be issued' : ($decision === 'reject' ? 'rejected' : 'returned for revision')) . '.';
                } else {
                    $msg = 'error:This request was already decided or changed — refresh and try again.';
                }
            }
        }
    }
}

$final_approval_queue = [];
if ($is_head) {
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name,
                r.estimated_cost, r.budget_status, r.quotation_reference, r.finance_notes
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         WHERE r.status = ?
         ORDER BY r.created_at ASC'
    );
    $__status = PROC_STATUS_FINANCE_OFFICER_REVIEWED;
    mysqli_stmt_bind_param($stmt, 's', $__status);
    mysqli_stmt_execute($stmt);
    $final_approval_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($final_approval_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

// ════════════════════════════════════════════════════════════════════
// HEAD TAB: Purchase Orders — Direct (Issue_Purchase_Order.php)
// ════════════════════════════════════════════════════════════════════

if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'issue') {
    $request_id      = (int) ($_POST['request_id'] ?? 0);
    $supplier_id     = (int) ($_POST['supplier_id'] ?? 0);
    $expected_date   = trim($_POST['expected_delivery_date'] ?? '');
    $costs           = $_POST['unit_cost'] ?? [];

    $supplierRow   = $supplier_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM suppliers WHERE supplier_id = $supplier_id AND status = 'active'")) : null;
    $supplier_name = $supplierRow['name'] ?? '';

    if (!$supplier_id || !$supplierRow) {
        $msg = 'error:Please select a supplier.';
    } elseif ($expected_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_date)) {
        $msg = 'error:Invalid expected delivery date.';
    } elseif (procurement_open_rfq_for_request($conn, $request_id)) {
        $msg = 'error:An RFQ is already open for this request — award or cancel it on RFQ / Canvass before issuing directly.';
    } else {
        $reqRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_id FROM procurement_requests WHERE request_id = $request_id"));
        $failed = $reqRow ? null : 'Request not found.';

        $items = [];
        if (!$failed) {
            $istmt = mysqli_prepare($conn,
                'SELECT item_id, item_name, unit, qty_requested, quoted_unit_price FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC'
            );
            mysqli_stmt_bind_param($istmt, 'i', $request_id);
            mysqli_stmt_execute($istmt);
            $items = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
            mysqli_stmt_close($istmt);

            if (empty($items)) {
                $failed = 'This request has no line items.';
            }
        }

        $lineData = [];
        if (!$failed) {
            foreach ($items as $it) {
                // A price the supplier already quoted during the stock check
                // is locked — never trust a client-submitted override for it,
                // re-derive from the DB every time. Only items with no quote
                // on file (e.g. request went straight through Owner-era data,
                // or a supplier was never assigned) fall back to manual entry.
                if ($it['quoted_unit_price'] !== null) {
                    $unit_cost = (float) $it['quoted_unit_price'];
                } else {
                    $raw = trim((string) ($costs[$it['item_id']] ?? ''));
                    if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
                        $failed = 'Enter a valid unit cost (0 or more) for every item.';
                        break;
                    }
                    $unit_cost = round((float) $raw, 2);
                }
                $qty       = (float) $it['qty_requested'];
                $line_total = round($unit_cost * $qty, 2);
                $lineData[] = [
                    'request_item_id' => (int) $it['item_id'],
                    'item_name'       => $it['item_name'],
                    'unit'            => $it['unit'],
                    'qty_ordered'     => $qty,
                    'unit_cost'       => $unit_cost,
                    'line_total'      => $line_total,
                ];
            }
        }

        if ($failed) {
            $msg = 'error:' . $failed;
        } else {
            $result = procurement_issue_po_from_lines(
                $conn, $request_id, (int) $reqRow['branch_id'], $supplier_id, $supplier_name,
                $expected_date, $lineData, $user_id, $full_name
            );
            $msg = $result['ok']
                ? 'success:Purchase Order ' . $result['po_number'] . ' issued.'
                : 'error:' . $result['error'];
        }
    }
}

$po_direct_queue = [];
$active_suppliers = [];
$open_rfqs_by_request = [];
if ($is_head) {
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.requested_by_name, r.notes, r.created_at, r.branch_id, b.branch_name,
                r.estimated_cost, r.quotation_reference
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         LEFT JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.status = ? AND po.po_id IS NULL
         ORDER BY r.created_at ASC"
    );
    $__status = PROC_STATUS_FINAL_APPROVED;
    mysqli_stmt_bind_param($stmt, 's', $__status);
    mysqli_stmt_execute($stmt);
    $po_direct_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $sres = mysqli_query($conn, "SELECT supplier_id, name FROM suppliers WHERE status = 'active' ORDER BY name");
    if ($sres) while ($s = mysqli_fetch_assoc($sres)) $active_suppliers[] = $s;

    if ($po_direct_queue) {
        $ids = implode(',', array_map(fn($r) => (int) $r['request_id'], $po_direct_queue));
        $rres = mysqli_query($conn, "SELECT rfq_id, rfq_number, request_id FROM procurement_rfqs WHERE request_id IN ($ids) AND status = 'open'");
        if ($rres) while ($rf = mysqli_fetch_assoc($rres)) $open_rfqs_by_request[$rf['request_id']] = $rf;
    }

    foreach ($po_direct_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_id, item_name, unit, qty_requested, quoted_unit_price FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

// ════════════════════════════════════════════════════════════════════
// HEAD TAB: Purchase Orders — Send RFQ (Create_RFQ_Page.php)
// ════════════════════════════════════════════════════════════════════

if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'send_rfq') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $supplier_ids = array_map('intval', $_POST['supplier_ids'] ?? []);
    $deadline = trim($_POST['quotation_deadline'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $req = $request_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM procurement_requests WHERE request_id = $request_id")) : null;

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_FINAL_APPROVED) {
        $msg = 'error:This request is not awaiting a Purchase Order.';
    } elseif (procurement_open_rfq_for_request($conn, $request_id)) {
        $msg = 'error:An RFQ is already open for this request.';
    } elseif (empty($supplier_ids)) {
        $msg = 'error:Select at least one supplier to invite.';
    } elseif ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        $msg = 'error:Invalid quotation deadline.';
    } else {
        $idsIn = implode(',', $supplier_ids);
        $validRes = mysqli_query($conn, "SELECT supplier_id FROM suppliers WHERE supplier_id IN ($idsIn) AND status = 'active'");
        $validIds = [];
        if ($validRes) while ($v = mysqli_fetch_assoc($validRes)) $validIds[] = (int) $v['supplier_id'];

        if (empty($validIds)) {
            $msg = 'error:None of the selected suppliers are active.';
        } else {
            $items = [];
            $ires = mysqli_query($conn, "SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = $request_id");
            if ($ires) while ($i = mysqli_fetch_assoc($ires)) $items[] = $i;

            if (empty($items)) {
                $msg = 'error:This request has no line items.';
            } else {
                mysqli_begin_transaction($conn);
                $failed = null;

                $deadlineOrNull = $deadline !== '' ? $deadline : null;
                $notesOrNull = $notes !== '' ? $notes : null;
                $ins = mysqli_prepare($conn,
                    "INSERT INTO procurement_rfqs (request_id, quotation_deadline, notes, created_by, created_by_name) VALUES (?,?,?,?,?)");
                mysqli_stmt_bind_param($ins, 'issis', $request_id, $deadlineOrNull, $notesOrNull, $user_id, $full_name);
                if (!mysqli_stmt_execute($ins)) {
                    $failed = 'Could not create the RFQ.';
                } else {
                    $rfq_id = mysqli_insert_id($conn);
                }
                mysqli_stmt_close($ins);

                if (!$failed) {
                    $rfq_number = procurement_rfq_number($rfq_id);
                    $upd = mysqli_prepare($conn, "UPDATE procurement_rfqs SET rfq_number = ? WHERE rfq_id = ?");
                    mysqli_stmt_bind_param($upd, 'si', $rfq_number, $rfq_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);

                    $itemStmt = mysqli_prepare($conn,
                        "INSERT INTO procurement_rfq_items (rfq_id, request_item_id, item_name, unit, qty_needed) VALUES (?,?,?,?,?)");
                    foreach ($items as $it) {
                        $itemId = (int) $it['item_id'];
                        mysqli_stmt_bind_param($itemStmt, 'iissd', $rfq_id, $itemId, $it['item_name'], $it['unit'], $it['qty_requested']);
                        if (!mysqli_stmt_execute($itemStmt)) { $failed = 'Could not save RFQ line items.'; break; }
                    }
                    mysqli_stmt_close($itemStmt);
                }

                if (!$failed) {
                    $supStmt = mysqli_prepare($conn, "INSERT INTO procurement_rfq_suppliers (rfq_id, supplier_id) VALUES (?,?)");
                    foreach ($validIds as $sid) {
                        mysqli_stmt_bind_param($supStmt, 'ii', $rfq_id, $sid);
                        if (!mysqli_stmt_execute($supStmt)) { $failed = 'Could not invite suppliers.'; break; }
                    }
                    mysqli_stmt_close($supStmt);
                }

                if ($failed) {
                    mysqli_rollback($conn);
                    $msg = 'error:' . $failed;
                } else {
                    mysqli_commit($conn);
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, 'rfq_sent',
                        $req['status'], $req['status'],
                        $rfq_number . ' sent to ' . count($validIds) . ' supplier(s).');
                    header('Location: Procurement_Hub.php?tab=purchase_orders&rfq_sent=' . urlencode($rfq_number));
                    exit;
                }
            }
        }
    }
}

$rfq_pick_request_id = (int) ($_GET['request_id'] ?? 0);
$rfq_pick_request = null;
$rfq_pick_items = [];
if ($rfq_pick_request_id) {
    $rfq_pick_request = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT r.request_id, r.status, r.requested_by_name, r.created_at, b.branch_name
          FROM procurement_requests r
          LEFT JOIN branches b ON b.branch_id = r.branch_id
         WHERE r.request_id = $rfq_pick_request_id"));
    if ($rfq_pick_request) {
        $ires = mysqli_query($conn, "SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = $rfq_pick_request_id ORDER BY item_id");
        if ($ires) while ($i = mysqli_fetch_assoc($ires)) $rfq_pick_items[] = $i;
    }
}

$rfq_eligible_requests = [];
if ($is_head && !$rfq_pick_request) {
    $qres = mysqli_query($conn, "
        SELECT r.request_id, r.requested_by_name, r.created_at, b.branch_name
          FROM procurement_requests r
          LEFT JOIN branches b ON b.branch_id = r.branch_id
          LEFT JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.status = 'FINAL_APPROVED' AND po.po_id IS NULL
           AND r.request_id NOT IN (SELECT request_id FROM procurement_rfqs WHERE status = 'open')
         ORDER BY r.created_at ASC");
    if ($qres) while ($q = mysqli_fetch_assoc($qres)) $rfq_eligible_requests[] = $q;
}

// ════════════════════════════════════════════════════════════════════
// HEAD TAB: Purchase Orders — RFQ / Canvass (RFQ_Canvass_Page.php)
// ════════════════════════════════════════════════════════════════════

if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'award') {
    $rfq_id      = (int) ($_POST['rfq_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

    $rfq = $rfq_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM procurement_rfqs WHERE rfq_id = $rfq_id")) : null;

    $invited = $rfq ? mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM procurement_rfq_suppliers WHERE rfq_id = $rfq_id AND supplier_id = $supplier_id")) : null;
    $quotation = $invited ? mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM supplier_quotations WHERE rfq_id = $rfq_id AND supplier_id = $supplier_id")) : null;

    if (!$rfq || $rfq['status'] !== 'open') {
        $msg = 'error:This RFQ is not open.';
    } elseif (!$invited || !$quotation) {
        $msg = 'error:That supplier has not submitted a quotation for this RFQ.';
    } else {
        $supplierRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM suppliers WHERE supplier_id = $supplier_id"));

        $lineData = [];
        $qires = mysqli_query($conn, "
            SELECT ri.request_item_id, ri.item_name, ri.unit, ri.qty_needed, qi.unit_price, qi.line_total
              FROM procurement_rfq_items ri
              JOIN supplier_quotation_items qi ON qi.rfq_item_id = ri.rfq_item_id AND qi.quotation_id = " . (int) $quotation['quotation_id'] . "
             WHERE ri.rfq_id = $rfq_id");
        if ($qires) while ($row = mysqli_fetch_assoc($qires)) {
            $lineData[] = [
                'request_item_id' => $row['request_item_id'] !== null ? (int) $row['request_item_id'] : null,
                'item_name'       => $row['item_name'],
                'unit'            => $row['unit'],
                'qty_ordered'     => (float) $row['qty_needed'],
                'unit_cost'       => (float) $row['unit_price'],
                'line_total'      => (float) $row['line_total'],
            ];
        }

        $branchRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_id FROM procurement_requests WHERE request_id = " . (int) $rfq['request_id']));

        if (empty($lineData)) {
            $msg = 'error:That quotation has no priced line items.';
        } elseif (!$branchRow) {
            $msg = 'error:Could not resolve the branch for this request.';
        } else {
            $result = procurement_issue_po_from_lines(
                $conn, (int) $rfq['request_id'], (int) $branchRow['branch_id'], $supplier_id, $supplierRow['name'] ?? '',
                null, $lineData, $user_id, $full_name
            );

            if (!$result['ok']) {
                $msg = 'error:' . $result['error'];
            } else {
                mysqli_query($conn, "UPDATE procurement_rfqs SET status='awarded', awarded_supplier_id=$supplier_id, awarded_po_id=" . (int) $result['po_id'] . ", awarded_at=NOW() WHERE rfq_id=$rfq_id");
                mysqli_query($conn, "UPDATE procurement_rfq_suppliers SET status='awarded' WHERE rfq_id=$rfq_id AND supplier_id=$supplier_id");
                mysqli_query($conn, "UPDATE procurement_rfq_suppliers SET status='not_awarded' WHERE rfq_id=$rfq_id AND supplier_id<>$supplier_id");
                mysqli_query($conn, "UPDATE supplier_quotations SET status='awarded' WHERE rfq_id=$rfq_id AND supplier_id=$supplier_id");
                mysqli_query($conn, "UPDATE supplier_quotations SET status='not_awarded' WHERE rfq_id=$rfq_id AND supplier_id<>$supplier_id");
                $msg = 'success:Awarded to ' . htmlspecialchars($supplierRow['name'] ?? '') . ' — Purchase Order ' . $result['po_number'] . ' issued.';
            }
        }
    }
} elseif ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'cancel_rfq') {
    $rfq_id = (int) ($_POST['rfq_id'] ?? 0);
    mysqli_query($conn, "UPDATE procurement_rfqs SET status='cancelled' WHERE rfq_id=$rfq_id AND status='open'");
    $msg = 'success:RFQ cancelled. You can issue a Purchase Order directly or send a new RFQ.';
}

$canvass_rfq_id = (int) ($_GET['rfq_id'] ?? 0);
$canvass_rfq = null;
if ($canvass_rfq_id) {
    $canvass_rfq = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT f.*, r.branch_id, r.requested_by_name, b.branch_name
          FROM procurement_rfqs f
          JOIN procurement_requests r ON r.request_id = f.request_id
          LEFT JOIN branches b ON b.branch_id = r.branch_id
         WHERE f.rfq_id = $canvass_rfq_id"));
}

$canvass_items = [];
$canvass_suppliers = [];
$canvass_quotations_by_supplier = [];
$canvass_items_by_quotation = [];
if ($canvass_rfq) {
    $ires = mysqli_query($conn, "SELECT * FROM procurement_rfq_items WHERE rfq_id = $canvass_rfq_id ORDER BY rfq_item_id");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) $canvass_items[] = $i;

    $sres = mysqli_query($conn, "
        SELECT rs.supplier_id, rs.status AS invite_status, s.name
          FROM procurement_rfq_suppliers rs
          JOIN suppliers s ON s.supplier_id = rs.supplier_id
         WHERE rs.rfq_id = $canvass_rfq_id ORDER BY s.name");
    if ($sres) while ($s = mysqli_fetch_assoc($sres)) $canvass_suppliers[] = $s;

    $qres = mysqli_query($conn, "SELECT * FROM supplier_quotations WHERE rfq_id = $canvass_rfq_id");
    if ($qres) while ($q = mysqli_fetch_assoc($qres)) $canvass_quotations_by_supplier[$q['supplier_id']] = $q;

    if ($canvass_quotations_by_supplier) {
        $qids = implode(',', array_map(fn($q) => (int) $q['quotation_id'], $canvass_quotations_by_supplier));
        $qires = mysqli_query($conn, "SELECT * FROM supplier_quotation_items WHERE quotation_id IN ($qids)");
        if ($qires) while ($qi = mysqli_fetch_assoc($qires)) $canvass_items_by_quotation[$qi['quotation_id']][$qi['rfq_item_id']] = $qi;
    }
}

$canvass_all_rfqs = [];
if ($is_head && !$canvass_rfq) {
    $lres = mysqli_query($conn, "
        SELECT f.rfq_id, f.rfq_number, f.status, f.created_at, f.quotation_deadline,
               r.request_id, b.branch_name,
               (SELECT COUNT(*) FROM procurement_rfq_suppliers WHERE rfq_id = f.rfq_id) AS invited_count,
               (SELECT COUNT(*) FROM supplier_quotations WHERE rfq_id = f.rfq_id) AS quoted_count
          FROM procurement_rfqs f
          JOIN procurement_requests r ON r.request_id = f.request_id
          LEFT JOIN branches b ON b.branch_id = r.branch_id
         ORDER BY (f.status = 'open') DESC, f.created_at DESC");
    if ($lres) while ($l = mysqli_fetch_assoc($lres)) $canvass_all_rfqs[] = $l;
}

// ════════════════════════════════════════════════════════════════════
// HEAD TAB: Payment Confirmation (Payment_Confirmation.php)
// ════════════════════════════════════════════════════════════════════

if ($is_head && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'confirm_payment') {
    $request_id = (int) ($_POST['request_id'] ?? 0);

    $stmt = mysqli_prepare($conn,
        'SELECT request_id, status, requested_by, last_actor_id FROM procurement_requests WHERE request_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif (procurement_required_stage($req['status']) !== PROC_STAGE_FINANCE_MANAGER) {
        $msg = 'error:This request is no longer awaiting disbursement confirmation — someone may have already acted on it.';
    } elseif (procurement_is_self_approval($req, $user_id)) {
        $msg = 'error:You cannot confirm a payment you recorded yourself.';
    } else {
        $old_status = $req['status'];
        $next = procurement_next_status($old_status, 'approve');

        if (!$next || !procurement_can_transition($old_status, $next)) {
            $msg = 'error:That action is not valid for this request\'s current state.';
        } else {
            $upd = mysqli_prepare($conn,
                'UPDATE procurement_requests
                 SET status = ?, last_actor_id = ?, payment_confirmed_by = ?, payment_confirmed_at = NOW()
                 WHERE request_id = ? AND status = ?'
            );
            mysqli_stmt_bind_param($upd, 'siiis', $next, $user_id, $user_id, $request_id, $old_status);
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($changed === 1) {
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'payment_confirmed', $old_status, $next, 'Disbursement confirmed.');
                $msg = 'success:Payment confirmed as disbursed.';
            } else {
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

$confirmation_queue = [];
if ($is_head) {
    $__status = PROC_STATUS_PAYMENT_PENDING;
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.requested_by_name, r.branch_id, b.branch_name,
                r.invoice_reference, r.payment_method, r.payment_amount, r.payment_notes, r.payment_recorded_at,
                po.po_id, po.po_number, po.supplier_name, po.total_cost
         FROM procurement_requests r
         LEFT JOIN branches b ON b.branch_id = r.branch_id
         JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.status = ?
         ORDER BY r.payment_recorded_at ASC'
    );
    mysqli_stmt_bind_param($stmt, 's', $__status);
    mysqli_stmt_execute($stmt);
    $confirmation_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

$rfq_sent_banner = $_GET['rfq_sent'] ?? '';
$activePage = 'proc-hub';
$rangeQuery = http_build_query(['range' => 'this_month', 'from' => (new DateTime('first day of this month'))->format('Y-m-d'), 'to' => (new DateTime('today'))->format('Y-m-d')]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Procurement — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}
    .req-actions{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;}
    .req-actions input[type=text],.req-actions input[type=number],.req-actions select{flex:1;min-width:150px;}
    .btn-reject{background:#fdeaea;color:#c0392b;border:1px solid #f3c6c6;}
    .btn-return{background:#fff4e5;color:#b45300;border:1px solid #f0d6a8;}
    .branch-tag{font-size:12px;color:var(--text-light);}
    .finance-box{background:#f7f8fa;border-radius:8px;padding:10px 14px;font-size:13px;margin:10px 0;display:flex;gap:20px;flex-wrap:wrap;}
    .finance-box b{color:var(--ink,#222);}
    .po-item-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;}
    .po-item-row .po-item-name{flex:1;}
    .po-item-row input[type=number]{width:110px;}
    .po-form-row{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0;}
    .po-form-row input[type=text],.po-form-row input[type=date],.po-form-row select{flex:1;min-width:180px;}
    .po-total{font-size:13px;font-weight:600;margin-top:6px;}
    .supplier-check-row{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13.5px;}
    .canvass-table{width:100%;border-collapse:collapse;margin:14px 0;}
    .canvass-table th,.canvass-table td{border:1px solid var(--border,#e5e7eb);padding:9px 10px;font-size:13px;text-align:left;}
    .canvass-table th{background:var(--mc-parchment,#FAF5EC);}
    .canvass-total-row td{font-weight:700;background:#fafafa;}
    .rfq-status-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
    .status-badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11.5px;font-weight:600;background:#e7f5ec;color:#1e7e42;}
    .hub-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border,#e5e7eb);flex-wrap:wrap;}
    .hub-tab-btn{padding:10px 16px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:600;color:var(--text-light,#6b6156);border-bottom:2px solid transparent;}
    .hub-tab-btn.active{color:var(--mc-espresso,#2A1B14);border-bottom-color:#b8703f;}
    .hub-tab-panel{display:none;}
    .hub-tab-panel.active{display:block;}
    .hub-subtabs{display:flex;gap:8px;margin-bottom:16px;}
    .hub-subtab-btn{padding:7px 14px;border-radius:20px;border:1px solid var(--border,#e5e7eb);background:#fff;cursor:pointer;font-size:12.5px;font-weight:600;}
    .hub-subtab-btn.active{background:#b8703f;color:#fff;border-color:#b8703f;}
    .hub-subtab-panel{display:none;}
    .hub-subtab-panel.active{display:block;}
  </style>
</head>

<body>
  <?php require_once __DIR__ . '/includes/finance_sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Procurement</div>
      </div>
    </div>

    <div class="content">
      <?php if ($msg): [$kind, $text] = explode(':', $msg, 2); ?>
        <div class="alert-strip">
          <div class="alert-card <?= $kind === 'success' ? '' : 'danger' ?>">
            <div class="alert-icon"><i data-lucide="<?= $kind === 'success' ? 'circle-check' : 'circle-alert' ?>"></i></div>
            <div><?= htmlspecialchars($text) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <div class="hub-tabs">
        <?php if ($is_officer): ?>
          <button type="button" class="hub-tab-btn" data-tab="restock_review">Restock Review</button>
          <button type="button" class="hub-tab-btn" data-tab="payment_queue">Payment Queue</button>
        <?php else: ?>
          <button type="button" class="hub-tab-btn" data-tab="final_approval">Final Approval</button>
          <button type="button" class="hub-tab-btn" data-tab="purchase_orders">Purchase Orders</button>
          <button type="button" class="hub-tab-btn" data-tab="payment_confirmation">Payment Confirmation</button>
        <?php endif; ?>
      </div>

      <?php if ($is_officer): ?>
      <!-- ══════════ TAB: Restock Review ══════════ -->
      <div class="hub-tab-panel" data-tab="restock_review">
        <?php if (empty($restock_queue)): ?>
          <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">No requests waiting for finance review.</div>
        <?php else: foreach ($restock_queue as $r): ?>
          <div class="req-card">
            <div class="req-card-head">
              <div>
                <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                — <span class="branch-tag"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
                — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
                on <?= date('M d, Y', strtotime($r['created_at'])) ?>
              </div>
              <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">STORE MANAGER APPROVED</span>
            </div>
            <?php if (!empty($r['notes'])): ?>
              <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Requester note: <?= htmlspecialchars($r['notes']) ?></div>
            <?php endif; ?>
            <ul class="req-items">
              <?php foreach ($r['items'] as $it): ?>
                <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($r['supplier_name']): ?>
              <div class="finance-box">
                <div>Supplier: <b><?= htmlspecialchars($r['supplier_name']) ?></b></div>
                <div>Quoted: <b><?= $r['quotation_amount'] !== null ? '₱' . number_format((float) $r['quotation_amount'], 2) : '—' ?></b></div>
                <div>Shipping: <b><?= $r['shipping_fee'] !== null ? '₱' . number_format((float) $r['shipping_fee'], 2) : '—' ?></b></div>
                <?php if ($r['quotation_attachment_path']): ?>
                  <div><a href="../<?= htmlspecialchars($r['quotation_attachment_path']) ?>" target="_blank" rel="noopener">View quotation document →</a></div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <form method="POST" action="?tab=restock_review" class="req-actions" onsubmit="return confirm('Confirm this decision?');">
              <input type="hidden" name="act" value="decide" />
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
              <input type="number" name="estimated_cost" step="0.01" min="0" placeholder="Est. cost (₱)" <?= $r['quotation_amount'] !== null ? 'value="' . htmlspecialchars((string) round((float) $r['quotation_amount'] + (float) ($r['shipping_fee'] ?? 0), 2)) . '"' : '' ?> />
              <select name="budget_status">
                <option value="">Budget status…</option>
                <?php foreach ($BUDGET_LABELS as $val => $label): ?>
                  <option value="<?= $val ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="quotation_reference" maxlength="100" placeholder="Quotation / reference #" />
              <input type="text" name="note" maxlength="255" placeholder="Finance note (required for reject/return)" />
              <button type="submit" name="decision" value="approve" class="btn-save">Approve</button>
              <button type="submit" name="decision" value="return" class="btn-return">Return for Revision</button>
              <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ══════════ TAB: Payment Queue ══════════ -->
      <div class="hub-tab-panel" data-tab="payment_queue">
        <p class="req-items">Requests whose delivery has been fully received and verified — ready for payment processing.</p>
        <?php if (empty($payment_queue)): ?>
          <p>No requests are currently ready for payment.</p>
        <?php else: foreach ($payment_queue as $r): ?>
          <div class="req-card">
            <div class="req-card-head">
              <div>
                <strong><?= htmlspecialchars($r['po_number'] ?? ('PO-' . str_pad((string) $r['po_id'], 6, '0', STR_PAD_LEFT))) ?></strong>
                &middot; Request #<?= (int) $r['request_id'] ?>
                <span class="branch-tag"> — <?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
              </div>
              <span class="status-badge">RECEIVED VERIFIED</span>
            </div>
            <div class="req-items">
              Requested by <?= htmlspecialchars($r['requested_by_name']) ?> on <?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?>
              &middot; Supplier: <?= htmlspecialchars($r['supplier_name']) ?>
              <?php if ($r['expected_delivery_date']): ?> &middot; Expected delivery: <?= htmlspecialchars(date('M j, Y', strtotime($r['expected_delivery_date']))) ?><?php endif; ?>
            </div>
            <ul class="req-items">
              <?php foreach ($r['items'] as $it): ?>
                <li><?= htmlspecialchars($it['item_name']) ?> — <?= htmlspecialchars($it['qty_ordered']) ?> <?= htmlspecialchars($it['unit']) ?>
                  @ &#8369;<?= number_format((float) $it['unit_cost'], 2) ?> = &#8369;<?= number_format((float) $it['line_total'], 2) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($r['notes']): ?><div class="req-items">Note: <?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>
            <div class="po-total">Total due: &#8369;<?= number_format((float) $r['total_cost'], 2) ?></div>
            <form method="post" action="?tab=payment_queue" class="req-actions" style="margin-top:12px;flex-direction:column;align-items:stretch;">
              <input type="hidden" name="act" value="record_payment">
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" name="invoice_reference" placeholder="Invoice reference" required style="flex:1;min-width:150px;">
                <select name="payment_method" required style="flex:1;min-width:150px;">
                  <option value="">Payment method…</option>
                  <?php foreach ($PAYMENT_METHODS as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
                </select>
                <input type="number" step="0.01" min="0.01" name="payment_amount" value="<?= number_format((float) $r['total_cost'], 2, '.', '') ?>" required style="flex:1;min-width:120px;">
              </div>
              <input type="text" name="payment_notes" placeholder="Notes (optional)" style="margin-top:8px;">
              <button type="submit" style="margin-top:8px;">Record Payment</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($is_head): ?>
      <!-- ══════════ TAB: Final Approval ══════════ -->
      <div class="hub-tab-panel" data-tab="final_approval">
        <?php if (empty($final_approval_queue)): ?>
          <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">No requests waiting for your final approval.</div>
        <?php else: foreach ($final_approval_queue as $r): ?>
          <div class="req-card">
            <div class="req-card-head">
              <div>
                <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                — <span class="branch-tag"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
                — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
                on <?= date('M d, Y', strtotime($r['created_at'])) ?>
              </div>
              <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">FINANCE OFFICER REVIEWED</span>
            </div>
            <?php if (!empty($r['notes'])): ?><div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Requester note: <?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>
            <ul class="req-items">
              <?php foreach ($r['items'] as $it): ?><li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li><?php endforeach; ?>
            </ul>
            <div class="finance-box">
              <div>Est. Cost: <b><?= $r['estimated_cost'] !== null ? '₱' . number_format((float) $r['estimated_cost'], 2) : '—' ?></b></div>
              <div>Budget: <b><?= htmlspecialchars($BUDGET_LABELS[$r['budget_status']] ?? '—') ?></b></div>
              <div>Quotation Ref: <b><?= htmlspecialchars($r['quotation_reference'] ?: '—') ?></b></div>
              <div>Finance Note: <b><?= htmlspecialchars($r['finance_notes'] ?: '—') ?></b></div>
            </div>
            <form method="POST" action="?tab=final_approval" class="req-actions" onsubmit="return confirm('Confirm this decision?');">
              <input type="hidden" name="act" value="head_decide" />
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
              <input type="text" name="note" maxlength="255" placeholder="Note (required for reject/return)" />
              <button type="submit" name="decision" value="approve" class="btn-save">Final Approve</button>
              <button type="submit" name="decision" value="return" class="btn-return">Return for Revision</button>
              <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ══════════ TAB: Purchase Orders ══════════ -->
      <div class="hub-tab-panel" data-tab="purchase_orders">
        <?php if ($rfq_sent_banner): ?>
          <div style="background:#e6f4ea;color:#2f6f4e;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13.5px;"><?= htmlspecialchars($rfq_sent_banner) ?> sent.</div>
        <?php endif; ?>
        <div class="hub-subtabs">
          <button type="button" class="hub-subtab-btn" data-subtab="direct">Issue Directly</button>
          <button type="button" class="hub-subtab-btn" data-subtab="send_rfq">Send RFQ</button>
          <button type="button" class="hub-subtab-btn" data-subtab="canvass">RFQ / Canvass</button>
        </div>

        <div class="hub-subtab-panel" data-subtab="direct">
          <?php if (empty($po_direct_queue)): ?>
            <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">No finally-approved requests are waiting for a Purchase Order.</div>
          <?php else: foreach ($po_direct_queue as $r): ?>
            <div class="req-card">
              <div class="req-card-head">
                <div>
                  <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                  — <span class="branch-tag"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
                  — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
                  on <?= date('M d, Y', strtotime($r['created_at'])) ?>
                </div>
                <span class="req-pill" style="background:#e7f1ff;color:#1a5fb4;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">FINAL APPROVED</span>
              </div>
              <?php if ($r['estimated_cost'] !== null || $r['quotation_reference']): ?>
                <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">
                  Finance est.: <?= $r['estimated_cost'] !== null ? '₱' . number_format((float) $r['estimated_cost'], 2) : '—' ?>
                  <?php if ($r['quotation_reference']): ?> · Quotation ref: <?= htmlspecialchars($r['quotation_reference']) ?><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($openRfq = $open_rfqs_by_request[$r['request_id']] ?? null): ?>
                <div style="background:#fff8e6;border:1px solid #f0d68a;border-radius:10px;padding:12px 14px;font-size:13px;color:#7a5b00;">
                  <?= htmlspecialchars($openRfq['rfq_number']) ?> sent — awaiting supplier quotations.
                  <a href="?tab=purchase_orders&rfq_id=<?= (int) $openRfq['rfq_id'] ?>" style="font-weight:700;">View / Award →</a>
                </div>
              <?php else: ?>
                <div style="margin-bottom:10px;">
                  <a href="?tab=purchase_orders&request_id=<?= (int) $r['request_id'] ?>" style="display:inline-block;font-size:12.5px;font-weight:600;color:var(--mc-espresso,#2A1B14);border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:7px 12px;text-decoration:none;">Send RFQ to multiple suppliers →</a>
                </div>
                <form method="POST" action="?tab=purchase_orders" onsubmit="return confirm('Issue this Purchase Order? This cannot be undone.');">
                  <input type="hidden" name="act" value="issue" />
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                  <div class="po-form-row">
                    <select name="supplier_id" required>
                      <option value="">— Select supplier *</option>
                      <?php foreach ($active_suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                    </select>
                    <input type="date" name="expected_delivery_date" />
                  </div>
                  <?php if (empty($active_suppliers)): ?>
                    <div style="font-size:12px;color:var(--danger,#b8453a);margin:-6px 0 10px">No active suppliers yet — see <a href="../admin/Supplier_List.php">Suppliers</a>.</div>
                  <?php endif; ?>
                  <?php foreach ($r['items'] as $it): ?>
                    <div class="po-item-row">
                      <span class="po-item-name"><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span>
                      <?php if ($it['quoted_unit_price'] !== null): ?>
                        <span style="width:110px;font-weight:600;" title="Already quoted by the supplier during the stock check — locked.">₱<?= number_format((float) $it['quoted_unit_price'], 2) ?></span>
                      <?php else: ?>
                        <input type="number" step="0.01" min="0" name="unit_cost[<?= (int) $it['item_id'] ?>]" placeholder="Unit cost" required />
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <button type="submit" class="btn-save" style="margin-top:8px;">Issue Purchase Order</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="hub-subtab-panel" data-subtab="send_rfq">
          <?php if (!$rfq_pick_request): ?>
            <div class="req-card">
              <div style="margin-bottom:12px;font-weight:700;">Select a request to send out for quotation</div>
              <?php if (empty($rfq_eligible_requests)): ?>
                <div style="color:var(--text-light);">No requests are eligible right now — a request must be FINAL_APPROVED, have no Purchase Order yet, and have no other open RFQ.</div>
              <?php else: foreach ($rfq_eligible_requests as $q): ?>
                <div class="po-item-row">
                  <span class="po-item-name">REQ-<?= str_pad((string) $q['request_id'], 4, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($q['branch_name'] ?? 'Unknown Branch') ?> — <?= htmlspecialchars($q['requested_by_name']) ?></span>
                  <a href="?tab=purchase_orders&request_id=<?= (int) $q['request_id'] ?>" class="btn-save" style="text-decoration:none;padding:7px 16px;">Select</a>
                </div>
              <?php endforeach; endif; ?>
            </div>
          <?php else: ?>
            <div class="req-card">
              <div class="req-card-head">
                <div>
                  <strong>REQ-<?= str_pad((string) $rfq_pick_request['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                  — <span class="branch-tag"><?= htmlspecialchars($rfq_pick_request['branch_name'] ?? 'Unknown Branch') ?></span>
                  — requested by <?= htmlspecialchars($rfq_pick_request['requested_by_name']) ?>
                </div>
              </div>
              <div style="margin:14px 0;">
                <strong style="font-size:13px;">Items in this request</strong>
                <?php foreach ($rfq_pick_items as $it): ?>
                  <div class="po-item-row"><span class="po-item-name"><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span></div>
                <?php endforeach; ?>
              </div>
              <?php if ($rfq_pick_request['status'] !== 'FINAL_APPROVED'): ?>
                <div style="color:var(--danger,#b8453a);">This request is not (or no longer) awaiting a Purchase Order.</div>
              <?php else: ?>
              <form method="POST" action="?tab=purchase_orders">
                <input type="hidden" name="act" value="send_rfq" />
                <input type="hidden" name="request_id" value="<?= (int) $rfq_pick_request['request_id'] ?>" />
                <div style="margin-bottom:14px;">
                  <strong style="font-size:13px;">Invite suppliers *</strong>
                  <?php if (empty($active_suppliers)): ?>
                    <div style="font-size:12px;color:var(--danger,#b8453a);">No active suppliers yet — add one in <a href="../admin/Supplier_List.php">Suppliers</a> first.</div>
                  <?php else: foreach ($active_suppliers as $s): ?>
                    <div class="supplier-check-row">
                      <input type="checkbox" name="supplier_ids[]" value="<?= (int) $s['supplier_id'] ?>" id="sup-<?= (int) $s['supplier_id'] ?>">
                      <label for="sup-<?= (int) $s['supplier_id'] ?>"><?= htmlspecialchars($s['name']) ?></label>
                    </div>
                  <?php endforeach; endif; ?>
                </div>
                <div class="po-form-row"><input type="date" name="quotation_deadline" placeholder="Quotation deadline" /></div>
                <div class="po-form-row"><input type="text" name="notes" maxlength="255" placeholder="Notes for suppliers (optional)" /></div>
                <button type="submit" class="btn-save">Send RFQ</button>
                <a href="?tab=purchase_orders" style="margin-left:10px;font-size:13px;">Cancel</a>
              </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="hub-subtab-panel" data-subtab="canvass">
          <?php if (!$canvass_rfq): ?>
            <div class="req-card">
              <table class="canvass-table">
                <thead><tr><th>RFQ #</th><th>Request</th><th>Branch</th><th>Status</th><th>Invited</th><th>Quoted</th><th>Deadline</th><th></th></tr></thead>
                <tbody>
                  <?php if (empty($canvass_all_rfqs)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-light);">No RFQs sent yet.</td></tr>
                  <?php else: foreach ($canvass_all_rfqs as $r):
                    $pillBg = ['open' => '#fff8e6', 'awarded' => '#e6f4ea', 'cancelled' => '#f1f1f1'][$r['status']];
                    $pillFg = ['open' => '#7a5b00', 'awarded' => '#2f6f4e', 'cancelled' => '#6b6156'][$r['status']];
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($r['rfq_number'] ?? ('#' . $r['rfq_id'])) ?></td>
                      <td>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></td>
                      <td><?= htmlspecialchars($r['branch_name'] ?? '—') ?></td>
                      <td><span class="rfq-status-pill" style="background:<?= $pillBg ?>;color:<?= $pillFg ?>;"><?= ucfirst($r['status']) ?></span></td>
                      <td><?= (int) $r['invited_count'] ?></td>
                      <td><?= (int) $r['quoted_count'] ?></td>
                      <td><?= $r['quotation_deadline'] ? date('M j, Y', strtotime($r['quotation_deadline'])) : '—' ?></td>
                      <td><a href="?tab=purchase_orders&rfq_id=<?= (int) $r['rfq_id'] ?>">View</a></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="req-card">
              <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;">
                <div>
                  <strong><?= htmlspecialchars($canvass_rfq['rfq_number'] ?? ('#' . $canvass_rfq['rfq_id'])) ?></strong>
                  — REQ-<?= str_pad((string) $canvass_rfq['request_id'], 4, '0', STR_PAD_LEFT) ?>
                  — <span style="color:var(--text-light);"><?= htmlspecialchars($canvass_rfq['branch_name'] ?? 'Unknown Branch') ?></span>
                </div>
                <a href="?tab=purchase_orders&subtab=canvass">← All RFQs</a>
              </div>
              <?php if ($canvass_rfq['quotation_deadline']): ?><div style="font-size:12.5px;color:var(--text-light);margin-bottom:10px;">Quotation deadline: <?= date('M j, Y', strtotime($canvass_rfq['quotation_deadline'])) ?></div><?php endif; ?>
              <?php if ($canvass_rfq['notes']): ?><div style="font-size:12.5px;color:var(--text-light);margin-bottom:10px;">Notes: <?= htmlspecialchars($canvass_rfq['notes']) ?></div><?php endif; ?>

              <?php if (empty($canvass_suppliers)): ?>
                <div style="color:var(--text-light);">No suppliers were invited.</div>
              <?php else: ?>
              <div style="overflow-x:auto;">
                <table class="canvass-table">
                  <thead>
                    <tr><th>Item</th><th>Qty</th>
                      <?php foreach ($canvass_suppliers as $s): ?><th><?= htmlspecialchars($s['name']) ?><?= $s['invite_status'] === 'awarded' ? ' 🏆' : '' ?></th><?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($canvass_items as $it): ?>
                      <tr>
                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                        <td><?= (float) $it['qty_needed'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></td>
                        <?php foreach ($canvass_suppliers as $s): $q = $canvass_quotations_by_supplier[$s['supplier_id']] ?? null; $qi = $q ? ($canvass_items_by_quotation[$q['quotation_id']][$it['rfq_item_id']] ?? null) : null; ?>
                          <td><?= $qi ? '₱' . number_format((float) $qi['unit_price'], 2) : '—' ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                    <tr class="canvass-total-row">
                      <td colspan="2">Total</td>
                      <?php foreach ($canvass_suppliers as $s): $q = $canvass_quotations_by_supplier[$s['supplier_id']] ?? null; ?>
                        <td><?= $q ? '₱' . number_format((float) $q['total_amount'], 2) : '—' ?></td>
                      <?php endforeach; ?>
                    </tr>
                    <tr>
                      <td colspan="2">Lead time</td>
                      <?php foreach ($canvass_suppliers as $s): $q = $canvass_quotations_by_supplier[$s['supplier_id']] ?? null; ?>
                        <td><?= $q && $q['lead_time_days'] !== null ? (int) $q['lead_time_days'] . ' day(s)' : '—' ?></td>
                      <?php endforeach; ?>
                    </tr>
                    <?php if ($canvass_rfq['status'] === 'open'): ?>
                    <tr>
                      <td colspan="2">Award</td>
                      <?php foreach ($canvass_suppliers as $s): $q = $canvass_quotations_by_supplier[$s['supplier_id']] ?? null; ?>
                        <td>
                          <?php if ($q): ?>
                            <form method="POST" action="?tab=purchase_orders" class="award-form" data-supplier="<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>">
                              <input type="hidden" name="act" value="award">
                              <input type="hidden" name="rfq_id" value="<?= (int) $canvass_rfq['rfq_id'] ?>">
                              <input type="hidden" name="supplier_id" value="<?= (int) $s['supplier_id'] ?>">
                              <button type="submit" class="btn-save" style="padding:6px 14px;font-size:12px;">Award</button>
                            </form>
                          <?php else: ?>
                            <span style="color:var(--text-light);font-size:12px;">Not quoted</span>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>

              <?php if ($canvass_rfq['status'] === 'open'): ?>
                <form method="POST" action="?tab=purchase_orders" id="form-cancel-rfq" style="margin-top:14px;">
                  <input type="hidden" name="act" value="cancel_rfq">
                  <input type="hidden" name="rfq_id" value="<?= (int) $canvass_rfq['rfq_id'] ?>">
                  <button type="submit" style="background:none;border:1px solid var(--danger,#b8453a);color:var(--danger,#b8453a);border-radius:8px;padding:7px 14px;font-size:12.5px;cursor:pointer;">Cancel this RFQ</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══════════ TAB: Payment Confirmation ══════════ -->
      <div class="hub-tab-panel" data-tab="payment_confirmation">
        <p class="req-items">Payments recorded by Finance and awaiting disbursement confirmation.</p>
        <?php if (empty($confirmation_queue)): ?>
          <p>No payments are currently awaiting confirmation.</p>
        <?php else: foreach ($confirmation_queue as $r): ?>
          <div class="req-card">
            <div class="req-card-head">
              <div>
                <strong><?= htmlspecialchars($r['po_number'] ?? ('PO-' . str_pad((string) $r['po_id'], 6, '0', STR_PAD_LEFT))) ?></strong>
                &middot; Request #<?= (int) $r['request_id'] ?>
                <span class="branch-tag"> — <?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></span>
              </div>
              <span class="status-badge" style="background:#fff4e5;color:#b45300;">PAYMENT PENDING</span>
            </div>
            <div class="req-items">Requested by <?= htmlspecialchars($r['requested_by_name']) ?> &middot; Supplier: <?= htmlspecialchars($r['supplier_name']) ?></div>
            <div class="req-items">
              Invoice: <?= htmlspecialchars($r['invoice_reference']) ?>
              &middot; Method: <?= htmlspecialchars($PAYMENT_METHOD_LABELS[$r['payment_method']] ?? $r['payment_method']) ?>
              &middot; Recorded: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['payment_recorded_at']))) ?>
            </div>
            <?php if ($r['payment_notes']): ?><div class="req-items">Note: <?= htmlspecialchars($r['payment_notes']) ?></div><?php endif; ?>
            <div class="po-total">Amount to disburse: &#8369;<?= number_format((float) $r['payment_amount'], 2) ?></div>
            <form method="post" action="?tab=payment_confirmation" style="margin-top:12px;">
              <input type="hidden" name="act" value="confirm_payment">
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
              <button type="submit">Confirm Disbursement</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    lucide.createIcons();

    function switchHubTab(tab) {
      document.querySelectorAll('.hub-tab-panel').forEach(p => p.classList.toggle('active', p.dataset.tab === tab));
      document.querySelectorAll('.hub-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    }
    function switchHubSubtab(subtab) {
      document.querySelectorAll('.hub-subtab-panel').forEach(p => p.classList.toggle('active', p.dataset.subtab === subtab));
      document.querySelectorAll('.hub-subtab-btn').forEach(b => b.classList.toggle('active', b.dataset.subtab === subtab));
    }
    document.addEventListener('DOMContentLoaded', function () {
      switchHubTab('<?= $active_tab ?>');
      switchHubSubtab('<?= $active_subtab ?>');
      document.querySelectorAll('.hub-tab-btn').forEach(function (b) {
        b.addEventListener('click', function () { switchHubTab(b.dataset.tab); history.replaceState(null, '', '?tab=' + b.dataset.tab); });
      });
      document.querySelectorAll('.hub-subtab-btn').forEach(function (b) {
        b.addEventListener('click', function () { switchHubSubtab(b.dataset.subtab); });
      });
      document.querySelectorAll('.award-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          Swal.fire({
            title: 'Award this RFQ?',
            html: 'Awarding to <b>' + form.dataset.supplier + '</b> will issue a Purchase Order and close this RFQ. This cannot be undone.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, award it', confirmButtonColor: '#2f6f4e', reverseButtons: true
          }).then(function (result) { if (result.isConfirmed) form.submit(); });
        });
      });
      var cancelForm = document.getElementById('form-cancel-rfq');
      if (cancelForm) {
        cancelForm.addEventListener('submit', function (e) {
          e.preventDefault();
          Swal.fire({
            title: 'Cancel this RFQ?',
            text: 'Suppliers who already quoted will no longer be considered.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, cancel it', confirmButtonColor: '#b8453a', reverseButtons: true
          }).then(function (result) { if (result.isConfirmed) cancelForm.submit(); });
        });
      }
    });
  </script>
</body>
</html>
