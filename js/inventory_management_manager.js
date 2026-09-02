function toggleSidebar() {
  document.body.classList.toggle('sidebar-hidden');
  localStorage.setItem('cc_sidebar_hidden', document.body.classList.contains('sidebar-hidden') ? '1' : '0');
}

function openModal(id) {
  document.getElementById('modal-' + id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// ── Admin-only actions (buttons that call these only render for admins) ──
function openEditModal(item) {
  document.getElementById('edit-id').value      = item.inventory_id;
  document.getElementById('edit-name').value    = item.item_name;
  document.getElementById('edit-category').value = item.category || '';
  document.getElementById('edit-unit').value    = item.unit;
  document.getElementById('edit-reorder').value = Math.round(parseFloat(item.reorder_level) || 0);
  document.getElementById('edit-cost').value    = item.cost_per_unit !== null && item.cost_per_unit !== '' ? Math.round(parseFloat(item.cost_per_unit)) : '';
  document.getElementById('modal-edit').classList.add('open');
}
function openRestockModal(item) {
  document.getElementById('restock-id').value      = item.inventory_id;
  document.getElementById('restock-item-name').textContent = item.item_name;
  document.getElementById('restock-current').value = Math.round(parseFloat(item.quantity) || 0) + ' ' + item.unit;
  document.getElementById('restock-qty').value     = '';
  document.getElementById('restock-supplier').value = '';
  document.getElementById('restock-date').value    = '';
  document.getElementById('restock-cost').value    = item.cost_per_unit !== null && item.cost_per_unit !== '' ? Math.round(parseFloat(item.cost_per_unit)) : '';
  document.getElementById('restock-payment').value = '';
  document.getElementById('restock-note').value    = '';
  document.getElementById('modal-restock').classList.add('open');
}

// ── Bulk Restock (Manager only) — checkbox selection across the inventory
// table, reviewed in one modal, then filed as several restock_requests
// rows sharing one batch_id (see act=bulk_restock in the PHP). Selection
// state lives in memory only (bulkSelected) — nothing is staged across
// page reloads the way the staff pending tray is, since this is meant to
// be a one-sitting "pick items, fill in delivery details, submit" flow.
var bulkSelected = {};

function onBulkCheckChange() {
  bulkSelected = {};
  document.querySelectorAll('.bulk-restock-check').forEach(function (cb) {
    if (cb.checked) {
      bulkSelected[cb.dataset.id] = {
        inventory_id: cb.dataset.id,
        item_name: cb.dataset.name,
        unit: cb.dataset.unit,
        default_cost: cb.dataset.cost
      };
    }
  });

  // Keep "select all" in sync if the user unchecked one row manually.
  var all = document.querySelectorAll('.bulk-restock-check');
  var selectAll = document.getElementById('bulkSelectAll');
  if (selectAll && all.length > 0) {
    selectAll.checked = Array.prototype.every.call(all, function (cb) { return cb.checked; });
  }

  updateBulkRestockBar();
}

function updateBulkRestockBar() {
  var bar = document.getElementById('bulkRestockBar');
  if (!bar) return;
  var ids = Object.keys(bulkSelected);
  if (ids.length === 0) {
    bar.style.display = 'none';
    return;
  }
  bar.style.display = 'block';
  document.getElementById('bulkRestockBarCount').textContent = ids.length + ' item' + (ids.length > 1 ? 's' : '') + ' selected';
  document.getElementById('bulkRestockBarBtnCount').textContent = ids.length;
}

function clearBulkSelection() {
  document.querySelectorAll('.bulk-restock-check').forEach(function (cb) { cb.checked = false; });
  var selectAll = document.getElementById('bulkSelectAll');
  if (selectAll) selectAll.checked = false;
  bulkSelected = {};
  updateBulkRestockBar();
}

(function () {
  var selectAll = document.getElementById('bulkSelectAll');
  if (!selectAll) return;
  selectAll.addEventListener('change', function () {
    document.querySelectorAll('.bulk-restock-check').forEach(function (cb) { cb.checked = selectAll.checked; });
    onBulkCheckChange();
  });
})();

function openBulkRestockModal() {
  var ids = Object.keys(bulkSelected);
  if (ids.length === 0) return;

  document.getElementById('bulk-restock-count').textContent   = ids.length;
  document.getElementById('bulk-restock-supplier').value      = '';
  document.getElementById('bulk-restock-date').value          = '';
  document.getElementById('bulk-restock-payment').value       = '';
  document.getElementById('bulk-restock-note').value          = '';

  var container = document.getElementById('bulk-restock-items');
  container.innerHTML = ids.map(function (id) {
    var it = bulkSelected[id];
    return '' +
      '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #faf8f4">' +
        '<div style="flex:1;font-size:13px;font-weight:600">' + escapeHtml(it.item_name) + '</div>' +
        '<input type="number" min="1" max="9999" step="1" placeholder="Qty *" style="width:80px" ' +
          'id="bulk-item-qty-' + id + '" oninput="this.value=this.value.slice(0,4)">' +
        '<span style="font-size:12px;color:#6b6156">' + escapeHtml(it.unit) + '</span>' +
        '<input type="number" min="0" max="9999" step="1" placeholder="Cost ₱" style="width:90px" ' +
          'id="bulk-item-cost-' + id + '" value="' + (it.default_cost || '') + '" oninput="this.value=this.value.slice(0,4)">' +
      '</div>';
  }).join('');

  openModal('bulk-restock');
}

// Field names here (act/supplier_name/delivery_date/payment_type/note/items)
// must match what the bulk_restock handler in Inventory_Management_Page.php
// reads from $_POST — same as any other Manager form action on this page,
// this is a real POST + full page reload (Swal feedback comes from
// $action_msg after reload), not a fetch()/JSON endpoint like the staff
// batch-adjustment tray.
function submitBulkRestock() {
  var ids = Object.keys(bulkSelected);
  if (ids.length === 0) return;

  var supplier = document.getElementById('bulk-restock-supplier').value.trim();
  var date     = document.getElementById('bulk-restock-date').value;
  var payment  = document.getElementById('bulk-restock-payment').value;
  var note     = document.getElementById('bulk-restock-note').value.trim();

  if (!supplier) { Swal.fire({ icon: 'warning', title: 'Missing Supplier', text: 'Please enter a supplier name.', confirmButtonColor: '#b8703f' }); return; }
  if (!date)     { Swal.fire({ icon: 'warning', title: 'Missing Delivery Date', text: 'Please select a delivery date.', confirmButtonColor: '#b8703f' }); return; }
  if (!payment)  { Swal.fire({ icon: 'warning', title: 'Missing Payment Type', text: 'Please select how the supplier will be paid.', confirmButtonColor: '#b8703f' }); return; }

  var items = [];
  for (var i = 0; i < ids.length; i++) {
    var id     = ids[i];
    var qtyEl  = document.getElementById('bulk-item-qty-' + id);
    var costEl = document.getElementById('bulk-item-cost-' + id);
    var qty    = qtyEl ? qtyEl.value : '';
    if (!qty || parseFloat(qty) <= 0) {
      Swal.fire({ icon: 'warning', title: 'Missing Quantity', text: '"' + bulkSelected[id].item_name + '": enter a valid quantity.', confirmButtonColor: '#b8703f' });
      return;
    }
    items.push({ inventory_id: id, qty_added: qty, cost: costEl ? costEl.value : '' });
  }

  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '';
  var fields = {
    act: 'bulk_restock',
    supplier_name: supplier,
    delivery_date: date,
    payment_type: payment,
    note: note,
    items: JSON.stringify(items)
  };
  Object.keys(fields).forEach(function (key) {
    var input = document.createElement('input');
    input.type  = 'hidden';
    input.name  = key;
    input.value = fields[key];
    form.appendChild(input);
  });
  document.body.appendChild(form);
  showSavingOverlay('Submitting batch for approval…');
  form.submit();
}

// Blurred, animated loading pop-up shown the instant Add Item / Save
// Changes is submitted, so there's clear feedback while it saves and the
// page reloads. Only fires once required-field validation passes, since
// that runs before 'submit' is dispatched.
function showSavingOverlay(title) {
  Swal.fire({
    title: title,
    html: 'Updating inventory…',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: { container: 'blurred-backdrop' },
    didOpen: () => Swal.showLoading()
  });
}

(function () {
  var addForm     = document.getElementById('form-add');
  var editForm    = document.getElementById('form-edit');
  var restockForm = document.getElementById('form-restock');
  if (addForm)     addForm.addEventListener('submit',     function () { showSavingOverlay('Adding item…'); });
  if (editForm)    editForm.addEventListener('submit',    function () { showSavingOverlay('Saving changes…'); });
  if (restockForm) restockForm.addEventListener('submit', function () { showSavingOverlay('Submitting for approval…'); });
})();

// Remove item — soft delete (is_active=0), recoverable from the Removed
// Items panel below the main table.
function confirmRemoveItem(id, name) {
  Swal.fire({
    title: 'Remove this item?',
    html: '<b>' + name + '</b> will be hidden from inventory and stock counts, but you can restore it anytime from Removed Items.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, remove it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#a6650f',
    cancelButtonColor: '#9c9184',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      document.getElementById('delete-id').value = id;
      showSavingOverlay('Removing item…');
      document.getElementById('form-delete').submit();
    }
  });
}

// Restore a previously removed item (is_active=1).
function confirmRestoreItem(id, name) {
  Swal.fire({
    title: 'Restore this item?',
    html: '<b>' + name + '</b> will reappear in inventory and count toward stock again.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, restore it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#2f6f4e',
    cancelButtonColor: '#9c9184',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      document.getElementById('restore-id').value = id;
      showSavingOverlay('Restoring item…');
      document.getElementById('form-restore').submit();
    }
  });
}

