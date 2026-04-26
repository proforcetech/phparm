/* PHPArm service worker
 *
 * Caching strategy:
 *   - Precache: app shell HTML, manifest, offline fallback, and core icons so the
 *     UI boots even on a cold offline launch.
 *   - Hashed Vite assets (/assets/*.js, /assets/*.css, /assets/*.{png,jpg,svg,...}):
 *     cache-first because filenames are content-hashed and never collide across
 *     versions.
 *   - Same-origin static images under /icons/ and /favicon.png: cache-first.
 *   - Navigation requests (HTML page loads): network-first, falling back to the
 *     cached app shell, then to the offline page if the shell isn't cached.
 *   - /api/* and any non-GET request: passthrough (no SW caching). The app's
 *     offlineQueue (src/react/services/offlineSync.js + utils/offlineQueue.js)
 *     handles mutation queueing and retry; auth-gated reads are intentionally
 *     not cached here to avoid serving stale or cross-user data.
 *
 * Update flow:
 *   - SW_VERSION below is the cache-bust key. Bumping it on each release
 *     installs a new SW; old caches are evicted in 'activate'.
 *   - On 'install' the new SW calls skipWaiting only if it received a
 *     SKIP_WAITING postMessage (so the active SW keeps serving the current
 *     session until the user accepts the update prompt in the UI).
 *   - When the new SW becomes 'installed' with an existing controller, every
 *     client gets a postMessage so the React shell can render the update toast.
 */

const SW_VERSION = 'v1.0.0';
const PRECACHE = `phparm-precache-${SW_VERSION}`;
const RUNTIME_ASSETS = `phparm-runtime-assets-${SW_VERSION}`;
const RUNTIME_PAGES = `phparm-runtime-pages-${SW_VERSION}`;

const APP_SHELL_URL = '/';
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  APP_SHELL_URL,
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/favicon.png',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-512.png',
  '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(PRECACHE);
      // Use Request with cache:'reload' so we don't bake stale HTTP-cached entries
      // into the precache when the SW is reinstalled on the same user agent.
      await Promise.all(
        PRECACHE_URLS.map(async (url) => {
          try {
            const req = new Request(url, { cache: 'reload' });
            const res = await fetch(req);
            if (res && res.ok) {
              await cache.put(url, res.clone());
            }
          } catch {
            // Best-effort precache: a missing icon shouldn't prevent install.
          }
        })
      );
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keep = new Set([PRECACHE, RUNTIME_ASSETS, RUNTIME_PAGES]);
      const names = await caches.keys();
      await Promise.all(
        names
          .filter((name) => name.startsWith('phparm-') && !keep.has(name))
          .map((name) => caches.delete(name))
      );
      if (self.registration && self.registration.navigationPreload) {
        try {
          await self.registration.navigationPreload.enable();
        } catch {
          // Navigation preload is optional; older browsers throw here.
        }
      }
      await self.clients.claim();
    })()
  );
});

self.addEventListener('message', (event) => {
  if (!event.data || typeof event.data !== 'object') return;
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data.type === 'GET_VERSION' && event.source) {
    event.source.postMessage({ type: 'SW_VERSION', version: SW_VERSION });
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') return;

  let url;
  try {
    url = new URL(request.url);
  } catch {
    return;
  }

  if (url.origin !== self.location.origin) return;

  // /api passes through. Mutations + queue handle offline writes; reads are
  // user-scoped and shouldn't be cached without explicit per-endpoint policy.
  if (url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(handleNavigation(event));
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(request, RUNTIME_ASSETS));
    return;
  }

  if (url.pathname.startsWith('/icons/') || url.pathname === '/favicon.png' || url.pathname === '/manifest.webmanifest') {
    event.respondWith(cacheFirst(request, PRECACHE));
    return;
  }
});

async function handleNavigation(event) {
  const cache = await caches.open(PRECACHE);
  try {
    const preload = event.preloadResponse ? await event.preloadResponse : null;
    const response = preload || (await fetch(event.request));
    if (response && response.ok && response.type !== 'opaqueredirect') {
      // Refresh cached app shell so the next cold offline boot serves the
      // latest HTML — but only when we're handling a top-level navigation.
      cache.put(APP_SHELL_URL, response.clone()).catch(() => {});
    }
    return response;
  } catch {
    const cached = await cache.match(APP_SHELL_URL);
    if (cached) return cached;
    const offline = await cache.match(OFFLINE_URL);
    if (offline) return offline;
    return new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response && response.ok) {
      cache.put(request, response.clone()).catch(() => {});
    }
    return response;
  } catch (err) {
    if (cached) return cached;
    throw err;
  }
}
