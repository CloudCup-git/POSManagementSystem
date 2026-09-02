// ── State ──────────────────────────────────────────────────────
let cart = [];
let selectedPayment = 'cash';
let orderType = 'dine_in';
let currentTotal = 0;
const TAX_RATE = 0.03; // 3% sales tax, applied to subtotal across cart, order confirm, and receipt

// TAKEOUT_QUEUE_NO is injected by PHP as a global in the HTML before this script loads.
// Fallback to 1 if somehow missing.
const QUEUE_NO = (typeof TAKEOUT_QUEUE_NO !== 'undefined') ? TAKEOUT_QUEUE_NO : 1;

// ── Category Filter ─────────────────────────────────────────────
function filterCat(tab) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
  tab.classList.add('active');
  const cat = tab.dataset.cat;
  document.querySelectorAll('.cat-section').forEach(s => {
    s.classList.toggle('hidden', cat !== 'All' && s.dataset.section !== cat);
  });
}

// ── Search ──────────────────────────────────────────────────────
function filterItems() {
  const q = document.getElementById('searchInput').value.toLowerCase().trim();
  document.querySelectorAll('.cat-section').forEach(section => {
    section.classList.remove('hidden');
    let any = false;
    section.querySelectorAll('.item-card').forEach(card => {
      const match = !q || card.dataset.name.includes(q);
      card.classList.toggle('search-hidden', !match);
      if (match) any = true;
    });
    section.style.display = any ? '' : 'none';
  });
}

// ── Cart ────────────────────────────────────────────────────────
// Each cart item now has: id, name, size, sizeLabel, price, img, qty, cupInvId

function handleItemClick(id, name, basePrice, img, category) {
  // Only drink categories get size selection
  const needsSize = CUP_SIZES.length > 0 && SIZED_CATEGORIES.includes(category);
  if (needsSize) {
    openSizePickerModal(id, name, basePrice, img);
  } else {
    addToCart(id, name, basePrice, img, '', 0);
  }
}

// ── Size Picker Modal ───────────────────────────────────────────
let _spPending = null; // { id, name, basePrice, img }
let _spSelected = null;

function openSizePickerModal(id, name, basePrice, img) {
  _spPending  = { id, name, basePrice, img };
  _spSelected = null;

  document.getElementById('sizePickerName').textContent = name;
  document.getElementById('sizePickerBase').textContent = 'Base price: ₱' + parseFloat(basePrice).toFixed(2);

  const thumb = document.getElementById('sizePickerThumb');
  thumb.innerHTML = img
    ? `<img src="${img}" onerror="this.parentNode.innerHTML='☕'">`
    : '☕';

  const ozMap = { 'Tall': '12 oz', 'Grande': '16 oz', 'Venti': '20 oz' };

  const group = document.getElementById('sizeBtnGroup');
  group.innerHTML = CUP_SIZES.map(sz => {
    const total = parseFloat(basePrice) + parseFloat(sz.price_add);
    const priceLabel = sz.price_add > 0 ? `+₱${parseFloat(sz.price_add).toFixed(0)}` : 'Base';
    const cls = parseFloat(sz.price_add) === 0 ? 'free' : '';
    return `<button class="size-btn" data-size-id="${sz.size_id}" data-price-add="${sz.price_add}" data-cup-inv="${sz.inventory_id}" data-size-name="${sz.size_name}"
      onclick="selectSize(this, ${total})">
      <div class="size-btn-label">${sz.size_name}</div>
      <div class="size-btn-oz">${ozMap[sz.size_name] || ''}</div>
      <div class="size-btn-price ${cls}">₱${total.toFixed(2)}</div>
    </button>`;
  }).join('');

  // Auto-select first size
  const first = group.querySelector('.size-btn');
  if (first) first.click();

  document.getElementById('sizePickerModal').classList.add('show');
}

function selectSize(btn, totalPrice) {
  document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  _spSelected = {
    sizeName:  btn.dataset.sizeName,
    cupInvId:  parseInt(btn.dataset.cupInv) || 0,
    price:     totalPrice,
  };
}

function closeSizePickerModal() {
  document.getElementById('sizePickerModal').classList.remove('show');
  _spPending = _spSelected = null;
}

