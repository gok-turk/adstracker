/**
 * AdsTracker Pro - Universal JavaScript Tracking Client
 * Portable Google Ads Click & Fraud Protection Script
 */
(function() {
  'use strict';

  // 1. Locate current script element to determine site key & host endpoint
  let currentScript = document.currentScript;
  if (!currentScript) {
    const scripts = document.getElementsByTagName('script');
    for (let i = scripts.length - 1; i >= 0; i--) {
      if (scripts[i].src && scripts[i].src.indexOf('tracker.js') !== -1) {
        currentScript = scripts[i];
        break;
      }
    }
  }

  const siteKey = (currentScript && currentScript.getAttribute('data-site-key')) 
    || window.AdsTrackerKey 
    || '';

  if (!siteKey) {
    console.warn('[AdsTracker] data-site-key özniteliği bulunamadı. İzleme başlatılamadı.');
    return;
  }

  // Derive endpoint URL from current script src
  let apiBase = '';
  if (currentScript && currentScript.src) {
    const url = new URL(currentScript.src);
    apiBase = url.origin + url.pathname.substring(0, url.pathname.lastIndexOf('/'));
  } else {
    apiBase = window.location.origin;
  }
  const collectEndpoint = apiBase + '/collect.php';

  // 2. Parse URL parameters for Google Ads tracking identifiers
  const urlParams = new URLSearchParams(window.location.search);
  const gclid = urlParams.get('gclid') || '';
  const wbraid = urlParams.get('wbraid') || '';
  const gbraid = urlParams.get('gbraid') || '';
  const gadSource = urlParams.get('gad_source') || '';
  const utmSource = urlParams.get('utm_source') || '';
  const utmCampaign = urlParams.get('utm_campaign') || '';
  const utmMedium = urlParams.get('utm_medium') || '';

  // 3. Prepare hit payload
  const payload = {
    site_key: siteKey,
    page_url: window.location.href,
    referrer: document.referrer || '',
    screen_res: (window.screen ? window.screen.width + 'x' + window.screen.height : ''),
    gclid: gclid,
    wbraid: wbraid,
    gbraid: gbraid,
    gad_source: gadSource,
    utm_source: utmSource,
    utm_campaign: utmCampaign,
    utm_medium: utmMedium,
    user_agent: navigator.userAgent || ''
  };

  // 4. Send telemetry asynchronously
  function sendHit() {
    try {
      fetch(collectEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data && data.blocked) {
          handleBlockedUser();
        }
      })
      .catch(function(err) {
        // Fallback with Image beacon if fetch fails
        try {
          const beaconUrl = collectEndpoint + '?site_key=' + encodeURIComponent(siteKey) +
            '&gclid=' + encodeURIComponent(gclid) +
            '&screen_res=' + encodeURIComponent(payload.screen_res) +
            '&page_url=' + encodeURIComponent(payload.page_url) +
            '&referrer=' + encodeURIComponent(payload.referrer);
          const img = new Image();
          img.src = beaconUrl;
        } catch(e) {}
      });
    } catch (e) {}
  }

  // 5. Anti-Fraud Screen if client is blocked
  function handleBlockedUser() {
    document.body.innerHTML = `
      <div style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:#0b1120;color:#f8fafc;display:flex;align-items:center;justify-content:center;font-family:sans-serif;z-index:99999999;text-align:center;padding:20px;">
        <div style="max-width:520px;background:#1e293b;padding:40px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.5);border:1px solid #334155;">
          <div style="font-size:3rem;margin-bottom:16px;">🛑</div>
          <h2 style="font-size:1.5rem;font-weight:800;color:#ef4444;margin-bottom:12px;">Erişim Kısıtlandı</h2>
          <p style="font-size:0.95rem;color:#94a3b8;line-height:1.6;margin-bottom:20px;">
            Güvenlik ve reklam doğrulama sistemimiz tarafından IP adresiniz üzerinden yapılan şüpheli/aşırı istekler tespit edildiği için bu sayfaya erişiminiz durdurulmuştur.
          </p>
          <div style="font-size:0.8rem;color:#64748b;">Hata Kodu: ERR_ADS_FRAUD_PREVENTION</div>
        </div>
      </div>
    `;
  }

  // Run immediately or on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendHit);
  } else {
    sendHit();
  }
})();
