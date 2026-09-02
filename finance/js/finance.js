/**
 * CloudCup — Finance page charts
 * Reads chart data from window.financeData (set inline in finance.php
 * from the PHP query results) and renders the Chart.js visuals.
 */

const money = (v) => '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

Chart.defaults.font = { family: "'Segoe UI', Roboto, Helvetica, Arial, sans-serif" };
Chart.defaults.color = '#6b6156';

const data = window.financeData || {};

if (data.revenue) {
  new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
      labels: data.revenue.labels,
      datasets: [{
        label: 'Revenue',
        data: data.revenue.data,
        borderColor: '#b8703f',
        backgroundColor: 'rgba(59,130,192,0.12)',
        fill: true,
        tension: 0.35,
        pointRadius: 3,
        pointBackgroundColor: '#b8703f',
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => money(ctx.parsed.y) } } },
      scales: {
        y: { beginAtZero: true, grid: { color: '#f0f5f9' }, ticks: { callback: (v) => '₱' + v } },
        x: { grid: { display: false } }
      }
    }
  });
}

if (data.paymentMethod) {
  new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
      labels: data.paymentMethod.labels,
      datasets: [{
        data: data.paymentMethod.data,
        backgroundColor: ['#2f6f4e', '#b8703f', '#b8703f', '#f5a623', '#94a3b8'],
        borderWidth: 0,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${money(ctx.parsed)}` } } },
      cutout: '65%'
    }
  });
}

if (data.cogsByCategory) {
  new Chart(document.getElementById('cogsChart'), {
    type: 'doughnut',
    data: {
      labels: data.cogsByCategory.labels,
      datasets: [{
        data: data.cogsByCategory.data,
        backgroundColor: ['#b8453a', '#f5a623', '#b8703f', '#2f6f4e', '#b8703f', '#94a3b8', '#241f19'],
        borderWidth: 0,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${money(ctx.parsed)}` } } },
      cutout: '65%'
    }
  });
}

if (data.topItems) {
  new Chart(document.getElementById('topItemsChart'), {
    type: 'bar',
    data: {
      labels: data.topItems.labels,
      datasets: [{
        label: 'Revenue',
        data: data.topItems.data,
        backgroundColor: '#b8703f',
        borderRadius: 6,
        maxBarThickness: 18,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => money(ctx.parsed.x) } } },
      scales: {
        x: { beginAtZero: true, grid: { color: '#f0f5f9' }, ticks: { callback: (v) => '₱' + v } },
        y: { grid: { display: false } }
      }
    }
  });
}

if (data.profitComposition) {
  new Chart(document.getElementById('profitCompositionChart'), {
    type: 'doughnut',
    data: {
      labels: data.profitComposition.labels,
      datasets: [{
        data: data.profitComposition.data,
        backgroundColor: ['#b8453a', '#f5a623', '#2f6f4e'],
        borderWidth: 0,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${money(ctx.parsed)}` } } },
      cutout: '65%'
    }
  });
}

if (data.opExByCategory) {
  new Chart(document.getElementById('opExChart'), {
    type: 'doughnut',
    data: {
      labels: data.opExByCategory.labels,
      datasets: [{
        data: data.opExByCategory.data,
        backgroundColor: ['#f5a623', '#b8703f', '#b8453a', '#2f6f4e', '#b8703f', '#94a3b8', '#241f19'],
        borderWidth: 0,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${money(ctx.parsed)}` } } },
      cutout: '65%'
    }
  });
}