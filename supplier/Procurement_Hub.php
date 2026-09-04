<?php
/**
 * Supplier Portal — Procurement Hub (consolidation pass)
 * -------------------------------------------------------------
 * Combines the five supplier-facing procurement pages into one tabbed
 * page, purely for navigation — every POST handler and query below is
 * copied verbatim from its original file (now a redirect stub here):
 * Supplier_Stock_Checks.php, Supplier_RFQs.php, Supplier_Requests.php,
 * Supplier_Deliveries.php, Supplier_Delivery_Issues.php. No `act` value
 * collides across the five. See docs/procurement/STATUS.md.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/procurement_queries.php';
require_once __DIR__ . '/../includes/procurement_rfq_queries.php';

$supplier_id = supplier_require_login();
$sid = (int) $supplier_id;
$actor_user_id = (int) ($_SESSION['user_id'] ?? 0);
$actor_name    = $_SESSION['full_name'] ?? 'Supplier';

ensure_procurement_tables($conn);
ensure_procurement_rfq_tables($conn);

$msg = '';
$ACTIONS = ['replacement' => 'Replacement', 'refund' => 'Refund', 'credit' => 'Store Credit', 'return' => 'Return Item', 'backorder' => 'Backorder'];
$DELIVERY_STATUSES = ['pending','confirmed','preparing','in_transit','partially_delivered','delivered','delayed','cancelled'];

$__act = $_POST['act'] ?? '';
$__tab_by_act = [
    'confirm_full' => 'stock_checks', 'partial' => 'stock_checks',
    'submit_quotation' => 'rfqs',
    'respond' => 'requests',
    'update_status' => 'deliveries', 'upload_proof' => 'deliveries',
    'reply' => 'issues',
];
$active_tab = $_GET['tab'] ?? ($__tab_by_act[$__act] ?? 'stock_checks');

// ════════════════════════════════════════════════════════════════════
// TAB: Stock Checks (Supplier_Stock_Checks.php)
// ════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'confirm_full') {
    $request_id   = (int) ($_POST['request_id'] ?? 0);
    $prices       = $_POST['unit_price'] ?? [];
    $shipping_fee = trim((string) ($_POST['shipping_fee'] ?? ''));
    $notes        = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    if ($shipping_fee === '' || !is_numeric($shipping_fee) || (float) $shipping_fee < 0) {
        $msg = 'error:Enter a valid shipping fee (0 or more).';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, status, supplier_id, supplier_check_status FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req || (int) $req['supplier_id'] !== $sid) {
            $msg = 'error:Request not found.';
        } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'pending') {
            $msg = 'error:This request is no longer awaiting your response.';
        } else {
            $items = [];
            $istmt = mysqli_prepare($conn, 'SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
            mysqli_stmt_bind_param($istmt, 'i', $request_id);
            mysqli_stmt_execute($istmt);
            $items = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
            mysqli_stmt_close($istmt);

            $failed = empty($items) ? 'This request has no line items.' : null;
            $quotation_amount = 0.0;
            $item_prices = []; // [item_id => unit price] — saved onto procurement_request_items below
            if (!$failed) {
                foreach ($items as $it) {
                    $raw = trim((string) ($prices[$it['item_id']] ?? ''));
                    if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
                        $failed = 'Enter a valid unit price (0 or more) for every item.';
                        break;
                    }
                    $unit_price = round((float) $raw, 2);
                    $item_prices[(int) $it['item_id']] = $unit_price;
                    $quotation_amount += $unit_price * (float) $it['qty_requested'];
                }
            }

            $attachment_path = null;
            if (!$failed && !empty($_FILES['attachment']['name'])) {
                $up = supplier_handle_upload($_FILES['attachment']);
                if (!$up['ok']) {
                    $failed = $up['error'];
                } else {
                    $attachment_path = $up['path'];
                }
            }

            if ($failed) {
                $msg = 'error:' . $failed;
            } else {
                $old_status = $req['status'];
                $next = procurement_next_status($old_status, 'supplier_quoted');

                if (!$next || !procurement_can_transition($old_status, $next)) {
                    $msg = 'error:That action is not valid for this request\'s current state.';
                } else {
                    $upd = mysqli_prepare($conn,
                        "UPDATE procurement_requests
                         SET status = ?, supplier_check_status = 'stock_confirmed',
                             quotation_amount = ?, shipping_fee = ?, quotation_attachment_path = ?, quotation_notes = ?
                         WHERE request_id = ? AND status = ? AND supplier_id = ?"
                    );
                    $__shipping = round((float) $shipping_fee, 2);
                    mysqli_stmt_bind_param($upd, 'sddssisi',
                        $next, $quotation_amount, $__shipping, $attachment_path, $notes, $request_id, $old_status, $sid
                    );
                    mysqli_stmt_execute($upd);
                    $changed = mysqli_stmt_affected_rows($upd);
                    mysqli_stmt_close($upd);

                    if ($changed === 1) {
                        // Lock in the per-item price the supplier just quoted, so
                        // Finance Head's direct-issue form doesn't have to (and
                        // can't accidentally mismatch) re-type it later.
                        $pstmt = mysqli_prepare($conn, 'UPDATE procurement_request_items SET quoted_unit_price = ? WHERE item_id = ? AND request_id = ?');
                        foreach ($item_prices as $item_id => $unit_price) {
                            mysqli_stmt_bind_param($pstmt, 'dii', $unit_price, $item_id, $request_id);
                            mysqli_stmt_execute($pstmt);
                        }
                        mysqli_stmt_close($pstmt);

                        procurement_log_audit($conn, $request_id, $actor_user_id, $actor_name, 'supplier_quoted', $old_status, $next,
                            'Quotation: ₱' . number_format($quotation_amount, 2) . ' + ₱' . number_format($__shipping, 2) . ' shipping');
                        supplier_log_activity($conn, $sid, 'stock_check_confirmed', 'stock_checks',
                            'Confirmed stock and quoted REQ-' . str_pad((string) $request_id, 4, '0', STR_PAD_LEFT), (string) $request_id);
                        $msg = 'success:Quotation submitted.';
                    } else {
                        $msg = 'error:This request was already updated — refresh and try again.';
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'partial') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $available  = $_POST['available_qty'] ?? [];
    $notes      = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    $stmt = mysqli_prepare($conn,
        'SELECT request_id, status, supplier_id, supplier_check_status FROM procurement_requests WHERE request_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$req || (int) $req['supplier_id'] !== $sid) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'pending') {
        $msg = 'error:This request is no longer awaiting your response.';
    } else {
        $items = [];
        $istmt = mysqli_prepare($conn, 'SELECT item_id, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $request_id);
        mysqli_stmt_execute($istmt);
        $items = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);

        $clean = [];
        $failed = empty($items) ? 'This request has no line items.' : null;
        if (!$failed) {
            foreach ($items as $it) {
                $raw = trim((string) ($available[$it['item_id']] ?? ''));
                $qty = $raw === '' ? 0.0 : (float) $raw;
                if (!is_numeric($raw === '' ? '0' : $raw) || $qty < 0 || $qty > (float) $it['qty_requested']) {
                    $failed = 'Enter a valid available quantity (0 up to the requested amount) for every item.';
                    break;
                }
                $clean[(int) $it['item_id']] = $qty;
            }
        }

        if ($failed) {
            $msg = 'error:' . $failed;
        } else {
            mysqli_begin_transaction($conn);
            $ok = true;
            $ustmt = mysqli_prepare($conn, 'UPDATE procurement_request_items SET available_quantity = ? WHERE item_id = ? AND request_id = ?');
            foreach ($clean as $item_id => $qty) {
                mysqli_stmt_bind_param($ustmt, 'dii', $qty, $item_id, $request_id);
                if (!mysqli_stmt_execute($ustmt)) { $ok = false; break; }
            }
            mysqli_stmt_close($ustmt);

            if ($ok) {
                $upd = mysqli_prepare($conn,
                    "UPDATE procurement_requests SET supplier_check_status = 'partial_stock', quotation_notes = ?
                     WHERE request_id = ? AND status = ? AND supplier_id = ? AND supplier_check_status = 'pending'"
                );
                $__status = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
                mysqli_stmt_bind_param($upd, 'sisi', $notes, $request_id, $__status, $sid);
                mysqli_stmt_execute($upd);
                $ok = mysqli_stmt_affected_rows($upd) === 1;
                mysqli_stmt_close($upd);
            }

            if ($ok) {
                mysqli_commit($conn);
                procurement_log_audit($conn, $request_id, $actor_user_id, $actor_name, 'partial_stock_reported', PROC_STATUS_SUPPLIER_STOCK_CHECKING, PROC_STATUS_SUPPLIER_STOCK_CHECKING,
                    $notes !== '' ? $notes : null);
                supplier_log_activity($conn, $sid, 'partial_stock_reported', 'stock_checks',
                    'Reported partial stock for REQ-' . str_pad((string) $request_id, 4, '0', STR_PAD_LEFT), (string) $request_id);
                $msg = 'success:Partial stock reported. Your Store Manager contact will decide how to proceed.';
            } else {
                mysqli_rollback($conn);
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

$stock_queue = [];
$__status = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
$stmt = mysqli_prepare($conn,
    "SELECT r.request_id, r.created_at, b.branch_name
     FROM procurement_requests r
     LEFT JOIN branches b ON b.branch_id = r.branch_id
     WHERE r.supplier_id = ? AND r.status = ? AND r.supplier_check_status = 'pending'
     ORDER BY r.created_at ASC"
);
mysqli_stmt_bind_param($stmt, 'is', $sid, $__status);
mysqli_stmt_execute($stmt);
$stock_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

foreach ($stock_queue as &$r) {
    $istmt = mysqli_prepare($conn, 'SELECT item_id, item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
    mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
    mysqli_stmt_execute($istmt);
    $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($istmt);
}
unset($r);

// ════════════════════════════════════════════════════════════════════
// TAB: RFQs (Supplier_RFQs.php)
// ════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'submit_quotation') {
    $rfq_id  = (int) ($_POST['rfq_id'] ?? 0);
    $prices  = $_POST['unit_price'] ?? [];
    $validity = trim($_POST['validity_date'] ?? '');
    $lead_time = trim($_POST['lead_time_days'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $invite = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rs.id, f.status AS rfq_status, f.rfq_number
          FROM procurement_rfq_suppliers rs
          JOIN procurement_rfqs f ON f.rfq_id = rs.rfq_id
         WHERE rs.rfq_id = $rfq_id AND rs.supplier_id = $sid"));

    if (!$invite) {
        $msg = 'error:You were not invited to this RFQ.';
    } elseif ($invite['rfq_status'] !== 'open') {
        $msg = 'error:This RFQ is no longer open for quotations.';
    } else {
        $rfqItems = [];
        $ires = mysqli_query($conn, "SELECT rfq_item_id FROM procurement_rfq_items WHERE rfq_id = $rfq_id");
        if ($ires) while ($i = mysqli_fetch_assoc($ires)) $rfqItems[] = (int) $i['rfq_item_id'];

        $lineItems = [];
        $bad = false;
        foreach ($rfqItems as $itemId) {
            $raw = trim((string) ($prices[$itemId] ?? ''));
            if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) { $bad = true; break; }
            $lineItems[$itemId] = round((float) $raw, 2);
        }

        if ($bad) {
            $msg = 'error:Enter a valid unit price for every item.';
        } elseif ($validity !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validity)) {
            $msg = 'error:Invalid validity date.';
        } else {
            $qtyMap = [];
            $qres = mysqli_query($conn, "SELECT rfq_item_id, qty_needed FROM procurement_rfq_items WHERE rfq_id = $rfq_id");
            if ($qres) while ($q = mysqli_fetch_assoc($qres)) $qtyMap[(int) $q['rfq_item_id']] = (float) $q['qty_needed'];

            $total_amount = 0.0;
            foreach ($lineItems as $itemId => $price) {
                $total_amount += round($price * ($qtyMap[$itemId] ?? 0), 2);
            }

            $attachment = null;
            if (!empty($_FILES['attachment']['name'])) {
                $upload = supplier_handle_upload($_FILES['attachment']);
                if (!$upload['ok']) {
                    $msg = 'error:' . $upload['error'];
                } else {
                    $attachment = $upload['path'];
                }
            }

            if ($msg === '') {
                mysqli_begin_transaction($conn);
                $failed = null;
                $leadTimeOrNull = $lead_time !== '' ? (int) $lead_time : null;
                $validityOrNull = $validity !== '' ? $validity : null;
                $notesOrNull = $notes !== '' ? $notes : null;

                $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quotation_id, attachment_path FROM supplier_quotations WHERE rfq_id = $rfq_id AND supplier_id = $sid"));
                if ($existing) {
                    $quotation_id = (int) $existing['quotation_id'];
                    if ($attachment === null) $attachment = $existing['attachment_path'];
                    $upd = mysqli_prepare($conn,
                        "UPDATE supplier_quotations SET total_amount=?, validity_date=?, lead_time_days=?, notes=?, attachment_path=?, status='submitted' WHERE quotation_id=?");
                    mysqli_stmt_bind_param($upd, 'dsissi', $total_amount, $validityOrNull, $leadTimeOrNull, $notesOrNull, $attachment, $quotation_id);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                    mysqli_query($conn, "DELETE FROM supplier_quotation_items WHERE quotation_id = $quotation_id");
                } else {
                    $ins = mysqli_prepare($conn,
                        "INSERT INTO supplier_quotations (rfq_id, supplier_id, total_amount, validity_date, lead_time_days, notes, attachment_path) VALUES (?,?,?,?,?,?,?)");
                    mysqli_stmt_bind_param($ins, 'iidsiss', $rfq_id, $sid, $total_amount, $validityOrNull, $leadTimeOrNull, $notesOrNull, $attachment);
                    mysqli_stmt_execute($ins);
                    $quotation_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                }

                $itemStmt = mysqli_prepare($conn,
                    "INSERT INTO supplier_quotation_items (quotation_id, rfq_item_id, unit_price, line_total) VALUES (?,?,?,?)");
                foreach ($lineItems as $itemId => $price) {
                    $lineTotal = round($price * ($qtyMap[$itemId] ?? 0), 2);
                    mysqli_stmt_bind_param($itemStmt, 'iidd', $quotation_id, $itemId, $price, $lineTotal);
                    if (!mysqli_stmt_execute($itemStmt)) { $failed = 'Could not save quotation line items.'; break; }
                }
                mysqli_stmt_close($itemStmt);

                if ($failed) {
                    mysqli_rollback($conn);
                    $msg = 'error:' . $failed;
                } else {
                    mysqli_query($conn, "UPDATE procurement_rfq_suppliers SET status='quoted' WHERE rfq_id=$rfq_id AND supplier_id=$sid");
                    mysqli_commit($conn);
                    supplier_log_activity($conn, $sid, 'quotation_submitted', 'rfq',
                        'Quotation submitted for ' . $invite['rfq_number'], (string) $rfq_id);
                    $msg = 'success:Quotation submitted.';
                }
            }
        }
    }
}

$rfqs = [];
$res = mysqli_query($conn, "
    SELECT f.rfq_id, f.rfq_number, f.status, f.quotation_deadline, f.notes,
           rs.status AS my_status, b.branch_name
      FROM procurement_rfq_suppliers rs
      JOIN procurement_rfqs f ON f.rfq_id = rs.rfq_id
      JOIN procurement_requests r ON r.request_id = f.request_id
      LEFT JOIN branches b ON b.branch_id = r.branch_id
     WHERE rs.supplier_id = $sid
     ORDER BY (f.status = 'open') DESC, f.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rfqs[] = $r;

$items_by_rfq = [];
$my_quotes_by_rfq = [];
$my_quote_items = [];
if ($rfqs) {
    $ids = implode(',', array_map(fn($r) => (int) $r['rfq_id'], $rfqs));
    $ires = mysqli_query($conn, "SELECT * FROM procurement_rfq_items WHERE rfq_id IN ($ids) ORDER BY rfq_item_id");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) $items_by_rfq[$i['rfq_id']][] = $i;

    $qres = mysqli_query($conn, "SELECT * FROM supplier_quotations WHERE rfq_id IN ($ids) AND supplier_id = $sid");
    if ($qres) while ($q = mysqli_fetch_assoc($qres)) $my_quotes_by_rfq[$q['rfq_id']] = $q;

    if ($my_quotes_by_rfq) {
        $qids = implode(',', array_map(fn($q) => (int) $q['quotation_id'], $my_quotes_by_rfq));
        $qires = mysqli_query($conn, "SELECT * FROM supplier_quotation_items WHERE quotation_id IN ($qids)");
        if ($qires) while ($qi = mysqli_fetch_assoc($qires)) $my_quote_items[$qi['quotation_id']][$qi['rfq_item_id']] = $qi;
    }
}

// ════════════════════════════════════════════════════════════════════
// TAB: Requests & POs (Supplier_Requests.php)
// ════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'respond') {
    $po_id    = (int)($_POST['po_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $remarks  = trim($_POST['remarks'] ?? '');
    $revised  = trim($_POST['revised_date'] ?? '');

    $valid = ['confirm' => 'confirmed', 'accept' => 'accepted', 'reject' => 'rejected', 'revise' => 'revised'];

    if (!$po_id || !isset($valid[$decision])) {
        $msg = 'error:Invalid request.';
    } elseif (in_array($decision, ['reject', 'revise'], true) && $remarks === '') {
        $msg = 'error:Please provide a reason/remarks.';
    } elseif ($decision === 'revise' && (!$revised || !DateTime::createFromFormat('Y-m-d', $revised))) {
        $msg = 'error:Please provide a valid revised delivery date.';
    } else {
        $status = $valid[$decision];
        $revisedDate = $decision === 'revise' ? $revised : null;
        $stmt = mysqli_prepare($conn,
            "UPDATE procurement_purchase_orders
                SET supplier_response_status = ?, supplier_response_date = NOW(),
                    supplier_revised_delivery_date = ?, supplier_response_remarks = ?
              WHERE po_id = ? AND supplier_id = ?");
        mysqli_stmt_bind_param($stmt, 'sssii', $status, $revisedDate, $remarks, $po_id, $sid);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) > 0) {
            $poRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id"));
            supplier_log_activity($conn, $sid, 'po_' . $decision, 'requests',
                'Purchase order ' . ($poRow['po_number'] ?? "#$po_id") . ' marked ' . $status . ($remarks ? " — $remarks" : ''),
                (string)$po_id);
            $msg = 'success:Response recorded.';
        } else {
            $msg = 'error:Could not find that purchase order.';
        }
    }
}

$pos = [];
$res = mysqli_query($conn, "
    SELECT po.*, b.branch_name
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid
     ORDER BY (po.supplier_response_status = 'pending') DESC, po.created_at DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $pos[] = $r;

$items_by_po = [];
if ($pos) {
    $ids = implode(',', array_map('intval', array_column($pos, 'po_id')));
    $ires = mysqli_query($conn, "SELECT * FROM procurement_po_items WHERE po_id IN ($ids)");
    if ($ires) while ($i = mysqli_fetch_assoc($ires)) $items_by_po[$i['po_id']][] = $i;
}

// ════════════════════════════════════════════════════════════════════
// TAB: Deliveries (Supplier_Deliveries.php)
// ════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'update_status') {
    $po_id  = (int)($_POST['po_id'] ?? 0);
    $status = $_POST['delivery_status'] ?? '';
    $ref    = trim($_POST['delivery_reference'] ?? '');

    if (!$po_id || !in_array($status, $DELIVERY_STATUSES, true)) {
        $msg = 'error:Invalid delivery status.';
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE procurement_purchase_orders
                SET delivery_status = ?, delivery_status_updated_at = NOW(), delivery_reference = ?
              WHERE po_id = ? AND supplier_id = ?");
        mysqli_stmt_bind_param($stmt, 'ssii', $status, $ref, $po_id, $sid);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            $poRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id"));
            supplier_log_activity($conn, $sid, 'delivery_status_updated', 'deliveries',
                'Delivery status for ' . ($poRow['po_number'] ?? "#$po_id") . ' set to ' . $status, (string)$po_id);
            $msg = 'success:Delivery status updated.';
        } else {
            $msg = 'error:Could not find that purchase order.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'upload_proof') {
    $po_id = (int)($_POST['po_id'] ?? 0);
    $type  = $_POST['document_type'] ?? 'other';
    $desc  = trim($_POST['description'] ?? '');

    $owns = $po_id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT po_number FROM procurement_purchase_orders WHERE po_id = $po_id AND supplier_id = $sid")) : null;
    if (!$owns) {
        $msg = 'error:Invalid purchase order.';
    } else {
        $upload = supplier_handle_upload($_FILES['proof_file'] ?? []);
        if (!$upload['ok']) {
            $msg = 'error:' . $upload['error'];
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO supplier_delivery_proofs (po_id, uploaded_by, file_path, original_filename, document_type, description)
                 VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'iissss', $po_id, $actor_user_id, $upload['path'], $upload['original'], $type, $desc);
            mysqli_stmt_execute($stmt);
            supplier_log_activity($conn, $sid, 'proof_uploaded', 'deliveries',
                'Uploaded ' . $type . ' for ' . $owns['po_number'], (string)$po_id);
            $msg = 'success:Document uploaded.';
        }
    }
}

$delivery_pos = [];
$res = mysqli_query($conn, "
    SELECT po.*, b.branch_name
      FROM procurement_purchase_orders po
      JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid AND po.supplier_response_status IN ('confirmed','accepted')
     ORDER BY (po.delivery_status = 'delivered') ASC, po.expected_delivery_date ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $delivery_pos[] = $r;

$proofs_by_po = [];
if ($delivery_pos) {
    $ids = implode(',', array_map('intval', array_column($delivery_pos, 'po_id')));
    $pres = mysqli_query($conn, "SELECT * FROM supplier_delivery_proofs WHERE po_id IN ($ids) ORDER BY uploaded_at DESC");
    if ($pres) while ($p = mysqli_fetch_assoc($pres)) $proofs_by_po[$p['po_id']][] = $p;
}

// ════════════════════════════════════════════════════════════════════
// TAB: Delivery Issues (Supplier_Delivery_Issues.php)
// ════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'reply') {
    $issue_id = (int) ($_POST['issue_id'] ?? 0);
    $message  = trim($_POST['message'] ?? '');
    $action   = $_POST['requested_action'] ?? '';
    $action   = array_key_exists($action, $ACTIONS) ? $action : null;

    $issue = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT i.issue_id, i.status, i.po_id
          FROM procurement_delivery_issues i
          JOIN procurement_purchase_orders po ON po.po_id = i.po_id
         WHERE i.issue_id = " . $issue_id . " AND po.supplier_id = " . $sid . " LIMIT 1"));

    if (!$issue) {
        $msg = 'error:Issue not found.';
    } elseif ($issue['status'] !== 'open') {
        $msg = 'error:This issue has already been resolved.';
    } elseif ($message === '') {
        $msg = 'error:Enter a reply message.';
    } else {
        $attachment_path = null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = supplier_handle_upload($_FILES['attachment']);
            if (!$up['ok']) {
                $msg = 'error:' . $up['error'];
            } else {
                $attachment_path = $up['path'];
            }
        }

        if ($msg === '') {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO procurement_delivery_issue_messages
                    (issue_id, sender_type, sender_supplier_id, message, requested_action, attachment_path)
                 VALUES (?, 'supplier', ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iisss', $issue_id, $sid, $message, $action, $attachment_path);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            supplier_log_activity($conn, $sid, 'delivery_issue_replied', 'delivery_issues',
                'Replied to delivery issue #' . $issue_id, (string) $issue_id);
            $msg = 'success:Reply sent.';
        }
    }
}

$issues = [];
$res = mysqli_query($conn, "
    SELECT i.issue_id, i.request_id, i.po_id, i.created_at, po.po_number, b.branch_name
      FROM procurement_delivery_issues i
      JOIN procurement_purchase_orders po ON po.po_id = i.po_id
      LEFT JOIN branches b ON b.branch_id = po.branch_id
     WHERE po.supplier_id = $sid AND i.status = 'open'
     ORDER BY i.created_at ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $issues[] = $r;

foreach ($issues as &$i) {
    $mres = mysqli_query($conn, "SELECT sender_type, message, requested_action, attachment_path, created_at
        FROM procurement_delivery_issue_messages WHERE issue_id = " . (int) $i['issue_id'] . " ORDER BY created_at ASC");
    $i['thread'] = [];
    if ($mres) while ($m = mysqli_fetch_assoc($mres)) $i['thread'][] = $m;
}
unset($i);

$activePage = 'proc-hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Procurement — Supplier Portal</title>
  <link rel="stylesheet" href="../css/admin_page.css"/>
  <link rel="stylesheet" href="css/supplier.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    .stock-card{border:1px solid var(--hr-border,#e9e3d8);border-radius:10px;padding:16px;margin-bottom:14px;}
    .stock-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .stock-item-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;}
    .stock-item-row .item-name{flex:1;}
    .stock-item-row input[type=number]{width:110px;}
    .resp-toggle{display:flex;gap:8px;margin-bottom:12px;}
    .resp-toggle button{flex:1;padding:8px;border-radius:8px;border:1px solid var(--hr-border,#e9e3d8);background:#fff;cursor:pointer;font-size:13px;font-weight:600;}
    .resp-toggle button.active{background:#b8703f;color:#fff;border-color:#b8703f;}
    .resp-panel{display:none;}
    .resp-panel.active{display:block;}
    .issue-card{border:1px solid var(--hr-border,#e9e3d8);border-radius:10px;padding:16px;margin-bottom:14px;}
    .issue-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .thread{border:1px solid var(--hr-border,#e9e3d8);border-radius:8px;padding:10px 12px;margin:10px 0;background:#faf8f4;}
    .thread-msg{font-size:13px;padding:6px 0;border-bottom:1px solid #eee;}
    .thread-msg:last-child{border-bottom:none;}
    .thread-msg .who{font-weight:700;}
    .thread-msg.store_manager .who{color:#7a5b00;}
    .thread-msg.supplier .who{color:#1a5fb4;}
    .hub-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--hr-border,#e9e3d8);flex-wrap:wrap;}
    .hub-tab-btn{padding:10px 16px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:600;color:#6b6156;border-bottom:2px solid transparent;}
    .hub-tab-btn.active{color:#241f19;border-bottom-color:#b8703f;}
    .hub-tab-panel{display:none;}
    .hub-tab-panel.active{display:block;}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/supplier_sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle sidebar"><i data-lucide="menu"></i></button>
      <h1 class="page-title">Procurement</h1>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): [$mt, $mm] = explode(':', $msg, 2); ?>
      <div class="msg-banner <?= $mt ?>"><?= htmlspecialchars($mm) ?></div>
    <?php endif; ?>

    <div class="hub-tabs">
      <button type="button" class="hub-tab-btn" data-tab="stock_checks">Stock Checks</button>
      <button type="button" class="hub-tab-btn" data-tab="rfqs">RFQs</button>
      <button type="button" class="hub-tab-btn" data-tab="requests">Requests & POs</button>
      <button type="button" class="hub-tab-btn" data-tab="deliveries">Deliveries</button>
      <button type="button" class="hub-tab-btn" data-tab="issues">Delivery Issues</button>
    </div>

    <!-- ══════════ TAB: Stock Checks ══════════ -->
    <div class="hub-tab-panel" data-tab="stock_checks">
      <?php if (empty($stock_queue)): ?>
        <div class="widget"><div class="table-wrap"><div class="empty-state" style="padding:40px;text-align:center;">No requests are waiting for your response right now.</div></div></div>
      <?php else: foreach ($stock_queue as $r): $formId = 'req' . $r['request_id']; ?>
        <div class="stock-card">
          <div class="stock-card-head">
            <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
            <span class="field-hint"><?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?> — <?= date('M j, Y', strtotime($r['created_at'])) ?></span>
          </div>

          <div class="resp-toggle">
            <button type="button" class="active" onclick="switchStockPanel('<?= $formId ?>','full')" id="<?= $formId ?>-btn-full">All items available</button>
            <button type="button" onclick="switchStockPanel('<?= $formId ?>','partial')" id="<?= $formId ?>-btn-partial">Some items unavailable</button>
          </div>

          <form method="POST" action="?tab=stock_checks" id="<?= $formId ?>-full" class="resp-panel active" enctype="multipart/form-data" onsubmit="return confirm('Submit this quotation?');">
            <input type="hidden" name="act" value="confirm_full"/>
            <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>"/>
            <?php foreach ($r['items'] as $it): ?>
              <div class="stock-item-row">
                <span class="item-name"><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span>
                <input type="number" step="0.01" min="0" name="unit_price[<?= (int) $it['item_id'] ?>]" placeholder="Unit price" required/>
              </div>
            <?php endforeach; ?>
            <div class="form-group"><label>Shipping Fee *</label><input type="number" step="0.01" min="0" name="shipping_fee" required/></div>
            <div class="form-group"><label>Notes (optional)</label><input type="text" name="notes" maxlength="255"/></div>
            <div class="form-group"><label>Quotation Document (optional — PDF/JPG/PNG)</label><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/></div>
            <button type="submit" class="btn-primary">Submit Quotation</button>
          </form>

          <form method="POST" action="?tab=stock_checks" id="<?= $formId ?>-partial" class="resp-panel" onsubmit="return confirm('Report partial stock for this request?');">
            <input type="hidden" name="act" value="partial"/>
            <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>"/>
            <?php foreach ($r['items'] as $it): ?>
              <div class="stock-item-row">
                <span class="item-name"><?= htmlspecialchars($it['item_name']) ?> — requested <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></span>
                <input type="number" step="0.01" min="0" max="<?= (float) $it['qty_requested'] + 0 ?>" name="available_qty[<?= (int) $it['item_id'] ?>]" placeholder="Available qty" value="<?= (float) $it['qty_requested'] + 0 ?>" required/>
              </div>
            <?php endforeach; ?>
            <div class="form-group"><label>Notes (optional)</label><input type="text" name="notes" maxlength="255" placeholder="Why the shortfall, expected restock, etc."/></div>
            <button type="submit" class="btn-primary">Report Partial Stock</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══════════ TAB: RFQs ══════════ -->
    <div class="hub-tab-panel" data-tab="rfqs">
      <?php if (empty($rfqs)): ?>
        <div class="widget"><div class="empty-state">No RFQs invited yet.</div></div>
      <?php else: foreach ($rfqs as $r): $items = $items_by_rfq[$r['rfq_id']] ?? []; $mine = $my_quotes_by_rfq[$r['rfq_id']] ?? null;
        if ($r['status'] === 'cancelled') { $pillClass = 'cancelled'; $pillText = 'Cancelled'; }
        elseif ($r['status'] === 'awarded') {
          $pillClass = $r['my_status'] === 'awarded' ? 'accepted' : 'rejected';
          $pillText  = $r['my_status'] === 'awarded' ? 'Awarded to you' : 'Not awarded';
        } else {
          $pillClass = $r['my_status'] === 'quoted' ? 'accepted' : 'pending';
          $pillText  = ucfirst($r['my_status']);
        }
      ?>
        <div class="widget">
          <div class="widget-header">
            <div>
              <div class="widget-title"><?= htmlspecialchars($r['rfq_number'] ?? ('#' . $r['rfq_id'])) ?> — <?= htmlspecialchars($r['branch_name'] ?? 'Unknown Branch') ?></div>
              <div class="page-sub"><?= $r['quotation_deadline'] ? 'Deadline: ' . date('M j, Y', strtotime($r['quotation_deadline'])) : 'No deadline set' ?></div>
            </div>
            <span class="status-pill pill-<?= $pillClass ?>"><?= $pillText ?></span>
          </div>
          <?php if ($r['notes']): ?><div style="font-size:12.5px;color:var(--text-light);margin-bottom:10px;">Notes: <?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Item</th><th>Qty Needed</th><th>Your Unit Price</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it): $qi = $mine ? ($my_quote_items[$mine['quotation_id']][$it['rfq_item_id']] ?? null) : null; ?>
                  <tr>
                    <td><?= htmlspecialchars($it['item_name']) ?></td>
                    <td><?= (float) $it['qty_needed'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></td>
                    <td><?= $qi ? '₱' . number_format((float) $qi['unit_price'], 2) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($r['status'] === 'open'): ?>
            <button class="btn-primary" style="margin-top:12px;" onclick='openQuoteModal(<?= htmlspecialchars(json_encode(["rfq_id" => $r['rfq_id'], "rfq_number" => $r['rfq_number'], "items" => $items, "mine" => $mine, "my_items" => $mine ? ($my_quote_items[$mine['quotation_id']] ?? new stdClass()) : new stdClass()])) ?>)">
              <?= $mine ? 'Update Quotation' : 'Submit Quotation' ?>
            </button>
          <?php elseif ($mine && $mine['attachment_path']): ?>
            <a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($mine['attachment_path']) ?>" target="_blank" rel="noopener">View submitted attachment</a>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══════════ TAB: Requests & POs ══════════ -->
    <div class="hub-tab-panel" data-tab="requests">
      <div class="widget">
        <div class="widget-header"><div class="widget-title">Purchase Orders assigned to you</div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>PO Number</th><th>Branch</th><th>Items</th><th>Total</th><th>Expected Delivery</th><th>Your Response</th><th></th></tr></thead>
            <tbody>
              <?php if (empty($pos)): ?>
                <tr><td colspan="7" class="empty-state">No purchase orders have been assigned to you yet.</td></tr>
              <?php else: foreach ($pos as $po): $itemsPo = $items_by_po[$po['po_id']] ?? []; ?>
                <tr>
                  <td><?= htmlspecialchars($po['po_number'] ?? ('#' . $po['po_id'])) ?></td>
                  <td><?= htmlspecialchars($po['branch_name']) ?></td>
                  <td><?= implode(', ', array_map(fn($i) => htmlspecialchars($i['item_name']) . ' (' . ($i['qty_ordered'] + 0) . ' ' . htmlspecialchars($i['unit']) . ')', $itemsPo)) ?: '—' ?></td>
                  <td>₱<?= number_format((float)$po['total_cost'], 2) ?></td>
                  <td><?= $po['expected_delivery_date'] ? date('M j, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                  <td>
                    <span class="status-pill pill-<?= $po['supplier_response_status'] ?>"><?= ucfirst($po['supplier_response_status']) ?></span>
                    <?php if ($po['supplier_response_status'] === 'revised' && $po['supplier_revised_delivery_date']): ?>
                      <div class="field-hint">Proposed: <?= date('M j, Y', strtotime($po['supplier_revised_delivery_date'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><button class="btn-ghost btn-sm" onclick='openRespondModal(<?= htmlspecialchars(json_encode(["po_id" => $po['po_id'], "po_number" => $po['po_number'] ?? ('#' . $po['po_id'])])) ?>)'>Respond</button></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ TAB: Deliveries ══════════ -->
    <div class="hub-tab-panel" data-tab="deliveries">
      <div style="font-size:12px;color:var(--text-light);margin-bottom:14px">Only confirmed/accepted orders appear here — respond to a request first from the Requests &amp; POs tab.</div>
      <?php if (empty($delivery_pos)): ?>
        <div class="widget"><div class="empty-state">No confirmed orders yet.</div></div>
      <?php else: foreach ($delivery_pos as $po): ?>
        <div class="widget">
          <div class="widget-header">
            <div>
              <div class="widget-title"><?= htmlspecialchars($po['po_number'] ?? ('#' . $po['po_id'])) ?> — <?= htmlspecialchars($po['branch_name']) ?></div>
              <div class="page-sub">Expected: <?= $po['expected_delivery_date'] ? date('M j, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div>
            </div>
            <span class="status-pill pill-<?= $po['delivery_status'] ?>"><?= ucfirst(str_replace('_', ' ', $po['delivery_status'])) ?></span>
          </div>

          <div class="form-row">
            <form method="POST" action="?tab=deliveries">
              <input type="hidden" name="act" value="update_status">
              <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
              <div class="form-group">
                <label>Delivery Status</label>
                <select name="delivery_status">
                  <?php foreach ($DELIVERY_STATUSES as $s): ?><option value="<?= $s ?>" <?= $po['delivery_status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="form-group"><label>Delivery Reference</label><input type="text" name="delivery_reference" value="<?= htmlspecialchars($po['delivery_reference'] ?? '') ?>" placeholder="Tracking / DR number" maxlength="60"></div>
              <button type="submit" class="btn-primary">Save Status</button>
            </form>

            <form method="POST" action="?tab=deliveries" enctype="multipart/form-data">
              <input type="hidden" name="act" value="upload_proof">
              <input type="hidden" name="po_id" value="<?= $po['po_id'] ?>">
              <div class="form-group">
                <label>Document Type</label>
                <select name="document_type">
                  <option value="receipt">Delivery Receipt</option><option value="invoice">Invoice</option><option value="photo">Photo</option><option value="other">Other</option>
                </select>
              </div>
              <div class="form-group"><label>File (PDF/JPG/PNG, max 5MB)</label><input type="file" name="proof_file" accept=".pdf,.jpg,.jpeg,.png" required></div>
              <div class="form-group"><label>Description</label><input type="text" name="description" maxlength="200" placeholder="Optional note"></div>
              <button type="submit" class="btn-primary">Upload Proof</button>
            </form>
          </div>

          <?php $proofs = $proofs_by_po[$po['po_id']] ?? []; if ($proofs): ?>
            <div class="doc-list" style="margin-top:14px">
              <?php foreach ($proofs as $doc): ?>
                <div class="doc-row">
                  <span><?= ucfirst($doc['document_type']) ?> — <?= htmlspecialchars($doc['original_filename']) ?><?= $doc['description'] ? ' (' . htmlspecialchars($doc['description']) . ')' : '' ?></span>
                  <a class="btn-ghost btn-sm" href="../<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" rel="noopener">View</a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══════════ TAB: Delivery Issues ══════════ -->
    <div class="hub-tab-panel" data-tab="issues">
      <?php if (empty($issues)): ?>
        <div class="widget"><div class="table-wrap"><div class="empty-state" style="padding:40px;text-align:center;">No open delivery issues right now.</div></div></div>
      <?php else: foreach ($issues as $i): ?>
        <div class="issue-card">
          <div class="issue-card-head">
            <div><strong><?= htmlspecialchars($i['po_number'] ?? ('#' . $i['po_id'])) ?></strong> — REQ-<?= str_pad((string) $i['request_id'], 4, '0', STR_PAD_LEFT) ?></div>
            <span class="field-hint"><?= htmlspecialchars($i['branch_name'] ?? '') ?></span>
          </div>

          <div class="thread">
            <?php foreach ($i['thread'] as $m): ?>
              <div class="thread-msg <?= $m['sender_type'] ?>">
                <span class="who"><?= $m['sender_type'] === 'supplier' ? 'You' : 'Store Manager' ?></span>
                <?php if ($m['requested_action']): ?> — <b><?= htmlspecialchars($ACTIONS[$m['requested_action']] ?? $m['requested_action']) ?></b><?php endif; ?>
                <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                <?php if ($m['attachment_path']): ?><a href="../<?= htmlspecialchars($m['attachment_path']) ?>" target="_blank" rel="noopener">Attachment →</a><?php endif; ?>
                <div class="field-hint"><?= date('M j, g:ia', strtotime($m['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="POST" action="?tab=issues" enctype="multipart/form-data" onsubmit="return confirm('Send this reply?');">
            <input type="hidden" name="act" value="reply"/>
            <input type="hidden" name="issue_id" value="<?= (int) $i['issue_id'] ?>"/>
            <div class="form-group">
              <label>What are you doing about it? (optional)</label>
              <select name="requested_action">
                <option value="">— Not specifying —</option>
                <?php foreach ($ACTIONS as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Message *</label><textarea name="message" rows="2" required></textarea></div>
            <div class="form-group"><label>Attachment (optional)</label><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/></div>
            <button type="submit" class="btn-primary">Send Reply</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</div>

<!-- RFQ quote modal (Supplier_RFQs.php) -->
<div class="modal-overlay" id="modal-quote">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Quotation — <span id="quote-rfq-number"></span></span>
      <button class="modal-close" onclick="closeModal('modal-quote')">✕</button>
    </div>
    <form method="POST" action="?tab=rfqs" enctype="multipart/form-data">
      <input type="hidden" name="act" value="submit_quotation">
      <input type="hidden" name="rfq_id" id="quote-rfq-id">
      <div id="quote-items"></div>
      <div class="form-row">
        <div class="form-group"><label>Validity Date</label><input type="date" name="validity_date" id="quote-validity"></div>
        <div class="form-group"><label>Lead Time (days)</label><input type="number" min="0" name="lead_time_days" id="quote-lead-time"></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" id="quote-notes" rows="2" maxlength="255"></textarea></div>
      <div class="form-group"><label>Attachment (PDF/JPG/PNG, optional)</label><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal('modal-quote')">Cancel</button>
        <button type="submit" class="btn-primary">Submit</button>
      </div>
    </form>
  </div>
</div>

<!-- PO respond modal (Supplier_Requests.php) -->
<div class="modal-overlay" id="modal-respond">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Respond — <span id="respond-po-number"></span></span>
      <button class="modal-close" onclick="closeModal('modal-respond')">✕</button>
    </div>
    <form method="POST" action="?tab=requests" id="form-respond">
      <input type="hidden" name="act" value="respond">
      <input type="hidden" name="po_id" id="respond-po-id">
      <div class="form-group">
        <label>Decision *</label>
        <select name="decision" id="respond-decision" required onchange="toggleRespondFields()">
          <option value="">— Select —</option>
          <option value="confirm">Confirm order</option>
          <option value="accept">Accept order</option>
          <option value="revise">Propose revised delivery date</option>
          <option value="reject">Reject order</option>
        </select>
      </div>
      <div class="form-group" id="revised-date-group" style="display:none">
        <label>Revised Delivery Date *</label>
        <input type="date" name="revised_date" id="respond-revised-date">
      </div>
      <div class="form-group" id="remarks-group" style="display:none">
        <label>Remarks *</label>
        <textarea name="remarks" id="respond-remarks" rows="3" placeholder="Reason for the delay/rejection…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeModal('modal-respond')">Cancel</button>
        <button type="submit" class="btn-primary">Submit Response</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openQuoteModal(data) {
  document.getElementById('quote-rfq-id').value = data.rfq_id;
  document.getElementById('quote-rfq-number').textContent = data.rfq_number;
  document.getElementById('quote-validity').value = data.mine ? (data.mine.validity_date || '') : '';
  document.getElementById('quote-lead-time').value = data.mine ? (data.mine.lead_time_days || '') : '';
  document.getElementById('quote-notes').value = data.mine ? (data.mine.notes || '') : '';
  var wrap = document.getElementById('quote-items');
  wrap.innerHTML = '';
  data.items.forEach(function (it) {
    var existing = data.my_items ? data.my_items[it.rfq_item_id] : null;
    var group = document.createElement('div');
    group.className = 'form-group';
    var label = document.createElement('label');
    label.textContent = it.item_name + ' (' + parseFloat(it.qty_needed) + ' ' + it.unit + ') — Unit Price *';
    var input = document.createElement('input');
    input.type = 'number'; input.step = '0.01'; input.min = '0'; input.required = true;
    input.name = 'unit_price[' + it.rfq_item_id + ']';
    input.value = existing ? existing.unit_price : '';
    group.appendChild(label);
    group.appendChild(input);
    wrap.appendChild(group);
  });
  document.getElementById('modal-quote').classList.add('open');
}
function openRespondModal(po) {
  document.getElementById('respond-po-id').value = po.po_id;
  document.getElementById('respond-po-number').textContent = po.po_number;
  document.getElementById('respond-decision').value = '';
  document.getElementById('respond-remarks').value = '';
  document.getElementById('respond-revised-date').value = '';
  toggleRespondFields();
  document.getElementById('modal-respond').classList.add('open');
}
function toggleRespondFields() {
  var d = document.getElementById('respond-decision').value;
  document.getElementById('revised-date-group').style.display = d === 'revise' ? '' : 'none';
  document.getElementById('remarks-group').style.display = (d === 'revise' || d === 'reject') ? '' : 'none';
}
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
});
document.getElementById('form-respond').addEventListener('submit', function (e) {
  var d = document.getElementById('respond-decision').value;
  if ((d === 'revise' || d === 'reject') && !document.getElementById('respond-remarks').value.trim()) {
    e.preventDefault();
    Swal.fire({ icon: 'error', title: 'Remarks required', text: 'Please explain the reason.', confirmButtonColor: '#b8703f' });
  }
});

function switchStockPanel(formId, which) {
  document.getElementById(formId + '-full').classList.toggle('active', which === 'full');
  document.getElementById(formId + '-partial').classList.toggle('active', which === 'partial');
  document.getElementById(formId + '-btn-full').classList.toggle('active', which === 'full');
  document.getElementById(formId + '-btn-partial').classList.toggle('active', which === 'partial');
}

function switchHubTab(tab) {
  document.querySelectorAll('.hub-tab-panel').forEach(p => p.classList.toggle('active', p.dataset.tab === tab));
  document.querySelectorAll('.hub-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  history.replaceState(null, '', '?tab=' + tab);
}
document.addEventListener('DOMContentLoaded', function () {
  switchHubTab('<?= $active_tab ?>');
  document.querySelectorAll('.hub-tab-btn').forEach(function (b) {
    b.addEventListener('click', function () { switchHubTab(b.dataset.tab); });
  });
});
</script>
<script src="../js/lucide-init.js"></script>
</body>
</html>
