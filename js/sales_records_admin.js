  function toggleOrderItems(row) {
    const detail = row.nextElementSibling;
    if (!detail || !detail.classList.contains('items-detail-row')) return;
    detail.style.display = detail.style.display === 'none' ? 'table-row' : 'none';
    row.classList.toggle('expanded');
  }

  // ---------- Button ripple effect (Filter / Clear / pagination) ----------
  document.querySelectorAll('.btn-filter, .btn-clear, .page-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      const rect = this.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const ripple = document.createElement('span');
      ripple.className = 'ripple';
      ripple.style.width = ripple.style.height = size + 'px';
      ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
      ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
      this.appendChild(ripple);
      setTimeout(function () { ripple.remove(); }, 600);
    });
  });

  // ---------- Custom Payment dropdown ----------
  (function () {
    const box = document.getElementById('paymentBox');
    const pop = document.getElementById('paymentPop');
    if (!box || !pop) return;
    const valueEl = document.getElementById('paymentValue');
    const dotEl = document.getElementById('paymentDot');
    const input = document.getElementById('methodInput');
    const opts = pop.querySelectorAll('.select-opt');

    opts.forEach(o => o.style.setProperty('--dot-color', o.dataset.dot));
    const active = pop.querySelector('.select-opt.active');
    if (active) dotEl.style.background = active.dataset.dot;

    function open() { pop.classList.add('open'); box.classList.add('open'); }
    function close() { pop.classList.remove('open'); box.classList.remove('open'); }

    box.addEventListener('click', function (e) {
      pop.classList.contains('open') ? close() : open();
      e.stopPropagation();
    });
    document.addEventListener('click', function (e) {
      if (!pop.contains(e.target) && !box.contains(e.target)) close();
    });
    opts.forEach(function (o) {
      o.addEventListener('click', function () {
        opts.forEach(x => x.classList.remove('active'));
        o.classList.add('active');
        valueEl.textContent = o.dataset.value === '' ? 'All' : o.textContent;
        dotEl.style.background = o.dataset.dot;
        input.value = o.dataset.value;
        close();
      });
    });
  })();

  // ---------- Custom From–To calendar range picker ----------
  (function () {
    const box = document.getElementById('rangeToggle');
    const pop = document.getElementById('calPop');
    if (!box || !pop) return;
    const fromDisplay = document.getElementById('fromDisplay');
    const toDisplay = document.getElementById('toDisplay');
    const titleEl = document.getElementById('calTitle');
    const gridEl = document.getElementById('calGrid');
    const hintEl = document.getElementById('calHint');
    const clearLink = document.getElementById('calClear');
    const fromInput = document.getElementById('dateFromInput');
    const toInput = document.getElementById('dateToInput');

    const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];

    function parseISO(s) {
      if (!s) return null;
      const parts = s.split('-').map(Number);
      if (parts.length !== 3 || parts.some(isNaN)) return null;
      return new Date(parts[0], parts[1] - 1, parts[2]);
    }
    function toISO(d) {
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const dd = String(d.getDate()).padStart(2, '0');
      return `${d.getFullYear()}-${mm}-${dd}`;
    }
    function fmt(d) {
      if (!d) return null;
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const dd = String(d.getDate()).padStart(2, '0');
      return `${mm}/${dd}/${d.getFullYear()}`;
    }
    function sameDay(a, b) {
      return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    let range = { from: parseISO(fromInput.value), to: parseISO(toInput.value) };
    let picking = range.from && !range.to ? 'to' : 'from';
    let view = range.from ? new Date(range.from.getFullYear(), range.from.getMonth(), 1) : new Date();
    view.setDate(1);

    function render() {
      titleEl.textContent = `${monthNames[view.getMonth()]} ${view.getFullYear()}`;
      gridEl.innerHTML = '';
      const first = new Date(view.getFullYear(), view.getMonth(), 1);
      const startOffset = first.getDay();
      const daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      const daysInPrev = new Date(view.getFullYear(), view.getMonth(), 0).getDate();
      const today = new Date();

      const cells = [];
      for (let i = startOffset - 1; i >= 0; i--) cells.push({ day: daysInPrev - i, muted: true, date: new Date(view.getFullYear(), view.getMonth() - 1, daysInPrev - i) });
      for (let d = 1; d <= daysInMonth; d++) cells.push({ day: d, muted: false, date: new Date(view.getFullYear(), view.getMonth(), d) });
      let next = 1;
      while (cells.length % 7 !== 0) { cells.push({ day: next, muted: true, date: new Date(view.getFullYear(), view.getMonth() + 1, next) }); next++; }

      cells.forEach(function (c) {
        const el = document.createElement('div');
        el.className = 'cal-day' + (c.muted ? ' muted' : '');
        el.textContent = c.day;

        if (sameDay(c.date, today)) el.classList.add('today');
        if (range.from && sameDay(c.date, range.from)) el.classList.add('selected', 'range-start');
        if (range.to && sameDay(c.date, range.to)) el.classList.add('selected', 'range-end');
        if (range.from && range.to && c.date > range.from && c.date < range.to) el.classList.add('in-range');

        el.addEventListener('click', function () {
          if (picking === 'from' || (range.from && range.to)) {
            range = { from: c.date, to: null };
            picking = 'to';
            hintEl.textContent = 'Pick an end date';
          } else {
            if (c.date < range.from) { range = { from: c.date, to: range.from }; }
            else { range.to = c.date; }
            picking = 'from';
            hintEl.textContent = 'Range selected';
          }
          fromDisplay.textContent = fmt(range.from) || 'mm/dd/yyyy';
          fromDisplay.classList.toggle('placeholder', !range.from);
          toDisplay.textContent = fmt(range.to) || 'mm/dd/yyyy';
          toDisplay.classList.toggle('placeholder', !range.to);
          fromDisplay.classList.toggle('picking', picking === 'from' && !!range.from && !range.to);
          toDisplay.classList.toggle('picking', picking === 'to');
          fromInput.value = range.from ? toISO(range.from) : '';
          toInput.value = range.to ? toISO(range.to) : '';
          render();
        });
        gridEl.appendChild(el);
      });
    }

    function open() { pop.classList.add('open'); box.classList.add('open'); render(); }
    function close() { pop.classList.remove('open'); box.classList.remove('open'); }

    box.addEventListener('click', function (e) {
      pop.classList.contains('open') ? close() : open();
      e.stopPropagation();
    });
    document.addEventListener('click', function (e) {
      if (!pop.contains(e.target) && !box.contains(e.target)) close();
    });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });

    document.getElementById('calPrev').addEventListener('click', function () { view.setMonth(view.getMonth() - 1); render(); });
    document.getElementById('calNext').addEventListener('click', function () { view.setMonth(view.getMonth() + 1); render(); });

    clearLink.addEventListener('click', function () {
      range = { from: null, to: null };
      picking = 'from';
      fromDisplay.textContent = 'mm/dd/yyyy'; fromDisplay.classList.add('placeholder');
      toDisplay.textContent = 'mm/dd/yyyy'; toDisplay.classList.add('placeholder');
      fromInput.value = ''; toInput.value = '';
      hintEl.textContent = 'Pick a start date';
      render();
    });
  })();
  
