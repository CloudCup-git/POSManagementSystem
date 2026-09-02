// js/cloudcup_login_fx.js — purely decorative motion for the login screen
// (card tilt + parallax bean drift). No effect on form behavior/validation.

document.addEventListener('DOMContentLoaded', function () {
  const card = document.getElementById('card');
  const right = document.querySelector('.right');
  if (card && right) {
    right.style.perspective = '1200px';
    card.addEventListener('mousemove', function (e) {
      const r = card.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      card.style.transform = 'rotateY(' + (x * 4) + 'deg) rotateX(' + (-y * 4) + 'deg) translateY(0)';
    });
    card.addEventListener('mouseleave', function () {
      card.style.transform = 'rotateY(0deg) rotateX(0deg) translateY(0)';
    });
  }

  const leftPanel = document.getElementById('leftPanel');
  const beansLayer = document.getElementById('beansLayer');
  const glowEl = leftPanel ? leftPanel.querySelector('.glow') : null;
  if (leftPanel && beansLayer && glowEl) {
    leftPanel.addEventListener('mousemove', function (e) {
      const r = leftPanel.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width - 0.5;
      const y = (e.clientY - r.top) / r.height - 0.5;
      beansLayer.style.transform = 'translate(' + (x * 14) + 'px, ' + (y * 10) + 'px)';
      glowEl.style.marginLeft = (x * 30) + 'px';
      glowEl.style.marginTop = (y * 20) + 'px';
    });
    leftPanel.addEventListener('mouseleave', function () {
      beansLayer.style.transform = 'translate(0,0)';
      glowEl.style.marginLeft = '0';
      glowEl.style.marginTop = '0';
    });
  }
});
