<?php
// ── LEAVE / EMPLOYMENT STATUS SYNC ─────────────────────────────────
// Keeps employees.employment_status lined up with approved leave
// requests, so the "On Leave" tab in Employee Records reflects reality
// without HR having to flip the dropdown by hand.
//
// Rules:
//  - An employee is moved to 'on_leave' the moment they have an
//    APPROVED leave request whose date range covers today.
//  - They're moved back to 'active' once none of their approved leave
//    requests cover today anymore (the leave ended, or it was
//    cancelled/rejected/revoked).
//  - 'terminated' employees are never touched — termination always
//    wins over a leave status.
//
// There's no cron job in this project, so this runs on-demand: call it
// once, right after DB_Connect, on any page that reads or writes
// employment_status or leave_requests (Leave Management, Employee
// Records). It's cheap and idempotent, so calling it on every load is
// fine.
function sync_employee_leave_statuses($conn) {
    if (!$conn) return;

    // Flip ACTIVE -> ON LEAVE for anyone with a currently-active approved leave.
    mysqli_query($conn, "
        UPDATE employees e
        JOIN leave_requests lr ON lr.employee_id = e.employee_id
        SET e.employment_status = 'on_leave'
        WHERE lr.status = 'approved'
          AND CURDATE() BETWEEN lr.date_from AND lr.date_to
          AND e.employment_status = 'active'
    ");

    // Flip ON LEAVE -> ACTIVE for anyone who no longer has a currently-active approved leave.
    mysqli_query($conn, "
        UPDATE employees e
        SET e.employment_status = 'active'
        WHERE e.employment_status = 'on_leave'
          AND NOT EXISTS (
              SELECT 1 FROM leave_requests lr
              WHERE lr.employee_id = e.employee_id
                AND lr.status = 'approved'
                AND CURDATE() BETWEEN lr.date_from AND lr.date_to
          )
    ");
}

// ── AUTO-EXPIRE STALE PENDING LEAVE REQUESTS ────────────────────────
// A request nobody acted on before its own date_to has effectively
// expired — the dates it asked for are already gone, so leaving it
// stuck as "pending" forever isn't useful. Falls it to 'rejected'
// automatically. reviewed_by stays NULL (unlike a human decision,
// which always sets it) so it's still possible to tell the two apart.
// Same on-demand pattern as sync_employee_leave_statuses() above —
// cheap, idempotent, safe to call on every load.
function expire_stale_leave_requests($conn) {
    if (!$conn) return;
    mysqli_query($conn, "
        UPDATE leave_requests
        SET status = 'rejected', reviewed_at = NOW()
        WHERE status = 'pending' AND date_to < CURDATE()
    ");
}