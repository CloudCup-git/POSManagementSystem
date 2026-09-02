// Auto-dismiss .msg-banner notifications (success/error alerts) after 3 seconds.
(function () {
  var DISMISS_AFTER_MS = 3000;
  var FADE_MS = 400;

  function autoDismiss(banner) {
    setTimeout(function () {
      banner.style.transition = 'opacity ' + FADE_MS + 'ms ease, max-height ' + FADE_MS + 'ms ease, margin ' + FADE_MS + 'ms ease, padding ' + FADE_MS + 'ms ease';
      banner.style.opacity = '0';
      banner.style.overflow = 'hidden';
      banner.style.maxHeight = banner.scrollHeight + 'px';
      // Force reflow so the transition picks up the starting max-height before collapsing.
      void banner.offsetHeight;
      banner.style.maxHeight = '0px';
      banner.style.marginTop = '0';
      banner.style.marginBottom = '0';
      banner.style.paddingTop = '0';
      banner.style.paddingBottom = '0';

      setTimeout(function () {
        banner.remove();
      }, FADE_MS);
    }, DISMISS_AFTER_MS);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.msg-banner').forEach(autoDismiss);
  });
})();