// ── Staff-only actions (buttons that call these only render for staff) ──
// Adjustments are staged locally first ("pending changes" tray) and only
// sent to the server as one batch when "Apply All Changes" is pressed —
// see applyBatchChanges() below. Nothing here touches the database.
var _staffAdjustInvId = null;
var _staffAdjustCurrentQty = null; // true DB quantity for the item currently open in the modal
var REASON_LABELS = {
  delivery: 'Received Delivery',
  recount:  'Recount Correction',
  damaged:  'Damaged/Spoiled',
  other:    'Other'
};
var STORAGE_KEY = 'cc_inventory_pending_v1';

function _loadPending() {
  try {
    var raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch (e) {
    return {};
  }
}
function _savePending(pending) {
  try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(pending)); } catch (e) { /* storage unavailable — tray still works for this page view */ }
}
var pendingAdjustments = _loadPending();

function openStaffAdjustModal(invId, itemName, currentQty, unit) {
  _staffAdjustInvId = invId;
  _staffAdjustCurrentQty = Math.round(parseFloat(currentQty) || 0);
  var staged = pendingAdjustments[invId];

  document.getElementById('staff-adjust-item-name').textContent = itemName;
  document.getElementById('staff-adjust-current').value = Math.round(parseFloat(currentQty) || 0) + ' ' + unit;
  document.getElementById('staff-adjust-qty').value = staged ? staged.new_qty : Math.round(parseFloat(currentQty) || 0);
  document.getElementById('staff-adjust-reason').value = staged ? staged.reason_key : '';
  document.getElementById('staff-adjust-note').value = staged ? staged.note : '';

  var saveBtn = document.getElementById('staff-adjust-save-btn');
  if (saveBtn) saveBtn.textContent = staged ? 'Update Pending Change' : 'Add to Pending Changes';

  document.getElementById('modal-staff-adjust').classList.add('open');
}