function confirmSizePicker() {
  if (!_spPending || !_spSelected) return;
  addToCart(_spPending.id, _spPending.name, _spSelected.price, _spPending.img, _spSelected.sizeName, _spSelected.cupInvId);
  closeSizePickerModal();
}

function addToCart(id, name, price, img, sizeName, cupInvId) {
  // Unique cart key includes size so Tall Latte and Grande Latte are separate
  const key = id + '::' + (sizeName || '');
  const existing = cart.find(i => i.key === key);
  if (existing) {
    existing.qty++;
  } else {
    cart.push({ key, id, name, size: sizeName || '', price: parseFloat(price), img, qty: 1, cupInvId: cupInvId || 0 });
  }
  renderCart();
}

function changeQty(key, delta) {
  const idx = cart.findIndex(i => i.key === key);
  if (idx === -1) return;
  cart[idx].qty += delta;
  if (cart[idx].qty <= 0) cart.splice(idx, 1);
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const emptyMsg  = document.getElementById('cartEmpty');

  if (cart.length === 0) {
    container.innerHTML = '';
    emptyMsg.style.display = 'flex';
    updateTotals(0);
    updateChargeBtn(false);
    return;
  }

  emptyMsg.style.display = 'none';
  container.innerHTML = cart.map(item => {
    const sub   = (item.price * item.qty).toFixed(2);
    const thumb = item.img
      ? `<img src="${item.img}" style="width:100%;height:100%;object-fit:cover;border-radius:10px" onerror="this.parentNode.innerHTML='☕'">`
      : '☕';
    const sizeTag = item.size ? `<span style="font-size:.7rem;background:rgba(59,130,192,.12);color:var(--caramel,#b8703f);border-radius:4px;padding:1px 6px;font-weight:700;margin-left:4px">${item.size}</span>` : '';
    // Escape for safe insertion into an HTML attribute (handles quotes, &, etc.)
    const safeKey = item.key.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    return `<div class="cart-item">
      <div class="cart-item-icon">${thumb}</div>
      <div class="cart-item-main">
        <div class="cart-item-top">
          <div class="cart-item-name">${item.name}${sizeTag}</div>
          <button class="cart-item-delete" title="Remove item" data-key="${safeKey}" data-action="remove">🗑</button>
        </div>
        <div class="cart-item-bottom">
          <span class="cart-item-price">₱${parseFloat(item.price).toFixed(2)} each</span>
          <div class="qty-control">
            <div class="qty-btn" data-key="${safeKey}" data-action="dec">−</div>
            <div class="qty-num">${item.qty}</div>
            <div class="qty-btn" data-key="${safeKey}" data-action="inc">+</div>
          </div>
          <div class="cart-item-total">₱${sub}</div>
        </div>
      </div>
    </div>`;
  }).join('');

  const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
  updateTotals(subtotal);
  updateChargeBtn(true);
  calcChange();
}

// ── Cart click delegation (handles delete + qty buttons safely) ─
document.getElementById('cartItems').addEventListener('click', function (e) {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const key = btn.dataset.key;
  const action = btn.dataset.action;
  if (action === 'remove') confirmRemoveItem(key);
  else if (action === 'inc') changeQty(key, 1);
  else if (action === 'dec') changeQty(key, -1);
});

// ── Remove Item (with confirmation) ────────────────────────────
function removeItem(key) {
  cart = cart.filter(i => i.key !== key);
  renderCart();
}

function confirmRemoveItem(key) {
  const item = cart.find(i => i.key === key);
  if (!item) return;
  openConfirmModal({
    icon: '🗑️',
    title: 'Remove item?',
    message: `Remove "${item.name}${item.size ? ' (' + item.size + ')' : ''}" (x${item.qty}) from this order?`,
    actionLabel: 'Yes, Remove',
    onConfirm: () => removeItem(key),
  });
}

// ── Generic Confirm Modal ───────────────────────────────────────
let _confirmCallback = null;

function openConfirmModal({ icon, title, message, actionLabel, onConfirm }) {
  document.getElementById('confirmIcon').textContent    = icon || '⚠️';
  document.getElementById('confirmTitle').textContent   = title || 'Are you sure?';
  document.getElementById('confirmMessage').textContent = message || '';
  document.getElementById('confirmActionBtn').textContent = actionLabel || 'Confirm';
  _confirmCallback = onConfirm;
  document.getElementById('confirmModal').classList.add('show');
}

