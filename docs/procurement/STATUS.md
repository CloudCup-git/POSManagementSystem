# Procurement Module — Status

Handover record for the operations-first procurement workflow. Read this
first in any new session before touching procurement code.

## Completed checkpoints
**C0–C22 all complete**, plus a post-C22 bug fix. Full chain: Inventory
Staff draft/submit → Store Manager → Area/Ops → Finance Officer →
Finance Manager → Admin final approval → PO issuance → receive/verify →
stock update → Finance Officer records payment → Finance Manager
confirms disbursement (PAID). C21 code review found no bugs (stage
gates, branch/self-approval checks, guarded updates, audit trail — all
verified). C22 added Admin-only manual resolution for
DELIVERY_DISCREPANCY (`→ RECEIVED_VERIFIED`). Post-C22: fixed a real
bug in `Receiving_Page.php` (qty input was a `placeholder`, not a real
`value` — untouched boxes silently submitted 0) and added a per-item
**good/damaged/missing 3-way split** in one receiving event, with new
server-side validation that the three qtys never exceed what's still
remaining on the PO.

## Next checkpoint
Next: **S1 — Supplier Module** (see architecture decision below).

## Key fixes applied outside the checkpoint sequence
1. Area/Ops Manager stage match fixed to `position_id === 10`.
2. `inventory.item_name` uniqueness changed to per-branch
   `(branch_id, item_name)` — migration
   `database/migrations/2026_08_30_inventory_branch_unique_fix.sql`, ran OK.
3. `mysqli_stmt_bind_param()` can't bind a PHP constant by reference —
   fixed across 8 files by assigning `PROC_STATUS_*` to local vars first.

## Confirmed tables/fields (live DB, cloudcup_db)
- `users.role` ENUM(admin,manager,hr_admin,employee,inventory_staff,
  finance,marketing) + `branch_id`.
- `employees.position_id` → `hr_positions`: 1=Owner/Admin,
  10=Area/Operations Manager, 11=Store Manager, 50=Finance Manager,
  51=Finance Officer.
- `branches`: 1 Main, 2 Cavite (inactive), 3 Tabi-Tabi lang.
- `activity_log` (generic) + `procurement_request_audit` (dedicated,
  append-only) both written on every action.
- `suppliers` table already exists: name, contact_person, phone, email,
  address, status — no login/auth, no accreditation fields, no
  documents. `procurement_purchase_orders.supplier_name` is still
  free-text, not FK'd to `suppliers.supplier_id`.
- Legacy `restock_requests` / `finance_restock_approvals.php`: untouched.

## Workflow states (state machine, live in procurement_workflow.php)
DRAFT → SUBMITTED → STORE_MANAGER_APPROVED → AREA_OR_OPERATIONS_APPROVED →
FINANCE_OFFICER_REVIEWED → FINANCE_MANAGER_RECOMMENDED → FINAL_APPROVED →
PO_ISSUED → PARTIALLY_RECEIVED / RECEIVED_VERIFIED → PAYMENT_PENDING → PAID
Side states: RETURNED_FOR_REVISION, REJECTED, CANCELLED,
DELIVERY_DISCREPANCY (resolves manually to RECEIVED_VERIFIED via Admin).
No editable status dropdown anywhere. `PROC_STAGE_FOR_STATUS` maps every
forward status to exactly one authorized stage, no gaps.

## Role / position / branch rules
- Tier resolved from `employees.position_id` (never `users.role` alone).
  Admin final approval = `role='admin'`.
- Self-approval blocked: actor ≠ requester, actor ≠ `last_actor_id`.
- Inventory Staff / Store Manager / receiving are branch-scoped. Area/Ops,
  Finance, Admin are not branch-restricted.

## Key database objects
- `procurement_requests` (status, last_actor_id, finance columns
  estimated_cost/budget_status/quotation_reference/finance_notes,
  payment columns invoice_reference/payment_method/payment_amount/
  payment_recorded_by+at/payment_confirmed_by+at/payment_notes)
- `procurement_request_items`, `procurement_request_audit` (append-only)
- `procurement_purchase_orders` (UNIQUE request_id), `procurement_po_items`
- `procurement_receipts` (one row per receiving event), `procurement_
  receipt_items` (qty_received + condition good/damaged/missing, up to
  3 rows per item per event now) — cumulative received always SUMmed,
  never stored redundantly.
- All tables/columns created lazily via `ensure_procurement_tables()`.

## Supplier Module — architecture decision (this session)
User provided a full Supplier Account spec (login, RBAC, portal, RFQ/
quotation/PO/delivery/payment, kept separate from HR/Accounts/Employee
modules). **Decision: integrate into the same CloudCup database**
(supersedes the earlier separate-DB-with-API-sync plan).

Proposed checkpoints (not started, need individual approval):
- **S1** Extend `suppliers` table (accreditation, TIN, category, bank,
  documents) + link to `users` (`user_type='supplier'`, excluded from
  employee lists/reports).