(function () {
  var form = document.getElementById('form-staff-adjust');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var newQtyRaw = document.getElementById('staff-adjust-qty').value;
    var reasonKey = document.getElementById('staff-adjust-reason').value;
    var note      = document.getElementById('staff-adjust-note').value.trim();
    var itemName  = document.getElementById('staff-adjust-item-name').textContent;
    var unit      = document.getElementById('staff-adjust-current').value.split(' ').slice(1).join(' ');

    if (newQtyRaw === '' || !reasonKey) return; // required attrs already guard this, just being defensive

    pendingAdjustments[_staffAdjustInvId] = {
      inventory_id: _staffAdjustInvId,
      item_name: itemName,
      unit: unit,
      old_qty: _staffAdjustCurrentQty,
      new_qty: Math.round(parseFloat(newQtyRaw) || 0),
      reason_key: reasonKey,
      reason_label: REASON_LABELS[reasonKey] || reasonKey,
      note: note
    };
    _savePending(pendingAdjustments);
    closeModal('modal-staff-adjust');
    renderTray();
  });
})();

function renderTray() {
  var tray  = document.getElementById('pendingTray');
  var body  = document.getElementById('pendingTrayBody');
  if (!tray || !body) return;

  var ids = Object.keys(pendingAdjustments);
  var countEl = document.getElementById('pendingTrayCount');
  var applyCountEl = document.getElementById('applyBatchCount');
  var applyBtn = document.getElementById('applyBatchBtn');

  if (ids.length === 0) {
    tray.style.display = 'none';
    body.innerHTML = '';
    return;
  }

  tray.style.display = 'block';
  if (countEl) countEl.textContent = ids.length + ' item' + (ids.length > 1 ? 's' : '') + ' pending';
  if (applyCountEl) applyCountEl.textContent = ids.length;
  if (applyBtn) applyBtn.disabled = false;

  body.innerHTML = ids.map(function (id) {
    var p = pendingAdjustments[id];
    return '' +
      '<div class="pending-row">' +
        '<div class="pending-row-info">' +
          '<div class="pending-row-name">' + escapeHtml(p.item_name) + '</div>' +
          '<div class="pending-row-qty">' + p.old_qty + ' → <b>' + p.new_qty + ' ' + escapeHtml(p.unit) + '</b></div>' +
          '<span class="pending-row-reason">' + escapeHtml(p.reason_label) + '</span>' +
        '</div>' +
        '<div class="pending-row-actions">' +
          '<button type="button" class="pending-row-btn" title="Edit" onclick="editFromTray(\'' + id + '\')"><i data-lucide="pencil"></i></button>' +
          '<button type="button" class="pending-row-btn danger" title="Remove" onclick="removeFromTray(\'' + id + '\')"><i data-lucide="x"></i></button>' +
        '</div>' +
      '</div>';
  }).join('');

  if (window.lucide) lucide.createIcons();
}