function closeConfirmModal() {
  document.getElementById('confirmModal').classList.remove('show');
  _confirmCallback = null;
}

document.getElementById('confirmActionBtn').addEventListener('click', function () {
  if (typeof _confirmCallback === 'function') _confirmCallback();
  closeConfirmModal();
});

// ── Totals ──────────────────────────────────────────────────────
function updateTotals(subtotal) {
  const tax   = subtotal * TAX_RATE;
  const total = subtotal + tax;
  currentTotal = total;
  const n = cart.reduce((s, i) => s + i.qty, 0);
  document.getElementById('subtotalRow').innerHTML =
    `<span>Subtotal (${n} item${n !== 1 ? 's' : ''})</span><span>₱${subtotal.toFixed(2)}</span>`;
  document.getElementById('taxRow').innerHTML =
    `<span>Tax (3%)</span><span>₱${tax.toFixed(2)}</span>`;
  document.getElementById('totalRow').innerHTML =
    `<span>Total</span><span>₱${total.toFixed(2)}</span>`;
  renderQuickCash();
  if (selectedPayment !== 'cash') renderOnlinePaymentNote();
}

function updateChargeBtn(hasItems) {
  const btn = document.getElementById('btnCharge');
  const lbl = document.getElementById('btnChargeLabel');
  const sub = document.getElementById('btnChargeSub');
  if (!hasItems) {
    btn.disabled = true;
    lbl.textContent = 'No items in cart';
    sub.textContent = 'Add items to begin';
  } else {
    btn.disabled = false;
    lbl.textContent = `Charge ₱${currentTotal.toFixed(2)}`;
    sub.textContent = selectedPayment.charAt(0).toUpperCase() + selectedPayment.slice(1) + ' payment';
  }
}

// ── Cash Tendered ───────────────────────────────────────────────
function calcChange() {
  const disp = document.getElementById('changeDisplay');
  if (selectedPayment !== 'cash' || cart.length === 0) return;
  const tenderedInput = document.getElementById('tenderedInput');
  const tendered = parseFloat(tenderedInput.value) || 0;
  const change = tendered - currentTotal;

  if (tendered >= currentTotal) {
    tenderedInput.style.borderColor = '';
    tenderedInput.style.boxShadow   = '';
  }

  if (tendered === 0) {
    disp.className   = 'tendered-hint';
    disp.textContent = 'Enter amount received';
  } else if (change >= 0) {
    disp.className   = 'tendered-hint success';
    disp.textContent = `Change: ₱${change.toFixed(2)}`;
  } else {
    disp.className   = 'tendered-hint danger';
    disp.textContent = `Short by: ₱${Math.abs(change).toFixed(2)}`;
  }
}

// ── Quick Cash Buttons ──────────────────────────────────────────
function renderQuickCash() {
  const wrap = document.getElementById('quickCash');
  if (!wrap || currentTotal <= 0) { if (wrap) wrap.innerHTML = ''; return; }

  const denoms = [20, 50, 100, 200, 500, 1000];
  const suggestions = new Set();
  for (const d of denoms) {
    if (d >= currentTotal) { suggestions.add(d); break; }
  }
  suggestions.add(Math.ceil(currentTotal / 50) * 50);
  suggestions.add(Math.ceil(currentTotal / 100) * 100);
  const quick = [...suggestions].filter(v => v > 0 && v >= currentTotal).sort((a, b) => a - b).slice(0, 3);

  let html = `<div class="quick-cash-btn exact" onclick="setTendered(${currentTotal})">Exact</div>`;
  html += quick.map(v => `<div class="quick-cash-btn" onclick="setTendered(${v})">₱${v}</div>`).join('');
  wrap.innerHTML = html;
}

function setTendered(amount) {
  document.getElementById('tenderedInput').value = amount.toFixed(2);
  calcChange();
}