- **S2** Supplier login + auth middleware, Portal-only access.
- **S3** Admin-side Supplier List (in Procurement module) — CRUD, filters.
- **S4** Migrate `procurement_purchase_orders.supplier_name` → FK.
- **S5** Supplier Portal: profile/accreditation, assigned POs.
- **S6** Supplier PO actions: confirm/accept/reject, delivery status.
- **S7** Supplier document uploads (PDF/JPG/PNG only).
- **S8** Supplier-facing payment status view.
- **S9** RFQ/Canvass/Quotation flow (largest — may split further).

## Files by checkpoint (brief — see code for full detail)
- **C17/C18** `Receive_Verify_Action.php`: locks PO+request, inserts
  receipt+item rows, auto-status rule, stock update for good-condition
  qty (branch re-checked) + `inventory_log` row.
- **C19/C20** `Finance_Payment_Queue.php` (list + record payment →
  PAYMENT_PENDING), `Payment_Confirmation.php` (Finance Manager confirms
  → PAID). `PAYMENT_PENDING` stage mapping added to workflow.
- **C22** `Resolve_Discrepancy.php`: Admin only, manual resolve (Accept
  as-is / Write off + required note) → RECEIVED_VERIFIED.
  `DELIVERY_DISCREPANCY → PROC_STAGE_ADMIN` mapping added; page
  explicitly re-checks exact status since PROC_STAGE_ADMIN now covers
  two statuses (FINAL_APPROVED and DELIVERY_DISCREPANCY).
- **Post-C22 fix** `Receiving_Page.php` + `Receive_Verify_Action.php`:
  qty inputs split into Good/Damaged/Missing (real `value`, not
  placeholder); server validates sum ≤ remaining ordered qty.
- Sidebar links per stage in `finance/includes/finance_sidebar.php`,
  `manager/Sidebar_Manager.php`, `admin/Sidebar_Admin.php`,
  `staff/Sidebar_Employee.php`.

## Manual test script (run locally — no PHP/MySQL in this sandbox)
1. Full chain DRAFT→PAID (verify wrong-branch/self-approval blocked at
   each stage, duplicate PO issuance blocked).
2. Receiving: full-good (stock +qty, one log row, RECEIVED_VERIFIED);
   partial (stock rises only by delivered qty); **mixed good+damaged in
   one line** (both rows inserted, stock +good qty only, status →
   DELIVERY_DISCREPANCY); qty over remaining → rejected.
3. Resolve_Discrepancy: Admin resolves → RECEIVED_VERIFIED → shows in
   Payment Queue.
4. Payment: Finance Officer records → PAYMENT_PENDING (can't
   self-confirm); Finance Manager confirms → PAID (double-submit fails
   cleanly).
5. Tamper test: forged status/branch_id/ids in POST/URL at every stage
   — all rejected (values always re-derived server-side).

## Test results
- No PHP/MySQL in this sandbox — all code reviewed by hand; user runs
  `php -l` and real DB testing locally.

## Process redesign — Phases A, B, D (this session, supersedes the sections above)

The Supplier Portal (S1–S8) and RFQ/Canvass/Quotation flow (S9) listed as
"not started" above were since built in full — see `supplier/`, `admin/
Supplier_List.php`, `admin/Create_RFQ_Page.php`, `admin/RFQ_Canvass_Page.php`.
`users.role='supplier'` is the account-type discriminator (not a separate
`user_type` column); suppliers are excluded from every HR/Accounts query.

