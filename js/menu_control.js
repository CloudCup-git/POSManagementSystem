function filterMenuCat(cat, btn) {
  document.querySelectorAll('.menu-cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  // Only filter the "available" grid — the unavailable section stays visible
  // as-is so staff can always find and re-enable hidden items regardless of tab.
  document.querySelectorAll('#menuGrid .menu-item-card').forEach(card => {
    card.style.display = (cat === 'all' || card.dataset.cat === cat) ? '' : 'none';
  });
}

function checkDuplicateName() {
  const nameInput  = document.getElementById('mm_name');
  const warnText   = document.getElementById('mm_name_warn');
  const submitBtn  = document.getElementById('menuModalSubmit');
  const editingId  = parseInt(document.getElementById('menuModalItemId').value || '0', 10);

  const typed = nameInput.value.trim().toLowerCase();
  const isDup = typed !== '' && EXISTING_MENU_ITEMS.some(
    item => item.name.trim().toLowerCase() === typed && item.id !== editingId
  );

  nameInput.classList.toggle('dup-warn', isDup);
  warnText.classList.toggle('show', isDup);
  submitBtn.disabled = isDup;
  return !isDup;
}

document.getElementById('mm_name').addEventListener('input', checkDuplicateName);

document.getElementById('menuModalForm').addEventListener('submit', function (e) {
  if (!checkDuplicateName()) e.preventDefault();
});

// ── Ingredients rows ────────────────────────────────────────────
// Each row = one recipe_ingredients entry: an inventory item picker
// (shows its unit right in the label, e.g. "Whole Milk (L)") plus a qty
// input for how much of that unit is used per 1 serving sold.
function addIngredientRow(selectedInvId, qty) {
  const list = document.getElementById('mm_ingredients_list');
  const row = document.createElement('div');
  row.className = 'ingredient-row';
  row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';

  const options = INVENTORY_ITEMS.map(inv =>
    `<option value="${inv.id}" data-unit="${inv.unit}" ${inv.id === selectedInvId ? 'selected' : ''}>${inv.name} (${inv.unit})</option>`
  ).join('');

  row.innerHTML = `
    <select name="ing_inventory_id[]" class="ing-select" style="flex:2">
      <option value="">— Select ingredient —</option>
      ${options}
    </select>
    <input type="number" name="ing_qty[]" class="ing-qty" style="flex:1" step="0.001" min="0"
           placeholder="Qty" value="${qty ?? ''}">
    <span class="ing-unit-label" style="width:48px;font-size:12px;color:var(--text-light,#888)"></span>
    <button type="button" class="modal-btn-ghost-admin ing-remove-btn" style="padding:4px 10px">✕</button>
  `;

  const select   = row.querySelector('.ing-select');
  const unitSpan = row.querySelector('.ing-unit-label');
  const syncUnit = () => {
    const opt = select.options[select.selectedIndex];
    unitSpan.textContent = opt && opt.dataset.unit ? opt.dataset.unit : '';
  };
  select.addEventListener('change', syncUnit);
  syncUnit();

  row.querySelector('.ing-remove-btn').addEventListener('click', () => row.remove());

  list.appendChild(row);
}

function clearIngredientRows() {
  document.getElementById('mm_ingredients_list').innerHTML = '';
}

document.getElementById('mm_add_ingredient_btn').addEventListener('click', () => addIngredientRow());

// ── Menu Item Modal ─────────────────────────────────────────────
function openMenuModal(item) {
  const modal       = document.getElementById('menuModal');
  const previewWrap = document.getElementById('mm_image_preview_wrap');
  const previewImg  = document.getElementById('mm_image_preview');

  if (item) {
    document.getElementById('menuModalTitle').textContent   = 'Edit Menu Item';
    document.getElementById('menuModalAct').value           = 'edit_item';
    document.getElementById('menuModalItemId').value        = item.item_id;
    document.getElementById('mm_name').value                = item.item_name;
    document.getElementById('mm_category').value            = item.category;
    document.getElementById('mm_price').value               = item.price;
    document.getElementById('mm_image_current').value       = item.image_path || '';
    document.getElementById('mm_image_file').value          = '';
    document.getElementById('mm_available').checked         = item.is_available == 1;
    document.getElementById('menuModalSubmit').textContent  = 'Save Changes';

    if (item.image_path) {
      previewImg.src = item.image_path;
      previewWrap.style.display = 'block';
    } else {
      previewWrap.style.display = 'none';
    }

    clearIngredientRows();
    (item.ingredients || []).forEach(ing => addIngredientRow(ing.inventory_id, ing.qty_per_unit));
  } else {
    document.getElementById('menuModalTitle').textContent   = 'Add Menu Item';
    document.getElementById('menuModalAct').value           = 'add_item';
    document.getElementById('menuModalItemId').value        = '';
    document.getElementById('menuModalForm').reset();
    document.getElementById('mm_image_current').value       = '';
    document.getElementById('mm_available').checked = true;
    document.getElementById('menuModalSubmit').textContent  = 'Add Item';
    previewWrap.style.display = 'none';
    clearIngredientRows();
  }
  checkDuplicateName();
  modal.classList.add('open');
}

// ── Edit button wiring ──────────────────────────────────────────
// Previously each card rendered onclick="openMenuModal(<?= json_encode($mi) ?>)"
// directly in the HTML. If an item's name/category contained a quote,
// ampersand, or other special character, the raw JSON dropped inline into
// the onclick="" attribute could malform the attribute/button markup and
// silently break the click handler (or emit invalid JS depending on PHP's
// htmlspecialchars() quote settings), leaving Edit unresponsive.
//
// Using data-item (a single, safely-escaped attribute) + one delegated
// listener avoids embedding raw JSON in inline JS entirely, so this can't
// happen regardless of what characters are in the item data. It also means
// new cards (available or unavailable) work automatically without needing
// their own inline handler.
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.menu-btn-edit');
  if (!btn) return;
  try {
    const item = JSON.parse(btn.dataset.item);
    openMenuModal(item);
  } catch (err) {
    console.error('Could not read menu item data for editing:', err);
  }
});

// Live preview of the selected photo before upload
document.getElementById('mm_image_file').addEventListener('change', function (e) {
  const file        = e.target.files[0];
  const previewWrap = document.getElementById('mm_image_preview_wrap');
  const previewImg  = document.getElementById('mm_image_preview');
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function (ev) {
    previewImg.src = ev.target.result;
    previewWrap.style.display = 'block';
  };
  reader.readAsDataURL(file);
});

function closeMenuModal() {
  document.getElementById('menuModal').classList.remove('open');
}

// Auto-dismiss flash message
const flashMsg = document.querySelector('.menu-msg');
if (flashMsg) setTimeout(() => flashMsg.style.display = 'none', 4000);