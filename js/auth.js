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

  // Brief loading feedback on the submit button while the POST is in
  // flight. Doesn't block or delay the actual form submission.
  const form = document.querySelector('.login-form');
  const submitBtn = document.getElementById('submitBtn');
  if (form && submitBtn) {
    form.addEventListener('submit', function () {
      if (submitBtn.classList.contains('loading')) return;
      submitBtn.classList.add('loading');
    });
  }
});
