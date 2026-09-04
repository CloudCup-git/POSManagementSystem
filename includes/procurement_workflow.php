<?php
/**
 * Procurement module — server-side status state machine.
 *
 * Statuses are never editable via a dropdown anywhere in the UI. Every
 * transition must go through procurement_can_transition() /
 * procurement_next_status() so approval order can't be skipped or bypassed
 * by a direct POST with a forged status value.
 */

require_once __DIR__ . '/procurement_auth.php';

// Forward (approval) path, in required order.
const PROC_STATUS_DRAFT                     = 'DRAFT';
const PROC_STATUS_SUBMITTED                 = 'SUBMITTED';
const PROC_STATUS_SUPPLIER_STOCK_CHECKING   = 'SUPPLIER_STOCK_CHECKING';
const PROC_STATUS_QUOTATION_RECEIVED        = 'QUOTATION_RECEIVED';
const PROC_STATUS_STORE_MANAGER_APPROVED    = 'STORE_MANAGER_APPROVED';
const PROC_STATUS_AREA_OPS_APPROVED         = 'AREA_OR_OPERATIONS_APPROVED';
const PROC_STATUS_FINANCE_OFFICER_REVIEWED  = 'FINANCE_OFFICER_REVIEWED';
const PROC_STATUS_FINANCE_MANAGER_RECOMMEND = 'FINANCE_MANAGER_RECOMMENDED';
const PROC_STATUS_FINAL_APPROVED            = 'FINAL_APPROVED';
const PROC_STATUS_PO_ISSUED                 = 'PO_ISSUED';
const PROC_STATUS_PARTIALLY_RECEIVED        = 'PARTIALLY_RECEIVED';
const PROC_STATUS_RECEIVED_VERIFIED         = 'RECEIVED_VERIFIED';
const PROC_STATUS_PAYMENT_PENDING           = 'PAYMENT_PENDING';
const PROC_STATUS_PAID                      = 'PAID';

// Side statuses.
const PROC_STATUS_RETURNED_FOR_REVISION = 'RETURNED_FOR_REVISION';
const PROC_STATUS_REJECTED              = 'REJECTED';
const PROC_STATUS_CANCELLED             = 'CANCELLED';
const PROC_STATUS_DELIVERY_DISCREPANCY  = 'DELIVERY_DISCREPANCY';

/**
 * Ordered forward path. Index order = required approval sequence.
 *
 * PROC_STATUS_AREA_OPS_APPROVED and PROC_STATUS_FINANCE_MANAGER_RECOMMEND
 * are deliberately absent — the Area/Ops Manager step and the Owner
 * (role='admin') step were both removed from the official workflow.
 * Finance Head's approval of a Finance-Officer-reviewed request now goes
 * straight to FINAL_APPROVED in one step (there is no longer a separate
 * "recommend to Owner" hop, since Owner no longer acts anywhere in
 * procurement — see docs/procurement/STATUS.md). Both retired constants
 * and their PROC_STAGE_AREA_OPS_MANAGER / PROC_STAGE_ADMIN mappings stay
 * in procurement_auth.php so manager/Area_Ops_Validation.php and
 * admin/Admin_Final_Approval.php keep loading (their queues are just
 * permanently empty now) rather than fataling for anyone still resolving
 * to those stages.
 */
const PROC_FORWARD_PATH = [
    PROC_STATUS_DRAFT,
    PROC_STATUS_SUBMITTED,
    PROC_STATUS_STORE_MANAGER_APPROVED,
    PROC_STATUS_FINANCE_OFFICER_REVIEWED,
    PROC_STATUS_FINAL_APPROVED,
    PROC_STATUS_PO_ISSUED,
    PROC_STATUS_RECEIVED_VERIFIED,
    PROC_STATUS_PAYMENT_PENDING,
    PROC_STATUS_PAID,
];

/**
 * Which stage is authorized to act on a given current status.
 *
 * PROC_STATUS_SUPPLIER_STOCK_CHECKING has no entry here on purpose — no
 * internal staff/manager stage acts on it. Only the supplier assigned to
 * that specific request can (via supplier_require_login() in supplier/,
 * a separate auth path from procurement_require_stage() entirely), so it
 * is never a valid "pending on stage X" queue item for anyone internal.
 *
 * Owner (role='admin') is not authorized on any status below — Finance
 * Head now both gives final approval AND issues the Purchase Order
 * (finance/Issue_Purchase_Order.php, finance/Create_RFQ_Page.php,
 * finance/RFQ_Canvass_Page.php).
 */
const PROC_STAGE_FOR_STATUS = [
    PROC_STATUS_SUBMITTED                 => PROC_STAGE_STORE_MANAGER,
    PROC_STATUS_QUOTATION_RECEIVED        => PROC_STAGE_STORE_MANAGER,
    PROC_STATUS_STORE_MANAGER_APPROVED    => PROC_STAGE_FINANCE_OFFICER,
    PROC_STATUS_FINANCE_OFFICER_REVIEWED  => PROC_STAGE_FINANCE_MANAGER,
    PROC_STATUS_FINANCE_MANAGER_RECOMMEND => PROC_STAGE_ADMIN,        // retired, unreachable — see comment above
    PROC_STATUS_FINAL_APPROVED            => PROC_STAGE_FINANCE_MANAGER, // issue PO
    PROC_STATUS_PO_ISSUED                 => PROC_STAGE_INVENTORY_STAFF, // receive
    PROC_STATUS_PARTIALLY_RECEIVED        => PROC_STAGE_INVENTORY_STAFF,
    PROC_STATUS_RECEIVED_VERIFIED         => PROC_STAGE_FINANCE_OFFICER, // pay
    PROC_STATUS_PAYMENT_PENDING           => PROC_STAGE_FINANCE_MANAGER, // confirm disbursement
    PROC_STATUS_DELIVERY_DISCREPANCY      => PROC_STAGE_STORE_MANAGER, // resolve, with supplier
];