function escapeHtml(str) {
  var d = document.createElement('div');
  d.textContent = str == null ? '' : String(str);
  return d.innerHTML;
}

function toggleTray() {
  var tray = document.getElementById('pendingTray');
  if (tray) tray.classList.toggle('collapsed');
}

function editFromTray(id) {
  var p = pendingAdjustments[id];
  if (!p) return;
  // Re-open the same modal used for a fresh adjustment, prefilled with the
  // staged values — openStaffAdjustModal() already knows to pull those in
  // when an entry for this item already exists in the tray. Pass the real
  // (pre-staging) quantity so "Current Quantity" stays accurate.
  openStaffAdjustModal(p.inventory_id, p.item_name, p.old_qty, p.unit);
}

function removeFromTray(id) {
  delete pendingAdjustments[id];
  _savePending(pendingAdjustments);
  renderTray();
}

function clearTray() {
  if (Object.keys(pendingAdjustments).length === 0) return;
  Swal.fire({
    title: 'Clear all pending changes?',
    text: 'Nothing has been saved yet, so this just empties the tray.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, clear it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#a6650f',
    cancelButtonColor: '#9c9184',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      pendingAdjustments = {};
      _savePending(pendingAdjustments);
      renderTray();
    }
  });
}

function applyBatchChanges() {
  var ids = Object.keys(pendingAdjustments);
  if (ids.length === 0) return;

  var applyBtn = document.getElementById('applyBatchBtn');
  if (applyBtn) applyBtn.disabled = true;
  showSavingOverlay('Applying changes…');

  var items = ids.map(function (id) { return pendingAdjustments[id]; });
  var fd = new FormData();
  fd.append('act', 'batch_adjust_qty');
  fd.append('items', JSON.stringify(items));

  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.success) {
        pendingAdjustments = {};
        _savePending(pendingAdjustments);
        Swal.fire({
          icon: 'success',
          title: 'Applied!',
          text: data.message,
          confirmButtonColor: '#b8703f',
          timer: 2000,
          timerProgressBar: true
        }).then(function () { window.location.reload(); });
      } else {
        if (applyBtn) applyBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Oops!', text: data.message || 'Something went wrong.', confirmButtonColor: '#b8703f' });
      }
    })
    .catch(function () {
      if (applyBtn) applyBtn.disabled = false;
      Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#b8703f' });
    });
}