On top of that, the user supplied a target process document restructuring
the approval chain itself. Implemented in scope **A + B + D** (Phase **C**
— conditional HR Head/Marketing Head approval + an Owner approval-limit
threshold — is explicitly deferred, needs a `request_category` concept
that doesn't exist yet).

**New status chain:**
```
DRAFT → SUBMITTED → SUPPLIER_STOCK_CHECKING → QUOTATION_RECEIVED → STORE_MANAGER_APPROVED
      → FINANCE_OFFICER_REVIEWED → FINANCE_MANAGER_RECOMMENDED → FINAL_APPROVED
      → PO_ISSUED → RECEIVED_VERIFIED → PAYMENT_PENDING → PAID
```
`STORE_MANAGER_APPROVED` is reused (not renamed) — it now means "Store
Manager forwarded this to Finance, quotation attached." Everything from
`FINANCE_OFFICER_REVIEWED` onward is unchanged from the original chain.

**Phase A — terminology + remove Area/Ops + Store Manager creates requests:**
- `hr_positions`: `Finance Manager`→`Finance Head` (id 50), `HR Manager`→
  `HR Head` (id 40), `Marketing Manager`→`Marketing Head` (id 60). Internal
  PHP constants (`PROC_STAGE_FINANCE_MANAGER`, etc.) unchanged — only the
  DB label and the one string comparison in `procurement_auth.php` moved.
- `role='admin'` displays as **"Owner"** on procurement pages only
  (`admin/Admin_Final_Approval.php` title/pill) — not a role rename.
- Area/Ops Manager step removed from `PROC_FORWARD_PATH`; `STORE_MANAGER_
  APPROVED` now maps straight to `PROC_STAGE_FINANCE_OFFICER`.
  `manager/Area_Ops_Validation.php` and `admin/Resolve_Discrepancy.php`
  (see Phase D) are left in place but retired non-destructively: their
  queue queries are now built from `PROC_STAGE_FOR_STATUS` dynamically
  rather than a hardcoded status, so they self-empty instead of showing
  stale un-actionable rows.
- `manager/Purchase_Request_Form.php` (moved from `staff/`) — Store
  Manager creates requests now, not Inventory Staff. Gated on
  `PROC_STAGE_STORE_MANAGER`.
- **Bug fixed in the same pass:** `finance/Finance_Officer_Review.php`'s
  queue query was still hardcoded to the now-dead `AREA_OR_OPERATIONS_
  APPROVED` status — would have shown an always-empty queue. Fixed to
  filter on `STORE_MANAGER_APPROVED`; also now surfaces the supplier
  name/quotation/shipping/attachment on each card.

**Phase B — supplier stock-check + quotation before Finance:**
- New statuses `SUPPLIER_STOCK_CHECKING`, `QUOTATION_RECEIVED`. New
  decisions: `assign_supplier` (SUBMITTED→SUPPLIER_STOCK_CHECKING, Store
  Manager only), `supplier_quoted` (→QUOTATION_RECEIVED, supplier only,
  via `supplier_require_login()` not `procurement_require_stage()`),
  `retry_supplier` (back to SUBMITTED for a different supplier),
  `forward_to_finance` (→STORE_MANAGER_APPROVED). New `cancel` decision
  (→CANCELLED) usable from SUBMITTED/SUPPLIER_STOCK_CHECKING/
  QUOTATION_RECEIVED.
- **Important guard:** the generic `approve` decision now explicitly
  returns `null` when `$current === SUBMITTED` — SUBMITTED must go
  through `assign_supplier`, never a plain approve. This is what keeps
  the old `manager/Approval_Queue.php` (superseded by `Assign_Supplier_
  Page.php`, unlinked from the sidebar but left in place) from silently
  corrupting state (moving to SUPPLIER_STOCK_CHECKING with no
  `supplier_id` set) if it's ever hit directly.
- `SUPPLIER_STOCK_CHECKING` has no `PROC_STAGE_FOR_STATUS` entry on
  purpose — only the assigned supplier acts on it.
- Single supplier at a time (no multi-supplier canvass at this stage —
  that's what RFQ/Canvass is for, later in the chain). Partial-stock
  responses don't auto-transition; Store Manager decides on `Quotation_
  Review_Page.php` (proceed with available qty / try another supplier /
  cancel).
- New columns on `procurement_requests`: `supplier_id`,
  `supplier_check_status`, `quotation_amount`, `shipping_fee`,
  `quotation_attachment_path`, `quotation_notes`. New column on
  `procurement_request_items`: `available_quantity`. New files:
  `manager/Assign_Supplier_Page.php`, `supplier/Supplier_Stock_Checks.php`,
  `manager/Quotation_Review_Page.php`.

**Phase D — Store-Manager-led discrepancy resolution + supplier messaging:**
- `DELIVERY_DISCREPANCY` now maps to `PROC_STAGE_STORE_MANAGER` (was
  `PROC_STAGE_ADMIN`) and is branch-scoped (the old Admin version was
  deliberately cross-branch).
- New tables: `procurement_delivery_issues`, `procurement_delivery_
  issue_messages` (one thread per open issue on a PO; "send resolution
  request" is pure record-keeping and does NOT change
  `procurement_requests.status` — Finance still manually reconciles the
  eventual payment, no automatic financial adjustment; only "Mark
  Resolved" transitions to `RECEIVED_VERIFIED`, gated on the supplier
  having replied at least once).
- New files: `manager/Discrepancy_Resolution_Page.php`, `supplier/
  Supplier_Delivery_Issues.php`.

All of the above added to `ensure_procurement_v2_tables()` in
`includes/procurement_queries.php`. Full chain re-verified end-to-end via
real HTTP (curl + cookie jar against the running Apache instance):
create → assign supplier → supplier quotes → forward to Finance → lands
correctly on Finance Officer's queue; and receiving-flagged-damaged →
Store Manager sends resolution request → supplier replies → Store
Manager marks resolved → RECEIVED_VERIFIED. `Area_Ops_Validation.php`
and `Resolve_Discrepancy.php` confirmed to load cleanly with empty
queues for their respective roles, not a broken page.
