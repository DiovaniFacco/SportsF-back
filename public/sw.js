/* Service Worker básico para PWA - Quadras */
const CACHE_NAME = 'quadras-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Estratégia network-first com fallback para cache (não quebra a API)
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  // Não intercepta API nem HMR do Vite
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/@')) return;

  event.respondWith(
    fetch(request)
      .then((response) => {
        // Cacheia apenas navegações e assets estáticos locais
        if (response.ok && url.origin === self.location.origin) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => caches.match(request).then((cached) => cached || caches.match('/?source=pwa')))
  );
});
