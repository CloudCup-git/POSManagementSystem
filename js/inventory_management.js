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
  var addForm  = document.getElementById('form-add');
  var editForm = document.getElementById('form-edit');
  if (addForm)  addForm.addEventListener('submit',  function () { showSavingOverlay('Adding item…'); });
  if (editForm) editForm.addEventListener('submit', function () { showSavingOverlay('Saving changes…'); });
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
    confirmButtonColor: '#b8453a',
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
// Adjustments are staged client-side in the "pending changes" tray and only
// hit the server as one batch (act=batch_adjust_qty) when "Apply All
// Changes" is pressed — see the PHP handler's comment for the exact field
// shape (new_qty / reason_key, not new_quantity / reason).
var _staffAdjustInvId  = null;
var _staffAdjustUnit   = '';
var _staffAdjustOldQty = 0;
var pendingAdjustments = {}; // inventory_id -> { item_name, unit, old_qty, new_qty, reason_key, note }

var REASON_LABELS = {
  delivery: 'Received Delivery',
  recount:  'Recount Correction',
  damaged:  'Damaged/Spoiled',
  other:    'Other',
};

function openStaffAdjustModal(invId, itemName, currentQty, unit) {
  _staffAdjustInvId  = invId;
  _staffAdjustUnit   = unit;
  _staffAdjustOldQty = Math.round(parseFloat(currentQty) || 0);

  var existing = pendingAdjustments[invId];
  document.getElementById('staff-adjust-item-name').textContent = itemName;
  document.getElementById('staff-adjust-current').value = _staffAdjustOldQty + ' ' + unit;
  document.getElementById('staff-adjust-qty').value = existing ? existing.new_qty : _staffAdjustOldQty;
  document.getElementById('staff-adjust-reason').value = existing ? existing.reason_key : '';
  document.getElementById('staff-adjust-note').value = existing ? existing.note : '';
  document.getElementById('modal-staff-adjust').classList.add('open');
}

function renderPendingTray() {
  var tray  = document.getElementById('pendingTray');
  var body  = document.getElementById('pendingTrayBody');
  var count = document.getElementById('pendingTrayCount');
  var applyCount = document.getElementById('applyBatchCount');
  if (!tray || !body) return;

  var ids = Object.keys(pendingAdjustments);
  if (ids.length === 0) {
    tray.style.display = 'none';
    body.innerHTML = '';
    return;
  }

  tray.style.display = '';
  count.textContent = ids.length + (ids.length === 1 ? ' item pending' : ' items pending');
  if (applyCount) applyCount.textContent = ids.length;

  // Built with data-* attributes + event delegation below, not inline
  // onclick — item_name/unit are free text and could contain a literal
  // quote, which would otherwise break out of an onclick="..." attribute.
  body.innerHTML = ids.map(function (invId) {
    var a = pendingAdjustments[invId];
    return '' +
      '<div class="pending-row">' +
        '<div class="pending-row-info">' +
          '<div class="pending-row-name">' + escapeHtml(a.item_name) + '</div>' +
          '<div class="pending-row-qty">' + a.old_qty + ' → ' + a.new_qty + ' ' + escapeHtml(a.unit) + '</div>' +
          '<span class="pending-row-reason">' + escapeHtml(REASON_LABELS[a.reason_key] || a.reason_key) + '</span>' +
        '</div>' +
        '<div class="pending-row-actions">' +
          '<button type="button" class="pending-row-btn" title="Edit" data-edit-id="' + invId + '"><i data-lucide="pencil"></i></button>' +
          '<button type="button" class="pending-row-btn danger" title="Remove" data-remove-id="' + invId + '"><i data-lucide="x"></i></button>' +
        '</div>' +
      '</div>';
  }).join('');

  body.querySelectorAll('[data-edit-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var a = pendingAdjustments[btn.dataset.editId];
      if (a) openStaffAdjustModal(Number(btn.dataset.editId), a.item_name, a.old_qty, a.unit);
    });
  });
  body.querySelectorAll('[data-remove-id]').forEach(function (btn) {
    btn.addEventListener('click', function () { removePendingItem(btn.dataset.removeId); });
  });

  if (window.lucide) lucide.createIcons();
}

function escapeHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function removePendingItem(invId) {
  delete pendingAdjustments[invId];
  renderPendingTray();
}

function toggleTray() {
  var tray = document.getElementById('pendingTray');
  if (tray) tray.classList.toggle('collapsed');
}

function clearTray() {
  if (Object.keys(pendingAdjustments).length === 0) return;
  Swal.fire({
    title: 'Clear all pending changes?',
    text: 'Nothing staged here has been saved yet — this just clears the list.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, clear it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#b8453a',
    cancelButtonColor: '#9c9184',
    reverseButtons: true
  }).then(function (result) {
    if (result.isConfirmed) {
      pendingAdjustments = {};
      renderPendingTray();
    }
  });
}

(function () {
  var form = document.getElementById('form-staff-adjust');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var newQty = document.getElementById('staff-adjust-qty').value;
    var reason = document.getElementById('staff-adjust-reason').value;
    var note   = document.getElementById('staff-adjust-note').value;

    if (newQty === '' || !reason) return; // required attrs already guard this

    if (parseFloat(newQty) === _staffAdjustOldQty) {
      Swal.fire({ icon: 'info', title: 'No change', text: 'That matches the current quantity — nothing to stage.', confirmButtonColor: '#b8703f' });
      return;
    }

    pendingAdjustments[_staffAdjustInvId] = {
      item_name:  document.getElementById('staff-adjust-item-name').textContent,
      unit:       _staffAdjustUnit,
      old_qty:    _staffAdjustOldQty,
      new_qty:    Math.round(parseFloat(newQty)),
      reason_key: reason,
      note:       note,
    };

    closeModal('modal-staff-adjust');
    renderPendingTray();
  });
})();

function applyBatchChanges() {
  var ids = Object.keys(pendingAdjustments);
  if (ids.length === 0) return;

  var btn = document.getElementById('applyBatchBtn');
  var originalHtml = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Applying…'; }

  var items = ids.map(function (invId) {
    var a = pendingAdjustments[invId];
    return { inventory_id: Number(invId), new_qty: a.new_qty, reason_key: a.reason_key, note: a.note };
  });

  var fd = new FormData();
  fd.append('act', 'batch_adjust_qty');
  fd.append('items', JSON.stringify(items));

  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Saved!',
          text: data.message,
          confirmButtonColor: '#b8703f',
          timer: 1800,
          timerProgressBar: true
        }).then(function () { window.location.reload(); });
      } else {
        if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
        Swal.fire({ icon: 'error', title: 'Oops!', text: data.message || 'Something went wrong.', confirmButtonColor: '#b8703f' });
      }
    })
    .catch(function () {
      if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
      Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#b8703f' });
    });
}

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
