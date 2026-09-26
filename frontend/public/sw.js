/*
 * Tessera till service worker (production builds only).
 * Lets the /till screen open without a connection: the page itself is network-first
 * (always fresh when online, cached copy when offline) and Next.js build assets, which are
 * content-hashed and never change, are cache-first. API calls are never cached.
 */
const CACHE = "tessera-till-v1";
const TILL_PAGE = "/till";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.add(TILL_PAGE))
      .catch(() => undefined),
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // the API is on another origin; never cached

  // The till page: network first, cached copy when offline.
  if (request.mode === "navigate" && url.pathname === TILL_PAGE) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(TILL_PAGE, copy));
          return response;
        })
        .catch(() => caches.match(TILL_PAGE).then((cached) => cached || Response.error())),
    );
    return;
  }

  // Build assets (hashed file names): cache first.
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/fonts/") || url.pathname === "/favicon.ico") {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ||
          fetch(request).then((response) => {
            if (response.ok) {
              const copy = response.clone();
              caches.open(CACHE).then((cache) => cache.put(request, copy));
            }
            return response;
          }),
      ),
    );
  }
});