// ── Online / E-Wallet (demo QR) ─────────────────────────────────
function generateDummyQR(seed) {
  let s = seed;
  function rand() { s = (s * 9301 + 49297) % 233280; return s / 233280; }
  const grid = 14, cell = 132 / grid;
  let rects = '';
  for (let y = 0; y < grid; y++) {
    for (let x = 0; x < grid; x++) {
      const inCorner = (x < 3 && y < 3) || (x > grid - 4 && y < 3) || (x < 3 && y > grid - 4);
      const on = inCorner ? ((x + y) % 2 === 0 || x === 1 || y === 1) : rand() > 0.55;
      if (on) rects += `<rect x="${x * cell}" y="${y * cell}" width="${cell}" height="${cell}"/>`;
    }
  }
  return `<svg viewBox="0 0 132 132" fill="#161009" xmlns="http://www.w3.org/2000/svg"><rect width="132" height="132" fill="#fff"/>${rects}</svg>`;
}

const ONLINE_PROVIDERS = {
  gcash: { label: '📱 GCash', number: '0917 123 4567 — Cloud Cup', seed: 42, hasQr: true, note: 'Customer will scan a QR code to pay after you confirm the order.' },
};

function renderOnlinePaymentNote() {
  const row = document.getElementById('onlinePayNoteRow');
  const provider = ONLINE_PROVIDERS[selectedPayment];
  if (!provider) { row.classList.remove('show'); return; }
  document.getElementById('onlinePayNoteText').textContent = `${provider.label} — ${provider.note}`;
  row.classList.add('show');
}

function renderScanPayModal() {
  const provider = ONLINE_PROVIDERS[selectedPayment];
  if (!provider) return;
  document.getElementById('scanProvider').textContent   = provider.label;
  document.getElementById('scanPayNumber').textContent  = provider.number;
  document.getElementById('scanAmountDue').textContent  = `₱${currentTotal.toFixed(2)}`;
  const qrBox = document.getElementById('scanQrBox');
  if (provider.hasQr) {
    qrBox.style.display = 'block';
    qrBox.innerHTML = generateDummyQR(provider.seed + Math.round(currentTotal));
  } else {
    qrBox.style.display = 'none';
  }
}

function openScanPayModal()  { renderScanPayModal(); document.getElementById('scanPayModal').classList.add('show'); }
function closeScanPayModal() { document.getElementById('scanPayModal').classList.remove('show'); }
function confirmPaymentReceived() { closeScanPayModal(); submitOrder(); }

// ── Order Type Tabs ─────────────────────────────────────────────
function applyOrderType(type) {
  orderType = type;
  const tableInput = document.getElementById('tableNo');
  const queueBadge = document.getElementById('queueBadge');

  if (type === 'takeout') {
    tableInput.value        = QUEUE_NO;
    tableInput.style.display = 'none';
    queueBadge.style.display = 'flex';
    document.getElementById('queueNum').textContent = QUEUE_NO;
  } else {
    tableInput.style.display = '';
    queueBadge.style.display = 'none';
    if (type !== 'dine_in') tableInput.value = '';
  }
}

document.querySelectorAll('#orderTypeTabs .order-tab').forEach(tab => {
  tab.addEventListener('click', function () {
    document.querySelectorAll('#orderTypeTabs .order-tab').forEach(t => t.classList.remove('active'));
    this.classList.add('active');
    applyOrderType(this.dataset.type);
  });
});

// ── Payment Methods ─────────────────────────────────────────────
document.querySelectorAll('#paymentMethods .pay-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    selectedPayment = this.dataset.method;
    const cashRow = document.getElementById('tenderedRow');
    if (selectedPayment === 'cash') {
      cashRow.style.display = 'block';
      document.getElementById('onlinePayNoteRow').classList.remove('show');
      renderQuickCash();
    } else {
      cashRow.style.display = 'none';
      renderOnlinePaymentNote();
    }
    updateChargeBtn(cart.length > 0);
  });
});

