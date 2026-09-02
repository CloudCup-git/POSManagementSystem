document.addEventListener('DOMContentLoaded', function () {
  const data = window.budgetingData || {};
  const palette = ['#3a2d1e', '#b8703f', '#c98a5c', '#b8703f', '#E8A87C', '#6B8F71', '#C97064', '#2f6690'];

  /* ---------- Forecast chart: actual vs projected revenue ---------- */
  if (data.forecast) {
    const ctx = document.getElementById('forecastChart');
    if (ctx) {
      const actualPoints    = data.forecast.revenue.map((v, i) => data.forecast.actual[i] ? v : null);
      const projectedPoints = data.forecast.revenue.map((v, i) => data.forecast.actual[i] ? null : v);

      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.forecast.labels,
          datasets: [
            { label: 'Actual', data: actualPoints, backgroundColor: '#3a2d1e', borderRadius: 4 },
            { label: 'Projected', data: projectedPoints, backgroundColor: 'rgba(157,196,224,0.6)', borderRadius: 4 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } },
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  }

  /* ---------- Allocation donut: actual spend by category ---------- */
  if (data.allocation) {
    const ctx = document.getElementById('allocationChart');
    if (ctx) {
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: data.allocation.labels,
          datasets: [{ data: data.allocation.data, backgroundColor: palette, borderWidth: 0 }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
            tooltip: { callbacks: { label: c => c.label + ': ₱' + c.raw.toLocaleString(undefined, {minimumFractionDigits:2}) } }
          }
        }
      });
    }
  }

  /* ---------- Budget vs Actual per category (grouped bars) ---------- */
  if (data.budgetVsActual) {
    const ctx = document.getElementById('budgetVsActualChart');
    if (ctx) {
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.budgetVsActual.labels,
          datasets: [
            { label: 'Budgeted', data: data.budgetVsActual.budget, backgroundColor: 'rgba(157,196,224,0.7)', borderRadius: 4 },
            { label: 'Actual', data: data.budgetVsActual.actual, backgroundColor: '#3a2d1e', borderRadius: 4 }
          ]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } },
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  }

  /* ---------- Monthly Budget vs Actual trend (line) ---------- */
  if (data.monthlyTrend) {
    const ctx = document.getElementById('monthlyTrendChart');
    if (ctx) {
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.monthlyTrend.labels,
          datasets: [
            { label: 'Budgeted', data: data.monthlyTrend.budget, borderColor: '#c98a5c', backgroundColor: 'rgba(157,196,224,0.15)', tension: 0.3, fill: true },
            { label: 'Actual', data: data.monthlyTrend.actual, borderColor: '#3a2d1e', backgroundColor: 'rgba(44,92,130,0.1)', tension: 0.3, fill: true }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } },
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  }

  /* ---------- Budget grid: live row/column totals + save ---------- */
  const grid = document.getElementById('budgetGrid');
  if (grid) {
    grid.addEventListener('input', function (e) {
      if (e.target.tagName !== 'INPUT') return;
      recalcTotals();
    });

    function recalcTotals() {
      const rows = grid.querySelectorAll('tbody tr');
      const colTotals = Array(13).fill(0); // index 1..12
      let grand = 0;

      rows.forEach(row => {
        const category = row.querySelector('input')?.dataset.category;
        if (!category) return;
        let rowTotal = 0;
        row.querySelectorAll('input').forEach(input => {
          const val = parseFloat(input.value) || 0;
          const month = parseInt(input.dataset.month, 10);
          rowTotal += val;
          colTotals[month] += val;
        });
        grand += rowTotal;
        const rowTotalCell = row.querySelector(`[data-row-total="${category}"]`);
        if (rowTotalCell) rowTotalCell.textContent = formatMoney(rowTotal);
      });

      for (let m = 1; m <= 12; m++) {
        const cell = grid.querySelector(`[data-col-total="${m}"]`);
        if (cell) cell.textContent = formatMoney(colTotals[m]);
      }
      const grandCell = document.getElementById('grandTotal');
      if (grandCell) grandCell.textContent = formatMoney(grand);
    }

    function formatMoney(n) {
      return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  }

  const saveBtn = document.getElementById('saveBudgetsBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async function () {
      if (typeof Swal !== 'undefined') {
        const confirmResult = await Swal.fire({
          icon: 'question',
          title: 'Save budgets?',
          text: 'This will overwrite the saved budget amounts for this year.',
          showCancelButton: true,
          confirmButtonText: 'Save',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#3a2d1e',
          cancelButtonColor: '#B00020',
          reverseButtons: true
        });
        if (!confirmResult.isConfirmed) return;
      } else if (!confirm('Save budgets? This will overwrite the saved budget amounts for this year.')) {
        return;
      }

      const statusEl = document.getElementById('saveStatus');
      const budgets = {};
      document.querySelectorAll('#budgetGrid input').forEach(input => {
        const cat = input.dataset.category;
        const month = input.dataset.month;
        if (!budgets[cat]) budgets[cat] = {};
        budgets[cat][month] = parseFloat(input.value) || 0;
      });

      saveBtn.disabled = true;
      if (statusEl) statusEl.textContent = 'Saving…';
      try {
        const res = await fetch('budgets_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ year: data.year, budgets })
        });
        const json = await res.json();
        if (statusEl) statusEl.textContent = '';
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: json.success ? 'success' : 'error',
            title: json.success ? 'Saved' : 'Error',
            text: json.success ? 'Budgets saved successfully.' : (json.message || 'Save failed'),
            timer: 3000,
            timerProgressBar: true,
            toast: true,
            position: 'top-end',
            showConfirmButton: false
          });
        } else if (statusEl) {
          statusEl.textContent = json.success ? 'Saved.' : ('Error: ' + (json.message || 'save failed'));
        }
      } catch (err) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Could not reach the server.',
            timer: 3000,
            timerProgressBar: true,
            toast: true,
            position: 'top-end',
            showConfirmButton: false
          });
        } else if (statusEl) {
          statusEl.textContent = 'Error: could not reach the server.';
        }
      } finally {
        saveBtn.disabled = false;
        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 4000);
      }
    });
  }
});
