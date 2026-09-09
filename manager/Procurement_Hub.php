<?php
/**
 * Procurement — Store Manager Hub (consolidation pass)
 * -------------------------------------------------------------
 * Combines the four Store Manager procurement pages into one tabbed
 * page, purely for navigation — every gate, POST handler, and query
 * below is copied verbatim from its original file (now a redirect
 * stub here): Purchase_Request_Form.php (New Request), Assign_Supplier_Page.php
 * (Assign Supplier), Quotation_Review_Page.php (Quotation Review),
 * Discrepancy_Resolution_Page.php (Delivery Discrepancies). No `act`
 * value collides across the four, so each POST handler block still
 * runs standalone. See docs/procurement/STATUS.md.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';
require_once __DIR__ . '/../includes/supplier_queries.php'; // supplier_handle_upload() for the discrepancy tab

$user = procurement_require_stage([PROC_STAGE_STORE_MANAGER]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);

$branch_id = $user['branch_id'];
$user_id   = $user['user_id'];
$full_name = $user['full_name'];

$msg = '';
$ACTIONS = ['replacement' => 'Replacement', 'refund' => 'Refund', 'credit' => 'Store Credit', 'return' => 'Return Item', 'backorder' => 'Backorder'];

if (!$branch_id) {
    $msg = 'error:Your account has no branch assigned — this must be fixed before you can use Procurement.';
}

$__act = $_POST['act'] ?? '';
$__tab_by_act = [
    'save_draft' => 'new_request', 'submit' => 'new_request',
    'assign' => 'assign_supplier',
    'proceed_available' => 'quotation_review', 'retry_supplier' => 'quotation_review',
    'cancel' => 'quotation_review', 'forward_to_finance' => 'quotation_review',
    'send_resolution_request' => 'discrepancy', 'mark_resolved' => 'discrepancy',
];
$active_tab = $_GET['tab'] ?? ($__tab_by_act[$__act] ?? 'new_request');

// ════════════════════════════════════════════════════════════════════
// TAB: New Request (Purchase_Request_Form.php)
// ════════════════════════════════════════════════════════════════════

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'save_draft') {
    $notes = trim($_POST['notes'] ?? '');
    if (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

    $items = json_decode($_POST['items'] ?? '', true);

    if (!is_array($items) || empty($items)) {
        $msg = 'error:Add at least one item before saving.';
    } elseif (count($items) > 50) {
        $msg = 'error:Too many items in one request (max 50) — save this batch and start another.';
    } else {
        $clean  = [];
        $errors = [];
        $seen   = [];
        foreach ($items as $row) {
            $inv_id = (int) ($row['inventory_id'] ?? 0);
            $qty    = isset($row['qty']) && $row['qty'] !== '' ? (float) $row['qty'] : null;

            if (!$inv_id) { $errors[] = 'One of the rows is missing its item.'; continue; }
            if (isset($seen[$inv_id])) { $errors[] = 'Duplicate entry for the same item.'; continue; }
            $seen[$inv_id] = true;

            $stmt = mysqli_prepare($conn,
                'SELECT item_name, unit FROM inventory WHERE inventory_id = ? AND branch_id = ? AND is_active = 1 LIMIT 1'
            );
            mysqli_stmt_bind_param($stmt, 'ii', $inv_id, $branch_id);
            mysqli_stmt_execute($stmt);
            $inv = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);

            if (!$inv) { $errors[] = 'An item in this request is not available in your branch.'; continue; }
            if ($qty === null || $qty <= 0) { $errors[] = '"' . $inv['item_name'] . '": enter a valid quantity.'; continue; }

            $clean[] = ['inv_id' => $inv_id, 'item_name' => $inv['item_name'], 'unit' => $inv['unit'], 'qty' => $qty];
        }

        if ($errors) {
            $shown = array_slice($errors, 0, 3);
            $extra = count($errors) - count($shown);
            $msg = 'error:' . implode(' ', $shown) . ($extra > 0 ? " (+$extra more)" : '');
        } else {
            mysqli_begin_transaction($conn);
            $ok = true;

            $stmt = mysqli_prepare($conn,
                'INSERT INTO procurement_requests (branch_id, status, requested_by, requested_by_name, notes)
                 VALUES (?, \'DRAFT\', ?, ?, ?)'
            );
            mysqli_stmt_bind_param($stmt, 'iiss', $branch_id, $user_id, $full_name, $notes);
            if (mysqli_stmt_execute($stmt)) {
                $request_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $item_stmt = mysqli_prepare($conn,
                    'INSERT INTO procurement_request_items (request_id, inventory_id, item_name, unit, qty_requested)
                     VALUES (?,?,?,?,?)'
                );
                foreach ($clean as $c) {
                    mysqli_stmt_bind_param($item_stmt, 'iissd', $request_id, $c['inv_id'], $c['item_name'], $c['unit'], $c['qty']);
                    if (!mysqli_stmt_execute($item_stmt)) { $ok = false; break; }
                }
                mysqli_stmt_close($item_stmt);
            } else {
                $ok = false;
            }

            if ($ok) {
                mysqli_commit($conn);
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'created', null, PROC_STATUS_DRAFT);
                $msg = 'success:Draft saved with ' . count($clean) . ' item(s). Submit it below when ready.';
            } else {
                mysqli_rollback($conn);
                $msg = 'error:Something went wrong saving the draft. Nothing was saved — try again.';
            }
        }
    }
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'submit') {
    $request_id = (int) ($_POST['request_id'] ?? 0);

    $stmt = mysqli_prepare($conn, 'SELECT request_id, status, requested_by FROM procurement_requests WHERE request_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ((int) $req['requested_by'] !== $user_id) {
        $msg = 'error:You can only submit your own requests.';
    } elseif ($req['status'] !== PROC_STATUS_DRAFT) {
        $msg = 'error:This request was already submitted or is no longer a draft.';
    } else {
        $count_stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM procurement_request_items WHERE request_id = ?');
        mysqli_stmt_bind_param($count_stmt, 'i', $request_id);
        mysqli_stmt_execute($count_stmt);
        $item_count = (int) mysqli_stmt_get_result($count_stmt)->fetch_assoc()['c'];
        mysqli_stmt_close($count_stmt);

        $next = procurement_next_status(PROC_STATUS_DRAFT, 'approve');

        if ($item_count < 1) {
            $msg = 'error:Add at least one item before submitting.';
        } elseif (!$next || !procurement_can_transition(PROC_STATUS_DRAFT, $next)) {
            $msg = 'error:Unable to submit — invalid workflow state.';
        } else {
            $upd = mysqli_prepare($conn,
                'UPDATE procurement_requests SET status = ?, last_actor_id = ?
                 WHERE request_id = ? AND status = ? AND requested_by = ?'
            );
            $__draft = PROC_STATUS_DRAFT;
            mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $__draft, $user_id);
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($changed === 1) {
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'submitted', PROC_STATUS_DRAFT, $next);
                $msg = 'success:Request submitted. Assign a supplier to it next.';
            } else {
                $msg = 'error:This request was already submitted or changed — refresh and try again.';
            }
        }
    }
}

$branch_items = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn, 'SELECT inventory_id, item_name, unit FROM inventory WHERE branch_id = ? AND is_active = 1 ORDER BY item_name ASC');
    mysqli_stmt_bind_param($stmt, 'i', $branch_id);
    mysqli_stmt_execute($stmt);
    $branch_items = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

$my_requests = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.status, r.notes, r.created_at,
                (SELECT COUNT(*) FROM procurement_request_items i WHERE i.request_id = r.request_id) AS item_count
         FROM procurement_requests r
         WHERE r.requested_by = ? AND r.branch_id = ?
         ORDER BY r.created_at DESC
         LIMIT 50'
    );
    mysqli_stmt_bind_param($stmt, 'ii', $user_id, $branch_id);
    mysqli_stmt_execute($stmt);
    $my_requests = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

function status_pill_class(string $status): string {
    if ($status === PROC_STATUS_DRAFT) return 'st-draft';
    if (in_array($status, [PROC_STATUS_REJECTED, PROC_STATUS_CANCELLED], true)) return 'st-bad';
    if ($status === PROC_STATUS_RETURNED_FOR_REVISION) return 'st-warn';
    return 'st-progress';
}

/**
 * Plain-language label for a request status. The raw enum names are internal
 * plumbing; store managers should never read SUPPLIER_STOCK_CHECKING off a
 * screen. Unknown statuses fall back to a prettified version of the enum so a
 * new constant never renders as garbage.
 */
function proc_status_label(string $status): string {
    static $map = null;
    if ($map === null) {
        $map = [
            PROC_STATUS_DRAFT                   => 'Draft',
            PROC_STATUS_SUBMITTED               => 'Needs a supplier',
            PROC_STATUS_SUPPLIER_STOCK_CHECKING => 'Supplier checking stock',
            PROC_STATUS_QUOTATION_RECEIVED      => 'Quote needs review',
            PROC_STATUS_RETURNED_FOR_REVISION   => 'Sent back for changes',
            PROC_STATUS_DELIVERY_DISCREPANCY    => 'Delivery has a problem',
            PROC_STATUS_CANCELLED               => 'Cancelled',
        ];
        // Constants that live in the includes but are not referenced on this page.
        foreach ([
            'PROC_STATUS_REJECTED'             => 'Rejected',
            'PROC_STATUS_PO_ISSUED'            => 'Order placed',
            'PROC_STATUS_FORWARDED_TO_FINANCE' => 'With finance',
            'PROC_STATUS_DELIVERED'            => 'Delivered',
            'PROC_STATUS_COMPLETED'            => 'Completed',
        ] as $const => $label) {
            if (defined($const)) $map[constant($const)] = $label;
        }
    }
    return $map[$status] ?? ucfirst(strtolower(str_replace('_', ' ', $status)));
}