// Render the tray on load, in case there were staged-but-unapplied changes
// from before a search/filter reload (session storage keeps them).
document.addEventListener('DOMContentLoaded', function () {
  if (document.getElementById('pendingTray')) renderTray();
});

// Close modal on backdrop click
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
});

          // Auto-update the inventory list while typing (debounced, no need to click Search).
          (function () {
            var input = document.getElementById('search-input');
            var form  = document.getElementById('filter-form');
            if (!input || !form) return;

            var debounceTimer = null;

            input.addEventListener('input', function () {
              clearTimeout(debounceTimer);
              debounceTimer = setTimeout(function () {
                form.submit();
              }, 400); // wait 400ms after the last keystroke before searching
            });

            // Keep focus + cursor position in the box after the page reloads from typing.
            if (input.value) {
              input.focus();
              var len = input.value.length;
              input.setSelectionRange(len, len);
            }
          })();

function reportLowStock(invId, btn) {
  btn.disabled = true;
  var originalHtml = btn.innerHTML;
  btn.innerHTML = 'Sending…';

  var fd = new FormData();
  fd.append('act', 'report_low_stock');
  fd.append('inventory_id', invId);

  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.success) {
        Swal.fire({
          icon: data.already ? 'info' : 'success',
          title: data.already ? 'Already Notified' : 'Manager Notified!',
          text: data.message,
          confirmButtonColor: '#b8703f',
          timer: 2200,
          timerProgressBar: true
        });
        btn.innerHTML = '<i data-lucide="check"></i> Reported';
        if (window.lucide) lucide.createIcons();
      } else {
        Swal.fire({ icon: 'error', title: 'Oops!', text: data.message || 'Something went wrong.', confirmButtonColor: '#b8703f' });
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (window.lucide) lucide.createIcons();
      }
    })
    .catch(function () {
      Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#b8703f' });
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      if (window.lucide) lucide.createIcons();
    });
}
// ── Custom filter dropdowns (status / category / sort) ──────────────────────
// Replaces plain <select> elements with a styled dropdown; keeps the same
// hidden <input name="..."> so the existing GET filter-form still works.
document.addEventListener('DOMContentLoaded', function () {
  var filterForm = document.getElementById('filter-form');

  document.querySelectorAll('.dd').forEach(function (dd) {
    var trigger = dd.querySelector('.dd-trigger');
    if (!trigger) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var wasOpen = dd.classList.contains('open');
      document.querySelectorAll('.dd.open').forEach(function (o) { o.classList.remove('open'); });
      if (!wasOpen) dd.classList.add('open');
    });

    dd.querySelectorAll('.dd-option').forEach(function (opt) {
      opt.addEventListener('click', function (e) {
        e.stopPropagation();
        dd.querySelectorAll('.dd-option').forEach(function (o) { o.classList.remove('selected'); });
        opt.classList.add('selected');

        var current = dd.querySelector('.dd-current');
        if (current) current.textContent = opt.dataset.label || opt.textContent.trim();

        var input = dd.querySelector('input[type="hidden"]');
        if (input) input.value = opt.dataset.value || '';

        dd.classList.remove('open');
        if (filterForm) filterForm.submit();
      });
    });
  });

  document.addEventListener('click', function () {
    document.querySelectorAll('.dd.open').forEach(function (o) { o.classList.remove('open'); });
  });
});
