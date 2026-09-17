<?php
/**
 * Cash Flow Overview module — server-side status state machine.
 *
 * Status is never editable via a dropdown anywhere in the UI. Every
 * transition must go through cashflow_next_status() so a direct POST with
 * a forged status can't skip a stage. Mirrors includes/procurement_workflow.php.
 */

require_once __DIR__ . '/cashflow_auth.php';

const CF_STATUS_DRAFT         = 'draft';
const CF_STATUS_UNDER_REVIEW  = 'under_review'; // covers spec's "Submitted" + "Under Finance Head Review" — one real DB state
const CF_STATUS_RETURNED      = 'returned';
const CF_STATUS_REJECTED      = 'rejected';
const CF_STATUS_APPROVED      = 'approved';
const CF_STATUS_POSTED        = 'posted';
const CF_STATUS_CLOSED        = 'closed';

/** Which stage is authorized to act on a given current status. Null = nothing pending. */
const CF_STAGE_FOR_STATUS = [
    CF_STATUS_DRAFT        => CF_STAGE_FINANCE_OFFICER,  // submit
    CF_STATUS_UNDER_REVIEW => CF_STAGE_FINANCE_HEAD,      // approve / reject / return
    CF_STATUS_RETURNED     => CF_STAGE_FINANCE_OFFICER,   // edit + resubmit
    CF_STATUS_APPROVED     => CF_STAGE_FINANCE_HEAD,      // post
    CF_STATUS_POSTED       => CF_STAGE_FINANCE_HEAD,      // close (as part of a period)
];

/** The one legal next status reached from $current on a named decision, or null. */
function cashflow_next_status(string $current, string $decision): ?string {
    if ($decision === 'submit')    return $current === CF_STATUS_DRAFT ? CF_STATUS_UNDER_REVIEW : null;
    if ($decision === 'resubmit')  return $current === CF_STATUS_RETURNED ? CF_STATUS_UNDER_REVIEW : null;
    if ($decision === 'approve')   return $current === CF_STATUS_UNDER_REVIEW ? CF_STATUS_APPROVED : null;
    if ($decision === 'reject')    return $current === CF_STATUS_UNDER_REVIEW ? CF_STATUS_REJECTED : null;
    if ($decision === 'return')    return $current === CF_STATUS_UNDER_REVIEW ? CF_STATUS_RETURNED : null;
    if ($decision === 'post')      return $current === CF_STATUS_APPROVED ? CF_STATUS_POSTED : null;
    if ($decision === 'close')     return $current === CF_STATUS_POSTED ? CF_STATUS_CLOSED : null;
    return null;
}

/** True only if $from -> $to is a single legal step (no skipping stages). */
function cashflow_can_transition(string $from, string $to): bool {
    foreach (['submit', 'resubmit', 'approve', 'reject', 'return', 'post', 'close'] as $decision) {
        if (cashflow_next_status($from, $decision) === $to) return true;
    }
    return false;
}

/** Which stage is required to act on $status right now. Null = no action pending. */
function cashflow_required_stage(string $status): ?string {
    return CF_STAGE_FOR_STATUS[$status] ?? null;
}

/**
 * Blocks self-approval: the acting user may not be the entry's own
 * creator. Per spec, since there is no second-approver role configured,
 * this blocks ANY actor (Officer or Head) from approving/posting/closing
 * a record they themselves created — not just an Officer-vs-Head check.
 */
function cashflow_is_self_approval(array $entry, int $actorUserId): bool {
    return (int) ($entry['created_by'] ?? 0) === $actorUserId;
}
