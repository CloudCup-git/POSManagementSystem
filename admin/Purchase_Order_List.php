<?php
/**
 * Procurement — Purchase Order List / Supplier Fulfillment Tracking (C15)
 * -------------------------------------------------------------
 * Admin only. Cross-branch. Lists every issued PO and lets Admin mark
 * one "Sent to Supplier" with an optional delivery note reference.
 * Metadata only — no request status transition, no stock change.
 * Idempotent: marking an already-sent PO again is a no-op (no second
 * audit row), not an error.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/procurement_queries.php';

$user = procurement_require_stage([PROC_STAGE_ADMIN]);

if (!$conn) {
    http_response_code(503);
    die('Database connection unavailable. Please check that MySQL/MariaDB is running, then refresh this page.');
}

ensure_procurement_tables($conn);

$user_id   = $user['user_id'];
$full_name = $user['full_name'];

$msg = '';

// ── POST: mark sent ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'mark_sent') {
    $po_id = (int) ($_POST['po_id'] ?? 0);
    $ref   = trim($_POST['delivery_note_reference'] ?? '');
    if (mb_strlen($ref) > 100) $ref = mb_substr($ref, 0, 100);
    $ref_or_null = $ref !== '' ? $ref : null;

    $stmt = mysqli_prepare($conn,
        'SELECT po_id, po_number, request_id, sent_at FROM procurement_purchase_orders WHERE po_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $po_id);
    mysqli_stmt_execute($stmt);
    $po = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$po) {
        $msg = 'error:Purchase Order not found.';
    } elseif ($po['sent_at'] !== null) {
        $msg = 'error:This PO was already marked as sent.';
    } else {
        $upd = mysqli_prepare($conn,
            'UPDATE procurement_purchase_orders
             SET sent_at = NOW(), delivery_note_reference = ?
             WHERE po_id = ? AND sent_at IS NULL'
        );
        mysqli_stmt_bind_param($upd, 'si', $ref_or_null, $po_id);
        mysqli_stmt_execute($upd);
        $changed = mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);

        if ($changed === 1) {
            procurement_log_audit($conn, (int) $po['request_id'], $user_id, $full_name, 'po_sent',
                PROC_STATUS_PO_ISSUED, PROC_STATUS_PO_ISSUED,
                $po['po_number'] . ($ref !== '' ? ' — delivery note: ' . $ref : ''));
            $msg = 'success:' . $po['po_number'] . ' marked as sent to supplier.';
        } else {
            $msg = 'error:This PO was already marked as sent — refresh and try again.';
        }
    }
}

// ── List: all issued POs ─────────────────────────────────────────────
$stmt = mysqli_prepare($conn,
    'SELECT po.po_id, po.po_number, po.request_id, po.branch_id, b.branch_name,
            po.supplier_name, po.expected_delivery_date, po.total_cost,
            po.sent_at, po.delivery_note_reference, po.created_at
     FROM procurement_purchase_orders po
     LEFT JOIN branches b ON b.branch_id = po.branch_id
     ORDER BY po.created_at DESC'
);
mysqli_stmt_execute($stmt);
$pos = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Purchase Orders — Cloud Cup</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../css/admin_page.css" />
  <link rel="stylesheet" href="../css/inventory_management.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .req-card{
      border:1px solid var(--border,#e5e7eb);
      border-radius:10px;
      padding:16px;
      margin-bottom:14px;
      transition:border-color .25s ease, box-shadow .25s ease, transform .25s ease;
    }
    .req-card:hover{
      border-color:#d8cdbb;
      box-shadow:0 6px 18px rgba(43,29,14,0.08);
      transform:translateY(-1px);
    }
    .req-card-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;flex-wrap:wrap;gap:6px;}
    .branch-tag{font-size:12px;color:var(--text-light);}

    .po-form-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center;}

    /* ── Delivery note input ─────────────────────────────── */
    .po-form-row input[type=text]{
      flex:1;
      min-width:200px;
      padding:10px 14px;
      border:1px solid var(--border,#e2d9c9);
      border-radius:8px;
      background:#fdfbf7;
      font-family:inherit;
      font-size:14px;
      color:#3a2f22;
      transition:border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .po-form-row input[type=text]::placeholder{
      color:#a99a84;
      transition:opacity .2s ease;
    }
    .po-form-row input[type=text]:hover{
      border-color:#c9b89c;
    }
    .po-form-row input[type=text]:focus{
      outline:none;
      background:#ffffff;
      border-color:#8a5a2b;
      box-shadow:0 0 0 3px rgba(138,90,43,0.15);
    }
    .po-form-row input[type=text]:focus::placeholder{
      opacity:.55;
    }

    /* ── Mark Sent button ────────────────────────────────── */
    .po-form-row .btn-save{
      position:relative;
      overflow:hidden;
      border:none;
      border-radius:8px;
      padding:10px 20px;
      font-family:inherit;
      font-size:14px;
      font-weight:600;
      letter-spacing:.2px;
      color:#fdf8f0;
      background:#3a2416;
      cursor:pointer;
      transition:transform .15s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .po-form-row .btn-save::after{
      content:"";
      position:absolute;
      inset:0;
      background:linear-gradient(120deg, transparent 20%, rgba(255,255,255,0.12) 45%, transparent 70%);
      transform:translateX(-100%);
      transition:transform .5s ease;
    }
    .po-form-row .btn-save:hover{
      background:#4a2f1c;
      box-shadow:0 4px 14px rgba(58,36,22,0.35);
      transform:translateY(-1px);
    }
    .po-form-row .btn-save:hover::after{
      transform:translateX(100%);
    }
    .po-form-row .btn-save:active{
      transform:translateY(0) scale(.97);
      box-shadow:0 2px 6px rgba(58,36,22,0.3);
    }
    .po-form-row .btn-save:focus-visible{
      outline:2px solid #8a5a2b;
      outline-offset:2px;
    }

    /* ── Status badges ───────────────────────────────────── */
    .sent-badge,
    .pending-badge{
      display:inline-flex;
      align-items:center;
      border-radius:20px;
      padding:3px 12px;
      font-size:12px;
      font-weight:600;
      transition:transform .2s ease;
    }
    .sent-badge{background:#e6f7ec;color:#1a7f45;}
    .pending-badge{
      background:#fff4e5;
      color:#a15c00;
      animation:pulse-pending 2.4s ease-in-out infinite;
    }
    @keyframes pulse-pending{
      0%, 100% { box-shadow:0 0 0 0 rgba(161,92,0,0.18); }
      50%      { box-shadow:0 0 0 5px rgba(161,92,0,0); }
    }

    /* ── SweetAlert2 theme (matches the coffee palette) ─── */
    .swal2-popup.po-swal{
      border-radius:16px;
      background:#fdfbf7;
      padding:28px 24px 24px;
      font-family:'Inter', sans-serif;
    }
    .swal2-popup.po-swal .swal2-title{
      font-family:'Playfair Display', serif;
      color:#3a2416;
      font-size:22px;
    }
    .swal2-popup.po-swal .swal2-html-container{
      color:#5c4a37;
      font-size:14.5px;
    }
    .swal2-popup.po-swal .swal2-icon.swal2-question{
      border-color:#c9a877;
      color:#8a5a2b;
    }
    .swal2-popup.po-swal .swal2-confirm{
      background:#3a2416 !important;
      color:#fdf8f0 !important;
      border-radius:8px !important;
      font-weight:600;
      padding:10px 22px;
      box-shadow:none !important;
      transition:transform .15s ease, background-color .2s ease;
    }
    .swal2-popup.po-swal .swal2-confirm:hover{
      background:#4a2f1c !important;
      color:#fdf8f0 !important;
      transform:translateY(-1px);
    }
    .swal2-popup.po-swal .swal2-cancel{
      background:#f1e9dc !important;
      color:#3a2416 !important;
      border-radius:8px !important;
      font-weight:600;
      padding:10px 22px;
      box-shadow:none !important;
      transition:transform .15s ease, background-color .2s ease;
    }
    .swal2-popup.po-swal .swal2-cancel:hover{
      background:#e6dbc8 !important;
      transform:translateY(-1px);
    }
    .swal2-popup.po-swal .swal2-actions{ gap:10px; }

    @media (prefers-reduced-motion: reduce){
      .req-card, .req-card:hover,
      .po-form-row input[type=text],
      .po-form-row .btn-save, .po-form-row .btn-save::after,
      .pending-badge{
        animation:none !important;
        transition:none !important;
        transform:none !important;
      }
    }
  </style>
</head>

<body>
  <script src="../js/sidebar-toggle.js"></script>
  <?php $active_page = 'proc-po-list'; require_once __DIR__ . '/Sidebar_Admin.php'; ?>

  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="topbar-title">Purchase Orders — All Branches</div>
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

      <?php if (empty($pos)): ?>
        <div class="table-wrap" style="padding:40px;text-align:center;color:var(--text-light);">
          No Purchase Orders issued yet.
        </div>
      <?php else: foreach ($pos as $po): ?>
        <div class="req-card">
          <div class="req-card-head">
            <div>
              <strong><?= htmlspecialchars($po['po_number']) ?></strong>
              (REQ-<?= str_pad((string) $po['request_id'], 4, '0', STR_PAD_LEFT) ?>)
              — <span class="branch-tag"><?= htmlspecialchars($po['branch_name'] ?? 'Unknown Branch') ?></span>
              — supplier: <?= htmlspecialchars($po['supplier_name']) ?>
            </div>
            <span class="<?= $po['sent_at'] ? 'sent-badge' : 'pending-badge' ?>">
              <?= $po['sent_at'] ? 'SENT ' . date('M d, Y', strtotime($po['sent_at'])) : 'NOT SENT YET' ?>
            </span>
          </div>

          <div style="font-size:13px;color:var(--text-light);">
            Total: ₱<?= number_format((float) $po['total_cost'], 2) ?>
            <?php if ($po['expected_delivery_date']): ?> · Expected: <?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?><?php endif; ?>
            <?php if ($po['delivery_note_reference']): ?> · Delivery note: <?= htmlspecialchars($po['delivery_note_reference']) ?><?php endif; ?>
          </div>

          <?php if (!$po['sent_at']): ?>
          <form method="POST" action="" class="po-form-row js-po-form" data-po-number="<?= htmlspecialchars($po['po_number']) ?>">
            <input type="hidden" name="act" value="mark_sent" />
            <input type="hidden" name="po_id" value="<?= (int) $po['po_id'] ?>" />
            <input type="text" name="delivery_note_reference" maxlength="100" placeholder="Delivery note reference (optional)" />
            <button type="submit" class="btn-save">Mark Sent to Supplier</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <script>
    lucide.createIcons();

    document.querySelectorAll('.js-po-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const poNumber = form.dataset.poNumber || 'this PO';

        Swal.fire({
          title: 'Mark as sent?',
          html: `Confirm <strong>${poNumber}</strong> has been sent to the supplier.`,
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Yes, mark sent',
          cancelButtonText: 'Cancel',
          reverseButtons: true,
          focusCancel: true,
          customClass: { popup: 'po-swal' },
          buttonsStyling: false
        }).then(function (result) {
          if (result.isConfirmed) {
            form.submit();
          }
        });
      });
    });
  </script>
</body>
</html>
