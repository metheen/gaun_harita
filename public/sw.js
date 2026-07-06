const CACHE_VERSION = 'v6';
const APP_CACHE = `gaun-app-${CACHE_VERSION}`;
const RUNTIME_CACHE = `gaun-runtime-${CACHE_VERSION}`;
const TILE_CACHE = `gaun-tiles-${CACHE_VERSION}`;
const MAX_TILE_ENTRIES = 120;

const APP_SHELL = [
  '/',
  '/index.php',
  '/assets/css/tailwind.css',
  '/manifest.json',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/uploads/Gaziantep-universitesi-logo.jpg',
  'https://unpkg.com/leaflet/dist/leaflet.css',
  'https://unpkg.com/leaflet/dist/leaflet.js',
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
  'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(APP_CACHE)
      .then(cache => Promise.all(APP_SHELL.map(url => precache(cache, url))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  const keep = new Set([APP_CACHE, RUNTIME_CACHE, TILE_CACHE]);
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.map(key => keep.has(key) ? undefined : caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin === self.location.origin && url.pathname.startsWith('/bordo/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request, APP_CACHE, caches.match('/')));
    return;
  }

  if (url.origin === self.location.origin) {
    if (url.pathname === '/get_locations.php' || url.pathname === '/get_campus_bulletin.php') {
      event.respondWith(networkFirst(request, RUNTIME_CACHE));
      return;
    }

    if (url.pathname.startsWith('/assets/')) {
      event.respondWith(networkFirst(request, RUNTIME_CACHE));
      return;
    }

    if (APP_SHELL.includes(url.pathname) || url.pathname.startsWith('/uploads/')) {
      event.respondWith(cacheFirst(request, RUNTIME_CACHE));
      return;
    }
  }

  if (isTileRequest(url)) {
    event.respondWith(tileCacheFirst(request));
    return;
  }

  if (isStaticExternalAsset(url)) {
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
  }
});

function isTileRequest(url) {
  return /(^|\.)tile\.openstreetmap\.org$/.test(url.hostname)
    && /^\/\d+\/\d+\/\d+\.png$/.test(url.pathname);
}

function isStaticExternalAsset(url) {
  return url.hostname === 'unpkg.com'
    || url.hostname === 'fonts.googleapis.com'
    || url.hostname === 'fonts.gstatic.com';
}

async function precache(cache, url) {
  try {
    const response = await fetch(url, { cache: 'reload' });
    if (isCacheable(response)) {
      await cache.put(url, response.clone());
    }
  } catch (error) {
    // CDN veya ağ geçici olarak yoksa SW kurulumu yine de tamamlanır.
  }
}

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) {
    return cached;
  }
  const response = await fetch(request);
  await putIfCacheable(cacheName, request, response);
  return response;
}

async function networkFirst(request, cacheName, fallbackPromise = null) {
  try {
    const response = await fetch(request);
    await putIfCacheable(cacheName, request, response);
    return response;
  } catch (error) {
    const cached = await caches.match(request);
    if (cached) {
      return cached;
    }
    if (fallbackPromise) {
      const fallback = await fallbackPromise;
      if (fallback) {
        return fallback;
      }
    }
    throw error;
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const refresh = fetch(request)
    .then(response => {
      if (isCacheable(response)) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  if (cached) {
    return cached;
  }

  const response = await refresh;
  if (response) {
    return response;
  }
  return fetch(request);
}

async function tileCacheFirst(request) {
  const cache = await caches.open(TILE_CACHE);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }

  const response = await fetch(request);
  if (isCacheable(response)) {
    await cache.put(request, response.clone());
    await trimCache(TILE_CACHE, MAX_TILE_ENTRIES);
  }
  return response;
}

async function putIfCacheable(cacheName, request, response) {
  if (!isCacheable(response)) {
    return;
  }
  const cache = await caches.open(cacheName);
  await cache.put(request, response.clone());
}

function isCacheable(response) {
  return response && (response.ok || response.type === 'opaque');
}

async function trimCache(cacheName, maxEntries) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length <= maxEntries) {
    return;
  }
  await Promise.all(keys.slice(0, keys.length - maxEntries).map(key => cache.delete(key)));
}