// ── Checkout ────────────────────────────────────────────────────
function doCheckout() {
  if (cart.length === 0) return;

  if (selectedPayment === 'cash') {
    const tenderedInput = document.getElementById('tenderedInput');
    const tendered = parseFloat(tenderedInput.value) || 0;
    if (tendered < currentTotal) {
      const disp = document.getElementById('changeDisplay');
      disp.className   = 'tendered-hint danger';
      disp.textContent = tendered === 0
        ? `Please enter the cash amount received (Total: ₱${currentTotal.toFixed(2)})`
        : `Short by: ₱${(currentTotal - tendered).toFixed(2)}`;
      tenderedInput.style.borderColor = 'var(--danger)';
      tenderedInput.style.boxShadow   = '0 0 0 3px rgba(239,68,68,0.15)';
      tenderedInput.focus();
      tenderedInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    tenderedInput.style.borderColor = '';
    tenderedInput.style.boxShadow   = '';
  }
  openOrderConfirmModal();
}

// ── Order Confirmation Modal ────────────────────────────────────
function openOrderConfirmModal() {
  document.getElementById('orderConfirmList').innerHTML = cart.map(item => {
    const label = item.size ? `${item.name} <span style="font-size:.72rem;background:rgba(59,130,192,.12);color:#b8703f;border-radius:4px;padding:1px 5px;font-weight:700">${item.size}</span> ×${item.qty}` : `${item.name} ×${item.qty}`;
    return `<div class="order-confirm-item"><span>${label}</span><span>₱${(item.price * item.qty).toFixed(2)}</span></div>`;
  }).join('');

  const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const tax      = subtotal * TAX_RATE;
  const payLabel = { cash:'Cash', card:'Card', gcash:'GCash', maya:'Maya' }[selectedPayment] || selectedPayment;
  const tendered = selectedPayment === 'cash'
    ? (parseFloat(document.getElementById('tenderedInput').value) || currentTotal)
    : currentTotal;

  let summaryHtml = `
    <div class="row"><span>Subtotal</span><span>₱${subtotal.toFixed(2)}</span></div>
    <div class="row"><span>Tax (3%)</span><span>₱${tax.toFixed(2)}</span></div>
    <div class="row grand"><span>Total</span><span>₱${currentTotal.toFixed(2)}</span></div>
    <div class="row"><span>Payment</span><span>${payLabel}</span></div>`;
  if (selectedPayment === 'cash') {
    summaryHtml += `
    <div class="row"><span>Cash Tendered</span><span>₱${tendered.toFixed(2)}</span></div>
    <div class="row"><span>Change</span><span>₱${Math.max(0, tendered - currentTotal).toFixed(2)}</span></div>`;
  }
  document.getElementById('orderConfirmSummary').innerHTML = summaryHtml;
  document.getElementById('orderConfirmModal').classList.add('show');
}

function closeOrderConfirmModal() { document.getElementById('orderConfirmModal').classList.remove('show'); }

function handleOrderConfirmed() {
  closeOrderConfirmModal();
  if (selectedPayment === 'cash') {
    submitOrder();
  } else {
    openScanPayModal();
  }
}

function submitOrder() {
  const btn = document.getElementById('btnCharge');
  btn.disabled = true;
  document.getElementById('btnChargeLabel').textContent = 'Processing…';

  const tendered = selectedPayment === 'cash'
    ? parseFloat(document.getElementById('tenderedInput').value) || currentTotal
    : currentTotal;

  const fd = new FormData();
  fd.append('action',          'checkout');
  fd.append('items',           JSON.stringify(cart.map(i => ({
    id:         i.id,
    name:       i.name,
    size:       i.size       || '',
    cup_inv_id: i.cupInvId   || 0,
    qty:        i.qty,
    price:      i.price,
  }))));
  fd.append('payment_method',  selectedPayment);
  fd.append('total_amount',    currentTotal.toFixed(2));
  fd.append('amount_tendered', tendered.toFixed(2));
  fd.append('order_type',      orderType);
  fd.append('customer_name',   document.getElementById('customerName').value);
  fd.append('table_no',        document.getElementById('tableNo').value);
  fd.append('notes',           '');

  fetch('Sales_Processing_Page.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (data.deduct_debug) console.log('Cup deduction debug:', data.deduct_debug);
        if (data.warning) console.warn(data.warning);
        showReceiptModal(data);
      } else {
        alert('Error: ' + (data.message || 'Unknown error'));
        btn.disabled = false;
        document.getElementById('btnChargeLabel').textContent = `Charge ₱${currentTotal.toFixed(2)}`;
      }
    })
    .catch(() => {
      alert('Network error. Please try again.');
      btn.disabled = false;
      document.getElementById('btnChargeLabel').textContent = `Charge ₱${currentTotal.toFixed(2)}`;
    });
}

