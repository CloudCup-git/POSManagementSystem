/* =====================================================================
   Cloud Cup — Global Light / Dark Theme toggle
   Works on every page: drops a floating button in the corner, saves
   the choice in localStorage, and applies it instantly on next load
   (an inline snippet in <head> already set the class before paint,
   this file just keeps things in sync and owns the button).
   ===================================================================== */
(function () {
  var STORAGE_KEY = 'cloudcup-theme';

  function getStoredTheme() {
    try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
  }

  function setStoredTheme(theme) {
    try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }
  }

  function applyTheme(theme) {
    var isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark-mode', isDark);
    document.documentElement.setAttribute('data-theme', theme);
  }

  var SUN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><line x1="12" y1="2" x2="12" y2="4"></line><line x1="12" y1="20" x2="12" y2="22"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="2" y1="12" x2="4" y2="12"></line><line x1="20" y1="12" x2="22" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
  var MOON_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"></path></svg>';

  function currentTheme() {
    return document.documentElement.classList.contains('dark-mode') ? 'dark' : 'light';
  }

  function updateButton(btn) {
    var theme = currentTheme();
    var next = theme === 'dark' ? 'light' : 'dark';
    btn.innerHTML = (theme === 'dark' ? SUN_ICON : MOON_ICON) +
      '<span class="ttb-label">Switch to ' + next + ' mode</span>';
    btn.setAttribute('aria-label', 'Switch to ' + next + ' mode');
    btn.title = 'Switch to ' + next + ' mode';
  }

  function init() {
    // Make sure the theme is applied even if the inline head snippet
    // was somehow skipped (e.g. cached page from before this feature).
    applyTheme(getStoredTheme() || 'light');

    // Turn on CSS transitions only after the initial paint, so the
    // very first load never "fades in" from the wrong theme.
    requestAnimationFrame(function () {
      document.documentElement.classList.add('theme-ready');
    });

    if (document.getElementById('theme-toggle-btn')) return;

    var btn = document.createElement('button');
    btn.id = 'theme-toggle-btn';
    btn.type = 'button';
    updateButton(btn);

    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      setStoredTheme(next);
      updateButton(btn);
    });

    document.body.appendChild(btn);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
