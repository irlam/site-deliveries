// pwa.js  — push subscribe + SW register (production)
(function () {
  if (!('serviceWorker' in navigator)) return;

  // ⬇️ Paste your PUBLIC VAPID KEY (Base64URL) between the quotes:
  const PUBLIC_VAPID_KEY = 'BB_SLSb4z2gRFdxuOh0IuyhJfi0bh4jscank24HIm8MscLhI8TUbAMulD7Sby10lawVla1tqcq80NymICayYvJc';

  async function ensureSW() {
    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;
    return reg;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  async function subscribeUser() {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('Permission denied');

    const reg = await ensureSW();
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(PUBLIC_VAPID_KEY)
    });

    // Try both endpoints (root shim and /push/)
    const endpoints = ['/push/subscribe.php', '/subscribe.php'];
    let lastErr = null;

    for (const url of endpoints) {
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
          body: JSON.stringify(sub)
        });
        let data = null;
        try { data = await res.json(); } catch (_) {}
        if (data && data.ok) return true;
        lastErr = new Error((data && data.error) ? data.error : `HTTP ${res.status}`);
      } catch (e) { lastErr = e; }
    }
    throw lastErr || new Error('Failed to save subscription');
  }

  // Expose the click helper the page calls
  window.requestPushSubscribe = async function () {
    try {
      await subscribeUser();
      alert('Notifications enabled on this device.');
    } catch (e) {
      console.error('[push] subscribe error:', e);
      alert('Could not enable notifications: ' + e.message);
    }
  };

  // Register SW on load (no prompt)
  ensureSW().catch(()=>{});
})();
