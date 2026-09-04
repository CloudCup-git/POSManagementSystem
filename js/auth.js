// js/auth.js — shared auth page behaviors

document.addEventListener('DOMContentLoaded', function () {
  const toggleBtn = document.getElementById('togglePassword');
  const pwdInput  = document.getElementById('password');

  if (toggleBtn && pwdInput) {
    toggleBtn.addEventListener('click', function () {
      const isHidden = pwdInput.type === 'password';
      pwdInput.type = isHidden ? 'text' : 'password';
      toggleBtn.classList.toggle('is-visible', isHidden);
      toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  // NOTE: submit-button loading/success/idle states are handled by the
  // AJAX script inline in Login_Page.php via submitBtn.dataset.state.
  // A duplicate handler used to live here (adding a 'loading' class that
  // was never removed) — removed to avoid it fighting with that logic.
});