/** The one legal forward status reached from $current on an "approve" decision. */
function procurement_next_status(string $current, string $decision): ?string {
    if ($decision === 'reject') {
        return in_array($current, [PROC_STATUS_SUBMITTED, PROC_STATUS_STORE_MANAGER_APPROVED,
            PROC_STATUS_AREA_OPS_APPROVED, PROC_STATUS_FINANCE_OFFICER_REVIEWED,
            PROC_STATUS_FINANCE_MANAGER_RECOMMEND], true) ? PROC_STATUS_REJECTED : null;
    }
    if ($decision === 'return') {
        return in_array($current, [PROC_STATUS_SUBMITTED, PROC_STATUS_STORE_MANAGER_APPROVED,
            PROC_STATUS_AREA_OPS_APPROVED, PROC_STATUS_FINANCE_OFFICER_REVIEWED,
            PROC_STATUS_FINANCE_MANAGER_RECOMMEND], true) ? PROC_STATUS_RETURNED_FOR_REVISION : null;
    }
    if ($decision === 'cancel') {
        // Store Manager can stand down a request any time before it reaches
        // Finance — before a supplier is even assigned, while stock is being
        // checked, or after a quotation comes back they don't want to use.
        return in_array($current, [PROC_STATUS_SUBMITTED, PROC_STATUS_SUPPLIER_STOCK_CHECKING,
            PROC_STATUS_QUOTATION_RECEIVED], true) ? PROC_STATUS_CANCELLED : null;
    }
    if ($decision === 'discrepancy') {
        return $current === PROC_STATUS_PO_ISSUED || $current === PROC_STATUS_PARTIALLY_RECEIVED
            ? PROC_STATUS_DELIVERY_DISCREPANCY : null;
    }
    if ($decision === 'partial_receive') {
        return $current === PROC_STATUS_PO_ISSUED ? PROC_STATUS_PARTIALLY_RECEIVED : null;
    }
    if ($decision === 'resolve_discrepancy') {
        return $current === PROC_STATUS_DELIVERY_DISCREPANCY ? PROC_STATUS_RECEIVED_VERIFIED : null;
    }
    // Supplier stock-check + quotation, ahead of Finance review. These are
    // their own named decisions (not the generic "approve" walk below)
    // because SUBMITTED now requires picking a supplier — not a plain
    // approve — and QUOTATION_RECEIVED requires the Store Manager to have
    // an actual quotation in hand before it can reach Finance.
    if ($decision === 'assign_supplier') {
        return $current === PROC_STATUS_SUBMITTED ? PROC_STATUS_SUPPLIER_STOCK_CHECKING : null;
    }
    if ($decision === 'supplier_quoted') {
        // The assigned supplier confirmed every item is available and
        // priced it. A partial-stock response is NOT this decision — it
        // stays at SUPPLIER_STOCK_CHECKING (just supplier_check_status
        // changes to 'partial_stock') so the Store Manager can decide
        // whether to proceed with the available quantity, wait, or try
        // another supplier before a real quotation exists.
        return $current === PROC_STATUS_SUPPLIER_STOCK_CHECKING ? PROC_STATUS_QUOTATION_RECEIVED : null;
    }
    if ($decision === 'retry_supplier') {
        // Store Manager gives up on the current supplier's partial-stock
        // response and sends the request back to SUBMITTED so a different
        // supplier can be assigned. Caller is responsible for clearing
        // supplier_id/supplier_check_status alongside this transition.
        return $current === PROC_STATUS_SUPPLIER_STOCK_CHECKING ? PROC_STATUS_SUBMITTED : null;
    }
    if ($decision === 'forward_to_finance') {
        return $current === PROC_STATUS_QUOTATION_RECEIVED ? PROC_STATUS_STORE_MANAGER_APPROVED : null;
    }
    if ($decision !== 'approve') return null;
    // SUBMITTED no longer advances on a plain "approve" — it must go
    // through assign_supplier above, so a request can never reach
    // STORE_MANAGER_APPROVED without a supplier_id/quotation attached.
    if ($current === PROC_STATUS_SUBMITTED) return null;

    $idx = array_search($current, PROC_FORWARD_PATH, true);
    if ($idx === false || !isset(PROC_FORWARD_PATH[$idx + 1])) return null;
    return PROC_FORWARD_PATH[$idx + 1];
}

/** True only if $from -> $to is a single legal step (no skipping stages). */
function procurement_can_transition(string $from, string $to): bool {
    foreach (['approve', 'reject', 'return', 'cancel', 'discrepancy', 'partial_receive',
        'resolve_discrepancy', 'assign_supplier', 'supplier_quoted', 'retry_supplier', 'forward_to_finance'] as $decision) {
        if (procurement_next_status($from, $decision) === $to) return true;
    }
    return false;
}

/** Which stage is required to act on $status right now. Null = no action pending. */
function procurement_required_stage(string $status): ?string {
    return PROC_STAGE_FOR_STATUS[$status] ?? null;
}

/**
 * Blocks self-approval: the acting user may not be the request's original
 * requester, nor the user who performed the immediately preceding approval
 * on this request.
 *
 * $request must include 'requested_by' (user_id) and 'last_actor_id'
 * (user_id of whoever actioned the previous stage, or null if none yet).
 */
function procurement_is_self_approval(array $request, int $actorUserId): bool {
    if ((int) ($request['requested_by'] ?? 0) === $actorUserId) return true;
    if (isset($request['last_actor_id']) && (int) $request['last_actor_id'] === $actorUserId) return true;
    return false;
}