// ════════════════════════════════════════════════════════════════════
// TAB: Assign Supplier (Assign_Supplier_Page.php)
// ════════════════════════════════════════════════════════════════════

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'assign') {
    $request_id  = (int) ($_POST['request_id'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

    $supplierRow = $supplier_id
        ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM suppliers WHERE supplier_id = $supplier_id AND status = 'active'"))
        : null;

    if (!$supplier_id || !$supplierRow) {
        $msg = 'error:Please select a supplier.';
    } else {
        $stmt = mysqli_prepare($conn,
            'SELECT request_id, branch_id, status FROM procurement_requests WHERE request_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($stmt, 'i', $request_id);
        mysqli_stmt_execute($stmt);
        $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$req) {
            $msg = 'error:Request not found.';
        } elseif ((int) $req['branch_id'] !== $branch_id) {
            $msg = 'error:That request does not belong to your branch.';
        } elseif ($req['status'] !== PROC_STATUS_SUBMITTED) {
            $msg = 'error:This request is no longer awaiting a supplier — someone may have already acted on it.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, 'assign_supplier');

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That action is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    "UPDATE procurement_requests
                     SET status = ?, supplier_id = ?, supplier_check_status = 'pending', last_actor_id = ?
                     WHERE request_id = ? AND status = ? AND branch_id = ?"
                );
                mysqli_stmt_bind_param($upd, 'siiisi', $next, $supplier_id, $user_id, $request_id, $old_status, $branch_id);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, 'supplier_assigned', $old_status, $next,
                        'Supplier: ' . $supplierRow['name']);
                    $msg = 'success:' . $supplierRow['name'] . ' assigned. Waiting for them to confirm stock and quote a price.';
                } else {
                    $msg = 'error:This request was already updated — refresh and try again.';
                }
            }
        }
    }
}

$submitted_queue = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        'SELECT request_id, requested_by_name, notes, created_at
         FROM procurement_requests
         WHERE branch_id = ? AND status = ?
         ORDER BY created_at ASC'
    );
    $__status = PROC_STATUS_SUBMITTED;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__status);
    mysqli_stmt_execute($stmt);
    $submitted_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($submitted_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

$active_suppliers = [];
$sres = mysqli_query($conn, "SELECT supplier_id, name FROM suppliers WHERE status = 'active' ORDER BY name");
if ($sres) while ($s = mysqli_fetch_assoc($sres)) $active_suppliers[] = $s;

$waiting = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.created_at, s.name AS supplier_name, r.supplier_check_status
         FROM procurement_requests r
         LEFT JOIN suppliers s ON s.supplier_id = r.supplier_id
         WHERE r.branch_id = ? AND r.status = ? AND r.supplier_check_status = 'pending'
         ORDER BY r.created_at ASC"
    );
    $__status2 = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__status2);
    mysqli_stmt_execute($stmt);
    $waiting = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// ════════════════════════════════════════════════════════════════════
// TAB: Quotation Review (Quotation_Review_Page.php)
// ════════════════════════════════════════════════════════════════════

function qr_load_request(mysqli $conn, int $requestId, int $branchId): ?array {
    $stmt = mysqli_prepare($conn,
        'SELECT request_id, branch_id, status, supplier_id, supplier_check_status FROM procurement_requests WHERE request_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
    if (!$req || (int) $req['branch_id'] !== $branchId) return null;
    return $req;
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'proceed_available') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $req = qr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'partial_stock') {
        $msg = 'error:This request is not awaiting a partial-stock decision.';
    } else {
        mysqli_begin_transaction($conn);
        $ok = true;
        $ustmt = mysqli_prepare($conn,
            'UPDATE procurement_request_items SET qty_requested = available_quantity, available_quantity = NULL
             WHERE request_id = ? AND available_quantity IS NOT NULL'
        );
        mysqli_stmt_bind_param($ustmt, 'i', $request_id);
        $ok = mysqli_stmt_execute($ustmt);
        mysqli_stmt_close($ustmt);

        if ($ok) {
            $upd = mysqli_prepare($conn,
                "UPDATE procurement_requests SET supplier_check_status = 'pending'
                 WHERE request_id = ? AND status = ? AND branch_id = ? AND supplier_check_status = 'partial_stock'"
            );
            $__status = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
            mysqli_stmt_bind_param($upd, 'isi', $request_id, $__status, $branch_id);
            mysqli_stmt_execute($upd);
            $ok = mysqli_stmt_affected_rows($upd) === 1;
            mysqli_stmt_close($upd);
        }

        if ($ok) {
            mysqli_commit($conn);
            procurement_log_audit($conn, $request_id, $user_id, $full_name, 'proceed_with_available_qty',
                PROC_STATUS_SUPPLIER_STOCK_CHECKING, PROC_STATUS_SUPPLIER_STOCK_CHECKING,
                'Quantities adjusted to reported availability; sent back to supplier for pricing.');
            $msg = 'success:Quantities adjusted. Sent back to the supplier for a price quote.';
        } else {
            mysqli_rollback($conn);
            $msg = 'error:This request was already updated — refresh and try again.';
        }
    }
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'retry_supplier') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $req = qr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_SUPPLIER_STOCK_CHECKING || $req['supplier_check_status'] !== 'partial_stock') {
        $msg = 'error:This request is not awaiting a partial-stock decision.';
    } else {
        $old_status = $req['status'];
        $next = procurement_next_status($old_status, 'retry_supplier');

        if (!$next || !procurement_can_transition($old_status, $next)) {
            $msg = 'error:That action is not valid for this request\'s current state.';
        } else {
            $upd = mysqli_prepare($conn,
                "UPDATE procurement_requests
                 SET status = ?, supplier_id = NULL, supplier_check_status = 'not_sent', last_actor_id = ?
                 WHERE request_id = ? AND status = ? AND branch_id = ?"
            );
            mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $old_status, $branch_id);
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($changed === 1) {
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'retry_with_new_supplier', $old_status, $next);
                $msg = 'success:Sent back to Assign Supplier so you can pick a different one.';
            } else {
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'cancel') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
    $req = qr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($note === '') {
        $msg = 'error:A reason is required to cancel a request.';
    } else {
        $old_status = $req['status'];
        $next = procurement_next_status($old_status, 'cancel');

        if (!$next || !procurement_can_transition($old_status, $next)) {
            $msg = 'error:That action is not valid for this request\'s current state.';
        } else {
            $upd = mysqli_prepare($conn,
                'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ? AND branch_id = ?'
            );
            mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $old_status, $branch_id);
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($changed === 1) {
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'cancelled', $old_status, $next, $note);
                $msg = 'success:Request cancelled.';
            } else {
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'forward_to_finance') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $req = qr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_QUOTATION_RECEIVED) {
        $msg = 'error:This request is no longer awaiting forwarding — someone may have already acted on it.';
    } else {
        $old_status = $req['status'];
        $next = procurement_next_status($old_status, 'forward_to_finance');

        if (!$next || !procurement_can_transition($old_status, $next)) {
            $msg = 'error:That action is not valid for this request\'s current state.';
        } else {
            $upd = mysqli_prepare($conn,
                'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ? AND branch_id = ?'
            );
            mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $old_status, $branch_id);
            mysqli_stmt_execute($upd);
            $changed = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($changed === 1) {
                procurement_log_audit($conn, $request_id, $user_id, $full_name, 'forwarded_to_finance', $old_status, $next);
                $msg = 'success:Request forwarded to Finance.';
            } else {
                $msg = 'error:This request was already updated — refresh and try again.';
            }
        }
    }
}

$partial_queue = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.created_at, s.name AS supplier_name
         FROM procurement_requests r
         LEFT JOIN suppliers s ON s.supplier_id = r.supplier_id
         WHERE r.branch_id = ? AND r.status = ? AND r.supplier_check_status = 'partial_stock'
         ORDER BY r.created_at ASC"
    );
    $__st1 = PROC_STATUS_SUPPLIER_STOCK_CHECKING;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__st1);
    mysqli_stmt_execute($stmt);
    $partial_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($partial_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested, available_quantity FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

$quote_queue = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT r.request_id, r.created_at, r.quotation_amount, r.shipping_fee, r.quotation_attachment_path, r.quotation_notes, s.name AS supplier_name
         FROM procurement_requests r
         LEFT JOIN suppliers s ON s.supplier_id = r.supplier_id
         WHERE r.branch_id = ? AND r.status = ?
         ORDER BY r.created_at ASC"
    );
    $__st2 = PROC_STATUS_QUOTATION_RECEIVED;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__st2);
    mysqli_stmt_execute($stmt);
    $quote_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($quote_queue as &$r) {
        $istmt = mysqli_prepare($conn, 'SELECT item_name, unit, qty_requested FROM procurement_request_items WHERE request_id = ? ORDER BY item_id ASC');
        mysqli_stmt_bind_param($istmt, 'i', $r['request_id']);
        mysqli_stmt_execute($istmt);
        $r['items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);
    }
    unset($r);
}

// ════════════════════════════════════════════════════════════════════
// TAB: Delivery Discrepancies (Discrepancy_Resolution_Page.php)
// ════════════════════════════════════════════════════════════════════

