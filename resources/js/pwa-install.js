// Popup de instalação PWA — mostra automaticamente ao entrar no app.
// Regras:
// - Android/Chrome/Edge: usa evento beforeinstallprompt
// - iPhone/iPad (Safari): mostra instrução manual (não tem prompt automático)
// - Não mostra se já estiver instalado ou se dispensado nos últimos 7 dias

let deferredPrompt = null;
const POPUP_ID = 'pwa-install-popup';
const DISMISS_KEY = 'pwa-install-dismissed-at';

function isStandalone() {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true || // iOS Safari
    document.referrer.includes('android-app://')
  );
}

function wasDismissedRecently() {
  try {
    const ts = Number(localStorage.getItem(DISMISS_KEY) || 0);
    const SEVEN_DAYS = 7 * 24 * 60 * 60 * 1000;
    return Date.now() - ts < SEVEN_DAYS;
  } catch {
    return false;
  }
}

function markDismissed() {
  try {
    localStorage.setItem(DISMISS_KEY, String(Date.now()));
  } catch {
    /* ignore */
  }
}

function isIos() {
  return /iphone|ipad|ipod/i.test(window.navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function showPopup({ ios = false } = {}) {
  const el = document.getElementById(POPUP_ID);
  if (!el || isStandalone() || wasDismissedRecently()) return;

  if (ios) {
    document.getElementById('pwa-install-text')?.classList.add('hidden');
    document.getElementById('pwa-install-ios-text')?.classList.remove('hidden');
    const btn = document.getElementById('pwa-install-btn');
    if (btn) btn.textContent = 'Entendi';
  }

  el.classList.remove('hidden');
}

function hidePopup() {
  document.getElementById(POPUP_ID)?.classList.add('hidden');
}

// Captura o evento do Chrome — sem isso o popup automático é bloqueado
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  // Mostra o popup automaticamente ao entrar (com pequeno atraso p/ UX)
  setTimeout(() => showPopup(), 1500);
});

window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  hidePopup();
  try {
    localStorage.removeItem(DISMISS_KEY);
  } catch {
    /* ignore */
  }
});

function initPwaInstall() {
  // iOS: não existe beforeinstallprompt → mostra instrução manual
  if (isIos() && !isStandalone() && !wasDismissedRecently()) {
    setTimeout(() => showPopup({ ios: true }), 1500);
  }

  document.getElementById('pwa-install-btn')?.addEventListener('click', async () => {
    // iOS ou sem prompt capturado: só fecha
    if (!deferredPrompt) {
      markDismissed();
      hidePopup();
      return;
    }
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') {
      try {
        localStorage.removeItem(DISMISS_KEY);
      } catch {
        /* ignore */
      }
    } else {
      markDismissed();
    }
    deferredPrompt = null;
    hidePopup();
  });

  const dismiss = () => {
    markDismissed();
    hidePopup();
  };
  document.getElementById('pwa-install-dismiss')?.addEventListener('click', dismiss);
  document.getElementById('pwa-install-close')?.addEventListener('click', dismiss);

  // Permite abrir manualmente, ex: <button onclick="window.showPwaInstall()">Instalar app</button>
  window.showPwaInstall = () => showPopup({ ios: isIos() && !deferredPrompt });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPwaInstall);
} else {
  initPwaInstall();
}

// Registra o Service Worker (obrigatório p/ instalar o PWA)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}
