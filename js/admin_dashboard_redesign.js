(function () {
  var DATA = window.CC_DASHBOARD && window.CC_DASHBOARD.periods;
  if (!DATA) return; // data failed to render (e.g. no DB connection) — page still works without JS enhancement

  var fmt      = function (n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var fmtShort = function (n) { return '₱' + Math.round(n).toLocaleString('en-PH'); };
  var fmtInt   = function (n) { return Number(n).toLocaleString('en-PH'); };

  var currentPeriod = 'today';

  function animateValue(el, start, end, duration) {
    var startTime = performance.now();
    function step(now) {
      var progress = Math.min((now - startTime) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var value = start + (end - start) * eased;
      el.textContent = fmtInt(Math.round(value));
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function renderChart(container, tooltipEl, wrapEl, highlightEl, values, labels) {
    container.innerHTML = '';
    var maxVal = Math.max.apply(null, values.concat([1]));
    var dense = values.length > 15;
    container.classList.toggle('dense', dense);

    values.forEach(function (v, i) {
      var col = document.createElement('div');
      col.className = 'cc-bar-col';
      var bar = document.createElement('div');
      bar.className = 'cc-bar' + (v === maxVal && v > 0 ? ' peak' : '');
      bar.style.height = '4%';
      bar.addEventListener('mouseenter', function () {
        var colRect = col.getBoundingClientRect();
        var barRect = bar.getBoundingClientRect();
        var chartRect = container.getBoundingClientRect();
        var wrapRect = wrapEl.getBoundingClientRect();
        var gapPx = parseFloat(getComputedStyle(container).gap) || 0;

        var wasShowing = tooltipEl.classList.contains('show');
        if (!wasShowing) {
          tooltipEl.classList.add('snap');
          highlightEl.classList.add('snap');
        }

        tooltipEl.innerHTML = '<div class="cc-tt-label">' + labels[i] + '</div><div class="cc-tt-value">' + fmtShort(v) + '</div>';
        tooltipEl.style.left = (barRect.left - wrapRect.left + barRect.width / 2) + 'px';
        tooltipEl.style.top = (barRect.top - wrapRect.top) + 'px';

        highlightEl.style.left = (colRect.left - wrapRect.left - gapPx / 2) + 'px';
        highlightEl.style.width = (colRect.width + gapPx) + 'px';
        highlightEl.style.top = (chartRect.top - wrapRect.top) + 'px';
        highlightEl.style.height = chartRect.height + 'px';

        if (!wasShowing) {
          void tooltipEl.offsetWidth;
          void highlightEl.offsetWidth;
          tooltipEl.classList.remove('snap');
          highlightEl.classList.remove('snap');
        }
        tooltipEl.classList.add('show');
        highlightEl.classList.add('show');
      });
      var day = document.createElement('div');
      day.className = 'cc-bar-day';
      day.textContent = (!dense || i % 3 === 0) ? labels[i] : '';
      col.appendChild(bar);
      col.appendChild(day);
      container.appendChild(col);
    });

    container.onmouseleave = function () {
      tooltipEl.classList.remove('show');
      highlightEl.classList.remove('show');
    };
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        Array.prototype.forEach.call(container.children, function (col, i) {
          col.firstChild.style.height = Math.max(4, (values[i] / maxVal * 100)) + '%';
        });
      });
    });
  }

  function renderTop(container, item) {
    if (!item) {
      container.innerHTML = '<div class="cc-spotlight-empty">No sales recorded for this period yet.</div>';
      return;
    }
    container.innerHTML =
      '<div class="cc-spotlight-main">' +
        '<div class="cc-spotlight-cup">☕</div>' +
        '<div>' +
          '<div class="cc-spotlight-name">' + item.name + '</div>' +
          '<div class="cc-spotlight-sold">' + fmtInt(item.sold) + ' units sold · ' + fmt(item.revenue) + ' revenue</div>' +
        '</div>' +
      '</div>' +
      '<div class="cc-spotlight-badge">★ #1 Best Seller</div>';
  }

  function renderOrders(tbody, orders) {
    if (!orders.length) {
      tbody.innerHTML = '<tr class="cc-empty-row"><td colspan="6">No orders in this period yet.</td></tr>';
      return;
    }
    tbody.innerHTML = orders.map(function (o) {
      return '<tr>' +
        '<td class="cc-order-id">' + o.id + '</td>' +
        '<td>' + o.cashier + '</td>' +
        '<td>' + o.item + '</td>' +
        '<td>' + o.method + '</td>' +
        '<td class="cc-amount">' + fmt(o.amount) + '</td>' +
        '<td><span class="cc-status ' + o.statusClass + '">' + o.status + '</span></td>' +
        '</tr>';
    }).join('');
  }

  function apply(period, animateNumbers) {
    var d = DATA[period];
    if (!d) return;
    var fadeEls = document.querySelectorAll('.cc-fade-swap');
    fadeEls.forEach(function (el) { el.classList.add('fading'); });

    setTimeout(function () {
      document.getElementById('ccPeriodLabel').textContent = d.label;
      document.getElementById('ccRevPeriodTag').textContent = d.label;
      document.getElementById('ccTopPeriodTag').textContent = d.label;
      document.getElementById('ccOrdersPeriodTag').textContent = d.label;

      var revIntEl = document.getElementById('ccRevenueInt');
      var prevRevenue = parseInt((revIntEl.textContent || '0').replace(/[^0-9]/g, '')) || 0;
      if (animateNumbers) animateValue(revIntEl, prevRevenue, Math.round(d.revenue), 700);
      else revIntEl.textContent = fmtInt(Math.round(d.revenue));

      var changeEl = document.getElementById('ccRevenueChange');
      changeEl.textContent = (d.changeUp ? '▲ ' : '▼ ') + d.change + '% vs previous period';
      changeEl.classList.toggle('down', !d.changeUp);

      renderChart(
        document.getElementById('ccRevenueChart'),
        document.getElementById('ccChartTooltip'),
        document.getElementById('ccChartWrap'),
        document.getElementById('ccChartHighlight'),
        d.chartValues, d.chartLabels
      );
      renderTop(document.getElementById('ccTopSection'), d.top);

      var ordersCountEl = document.getElementById('ccOrdersCount');
      var prevOrders = parseInt((ordersCountEl.textContent || '0').replace(/[^0-9]/g, '')) || 0;
      if (animateNumbers) animateValue(ordersCountEl, prevOrders, d.ordersCount, 700);
      else ordersCountEl.textContent = fmtInt(d.ordersCount);

      renderOrders(document.getElementById('ccOrdersTableBody'), d.orders);

      fadeEls.forEach(function (el) { el.classList.remove('fading'); });
    }, 160);
  }

  function wireDropdown(id, btnId) {
    var dd = document.getElementById(id);
    var btn = document.getElementById(btnId);
    if (!dd || !btn) return;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = !dd.classList.contains('open');
      document.querySelectorAll('.cc-dropdown.open').forEach(function (o) { o.classList.remove('open'); });
      if (willOpen) dd.classList.add('open');
    });
  }

  wireDropdown('ccPeriodDropdown', 'ccPeriodBtn');
  wireDropdown('ccActivityDropdown', 'ccActivityBtn');

  document.addEventListener('click', function () {
    document.querySelectorAll('.cc-dropdown.open').forEach(function (o) { o.classList.remove('open'); });
  });

  document.querySelectorAll('.cc-dd-option').forEach(function (opt) {
    opt.addEventListener('click', function (e) {
      e.stopPropagation();
      var period = opt.getAttribute('data-period');
      if (period === currentPeriod) return;
      document.querySelectorAll('.cc-dd-option').forEach(function (o) { o.classList.remove('selected'); });
      opt.classList.add('selected');
      currentPeriod = period;
      apply(period, true);
      document.getElementById('ccPeriodDropdown').classList.remove('open');
    });
  });

  apply('today', false);
})();
