<?php
// /pwa.php — tiny helper loaded as a <script src="/pwa.php"></script>
declare(strict_types=1);
require_once __DIR__ . '/includes/vapid.php';
header('Content-Type: application/javascript; charset=utf-8');
?>
/* pwa.js – push helper (served via pwa.php) */
(function () {
  if (!('serviceWorker' in navigator)) return;

  const PUBLIC_VAPID_KEY = '<?= htmlspecialchars(VAPID_PUBLIC_KEY, ENT_QUOTES) ?>';

  async function ensureSW() {
    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;
    return reg;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
    return out;
  }

  async function subscribeUser() {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('Permission denied');

    const reg = await ensureSW();
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(PUBLIC_VAPID_KEY)
    });

    const res = await fetch('/push/subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub.toJSON()),
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('Failed to save subscription');
    const j = await res.json().catch(() => ({}));
    if (!j.ok) throw new Error(j.error || 'Failed to save subscription');
    return true;
  }

  async function unsubscribeUser() {
    const reg = await ensureSW();
    const sub = await reg.pushManager.getSubscription();
    if (!sub) return true; // already off

    try {
      await fetch('/push/unsubscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub.toJSON()),
        credentials: 'same-origin'
      });
    } catch (e) { /* ignore network fail — we still drop local sub */ }

    const ok = await sub.unsubscribe().catch(() => false);
    return !!ok;
  }

  async function isSubscribed() {
    const reg = await ensureSW();
    const sub = await reg.pushManager.getSubscription();
    return !!sub;
  }

  // Public API
  window.PushToggle = {
    subscribe: subscribeUser,
    unsubscribe: unsubscribeUser,
    isSubscribed: isSubscribed,

    // Wire a checkbox <input type="checkbox" id="pushToggle">
    // Options: { onLabel, offLabel, onChange(status:boolean) }
    attachCheckbox: async function (elOrId, opts = {}) {
      const el = typeof elOrId === 'string' ? document.getElementById(elOrId) : elOrId;
      if (!el) return;

      function setLabel(on) {
        const lOn  = opts.onLabel  ?? 'Notifications ON';
        const lOff = opts.offLabel ?? 'Notifications OFF';
        el.nextElementSibling && (el.nextElementSibling.textContent = on ? lOn : lOff);
      }

      const current = await isSubscribed().catch(() => false);
      el.checked = current;
      setLabel(current);

      el.addEventListener('change', async () => {
        el.disabled = true;
        try {
          if (el.checked) {
            await subscribeUser();
            setLabel(true);
            opts.onChange && opts.onChange(true);
            alert('Notifications enabled on this device.');
          } else {
            await unsubscribeUser();
            setLabel(false);
            opts.onChange && opts.onChange(false);
            alert('Notifications disabled on this device.');
          }
        } catch (e) {
          // revert UI on error
          el.checked = !el.checked;
          setLabel(el.checked);
          alert('Push error: ' + (e && e.message ? e.message : e));
        } finally {
          el.disabled = false;
        }
      });
    }
  };
})();