// ── Receipt Modal ───────────────────────────────────────────────
function showReceiptModal(data) {
  document.getElementById('receiptModal').classList.add('show');
  document.getElementById('printerWrap').style.display    = 'flex';
  document.getElementById('receiptContent').style.display = 'none';
  document.getElementById('modalActions').style.display   = 'none';

  setTimeout(() => {
    document.getElementById('printerWrap').style.display    = 'none';
    buildReceipt(data);
    document.getElementById('receiptContent').style.display = 'block';
    document.getElementById('modalActions').style.display   = 'flex';
  }, 2200);
}

function buildReceipt(data) {
  const now      = new Date();
  const dateStr  = now.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
  const timeStr  = now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });
  const orderId  = '#ORD-' + String(data.order_id).padStart(4, '0');
  const payLabel  = { cash:'Cash 💵', card:'Card 💳', gcash:'GCash 📱', maya:'Maya 💜' }[data.payment_method] || data.payment_method;
  const typeLabel = { dine_in:'🍽️ Dine In', takeout:'🥡 Takeout', delivery:'🛵 Delivery' }[data.order_type] || data.order_type;

  document.getElementById('receiptOrderType').textContent = typeLabel;

  const tableBadgeEl = document.getElementById('receiptTableBadge');
  if (data.table_no) {
    tableBadgeEl.style.display = 'flex';
    document.getElementById('receiptTableNum').textContent = data.table_no;
    const lblEl = tableBadgeEl.querySelector('.tbl-label');
    if (lblEl) lblEl.textContent = data.order_type === 'takeout' ? 'Queue No.' : 'Table No.';
    const bannerNameEl = document.getElementById('receiptBannerName');
    if (bannerNameEl) {
      bannerNameEl.style.display = data.customer_name ? 'block' : 'none';
      bannerNameEl.textContent   = data.customer_name;
    }
  } else {
    tableBadgeEl.style.display = 'none';
  }

  const showCustomerInMeta = data.customer_name && !data.table_no;
  document.getElementById('receiptMeta').innerHTML = `
    <span><span>Order No.</span><strong>${orderId}</strong></span>
    <span><span>Date</span><span>${dateStr}</span></span>
    <span><span>Time</span><span>${timeStr}</span></span>
    <span><span>Cashier</span><span>${data.cashier}</span></span>
    ${showCustomerInMeta ? `<span><span>Customer</span><span>${data.customer_name}</span></span>` : ''}
    <span><span>Payment</span><span>${payLabel}</span></span>`;

  const subtotal = data.items.reduce((s, i) => s + i.price * i.qty, 0);
  const tax      = subtotal * TAX_RATE;
  const total    = subtotal + tax;
  const change   = parseFloat(data.change_due) || 0;

  document.getElementById('receiptItems').innerHTML = data.items.map(i =>
    `<tr><td>${i.name}${i.size ? ` <span style="font-size:9.5px;color:#b8703f;font-weight:700">(${i.size})</span>` : ''}</td><td>${i.qty}</td><td>₱${(i.price * i.qty).toFixed(2)}</td></tr>`
  ).join('');

  document.getElementById('receiptTotals').innerHTML = `
    <div class="receipt-total-row"><span>Subtotal</span><span>₱${subtotal.toFixed(2)}</span></div>
    <div class="receipt-total-row"><span>Tax (3%)</span><span>₱${tax.toFixed(2)}</span></div>
    <div class="receipt-total-row grand"><span>TOTAL</span><span>₱${total.toFixed(2)}</span></div>
    ${data.payment_method === 'cash' ? `
    <div class="receipt-total-row"><span>Cash Tendered</span><span>₱${(total + change).toFixed(2)}</span></div>
    <div class="receipt-total-row change"><span>Change</span><span>₱${change.toFixed(2)}</span></div>` : ''}`;
}