function dr_load_request(mysqli $conn, int $requestId, int $branchId): ?array {
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.branch_id, r.status, po.po_id
         FROM procurement_requests r
         JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.request_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $req = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
    if (!$req || (int) $req['branch_id'] !== $branchId) return null;
    return $req;
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'send_resolution_request') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $action     = $_POST['requested_action'] ?? '';
    $message    = trim($_POST['message'] ?? '');

    $req = dr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_DELIVERY_DISCREPANCY) {
        $msg = 'error:This request is not awaiting discrepancy resolution.';
    } elseif (!array_key_exists($action, $ACTIONS)) {
        $msg = 'error:Select what you want the supplier to do.';
    } elseif ($message === '') {
        $msg = 'error:Describe the issue for the supplier.';
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
            $issue = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT issue_id FROM procurement_delivery_issues WHERE po_id = " . (int) $req['po_id'] . " AND status = 'open' LIMIT 1"));
            $issue_id = $issue['issue_id'] ?? null;

            if (!$issue_id) {
                $ins = mysqli_prepare($conn,
                    'INSERT INTO procurement_delivery_issues (request_id, po_id, raised_by, raised_by_name) VALUES (?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'iiis', $request_id, $req['po_id'], $user_id, $full_name);
                mysqli_stmt_execute($ins);
                $issue_id = mysqli_insert_id($conn);
                mysqli_stmt_close($ins);
            }

            $mstmt = mysqli_prepare($conn,
                "INSERT INTO procurement_delivery_issue_messages
                    (issue_id, sender_type, sender_user_id, message, requested_action, attachment_path)
                 VALUES (?, 'store_manager', ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($mstmt, 'iisss', $issue_id, $user_id, $message, $action, $attachment_path);
            mysqli_stmt_execute($mstmt);
            mysqli_stmt_close($mstmt);

            procurement_log_audit($conn, $request_id, $user_id, $full_name, 'discrepancy_resolution_requested',
                $req['status'], $req['status'], $ACTIONS[$action] . ' — ' . $message);
            $msg = 'success:Resolution request sent to the supplier.';
        }
    }
}

if ($branch_id && $_SERVER['REQUEST_METHOD'] === 'POST' && $__act === 'mark_resolved') {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $req = dr_load_request($conn, $request_id, $branch_id);

    if (!$req) {
        $msg = 'error:Request not found.';
    } elseif ($req['status'] !== PROC_STATUS_DELIVERY_DISCREPANCY) {
        $msg = 'error:This request is not awaiting discrepancy resolution — someone may have already acted on it.';
    } else {
        $hasSupplierReply = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT 1 FROM procurement_delivery_issue_messages m
             JOIN procurement_delivery_issues i ON i.issue_id = m.issue_id
             WHERE i.po_id = " . (int) $req['po_id'] . " AND m.sender_type = 'supplier' LIMIT 1"));

        if (!$hasSupplierReply) {
            $msg = 'error:Wait for the supplier to reply before marking this resolved.';
        } else {
            $old_status = $req['status'];
            $next = procurement_next_status($old_status, 'resolve_discrepancy');

            if (!$next || !procurement_can_transition($old_status, $next)) {
                $msg = 'error:That action is not valid for this request\'s current state.';
            } else {
                $upd = mysqli_prepare($conn,
                    'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ? AND branch_id = ?'
                );
                mysqli_stmt_bind_param($upd, 'siisi', $next, $user_id, $request_id, $old_status, $branch_id);
                mysqli_stmt_execute($upd);
                $changed = mysqli_stmt_affected_rows($upd);
                mysqli_stmt_close($upd);

                if ($changed === 1) {
                    mysqli_query($conn, "UPDATE procurement_delivery_issues SET status = 'resolved', resolved_at = NOW() WHERE po_id = " . (int) $req['po_id'] . " AND status = 'open'");
                    procurement_log_audit($conn, $request_id, $user_id, $full_name, 'discrepancy_resolved', $old_status, $next,
                        'Resolved with supplier — see delivery issue thread for details. Finance reconciles payment manually.');
                    $msg = 'success:Discrepancy resolved. Request is now eligible for payment.';
                } else {
                    $msg = 'error:This request was already updated — refresh and try again.';
                }
            }
        }
    }
}

$discrepancy_queue = [];
if ($branch_id) {
    $stmt = mysqli_prepare($conn,
        'SELECT r.request_id, r.requested_by_name, po.po_id, po.po_number, po.supplier_name
         FROM procurement_requests r
         JOIN procurement_purchase_orders po ON po.request_id = r.request_id
         WHERE r.branch_id = ? AND r.status = ?
         ORDER BY r.updated_at ASC'
    );
    $__status = PROC_STATUS_DELIVERY_DISCREPANCY;
    mysqli_stmt_bind_param($stmt, 'is', $branch_id, $__status);
    mysqli_stmt_execute($stmt);
    $discrepancy_queue = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    foreach ($discrepancy_queue as &$r) {
        $istmt = mysqli_prepare($conn,
            'SELECT ri.item_name, ri.unit, ri.qty_received, ri.item_condition, ri.note
             FROM procurement_receipt_items ri
             JOIN procurement_receipts rec ON rec.receipt_id = ri.receipt_id
             WHERE rec.po_id = ? AND ri.item_condition <> "good"
             ORDER BY ri.receipt_item_id ASC'
        );
        mysqli_stmt_bind_param($istmt, 'i', $r['po_id']);
        mysqli_stmt_execute($istmt);
        $r['flagged_items'] = mysqli_stmt_get_result($istmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($istmt);

        $tstmt = mysqli_prepare($conn,
            "SELECT m.sender_type, m.message, m.requested_action, m.attachment_path, m.created_at
             FROM procurement_delivery_issue_messages m
             JOIN procurement_delivery_issues i ON i.issue_id = m.issue_id
             WHERE i.po_id = ? AND i.status = 'open'
             ORDER BY m.created_at ASC"
        );
        mysqli_stmt_bind_param($tstmt, 'i', $r['po_id']);
        mysqli_stmt_execute($tstmt);
        $r['thread'] = mysqli_stmt_get_result($tstmt)->fetch_all(MYSQLI_ASSOC);
        mysqli_stmt_close($tstmt);
        $r['has_supplier_reply'] = (bool) array_filter($r['thread'], fn($m) => $m['sender_type'] === 'supplier');
    }
    unset($r);
}

$active_page = 'proc-hub';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script src="../js/tab_session_guard.js"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Procurement — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .req-card{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:16px;margin-bottom:14px;}
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
    .req-items{margin:10px 0;font-size:13px;color:var(--text-light);}
    .req-items li{margin-bottom:2px;}
    .req-actions{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;}
    .req-actions input[type=text]{flex:1;min-width:200px;}
    .btn-reject{background:#fdeaea;color:#c0392b;border:1px solid #f3c6c6;}
    .btn-return{background:#fff4e5;color:#b45300;border:1px solid #f0d6a8;}
    .po-form-row{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0;}
    .po-form-row select{flex:1;min-width:220px;}
    .waiting-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border,#e5e7eb);font-size:13px;}
    .waiting-row:last-child{border-bottom:none;}
    .pill-wait{background:#fff4e5;color:#b45300;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;}
    .finance-box{background:#f7f8fa;border-radius:8px;padding:10px 14px;font-size:13px;margin:10px 0;display:flex;gap:20px;flex-wrap:wrap;}
    .finance-box b{color:var(--ink,#222);}
    .shortfall{color:#b45300;font-weight:700;}
    .cond-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;margin-left:6px;}
    .cond-damaged{background:#fdeaea;color:#c0392b;}
    .cond-missing{background:#fff4e5;color:#b45300;}
    .thread{border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:10px 12px;margin:10px 0;background:#fafafa;}
    .thread-msg{font-size:13px;padding:6px 0;border-bottom:1px solid #eee;}
    .thread-msg:last-child{border-bottom:none;}
    .thread-msg .who{font-weight:700;}
    .thread-msg.supplier .who{color:#1a5fb4;}
    .thread-msg.store_manager .who{color:#7a5b00;}
    .req-pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;}
    .req-pill.st-draft{background:#eef0f2;color:#555;}
    .req-pill.st-progress{background:#e7f1ff;color:#1a5fb4;}
    .req-pill.st-warn{background:#fff4e5;color:#b45300;}
    .req-pill.st-bad{background:#fdeaea;color:#c0392b;}
    #itemRows .item-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;}
    #itemRows select{flex:2;}
    #itemRows input[type=number]{flex:1;}
    h2.section-title{font-size:16px;margin:24px 0 10px;}
    .hub-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border,#e5e7eb);flex-wrap:wrap;}
    .hub-tab-btn{padding:10px 16px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:600;color:var(--text-light,#6b6156);border-bottom:2px solid transparent;}
    .hub-tab-btn.active{color:var(--mc-espresso,#2A1B14);border-bottom-color:#b8703f;}
    .hub-tab-panel{display:none;}
    .hub-tab-panel.active{display:block;}

    /* ══════════════════════════════════════════════════════════════════
       Procurement redesign — palette + New Request tab
       Everything here is namespaced .pr-* so the Assign Supplier,
       Quotation Review and Discrepancy panels keep their existing styles.
       ══════════════════════════════════════════════════════════════════ */
    :root{
      --pr-cocoa:#3C2317;
      --pr-teal:#628E90;
      --pr-sky:#B4CDE6;
      --pr-cream:#F5EFE6;
      --pr-card:#FDFBF7;
      --pr-ink:#3C2317;
      --pr-ink-soft:#6B5142;
      --pr-mute:#93806F;
      --pr-faint:#B9A895;
      --pr-rule:#E5DACC;
      --pr-rule-soft:#F0E9DE;
      --pr-teal-dark:#3F6A6C;
      --pr-teal-tint:#E3EDED;
      --pr-sky-deep:#2F5372;
      --pr-sky-tint:#E8F1F9;
      --pr-alert:#A8443A;          /* the palette has no warm signal colour */
      --pr-alert-tint:#F7E9E7;
      --pr-ease:cubic-bezier(.2,.8,.3,1);
      --pr-fast:130ms;
      --pr-med:200ms;
    }

    /* ---------- tab bar ---------- */
    .hub-tabs.pr-tabs{position:relative;border-bottom:1px solid var(--pr-rule);}
    .pr-tabs .hub-tab-btn{display:inline-flex;align-items:center;gap:8px;color:var(--pr-mute);
      border-bottom:2px solid transparent;transition:color var(--pr-fast) var(--pr-ease);}
    .pr-tabs .hub-tab-btn:hover{color:var(--pr-ink-soft);}
    .pr-tabs .hub-tab-btn.active{color:var(--pr-ink);border-bottom-color:transparent;}
    .pr-tab-indicator{position:absolute;bottom:-1px;left:0;height:2px;width:0;background:var(--pr-teal);
      border-radius:2px;transform-origin:left;
      transition:transform var(--pr-med) var(--pr-ease),width var(--pr-med) var(--pr-ease);}
    .pr-count{font-size:11px;font-weight:600;background:var(--pr-rule-soft);color:var(--pr-ink-soft);
      border-radius:20px;padding:1px 7px;}
    .pr-count.pr-due{background:var(--pr-sky);color:var(--pr-sky-deep);}

    /* ---------- page head ---------- */
    .pr-pagehead{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;
      flex-wrap:wrap;margin-bottom:18px;}
    .pr-h1{font-size:20px;font-weight:650;letter-spacing:-.015em;margin:0;color:var(--pr-ink);}
    .pr-standfirst{font-size:13px;color:var(--pr-mute);margin:4px 0 0;max-width:60ch;}
    .pr-tallies{display:flex;gap:8px;}
    .pr-tally{background:var(--pr-card);border:1px solid var(--pr-rule);border-radius:10px;
      padding:9px 15px;min-width:104px;}
    .pr-tally b{display:block;font-size:19px;font-weight:650;letter-spacing:-.02em;line-height:1.2;}
    .pr-tally span{font-size:11.5px;color:var(--pr-mute);}
    .pr-tally.pr-flag{background:var(--pr-alert-tint);border-color:#EAD3CF;}
    .pr-tally.pr-flag b{color:var(--pr-alert);}

    /* ---------- panels ---------- */
    .pr-work{display:grid;grid-template-columns:minmax(0,1fr) 258px;gap:18px;align-items:start;}
    .pr-panel{background:var(--pr-card);border:1px solid var(--pr-rule);border-radius:12px;}
    .pr-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;
      padding:14px 18px;border-bottom:1px solid var(--pr-rule-soft);}
    .pr-panel-title{font-size:14.5px;font-weight:650;color:var(--pr-ink);}
    .pr-panel-meta{font-size:12px;color:var(--pr-mute);}
    .pr-panel-body{padding:8px 18px 18px;}
    .pr-empty{font-size:13px;color:var(--pr-mute);padding:18px 0;}

    /* ---------- line items ---------- */
    .pr-grid-row{display:grid;grid-template-columns:minmax(0,1fr) 96px 96px 36px;gap:10px;align-items:center;}
    .pr-lines-head{padding:10px 0 6px;font-size:11.5px;font-weight:600;color:var(--pr-mute);}
    #itemRows .item-row.pr-line{display:grid;margin:0;padding:4px 0;max-height:64px;
      transition:opacity var(--pr-med) var(--pr-ease),transform var(--pr-med) var(--pr-ease);}
    .pr-line.pr-entering{animation:prLineIn 260ms var(--pr-ease);}
    @keyframes prLineIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
    #itemRows .item-row.pr-line.pr-leaving{opacity:0;transform:translateX(-10px);max-height:0;
      padding:0;overflow:hidden;pointer-events:none;
      transition:opacity 160ms var(--pr-ease),transform 160ms var(--pr-ease),
                 max-height 200ms var(--pr-ease) 40ms,padding 200ms var(--pr-ease) 40ms;}
    .pr-line.pr-flash .pr-field{animation:prFlash 900ms var(--pr-ease);}
    @keyframes prFlash{0%{background:var(--pr-teal-tint);border-color:var(--pr-teal)}
                       100%{background:var(--pr-card);border-color:var(--pr-rule)}}

    .pr-panel .pr-field,.req-card .pr-field{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;
      border:1px solid var(--pr-rule);background:var(--pr-card);border-radius:8px;padding:9px 11px;
      min-height:40px;font-size:13.5px;line-height:1.3;color:var(--pr-ink);text-align:left;font-family:inherit;
      transition:border-color var(--pr-fast) var(--pr-ease),background var(--pr-fast) var(--pr-ease),
                 box-shadow var(--pr-fast) var(--pr-ease);}
    .pr-panel button.pr-field:hover,.req-card button.pr-field:hover{border-color:var(--pr-teal);}
    .pr-field.pr-empty-val{color:var(--pr-faint);}
    .pr-field .pr-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pr-field.pr-static{color:var(--pr-mute);background:var(--pr-rule-soft);border-style:dashed;
      justify-content:center;}
    .pr-panel input.pr-field:focus,.pr-panel textarea:focus{outline:none;border-color:var(--pr-teal);
      box-shadow:0 0 0 3px rgba(98,142,144,.16);}
    .pr-panel input.pr-field::placeholder{color:var(--pr-faint);}
    .pr-caret{flex:none;width:7px;height:7px;border-right:1.5px solid var(--pr-teal);
      border-bottom:1.5px solid var(--pr-teal);transform:rotate(45deg) translate(-1px,-1px);
      transition:transform var(--pr-med) var(--pr-ease);}

    /* ---------- combobox ---------- */
    .pr-combo{position:relative;min-width:0;}
    .pr-js .pr-combo > select{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;}
    .pr-combo[data-open="true"] .pr-field{border-color:var(--pr-teal);box-shadow:0 0 0 3px rgba(98,142,144,.16);}
    .pr-combo[data-open="true"] .pr-caret{transform:rotate(-135deg) translate(-2px,-2px);}
    .pr-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:60;background:var(--pr-card);
      border:1px solid var(--pr-rule);border-radius:10px;box-shadow:0 14px 30px -12px rgba(60,35,23,.28);
      padding:6px;opacity:0;transform:translateY(-6px) scale(.985);transform-origin:top center;
      pointer-events:none;transition:opacity var(--pr-fast) var(--pr-ease),transform var(--pr-fast) var(--pr-ease);}
    .pr-combo[data-open="true"] .pr-menu{opacity:1;transform:none;pointer-events:auto;}
    .pr-menu-search{width:100%;border:1px solid var(--pr-rule);border-radius:7px;padding:8px 10px;
      font-size:13px;font-family:inherit;margin-bottom:6px;}
    .pr-menu-search:focus{outline:none;border-color:var(--pr-teal);}
    .pr-menu-list{max-height:212px;overflow-y:auto;list-style:none;margin:0;padding:0;}
    .pr-opt{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:8px 10px;
      border-radius:7px;font-size:13px;cursor:pointer;transition:background var(--pr-fast) var(--pr-ease);}
    .pr-opt small{color:var(--pr-mute);font-size:11px;flex:none;}
    .pr-opt[aria-selected="true"]{font-weight:600;}
    .pr-opt.pr-active{background:var(--pr-teal-tint);}
    .pr-opt-empty{padding:12px 10px;font-size:12.5px;color:var(--pr-mute);list-style:none;}

    .pr-drop{width:32px;height:32px;border:0;background:none;border-radius:8px;color:var(--pr-faint);
      display:grid;place-items:center;font-size:18px;line-height:1;cursor:pointer;
      transition:background var(--pr-fast) var(--pr-ease),color var(--pr-fast) var(--pr-ease),
                 transform var(--pr-fast) var(--pr-ease);}
    .pr-drop:hover{background:var(--pr-rule-soft);color:var(--pr-cocoa);transform:rotate(90deg);}

    .pr-addline{margin-top:10px;width:100%;border:1px dashed var(--pr-rule);background:none;border-radius:9px;
      padding:11px;font-size:13px;font-family:inherit;color:var(--pr-teal-dark);font-weight:600;cursor:pointer;
      transition:background var(--pr-fast) var(--pr-ease),border-color var(--pr-fast) var(--pr-ease);}
    .pr-addline:hover{background:var(--pr-teal-tint);border-color:var(--pr-teal);}
    .pr-addline:active{transform:translateY(1px);}

    .pr-notes-label{display:flex;justify-content:space-between;align-items:baseline;margin:20px 0 7px;
      font-size:12.5px;font-weight:600;color:var(--pr-ink-soft);}
    .pr-notes-label span{font-weight:400;color:var(--pr-faint);transition:color var(--pr-fast) var(--pr-ease);}
    .pr-panel textarea{width:100%;border:1px solid var(--pr-rule);background:var(--pr-card);border-radius:9px;
      padding:11px 12px;font-family:inherit;font-size:13.5px;color:var(--pr-ink);min-height:70px;resize:vertical;
      transition:border-color var(--pr-fast) var(--pr-ease),box-shadow var(--pr-fast) var(--pr-ease);}
    .pr-panel textarea::placeholder{color:var(--pr-faint);}

    .pr-panel-foot{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
      padding:13px 18px;border-top:1px solid var(--pr-rule-soft);background:var(--pr-cream);
      border-radius:0 0 11px 11px;}
    .pr-foot-note{font-size:12px;color:var(--pr-mute);margin:0;}
    .pr-actions{display:flex;gap:8px;align-items:center;}

    /* ---------- buttons ---------- */
    .pr-btn{position:relative;border:1px solid var(--pr-rule);background:var(--pr-card);color:var(--pr-ink-soft);
      border-radius:8px;padding:9px 16px;font-size:13px;font-family:inherit;font-weight:600;cursor:pointer;
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
      transition:background var(--pr-fast) var(--pr-ease),border-color var(--pr-fast) var(--pr-ease),
                 color var(--pr-fast) var(--pr-ease),transform var(--pr-fast) var(--pr-ease),
                 box-shadow var(--pr-fast) var(--pr-ease);}
    .pr-btn:hover{background:var(--pr-rule-soft);border-color:#D8CBB9;}
    .pr-btn:active{transform:translateY(1px);}
    .pr-btn.pr-primary{background:var(--pr-cocoa);border-color:var(--pr-cocoa);color:var(--pr-cream);
      box-shadow:0 1px 0 rgba(60,35,23,.18);}
    .pr-btn.pr-primary:hover{background:#4E3122;box-shadow:0 4px 12px -4px rgba(60,35,23,.45);}
    .pr-btn.pr-primary:active{transform:translateY(1px);box-shadow:0 1px 0 rgba(60,35,23,.18);}
    .pr-btn.pr-quiet{border-color:transparent;background:none;color:var(--pr-mute);font-weight:500;}
    .pr-btn.pr-quiet:hover{background:var(--pr-rule-soft);color:var(--pr-ink-soft);}
    .pr-btn[disabled]{opacity:.42;pointer-events:none;box-shadow:none;}
    .pr-btn.pr-mini{padding:6px 12px;font-size:12px;}
    .pr-spin{width:13px;height:13px;border:2px solid rgba(245,239,230,.35);border-top-color:var(--pr-cream);
      border-radius:50%;display:none;animation:prSpin 620ms linear infinite;}
    .pr-btn.pr-loading .pr-spin{display:block;}
    @keyframes prSpin{to{transform:rotate(360deg)}}

    /* ---------- side rail ---------- */
    .pr-rail{display:flex;flex-direction:column;gap:14px;}
    .pr-pair{display:flex;justify-content:space-between;gap:10px;padding:7px 0;
      border-bottom:1px dashed var(--pr-rule-soft);font-size:13px;color:var(--pr-mute);}
    .pr-pair:last-child{border-bottom:0;}
    .pr-pair b{color:var(--pr-ink);font-weight:600;}
    .pr-pair b.pr-bumped{display:inline-block;animation:prBump 380ms var(--pr-ease);}
    @keyframes prBump{0%{transform:none}35%{transform:translateY(-3px) scale(1.06);color:var(--pr-teal-dark)}
                      100%{transform:none}}
    /* live contents of the request being written */
    .pr-sum-list{list-style:none;margin:0;padding:0;}
    .pr-sum-list li{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
      padding:9px 0;border-bottom:1px solid var(--pr-rule-soft);font-size:13px;}
    .pr-sum-list li:first-child{padding-top:2px;}
    .pr-sum-name{color:var(--pr-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pr-sum-qty{flex:none;font-weight:600;color:var(--pr-teal-dark);white-space:nowrap;}
    .pr-sum-qty.pr-pending{font-weight:400;color:var(--pr-faint);}
    .pr-sum-list li.pr-sum-empty{display:block;border-bottom:0;color:var(--pr-mute);
      font-size:12.5px;line-height:1.55;padding:10px 0 12px;}
    .pr-sum-list li.pr-added{animation:prLineIn 260ms var(--pr-ease);}
    .pr-sum-rule{height:1px;background:var(--pr-rule);margin:12px 0 2px;}

    /* ---------- request table ---------- */
    .pr-tracking{margin-top:20px;}
    .pr-filters{display:flex;gap:8px;align-items:center;}
    .pr-search{border:1px solid var(--pr-rule);background:var(--pr-card);border-radius:8px;padding:8px 12px;
      font-family:inherit;font-size:12.5px;color:var(--pr-ink);min-width:200px;
      transition:border-color var(--pr-fast) var(--pr-ease),box-shadow var(--pr-fast) var(--pr-ease);}
    .pr-search::placeholder{color:var(--pr-faint);}
    .pr-search:focus{border-color:var(--pr-teal);outline:none;box-shadow:0 0 0 3px rgba(98,142,144,.16);}
    .pr-combo.pr-compact{min-width:172px;}

    .pr-panel .pr-requests{width:100%;border-collapse:collapse;}
    .pr-panel .pr-requests th{text-align:left;font-size:11.5px;font-weight:600;color:var(--pr-mute);
      padding:12px 18px 10px;background:none;border:0;text-transform:none;letter-spacing:0;}
    .pr-panel .pr-requests td{padding:13px 18px;border-top:1px solid var(--pr-rule-soft);
      border-bottom:0;vertical-align:middle;font-size:13px;color:var(--pr-ink);}
    .pr-panel .pr-requests tbody tr{transition:background var(--pr-fast) var(--pr-ease);}
    .pr-panel .pr-requests tbody tr:hover{background:var(--pr-cream);}
    .pr-panel .pr-requests tbody tr.pr-hidden{display:none;}
    .pr-ref{font-weight:600;letter-spacing:-.01em;}
    .pr-contents{color:var(--pr-ink-soft);}
    .pr-contents small{display:block;color:var(--pr-mute);font-size:11.5px;margin-top:2px;
      max-width:34ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

    .pr-status{display:inline-flex;align-items:center;gap:7px;border-radius:20px;padding:4px 11px;
      font-size:12px;font-weight:600;white-space:nowrap;}
    .pr-status i{width:6px;height:6px;border-radius:50%;background:currentColor;flex:none;}
    .pr-st-draft{background:var(--pr-rule-soft);color:var(--pr-ink-soft);}
    .pr-st-progress{background:var(--pr-sky-tint);color:var(--pr-sky-deep);}
    .pr-st-progress i{animation:prPulse 1.8s var(--pr-ease) infinite;}
    @keyframes prPulse{0%,100%{opacity:1}50%{opacity:.35}}
    .pr-st-warn{background:var(--pr-sky);color:var(--pr-sky-deep);}
    .pr-st-bad{background:var(--pr-alert-tint);color:var(--pr-alert);}
    .pr-when{color:var(--pr-mute);font-size:12.5px;white-space:nowrap;}
    .pr-none{color:var(--pr-faint);}

    @media (max-width:1000px){
      .pr-work{grid-template-columns:minmax(0,1fr);}
      .pr-tallies{display:none;}
      .pr-panel .pr-requests td:nth-child(4),.pr-panel .pr-requests th:nth-child(4){display:none;}
      .pr-grid-row{grid-template-columns:minmax(0,1fr) 78px 78px 32px;gap:7px;}
    }
    @media (prefers-reduced-motion:reduce){
      .pr-panel *,.pr-panel *::before,.pr-panel *::after,.pr-tab-indicator{
        animation-duration:.01ms !important;animation-iteration-count:1 !important;
        transition-duration:.01ms !important;}
    }

    /* ══════════════════════════════════════════════════════════════════
       Bring Assign Supplier / Quotation Review / Discrepancies up to the
       same --pr-* visual language as New Request, without touching their
       markup, JS handlers, or PHP form logic — restyling the existing
       classes only.
       ══════════════════════════════════════════════════════════════════ */
    .req-card{background:var(--pr-card);border:1px solid var(--pr-rule);border-radius:12px;
      padding:18px 20px;margin-bottom:14px;}
    .req-card-head strong{font-size:14.5px;font-weight:650;color:var(--pr-ink);}
    .req-card-head{color:var(--pr-mute);font-size:13px;}
    .req-items{color:var(--pr-mute);}
    .req-pill{background:var(--pr-sky-tint);color:var(--pr-sky-deep);}

    .po-form-row select{
      border:1px solid var(--pr-rule);background:var(--pr-card);border-radius:8px;
      padding:9px 11px;font-size:13.5px;color:var(--pr-ink);font-family:inherit;
      transition:border-color var(--pr-fast) var(--pr-ease);
    }
    .po-form-row select:focus{outline:none;border-color:var(--pr-teal);
      box-shadow:0 0 0 3px rgba(98,142,144,.16);}
    .po-form-row .pr-combo{flex:1;min-width:220px;}

    .table-wrap{background:var(--pr-card);border:1px solid var(--pr-rule);border-radius:12px;}
    .table-wrap h3{font-size:14.5px;font-weight:650;color:var(--pr-ink);}
    .waiting-row{border-bottom-color:var(--pr-rule-soft);color:var(--pr-ink-soft);}
    .pill-wait{background:var(--pr-sky);color:var(--pr-sky-deep);border-radius:20px;padding:3px 10px;
      font-size:12px;font-weight:600;}

    .thread{background:var(--pr-cream);border-color:var(--pr-rule);}
    .cond-badge.cond-damaged{background:var(--pr-alert-tint);color:var(--pr-alert);}
    .cond-badge.cond-missing{background:var(--pr-sky);color:var(--pr-sky-deep);}
    .finance-box{background:var(--pr-rule-soft);}
    .shortfall{color:var(--pr-alert);}
  </style>
</head>

<body>
  <?php require_once __DIR__ . '/Sidebar_Manager.php'; ?>

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

      <?php
        $tab_counts = [
            'assign_supplier'  => count($submitted_queue),
            'quotation_review' => count($partial_queue) + count($quote_queue),
            'discrepancy'      => count($discrepancy_queue),
        ];
      ?>
      <div class="hub-tabs pr-tabs">
        <button type="button" class="hub-tab-btn" data-tab="new_request">New request</button>
        <button type="button" class="hub-tab-btn" data-tab="assign_supplier">Assign supplier<?php if ($tab_counts['assign_supplier']): ?> <span class="pr-count"><?= $tab_counts['assign_supplier'] ?></span><?php endif; ?></button>
        <button type="button" class="hub-tab-btn" data-tab="quotation_review">Quotation review<?php if ($tab_counts['quotation_review']): ?> <span class="pr-count pr-due"><?= $tab_counts['quotation_review'] ?></span><?php endif; ?></button>
        <button type="button" class="hub-tab-btn" data-tab="discrepancy">Delivery discrepancies<?php if ($tab_counts['discrepancy']): ?> <span class="pr-count"><?= $tab_counts['discrepancy'] ?></span><?php endif; ?></button>
        <span class="pr-tab-indicator" aria-hidden="true"></span>
      </div>

      <!-- ══════════ TAB PANEL: New Request ══════════ -->
      <div class="hub-tab-panel" data-tab="new_request">
        <?php if ($branch_id): ?>

        <div class="pr-pagehead">
          <div>
            <h3 class="pr-h1">New restock request</h3>
            <p class="pr-standfirst">Add what your branch is running low on. Save it as a draft, then submit it from the list below to start looking for a supplier.</p>
          </div>
          <div class="pr-tallies">
            <div class="pr-tally"><b><?= count($my_requests) ?></b><span>Your requests</span></div>
            <div class="pr-tally"><b><?= $tab_counts['assign_supplier'] ?></b><span>Need a supplier</span></div>
            <div class="pr-tally<?= $tab_counts['discrepancy'] ? ' pr-flag' : '' ?>"><b><?= $tab_counts['discrepancy'] ?></b><span>Delivery issues</span></div>
          </div>
        </div>

        <div class="pr-work">
          <section class="pr-panel">
            <div class="pr-panel-head">
              <div>
                <div class="pr-panel-title">Items</div>
                <div class="pr-panel-meta" id="pr-line-summary">No items yet</div>
              </div>
              <div class="pr-panel-meta"><?= count($branch_items) ?> item<?= count($branch_items) === 1 ? '' : 's' ?> in your inventory</div>
            </div>

            <form method="POST" action="?tab=new_request" id="form-draft">
              <input type="hidden" name="act" value="save_draft" />
              <input type="hidden" name="items" id="items-json" />

              <div class="pr-panel-body">
                <?php if (empty($branch_items)): ?>
                  <p class="pr-empty">Your branch inventory is empty, so there is nothing to request yet. Add items in Inventory first.</p>
                <?php else: ?>
                  <div class="pr-grid-row pr-lines-head">
                    <div>Item</div><div>Quantity</div><div>Unit</div><div></div>
                  </div>

                  <div id="itemRows">
                    <div class="item-row pr-line pr-grid-row">
                      <div class="pr-combo">
                        <select class="row-item" aria-label="Item">
                          <option value="">— Select item —</option>
                          <?php foreach ($branch_items as $it): ?>
                            <option value="<?= (int) $it['inventory_id'] ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>"><?= htmlspecialchars($it['item_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <input type="number" class="row-qty pr-field" placeholder="Qty" min="0.01" step="0.01" aria-label="Quantity" />
                      <div class="pr-field pr-static pr-unit">—</div>
                      <button type="button" class="pr-drop" data-remove-row title="Remove item" aria-label="Remove item">&times;</button>
                    </div>
                  </div>

                  <button type="button" class="pr-addline" id="pr-add-line">Add another item</button>

                  <div class="pr-notes-label">
                    <label for="pr-notes">Notes</label>
                    <span id="pr-note-count">0 / 255</span>
                  </div>
                  <textarea id="pr-notes" name="notes" maxlength="255" placeholder="Anything the supplier or finance should know, for example: beans run out by Friday."></textarea>
                <?php endif; ?>
              </div>

              <?php if (!empty($branch_items)): ?>
                <div class="pr-panel-foot">
                  <p class="pr-foot-note" id="pr-foot-note">Add at least one item with a quantity.</p>
                  <div class="pr-actions">
                    <button type="button" class="pr-btn pr-quiet" id="pr-clear">Clear</button>
                    <button type="submit" class="pr-btn pr-primary" id="pr-save" disabled>
                      <span class="pr-spin" aria-hidden="true"></span>
                      <span class="pr-btn-text">Save as draft</span>
                    </button>
                  </div>
                </div>
              <?php endif; ?>
            </form>
          </section>

          <aside class="pr-rail">
            <section class="pr-panel">
              <div class="pr-panel-head">
                <div class="pr-panel-title">This request</div>
                <div class="pr-panel-meta" id="pr-sum-count">Empty</div>
              </div>
              <div class="pr-panel-body">
                <ul class="pr-sum-list" id="pr-sum-list">
                  <li class="pr-sum-empty">Nothing added yet. Pick an item on the left and it will show up here.</li>
                </ul>
                <div class="pr-sum-rule"></div>
                <div class="pr-pair"><span>Raised by</span><b><?= htmlspecialchars($full_name) ?></b></div>
                <div class="pr-pair"><span>Notes</span><b id="pr-sum-notes">None</b></div>
                <div class="pr-pair"><span>Saves as</span><b>Draft</b></div>
              </div>
            </section>
          </aside>
        </div>

        <section class="pr-panel pr-tracking">
          <div class="pr-panel-head">
            <div>
              <div class="pr-panel-title">Your requests</div>
              <div class="pr-panel-meta">50 most recent</div>
            </div>
            <?php if (!empty($my_requests)):
              $status_labels = [];
              foreach ($my_requests as $r) { $status_labels[proc_status_label($r['status'])] = true; }
              ksort($status_labels);
            ?>
              <div class="pr-filters">
                <input class="pr-search" id="pr-req-search" type="search" placeholder="Search number or note" />
                <div class="pr-combo pr-compact">
                  <select id="pr-status-filter" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <?php foreach (array_keys($status_labels) as $lbl): ?>
                      <option value="<?= htmlspecialchars($lbl) ?>"><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <table class="pr-requests">
            <thead>
              <tr>
                <th>Request</th>
                <th>Contents</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="pr-req-body">
              <?php if (empty($my_requests)): ?>
                <tr><td colspan="5" class="pr-empty" style="text-align:center;padding:40px;">Nothing here yet. Add an item above to raise your first request.</td></tr>
              <?php else: foreach ($my_requests as $r):
                $status_label = proc_status_label($r['status']);
                $note = trim((string) ($r['notes'] ?? ''));
              ?>
                <tr data-status="<?= htmlspecialchars($status_label) ?>">
                  <td class="pr-ref">REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></td>
                  <td class="pr-contents">
                    <?= (int) $r['item_count'] ?> item<?= (int) $r['item_count'] === 1 ? '' : 's' ?>
                    <?php if ($note !== ''): ?><small title="<?= htmlspecialchars($note) ?>"><?= htmlspecialchars($note) ?></small><?php endif; ?>
                  </td>
                  <td><span class="pr-status pr-<?= status_pill_class($r['status']) ?>"><i></i><?= htmlspecialchars($status_label) ?></span></td>
                  <td class="pr-when"><?= date('j M Y', strtotime($r['created_at'])) ?></td>
                  <td>
                    <?php if ($r['status'] === PROC_STATUS_DRAFT): ?>
                      <form method="POST" action="?tab=new_request" style="display:inline" class="swal-confirm-form" data-swal-text="Submit this request? You will be able to assign a supplier next." data-swal-confirm="Yes, submit">
                        <input type="hidden" name="act" value="submit" />
                        <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                        <button type="submit" class="pr-btn pr-primary pr-mini">Submit</button>
                      </form>
                    <?php else: ?>
                      <span class="pr-none">&mdash;</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </section>
        <?php endif; ?>
      </div>

      <!-- ══════════ TAB PANEL: Assign Supplier ══════════ -->
      <div class="hub-tab-panel" data-tab="assign_supplier">
        <?php if ($branch_id): ?>
          <?php if (empty($active_suppliers)): ?>
            <div style="font-size:12.5px;color:var(--danger,#b8453a);margin-bottom:14px;">No active suppliers yet — add one in <a href="../admin/Supplier_List.php">Suppliers</a> first.</div>
          <?php endif; ?>

          <?php if (empty($submitted_queue)): ?>
            <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
              No new requests awaiting a supplier.
            </div>
          <?php else: foreach ($submitted_queue as $r): ?>
            <div class="req-card">
              <div class="req-card-head">
                <div>
                  <strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong>
                  — requested by <?= htmlspecialchars($r['requested_by_name']) ?>
                  on <?= date('M d, Y', strtotime($r['created_at'])) ?>
                </div>
                <span class="req-pill">SUBMITTED</span>
              </div>

              <?php if (!empty($r['notes'])): ?>
                <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Notes: <?= htmlspecialchars($r['notes']) ?></div>
              <?php endif; ?>

              <ul class="req-items">
                <?php foreach ($r['items'] as $it): ?>
                  <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
                <?php endforeach; ?>
              </ul>

              <form method="POST" action="?tab=assign_supplier" class="swal-confirm-form" data-swal-text="Send this request to the selected supplier to check stock and quote a price?" data-swal-confirm="Yes, send it">
                <input type="hidden" name="act" value="assign" />
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                <div class="po-form-row">
                  <div class="pr-combo">
                    <select name="supplier_id" required>
                      <option value="">— Select supplier *</option>
                      <?php foreach ($active_suppliers as $s): ?>
                        <option value="<?= (int) $s['supplier_id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="btn-primary">Assign Supplier</button>
                </div>
              </form>
            </div>
          <?php endforeach; endif; ?>

          <?php if (!empty($waiting)): ?>
            <div class="table-wrap" style="padding:16px 20px;margin-top:20px;">
              <h3 style="margin-top:0">Waiting on Supplier</h3>
              <?php foreach ($waiting as $w): ?>
                <div class="waiting-row">
                  <span>REQ-<?= str_pad((string) $w['request_id'], 4, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($w['supplier_name'] ?? 'Unknown supplier') ?></span>
                  <span class="pill-wait">AWAITING RESPONSE</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- ══════════ TAB PANEL: Quotation Review ══════════ -->
      <div class="hub-tab-panel" data-tab="quotation_review">
        <?php if ($branch_id): ?>
          <h2 class="section-title">Partial Stock — Decide How to Proceed</h2>
          <?php if (empty($partial_queue)): ?>
            <div class="table-wrap" style="padding:24px;text-align:center;color:var(--text-light);">Nothing needs a decision right now.</div>
          <?php else: foreach ($partial_queue as $r): ?>
            <div class="req-card">
              <div class="req-card-head">
                <div><strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong> — <?= htmlspecialchars($r['supplier_name'] ?? 'Unknown supplier') ?></div>
                <span class="req-pill" style="background:#fff4e5;color:#b45300;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">PARTIAL STOCK</span>
              </div>
              <ul class="req-items">
                <?php foreach ($r['items'] as $it): $avail = $it['available_quantity']; $short = $avail !== null && (float) $avail < (float) $it['qty_requested']; ?>
                  <li><?= htmlspecialchars($it['item_name']) ?> — requested <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?>,
                    available <span class="<?= $short ? 'shortfall' : '' ?>"><?= $avail !== null ? (float) $avail + 0 : (float) $it['qty_requested'] + 0 ?></span></li>
                <?php endforeach; ?>
              </ul>
              <div class="req-actions">
                <form method="POST" action="?tab=quotation_review" class="swal-confirm-form" data-swal-text="Proceed with the available quantities and ask this supplier to re-quote?" data-swal-confirm="Yes, proceed">
                  <input type="hidden" name="act" value="proceed_available" />
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                  <button type="submit" class="btn-save">Proceed with Available Qty</button>
                </form>
                <form method="POST" action="?tab=quotation_review" class="swal-confirm-form" data-swal-text="Send this back to Assign Supplier so you can pick a different supplier?" data-swal-confirm="Yes, send back">
                  <input type="hidden" name="act" value="retry_supplier" />
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                  <button type="submit" class="btn-return">Try Another Supplier</button>
                </form>
                <form method="POST" action="?tab=quotation_review" class="swal-confirm-form" data-swal-text="Cancel this request? This cannot be undone." data-swal-icon="warning" data-swal-confirm="Yes, cancel it" data-swal-confirm-color="#c0392b" style="display:flex;gap:8px;flex:1;min-width:220px;">
                  <input type="hidden" name="act" value="cancel" />
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                  <input type="text" name="note" maxlength="255" placeholder="Reason for cancelling (required)" />
                  <button type="submit" class="btn-reject">Cancel Request</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>

          <h2 class="section-title">Quotations Ready to Forward</h2>
          <?php if (empty($quote_queue)): ?>
            <div class="table-wrap" style="padding:24px;text-align:center;color:var(--text-light);">No quotations waiting for you right now.</div>
          <?php else: foreach ($quote_queue as $r): ?>
            <div class="req-card">
              <div class="req-card-head">
                <div><strong>REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?></strong> — <?= htmlspecialchars($r['supplier_name'] ?? 'Unknown supplier') ?></div>
                <span class="req-pill" style="background:#e7f5ec;color:#1e7e42;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">QUOTATION RECEIVED</span>
              </div>
              <ul class="req-items">
                <?php foreach ($r['items'] as $it): ?>
                  <li><?= htmlspecialchars($it['item_name']) ?> — <?= (float) $it['qty_requested'] + 0 ?> <?= htmlspecialchars($it['unit']) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="finance-box">
                <div>Quoted: <b>₱<?= number_format((float) $r['quotation_amount'], 2) ?></b></div>
                <div>Shipping: <b>₱<?= number_format((float) $r['shipping_fee'], 2) ?></b></div>
                <div>Total: <b>₱<?= number_format((float) $r['quotation_amount'] + (float) $r['shipping_fee'], 2) ?></b></div>
                <?php if ($r['quotation_attachment_path']): ?>
                  <div><a href="../<?= htmlspecialchars($r['quotation_attachment_path']) ?>" target="_blank" rel="noopener">View document →</a></div>
                <?php endif; ?>
              </div>
              <?php if (!empty($r['quotation_notes'])): ?>
                <div style="font-size:13px;color:var(--text-light);margin-bottom:6px;">Supplier note: <?= htmlspecialchars($r['quotation_notes']) ?></div>
              <?php endif; ?>
              <form method="POST" action="?tab=quotation_review" class="swal-confirm-form" data-swal-text="Forward this request to Finance for review?" data-swal-confirm="Yes, forward it">
                <input type="hidden" name="act" value="forward_to_finance" />
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                <button type="submit" class="btn-save">Forward to Finance</button>
              </form>
            </div>
          <?php endforeach; endif; ?>
        <?php endif; ?>
      </div>

      <!-- ══════════ TAB PANEL: Delivery Discrepancies ══════════ -->
      <div class="hub-tab-panel" data-tab="discrepancy">
        <?php if ($branch_id): ?>
          <?php if (empty($discrepancy_queue)): ?>
            <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
              No delivery discrepancies for your branch right now.
            </div>
          <?php else: foreach ($discrepancy_queue as $r): ?>
            <div class="req-card">
              <div class="req-card-head">
                <div>
                  <strong><?= htmlspecialchars($r['po_number'] ?? ('PO-' . str_pad((string) $r['po_id'], 6, '0', STR_PAD_LEFT))) ?></strong>
                  &middot; REQ-<?= str_pad((string) $r['request_id'], 4, '0', STR_PAD_LEFT) ?>
                  &middot; Supplier: <?= htmlspecialchars($r['supplier_name']) ?>
                </div>
                <span class="req-pill" style="background:#fdeaea;color:#c0392b;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;">DELIVERY DISCREPANCY</span>
              </div>

              <ul class="req-items">
                <?php foreach ($r['flagged_items'] as $it): ?>
                  <li><?= htmlspecialchars($it['item_name']) ?> — <?= htmlspecialchars($it['qty_received']) ?> <?= htmlspecialchars($it['unit']) ?>
                    <span class="cond-badge cond-<?= htmlspecialchars($it['item_condition']) ?>"><?= strtoupper(htmlspecialchars($it['item_condition'])) ?></span>
                    <?php if ($it['note']): ?> — <?= htmlspecialchars($it['note']) ?><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>

              <?php if (!empty($r['thread'])): ?>
                <div class="thread">
                  <?php foreach ($r['thread'] as $m): ?>
                    <div class="thread-msg <?= $m['sender_type'] ?>">
                      <span class="who"><?= $m['sender_type'] === 'supplier' ? 'Supplier' : 'You' ?></span>
                      <?php if ($m['requested_action']): ?> — requested <b><?= htmlspecialchars($ACTIONS[$m['requested_action']] ?? $m['requested_action']) ?></b><?php endif; ?>
                      <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                      <?php if ($m['attachment_path']): ?><a href="../<?= htmlspecialchars($m['attachment_path']) ?>" target="_blank" rel="noopener">Attachment →</a><?php endif; ?>
                      <div style="color:var(--text-light);font-size:11px;"><?= date('M j, g:ia', strtotime($m['created_at'])) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <form method="POST" action="?tab=discrepancy" enctype="multipart/form-data" class="swal-confirm-form" data-swal-text="Send this resolution request to the supplier?" data-swal-confirm="Yes, send it">
                <input type="hidden" name="act" value="send_resolution_request" />
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                <div class="po-form-row">
                  <select name="requested_action" required>
                    <option value="">What do you need from the supplier?</option>
                    <?php foreach ($ACTIONS as $k => $label): ?>
                      <option value="<?= $k ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="message" maxlength="255" placeholder="Describe the issue" required />
                </div>
                <div class="po-form-row">
                  <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" />
                  <button type="submit" style="padding:8px 14px;border-radius:8px;border:1px solid var(--border,#e5e7eb);background:#fff;cursor:pointer;">Send to Supplier</button>
                </div>
              </form>

              <form method="POST" action="?tab=discrepancy" style="margin-top:10px;" class="swal-confirm-form" data-swal-text="Mark this discrepancy resolved? The request becomes payment-eligible." data-swal-confirm="Yes, mark resolved">
                <input type="hidden" name="act" value="mark_resolved" />
                <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>" />
                <button type="submit" class="btn-save" <?= $r['has_supplier_reply'] ? '' : 'disabled title="Wait for the supplier to reply first"' ?>>Mark Resolved</button>
              </form>
            </div>
          <?php endforeach; endif; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <script>
    lucide.createIcons();

    // SweetAlert2 confirmation for all forms flagged with .swal-confirm-form
    document.querySelectorAll('form.swal-confirm-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Respect native validation (required selects/inputs) before prompting
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }

        Swal.fire({
          title: 'Are you sure?',
          text: form.dataset.swalText || 'Are you sure you want to continue?',
          icon: form.dataset.swalIcon || 'question',
          showCancelButton: true,
          confirmButtonText: form.dataset.swalConfirm || 'Yes, continue',
          cancelButtonText: 'Cancel',
          confirmButtonColor: form.dataset.swalConfirmColor || '#3C2317',
          cancelButtonColor: '#6b6156',
          reverseButtons: true,
          focusCancel: true
        }).then(function (result) {
          if (result.isConfirmed) {
            form.submit();
          }
        });
      });
    });

    /* ══════════════════════════════════════════════════════════════════
       New Request tab — searchable item picker, live totals, motion.
       The form contract is unchanged: every .item-row still holds a real
       <select class="row-item"> and <input class="row-qty">, and the
       submit handler still posts items as JSON. The combobox is a skin on
       top of the native select, so this degrades to plain selects if the
       script fails.
       ══════════════════════════════════════════════════════════════════ */
    (function () {
      var wrap = document.getElementById('itemRows');
      var openCombo = null;

      document.body.classList.add('pr-js');

      function esc(str) {
        return String(str).replace(/[&<>"]/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
      }

      /* ---------- combobox over a native <select> ---------- */
      function enhance(host) {
        var select = host.querySelector('select');
        if (!select || host.dataset.enhanced === 'true') return;
        host.dataset.enhanced = 'true';

        var searchable = select.options.length > 8;
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'pr-field';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="pr-label"></span><span class="pr-caret" aria-hidden="true"></span>';

        var menu = document.createElement('div');
        menu.className = 'pr-menu';
        menu.innerHTML =
          (searchable ? '<input class="pr-menu-search" type="text" placeholder="Type a name" aria-label="Search items">' : '') +
          '<ul class="pr-menu-list" role="listbox"></ul>';

        host.appendChild(trigger);
        host.appendChild(menu);

        var search = menu.querySelector('.pr-menu-search');
        var list = menu.querySelector('.pr-menu-list');
        var opts = Array.prototype.slice.call(select.options);
        var shown = opts.slice();
        var active = -1;

        function paint() {
          var opt = select.options[select.selectedIndex];
          var label = trigger.querySelector('.pr-label');
          var blank = !opt || opt.value === '';
          trigger.classList.toggle('pr-empty-val', blank);
          label.textContent = opt ? opt.textContent : '';
        }

        function render(q) {
          q = (q || '').toLowerCase();
          shown = opts.filter(function (o) {
            return !q || o.textContent.toLowerCase().indexOf(q) > -1;
          });
          list.innerHTML = shown.length
            ? shown.map(function (o, k) {
                var unit = o.dataset ? o.dataset.unit : '';
                return '<li class="pr-opt" role="option" data-k="' + k + '"' +
                  (o.selected ? ' aria-selected="true"' : '') + '>' +
                  '<span>' + esc(o.textContent) + '</span>' +
                  (unit ? '<small>' + esc(unit) + '</small>' : '') + '</li>';
              }).join('')
            : '<li class="pr-opt-empty">No match. Check the spelling, or add the item in Inventory first.</li>';
          active = -1;
        }

        function highlight(k) {
          var items = list.querySelectorAll('.pr-opt');
          if (!items.length) return;
          if (k < 0) k = items.length - 1;
          if (k >= items.length) k = 0;
          Array.prototype.forEach.call(items, function (el) { el.classList.remove('pr-active'); });
          items[k].classList.add('pr-active');
          items[k].scrollIntoView({ block: 'nearest' });
          active = k;
        }

        function open() {
          if (openCombo && openCombo !== close) openCombo();
          openCombo = close;
          host.dataset.open = 'true';
          trigger.setAttribute('aria-expanded', 'true');
          render('');
          if (search) { search.value = ''; setTimeout(function () { search.focus(); }, 40); }
        }
        function close() {
          host.dataset.open = 'false';
          trigger.setAttribute('aria-expanded', 'false');
          if (openCombo === close) openCombo = null;
        }
        function pick(k) {
          if (!shown[k]) return;
          select.value = shown[k].value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
          paint();
          close();
          trigger.focus();
        }

        trigger.addEventListener('click', function () {
          host.dataset.open === 'true' ? close() : open();
        });
        list.addEventListener('click', function (e) {
          var li = e.target.closest('.pr-opt');
          if (li) pick(+li.dataset.k);
        });
        list.addEventListener('mousemove', function (e) {
          var li = e.target.closest('.pr-opt');
          if (li) highlight(+li.dataset.k);
        });
        if (search) search.addEventListener('input', function () { render(search.value); });

        host.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') { close(); trigger.focus(); }
          else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (host.dataset.open !== 'true') open(); else highlight(active + 1);
          } else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
          else if (e.key === 'Enter' && host.dataset.open === 'true') {
            e.preventDefault();
            pick(active > -1 ? active : 0);
          }
        });

        select.addEventListener('change', paint);
        paint();
      }

      document.addEventListener('click', function (e) {
        if (openCombo && !e.target.closest('.pr-combo')) openCombo();
      });

      document.querySelectorAll('.pr-combo').forEach(enhance);

      if (!wrap) return;   // inventory empty, or a branch with no access

      /* ---------- rows ---------- */
      var template = wrap.children[0].cloneNode(true);

      function syncUnit(row) {
        var select = row.querySelector('.row-item');
        var opt = select.options[select.selectedIndex];
        var unit = opt && opt.dataset ? (opt.dataset.unit || '') : '';
        row.querySelector('.pr-unit').textContent = unit || '—';
      }

      function bump(el, text) {
        if (!el || el.textContent === text) return;
        el.textContent = text;
        el.classList.remove('pr-bumped');
        void el.offsetWidth;
        el.classList.add('pr-bumped');
      }

      /* Mirrors the rows into the side panel so the request reads back as a
         list while it is being written, not just a count. */
      function renderSummary() {
        var list = document.getElementById('pr-sum-list');
        var picked = [], ready = 0;

        Array.prototype.forEach.call(wrap.children, function (row) {
          var select = row.querySelector('.row-item');
          if (!select.value) return;
          var opt = select.options[select.selectedIndex];
          var qty = parseFloat(row.querySelector('.row-qty').value);
          var unit = opt.dataset ? (opt.dataset.unit || '') : '';
          if (qty > 0) ready++;
          picked.push({
            name: opt.textContent,
            qty: qty > 0 ? (qty + (unit ? ' ' + unit : '')) : null
          });
        });

        if (list) {
          var markup = picked.length
            ? picked.map(function (p) {
                return '<li><span class="pr-sum-name">' + esc(p.name) + '</span>' +
                  '<span class="pr-sum-qty' + (p.qty ? '' : ' pr-pending') + '">' +
                  (p.qty ? esc(p.qty) : 'needs a qty') + '</span></li>';
              }).join('')
            : '<li class="pr-sum-empty">Nothing added yet. Pick an item on the left and it will show up here.</li>';
          if (markup !== list.innerHTML) list.innerHTML = markup;
        }

        bump(document.getElementById('pr-sum-count'),
             picked.length ? picked.length + (picked.length === 1 ? ' item' : ' items') : 'Empty');
        return ready;
      }

      function recalc() {
        var ready = renderSummary();

        var summary = document.getElementById('pr-line-summary');
        if (summary) summary.textContent = ready ? ready + (ready === 1 ? ' item ready' : ' items ready') : 'No items yet';

        var save = document.getElementById('pr-save');
        var note = document.getElementById('pr-foot-note');
        if (save) save.disabled = ready === 0;
        if (note) note.textContent = ready
          ? 'Saved as a draft first. Submit it below when you are ready.'
          : 'Add at least one item with a quantity.';
      }

      function wire(row) {
        enhance(row.querySelector('.pr-combo'));
        row.querySelector('.row-item').addEventListener('change', function () {
          syncUnit(row);
          recalc();
          row.querySelector('.row-qty').focus();
        });
        row.querySelector('.row-qty').addEventListener('input', recalc);
        row.querySelector('[data-remove-row]').addEventListener('click', function () { removeRow(row); });
        syncUnit(row);
      }

      function addRow() {
        var row = template.cloneNode(true);
        row.classList.add('pr-entering');
        row.dataset.enhanced = '';
        var combo = row.querySelector('.pr-combo');
        combo.dataset.enhanced = '';
        combo.querySelectorAll('.pr-field, .pr-menu').forEach(function (el) { el.remove(); });
        row.querySelector('.row-item').selectedIndex = 0;
        row.querySelector('.row-qty').value = '';
        wrap.appendChild(row);
        wire(row);
        row.addEventListener('animationend', function () { row.classList.remove('pr-entering'); }, { once: true });
        recalc();
        return row;
      }

      function removeRow(row) {
        if (wrap.children.length === 1) {
          row.querySelector('.row-item').selectedIndex = 0;
          row.querySelector('.row-item').dispatchEvent(new Event('change', { bubbles: true }));
          row.querySelector('.row-qty').value = '';
          syncUnit(row);
          recalc();
          return;
        }
        row.classList.add('pr-leaving');
        setTimeout(function () { row.remove(); recalc(); }, 240);
      }

      Array.prototype.forEach.call(wrap.children, wire);

      var addBtn = document.getElementById('pr-add-line');
      if (addBtn) addBtn.addEventListener('click', function () {
        addRow().querySelector('.pr-field').focus();
      });

      var clearBtn = document.getElementById('pr-clear');
      if (clearBtn) clearBtn.addEventListener('click', function () {
        Array.prototype.slice.call(wrap.children).forEach(function (row, k) {
          setTimeout(function () { removeRow(row); }, k * 50);
        });
        var notes = document.getElementById('pr-notes');
        if (notes) { notes.value = ''; document.getElementById('pr-note-count').textContent = '0 / 255'; }
      });

      /* ---------- notes counter ---------- */
      var notes = document.getElementById('pr-notes');
      var noteCount = document.getElementById('pr-note-count');
      var sumNotes = document.getElementById('pr-sum-notes');
      if (notes) notes.addEventListener('input', function () {
        var len = notes.value.length;
        noteCount.textContent = len + ' / 255';
        noteCount.style.color = len > 230 ? 'var(--pr-sky-deep)' : '';
        if (sumNotes) sumNotes.textContent = len ? 'Added' : 'None';
      });

      /* ---------- submit: unchanged payload, plus a loading state ---------- */
      var draftForm = document.getElementById('form-draft');
      if (draftForm) draftForm.addEventListener('submit', function (e) {
        var items = [];
        wrap.querySelectorAll('.item-row').forEach(function (r) {
          var inv = r.querySelector('.row-item').value;
          var qty = r.querySelector('.row-qty').value;
          if (inv && qty) items.push({ inventory_id: inv, qty: qty });
        });
        if (items.length === 0) {
          e.preventDefault();
          Swal.fire({ icon: 'info', title: 'Nothing to save', text: 'Add at least one item with a quantity.', confirmButtonColor: '#3C2317' });
          return;
        }
        document.getElementById('items-json').value = JSON.stringify(items);
        var save = document.getElementById('pr-save');
        save.classList.add('pr-loading');
        save.querySelector('.pr-btn-text').textContent = 'Saving';
      });

      /* ---------- request list filters ---------- */
      var body = document.getElementById('pr-req-body');
      var search = document.getElementById('pr-req-search');
      var statusSel = document.getElementById('pr-status-filter');
      function filter() {
        if (!body) return;
        var q = (search ? search.value : '').toLowerCase();
        var st = statusSel ? statusSel.value : '';
        Array.prototype.forEach.call(body.children, function (tr) {
          if (!tr.dataset.status) return;
          var okQ = tr.textContent.toLowerCase().indexOf(q) > -1;
          var okS = !st || tr.dataset.status === st;
          tr.classList.toggle('pr-hidden', !(okQ && okS));
        });
      }
      if (search) search.addEventListener('input', filter);
      if (statusSel) statusSel.addEventListener('change', filter);

      recalc();
    })();

    function switchHubTab(tab) {
      document.querySelectorAll('.hub-tab-panel').forEach(p => p.classList.toggle('active', p.dataset.tab === tab));
      document.querySelectorAll('.hub-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
      moveTabIndicator();
      history.replaceState(null, '', '?tab=' + tab);
    }

    function moveTabIndicator() {
      var active = document.querySelector('.hub-tab-btn.active');
      var bar = document.querySelector('.pr-tab-indicator');
      if (!active || !bar) return;
      bar.style.width = active.offsetWidth + 'px';
      bar.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    }

    document.addEventListener('DOMContentLoaded', function () {
      switchHubTab('<?= $active_tab ?>');
      document.querySelectorAll('.hub-tab-btn').forEach(function (b) {
        b.addEventListener('click', function () { switchHubTab(b.dataset.tab); });
      });
    });
    window.addEventListener('resize', moveTabIndicator);
  </script>
</body>
</html>
