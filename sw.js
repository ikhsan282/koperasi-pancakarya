const CACHE_NAME = 'pancakarya-v1';
const OFFLINE_ASSETS = [
  '/koperasi-pancakarya/',
  '/koperasi-pancakarya/offline.html',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(OFFLINE_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => 
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      
      return fetch(e.request).then(response => {
        if (!response || response.status !== 200 || response.type === 'error') {
          return response;
        }
        
        const url = new URL(e.request.url);
        if (url.pathname.endsWith('.css') || url.pathname.endsWith('.js') || 
            url.pathname.endsWith('.png') || url.pathname.endsWith('.jpg')) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
        }
        
        return response;
      }).catch(() => {
        if (e.request.mode === 'navigate') {
          return caches.match('/koperasi-pancakarya/offline.html');
        }
      });
    })
  );
});
