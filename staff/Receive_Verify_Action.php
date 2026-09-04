<?php
/**
 * Procurement — Receive and Verify (C17)
 * -------------------------------------------------------------
 * Inventory Staff only, own branch only. Records one receiving event
 * (delivery reference, notes, per-item good/damaged/missing qty split)
 * against an issued PO, then applies the auto-status rule:
 *   - every item's cumulative qty_received >= qty_ordered AND no item
 *     ever flagged damaged/missing (this event or a prior one)
 *         -> RECEIVED_VERIFIED
 *   - any item flagged damaged or missing (this event or a prior one)
 *         -> DELIVERY_DISCREPANCY
 *   - otherwise (some items still short, nothing flagged bad)
 *         -> PARTIALLY_RECEIVED
 * C18: for every line with condition='good' and qty>0, also increments
 * inventory.quantity (branch re-checked, not trusted) and writes one
 * matching inventory_log row (change_type='restock', batch_id =
 * 'PROC-RCPT-{receipt_id}') in the SAME transaction as the receipt insert
 * and status transition below. Damaged/missing qty is never added to
 * stock. Stock only ever moves once per receipt line because each POST
 * creates a brand-new procurement_receipt_items row — there is no path
 * that re-processes an already-inserted line. Bug fix (post-C22): a
 * single item can now produce up to 3 receipt rows in one event
 * (good/damaged/missing simultaneously), and the sum is validated
 * server-side against what's still remaining on the PO — the qty input
 * used to be a placeholder (not a real value), so an untouched box
 * silently submitted 0 instead of the intended full remaining amount.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user = procurement_require_stage([PROC_STAGE_INVENTORY_STAFF]);

if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable.']);
    exit;
}
ensure_procurement_tables($conn);

$poId = (int) ($_POST['po_id'] ?? 0);
$deliveryReference = trim((string) ($_POST['delivery_reference'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$lines = $_POST['lines'] ?? []; // [po_item_id => ['qty_good' => x, 'qty_damaged' => x, 'qty_missing' => x, 'note' => '']]

if ($poId <= 0 || !is_array($lines) || count($lines) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid receiving data.']);
    exit;
}

mysqli_begin_transaction($conn);
try {
    // Lock the PO + its request row; never trust branch_id/status from POST.
    $stmt = mysqli_prepare($conn,
        'SELECT po.po_id, po.request_id, po.branch_id, po.supplier_name, r.status
         FROM procurement_purchase_orders po
         JOIN procurement_requests r ON r.request_id = po.request_id
         WHERE po.po_id = ? FOR UPDATE'
    );
    mysqli_stmt_bind_param($stmt, 'i', $poId);
    mysqli_stmt_execute($stmt);
    $po = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$po) throw new Exception('Purchase order not found.');
    if ((int) $po['branch_id'] !== (int) $user['branch_id']) throw new Exception('That PO does not belong to your branch.');
    if (!in_array($po['status'], [PROC_STATUS_PO_ISSUED, PROC_STATUS_PARTIALLY_RECEIVED], true)) {
        throw new Exception('This request is not open for receiving right now.');
    }

    // PO line items, to validate posted po_item_ids belong to this PO and
    // to know each line's ordered qty.
    // inventory_id is resolved via request_item_id -> procurement_request_items,
    // never trusted from POST; NULL only if a request item was somehow deleted.
    $stmt = mysqli_prepare($conn,
        'SELECT pi.po_item_id, pi.item_name, pi.unit, pi.qty_ordered, ri.inventory_id
         FROM procurement_po_items pi
         LEFT JOIN procurement_request_items ri ON ri.item_id = pi.request_item_id
         WHERE pi.po_id = ?'
    );
    mysqli_stmt_bind_param($stmt, 'i', $poId);
    mysqli_stmt_execute($stmt);
    $poItems = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $poItemsById = [];
    foreach ($poItems as $it) $poItemsById[(int) $it['po_item_id']] = $it;

    // Already-received totals per line (across prior receipt events), to
    // enforce that this event's good+damaged+missing never exceeds what's
    // still remaining. Never trust a client-side "remaining" figure.
    $stmt = mysqli_prepare($conn,
        'SELECT po_item_id, SUM(qty_received) AS total FROM procurement_receipt_items ri
         JOIN procurement_receipts r ON r.receipt_id = ri.receipt_id
         WHERE r.po_id = ? GROUP BY po_item_id'
    );
    mysqli_stmt_bind_param($stmt, 'i', $poId);
    mysqli_stmt_execute($stmt);
    $alreadyReceivedById = [];
    foreach (mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC) as $row) {
        $alreadyReceivedById[(int) $row['po_item_id']] = (float) $row['total'];
    }
    mysqli_stmt_close($stmt);

    // Insert the receipt header.
    $stmt = mysqli_prepare($conn,
        'INSERT INTO procurement_receipts (po_id, request_id, branch_id, received_by, received_by_name, delivery_reference, notes)
         VALUES (?,?,?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($stmt, 'iiiisss', $poId, $po['request_id'], $po['branch_id'], $user['user_id'], $user['full_name'], $deliveryReference, $notes);
    mysqli_stmt_execute($stmt);
    $receiptId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $anyLineInserted = false;
    foreach ($lines as $poItemId => $line) {
        $poItemId = (int) $poItemId;
        if (!isset($poItemsById[$poItemId])) continue; // ignore any id not actually on this PO

        $item = $poItemsById[$poItemId];
        $note = trim((string) ($line['note'] ?? ''));

        $qtyByCondition = [
            'good'    => max(0.0, (float) ($line['qty_good'] ?? 0)),
            'damaged' => max(0.0, (float) ($line['qty_damaged'] ?? 0)),
            'missing' => max(0.0, (float) ($line['qty_missing'] ?? 0)),
        ];
        $lineTotal = array_sum($qtyByCondition);
        if ($lineTotal <= 0) continue; // nothing reported for this line

        $alreadyReceived = $alreadyReceivedById[$poItemId] ?? 0.0;
        $remaining = (float) $item['qty_ordered'] - $alreadyReceived;
        if ($lineTotal > $remaining + 0.0001) { // small epsilon for float compare
            throw new Exception("Received quantity for {$item['item_name']} exceeds what's still remaining ({$remaining} {$item['unit']}).");
        }

        foreach ($qtyByCondition as $condition => $qty) {
            if ($qty <= 0) continue;

            $stmt = mysqli_prepare($conn,
                'INSERT INTO procurement_receipt_items (receipt_id, po_item_id, item_name, unit, qty_received, item_condition, note)
                 VALUES (?,?,?,?,?,?,?)'
            );
            mysqli_stmt_bind_param($stmt, 'iissdss', $receiptId, $poItemId, $item['item_name'], $item['unit'], $qty, $condition, $note);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $anyLineInserted = true;

            // C18: only good-condition qty ever moves stock. branch_id comes
            // from the locked $po row above, never from POST.
            if ($condition === 'good' && !empty($item['inventory_id'])) {
                $invId = (int) $item['inventory_id'];

                $upd = mysqli_prepare($conn,
                    'UPDATE inventory SET quantity = quantity + ? WHERE inventory_id = ? AND branch_id = ?'
                );
                mysqli_stmt_bind_param($upd, 'dii', $qty, $invId, $po['branch_id']);
                mysqli_stmt_execute($upd);
                $stockUpdated = mysqli_stmt_affected_rows($upd) === 1;
                mysqli_stmt_close($upd);

                if ($stockUpdated) {
                    $logNote = 'Received via ' . $po['supplier_name'] . ' — ' . $item['item_name']
                        . ($deliveryReference !== '' ? ' (delivery ref: ' . $deliveryReference . ')' : '')
                        . ' (receipt #' . $receiptId . ', received by ' . $user['full_name'] . ')';
                    $batchId = 'PROC-RCPT-' . $receiptId;
                    $today = date('Y-m-d');
                    $changeType = 'restock';
                    $userId = (int) $user['user_id'];

                    $log = mysqli_prepare($conn,
                        'INSERT INTO inventory_log (inventory_id, employee_id, change_type, qty_change, notes, supplier_name, delivery_date, batch_id)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    mysqli_stmt_bind_param($log, 'iisdssss', $invId, $userId, $changeType, $qty, $logNote, $po['supplier_name'], $today, $batchId);
                    mysqli_stmt_execute($log);
                    mysqli_stmt_close($log);
                }
            }
        }
    }

    if (!$anyLineInserted) throw new Exception('No valid item lines were submitted.');

    // Cumulative qty_received (all receipts, this PO) and whether any
    // flagged-bad line was ever recorded for this PO.
    $stmt = mysqli_prepare($conn,
        'SELECT ri.po_item_id, SUM(ri.qty_received) AS total_received,
                SUM(CASE WHEN ri.item_condition <> "good" THEN 1 ELSE 0 END) AS bad_count
         FROM procurement_receipt_items ri
         JOIN procurement_receipts r ON r.receipt_id = ri.receipt_id
         WHERE r.po_id = ?
         GROUP BY ri.po_item_id'
    );
    mysqli_stmt_bind_param($stmt, 'i', $poId);
    mysqli_stmt_execute($stmt);
    $totals = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $receivedByItem = [];
    $anyBad = false;
    foreach ($totals as $t) {
        $receivedByItem[(int) $t['po_item_id']] = (float) $t['total_received'];
        if ((int) $t['bad_count'] > 0) $anyBad = true;
    }

    $allFullyReceived = true;
    foreach ($poItemsById as $id => $item) {
        $received = $receivedByItem[$id] ?? 0.0;
        if ($received < (float) $item['qty_ordered']) { $allFullyReceived = false; break; }
    }

    if ($anyBad) {
        $decision = 'discrepancy';
    } elseif ($allFullyReceived) {
        $decision = 'approve';
    } else {
        $decision = 'partial_receive';
    }

    $newStatus = procurement_next_status($po['status'], $decision);
    if (!$newStatus || !procurement_can_transition($po['status'], $newStatus)) {
        throw new Exception('Could not determine a valid next status.');
    }

    $stmt = mysqli_prepare($conn, 'UPDATE procurement_requests SET status = ?, last_actor_id = ? WHERE request_id = ? AND status = ?');
    mysqli_stmt_bind_param($stmt, 'siis', $newStatus, $user['user_id'], $po['request_id'], $po['status']);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if ($affected !== 1) throw new Exception('Request status changed elsewhere — please refresh and try again.');

    procurement_log_audit($conn, (int) $po['request_id'], (int) $user['user_id'], $user['full_name'],
        'receive_verify', $po['status'], $newStatus,
        $deliveryReference !== '' ? "Delivery ref: $deliveryReference" : null);

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'new_status' => $newStatus]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
