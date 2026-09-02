(function () {
  // Per-tab anonymous id so engagement rows from the same visit can be grouped later.
  function getSessionId() {
    let id = sessionStorage.getItem('cc_ad_session');
    if (!id) {
      id = 'sess_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      sessionStorage.setItem('cc_ad_session', id);
    }
    return id;
  }

  function track(campaignId, eventType, watchSeconds) {
    fetch('../marketing/track_engagement.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        campaign_id: campaignId,
        event_type: eventType,
        watch_seconds: watchSeconds || null,
        session_id: getSessionId(),
      }),
    }).catch(() => {});
  }

  function closePopup(overlay) {
    overlay.classList.remove('open');
    setTimeout(() => overlay.remove(), 250);
  }

  function buildPopup(campaign) {
    const overlay = document.createElement('div');
    overlay.className = 'ad-popup-overlay';

    const isVideo = campaign.media_type === 'video';
    const ctaHref = campaign.cta_url || '#';

    overlay.innerHTML = `
      <div class="ad-popup-card">
        <button class="ad-popup-close" aria-label="Close">✕</button>
        <span class="ad-popup-badge">Ad</span>
        <div class="ad-popup-media">
          ${isVideo
            ? `<video id="ad-popup-video" playsinline ${campaign.thumbnail_path ? `poster="${campaign.thumbnail_path}"` : ''} controls>
                 <source src="${campaign.file_path}" type="video/mp4">
               </video>`
            : `<img src="${campaign.file_path}" alt="${campaign.title.replace(/"/g, '&quot;')}">`
          }
        </div>
        <div class="ad-popup-body">
          <div class="ad-popup-title">${campaign.title}</div>
          ${campaign.description ? `<div class="ad-popup-desc">${campaign.description}</div>` : ''}
          ${campaign.cta_url ? `<a class="ad-popup-cta" id="ad-popup-cta" href="${ctaHref}" target="_blank" rel="noopener">${campaign.cta_label} →</a>` : ''}
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('open'));

    overlay.querySelector('.ad-popup-close').addEventListener('click', () => closePopup(overlay));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closePopup(overlay); });

    const ctaBtn = overlay.querySelector('#ad-popup-cta');
    if (ctaBtn) {
      ctaBtn.addEventListener('click', () => track(campaign.campaign_id, 'click'));
    }

    if (isVideo) {
      const video = overlay.querySelector('#ad-popup-video');
      const milestonesHit = { 25: false, 50: false, 75: false, complete: false };
      let startedTracked = false;

      video.addEventListener('play', () => {
        if (!startedTracked) {
          startedTracked = true;
          track(campaign.campaign_id, 'watch_start');
        }
      });

      video.addEventListener('timeupdate', () => {
        if (!video.duration) return;
        const pct = (video.currentTime / video.duration) * 100;
        if (pct >= 25 && !milestonesHit[25]) { milestonesHit[25] = true; track(campaign.campaign_id, 'watch_25', video.currentTime); }
        if (pct >= 50 && !milestonesHit[50]) { milestonesHit[50] = true; track(campaign.campaign_id, 'watch_50', video.currentTime); }
        if (pct >= 75 && !milestonesHit[75]) { milestonesHit[75] = true; track(campaign.campaign_id, 'watch_75', video.currentTime); }
      });

      video.addEventListener('ended', () => {
        if (!milestonesHit.complete) {
          milestonesHit.complete = true;
          track(campaign.campaign_id, 'watch_complete', video.duration);
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    fetch('../marketing/get_featured_campaign.php')
      .then((res) => res.json())
      .then((data) => {
        if (data && data.campaign) {
          buildPopup(data.campaign);
        }
      })
      .catch(() => {});
  });
})();