function printReceipt() {
  const content = document.getElementById('receiptContent').innerHTML;
  const w = window.open('', '_blank', 'width=400,height=600');
  w.document.write(`<!DOCTYPE html><html><head>
    <title>Receipt</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
    <style>
      body{font-family:'Inter',sans-serif;padding:20px;max-width:320px;margin:auto}
      .receipt-logo{font-family:'Fraunces',serif;font-size:24px;font-weight:700;color:#161009;text-align:center}
      .receipt-logo span{color:#b8703f}
      .receipt-tagline{text-align:center;font-size:11px;color:#2f6690;margin-top:2px}
      hr,.receipt-divider{border:none;border-top:1px dashed #ccc;margin:10px 0}
      table{width:100%;border-collapse:collapse;font-size:12px}
      th{text-align:left;font-size:9.5px;color:#999;text-transform:uppercase;letter-spacing:.7px;padding-bottom:6px;border-bottom:1px solid #eee}
      th:nth-child(2){text-align:center} th:last-child{text-align:right}
      td{padding:5px 0;vertical-align:top;border-bottom:1px solid #f3f3f3}
      td:nth-child(2){text-align:center;color:#999} td:last-child{text-align:right;font-weight:700}
      .receipt-order-type-wrap{text-align:center;margin:8px 0 4px}
      .receipt-order-type{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#b8703f;border:1px solid #b8703f;padding:3px 10px;border-radius:50px}
      .receipt-table-badge{background:#161009;color:#fff;border-radius:8px;padding:10px 14px;margin:8px 0;display:flex;align-items:center}
      .receipt-table-badge>div{flex:1;text-align:center}
      .tbl-divider{border-left:1px solid rgba(255,255,255,0.12);padding-left:14px}
      .tbl-label{font-size:9px;letter-spacing:1.2px;text-transform:uppercase;opacity:.5;margin-bottom:3px}
      .tbl-num{font-family:'Fraunces',serif;font-size:20px;font-weight:700;color:#c98a5c;line-height:1}
      .tbl-customer{font-size:12px;font-weight:600;color:#fff}
      .receipt-meta span{display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:3.5px}
      .receipt-meta span>span:first-child{font-weight:600;color:#335270}
      .receipt-meta span>strong{color:#b8703f;font-size:12px}
      .receipt-total-row{display:flex;justify-content:space-between;font-size:12px;color:#666;padding:3.5px 0}
      .receipt-total-row span:last-child{font-weight:500;color:#161009}
      .receipt-total-row.grand{font-size:15px;font-weight:800;color:#000;border-top:2px solid #eee;padding-top:8px;margin-top:5px}
      .receipt-total-row.grand span:last-child{color:#b8703f;font-size:16px}
      .receipt-total-row.change{color:#2f6f4e;font-weight:700}
      .receipt-total-row.change span:last-child{color:#2f6f4e}
      .receipt-footer{text-align:center;margin-top:14px;padding-top:12px;border-top:1px dashed #ccc;font-size:11px;color:#999;line-height:1.6}
      .receipt-footer strong{display:block;font-size:13px;color:#333;margin-bottom:3px}
      .powered{font-size:9.5px;color:#bbb;margin-top:5px}
      .success-badge{display:none}
    </style>
  </head><body>${content}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => { w.print(); w.close(); }, 600);
}

function closeReceipt() {
  document.getElementById('receiptModal').classList.remove('show');
  cart = [];
  renderCart();
  document.getElementById('customerName').value             = '';
  document.getElementById('tableNo').value                  = '';
  document.getElementById('tenderedInput').value            = '';
  document.getElementById('tenderedInput').style.borderColor = '';
  document.getElementById('tenderedInput').style.boxShadow  = '';
  document.getElementById('changeDisplay').className        = 'tendered-hint';
  document.getElementById('changeDisplay').textContent      = 'Enter amount received';
  location.reload();
}

// ── Logout confirmation (SweetAlert2) ────────────────────────────
// SweetAlert2 is loaded via CDN in the page; guard with a null check
// since this script may load before that library resolves.
const _logoutBtn = document.getElementById('staff-logout-btn');
if (_logoutBtn) {
  _logoutBtn.addEventListener('click', function (e) {
    e.preventDefault();
    const logoutUrl = this.getAttribute('href');
    Swal.fire({
      title: 'Log out?',
      text: "You'll be taken back to the role selection screen.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Log out',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#b8703f',
      cancelButtonColor: '#6b6156',
      reverseButtons: true
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = logoutUrl;
      }
    });
  });
}

// ── Live clock (bottom of screen) ────────────────────────────────
function updatePosClock() {
  const el = document.getElementById('posClock');
  if (!el) return;
  const now     = new Date();
  const dateStr = now.toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
  const timeStr = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit'});
  el.textContent = `${dateStr}  •  ${timeStr}`;
}
updatePosClock();
setInterval(updatePosClock, 1000);