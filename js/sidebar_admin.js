  // ── Sidebar collapse/expand + avatar → coffee cup morph (matches cloud-cup-redesign-v13 reference) ──
  function collapseSidebar() {
    document.body.classList.add('sidebar-hidden');
    try { localStorage.setItem('cc_sidebar_hidden', '1'); } catch (e) { /* storage unavailable — collapse still works */ }
    brewSidebarLogo();
  }

  function handleAvatarClick() {
    // The cup/avatar's only job is to open the collapsed sidebar back up — it should never
    // navigate anywhere. (The "Cloud Cup" wordmark next to it is the home link.)
    if (document.body.classList.contains('sidebar-hidden')) {
      document.body.classList.remove('sidebar-hidden');
      try { localStorage.setItem('cc_sidebar_hidden', '0'); } catch (e) { /* storage unavailable — expand still works */ }
    }
  }

  function brewSidebarLogo() {
    var mark = document.getElementById('sidebarAvatar');
    if (!mark || mark.classList.contains('brewing')) return; // let a running animation finish
    mark.classList.add('brewing');
    // swap CC -> cup right at the pinch-point of the flip (~50%)
    setTimeout(function () { mark.classList.add('show-cup'); }, 310);
    mark.addEventListener('animationend', function done() {
      mark.classList.remove('brewing');
      mark.removeEventListener('animationend', done);
    });
  }

  // If the sidebar was already collapsed on a previous visit, show the cup
  // immediately (no flip) so the logo matches the collapsed rail on load.
  (function () {
    if (document.body.classList.contains('sidebar-hidden')) {
      var mark = document.getElementById('sidebarAvatar');
      if (mark) mark.classList.add('show-cup');
    }
  })();

  function ackAlert(alertId, btn) {
    var fd = new FormData();
    fd.append('alert_id', alertId);
    fetch('Ack_Alert.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          var row = btn.closest('.alert-item');
          if (row) row.remove();
          var list = document.getElementById('alertDropdownList');
          if (list && !list.querySelector('.alert-item')) {
            list.innerHTML = '<div class="alert-empty">No low stock reports right now 🎉</div>';
          }
          var dot = document.querySelector('.cc-icon-btn-dot');
          if (dot && list && !list.querySelector('.alert-item')) dot.remove();
        }
      });
  }

  function ackAllAlerts() {
    var fd = new FormData();
    fd.append('mark_all', '1');
    fetch('Ack_Alert.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          document.getElementById('alertDropdownList').innerHTML = '<div class="alert-empty">No low stock reports right now 🎉</div>';
          var dot = document.querySelector('.cc-icon-btn-dot');
          if (dot) dot.remove();
          var markAllBtn = document.querySelector('.alert-mark-all');
          if (markAllBtn) markAllBtn.remove();
        }
      });
  }

  (function () {
    var logoutBtn = document.getElementById('admin-logout-btn');
    if (!logoutBtn) return;
    logoutBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var href = this.getAttribute('href');
      Swal.fire({
        title: 'Logout?',
        text: 'Are you sure you want to log out?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, logout',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#b8703f',
        cancelButtonColor: '#9c9184',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) {
          window.location.href = href;
        }
      });
    });
  })();
