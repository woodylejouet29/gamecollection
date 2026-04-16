/**
 * Service Worker basique pour PlayShelf
 * 
 * Stratégie de cache : Cache First pour les assets statiques,
 * Network First pour les pages HTML (pour la fraîcheur des données).
 * 
 * Version: 1.0.1
 */

const CACHE_NAME = 'playshelf-v2';
const STATIC_CACHE = 'playshelf-static-v2';

// Assets à mettre en cache immédiatement à l'installation
const PRECACHE_ASSETS = [
  '/',
  '/manifest.json',
  '/assets/css/global.css',
  '/assets/css/platform-badges.css',
  '/assets/css/footer.css',
  '/assets/js/theme.js',
  '/assets/js/app.js',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-512x512.png'
];

// Install event - precache les assets critiques
self.addEventListener('install', event => {
  console.log('[Service Worker] Installation');
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('[Service Worker] Pré-cache des assets');
        return cache.addAll(PRECACHE_ASSETS);
      })
      .then(() => {
        console.log('[Service Worker] Installation terminée');
        return self.skipWaiting();
      })
      .catch(error => {
        console.error('[Service Worker] Erreur lors de l\'installation:', error);
      })
  );
});

// Activate event - nettoyer les anciens caches
self.addEventListener('activate', event => {
  console.log('[Service Worker] Activation');
  const cacheWhitelist = [CACHE_NAME, STATIC_CACHE];
  
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (!cacheWhitelist.includes(cacheName)) {
            console.log('[Service Worker] Suppression ancien cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
    .then(() => {
      console.log('[Service Worker] Activation terminée');
      return self.clients.claim();
    })
  );
});

// Fetch event - stratégie de cache
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Ignorer les requêtes non-HTTP(S) (ex: chrome-extension:, data:, etc.)
  if (!url.protocol.startsWith('http')) {
    return;
  }
  
  // Ignorer les requêtes d'API et les uploads
  if (url.pathname.startsWith('/api/') || 
      url.pathname.startsWith('/uploads/') ||
      event.request.method !== 'GET') {
    return;
  }
  
  // Pour les assets statiques (CSS, JS, images, fonts) : Cache First
  if (url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff|woff2|ttf|eot)$/) ||
      url.pathname.startsWith('/assets/') ||
      url.pathname.startsWith('/storage/images/')) {
    
    event.respondWith(
      caches.match(event.request)
        .then(cachedResponse => {
          if (cachedResponse) {
            console.log('[Service Worker] Asset servi depuis le cache:', url.pathname);
            return cachedResponse;
          }
          
          // Pas dans le cache, aller sur le réseau
          return fetch(event.request)
            .then(networkResponse => {
              // Mettre en cache pour la prochaine fois
              if (networkResponse.ok) {
                const responseToCache = networkResponse.clone();
                caches.open(STATIC_CACHE)
                  .then(cache => {
                    cache.put(event.request, responseToCache);
                    console.log('[Service Worker] Asset ajouté au cache:', url.pathname);
                  });
              }
              return networkResponse;
            })
            .catch(error => {
              console.error('[Service Worker] Erreur de fetch:', error);
              // Si on ne peut pas récupérer l'asset, on pourrait retourner
              // une réponse de secours si on en avait une
              return new Response('Erreur de réseau', {
                status: 408,
                headers: { 'Content-Type': 'text/plain' }
              });
            });
        })
    );
    
  } else {
    // Pour les pages HTML : Network First (pour avoir les données fraîches)
    event.respondWith(
      fetch(event.request)
        .then(networkResponse => {
          // Si la réponse réseau est bonne, mettre à jour le cache
          if (networkResponse.ok) {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME)
              .then(cache => {
                cache.put(event.request, responseToCache);
              });
          }
          return networkResponse;
        })
        .catch(() => {
          // Si le réseau échoue, essayer le cache
          return caches.match(event.request)
            .then(cachedResponse => {
              if (cachedResponse) {
                console.log('[Service Worker] Page servie depuis le cache (mode hors ligne):', url.pathname);
                return cachedResponse;
              }
              
              // Si pas dans le cache non plus, retourner la page d'accueil
              if (url.pathname === '/' || url.pathname === '') {
                return caches.match('/');
              }
              
              // Pour les autres pages, on pourrait retourner une page d'erreur hors ligne
              return new Response(
                `<html>
                  <head>
                    <title>Hors ligne - PlayShelf</title>
                    <style>
                      body { font-family: sans-serif; text-align: center; padding: 40px; }
                      h1 { color: #7c3aed; }
                    </style>
                  </head>
                  <body>
                    <h1>⚡️ Vous êtes hors ligne</h1>
                    <p>Cette page n'est pas disponible hors ligne.</p>
                    <p><a href="/">Retour à l'accueil</a></p>
                  </body>
                </html>`,
                {
                  status: 200,
                  headers: { 'Content-Type': 'text/html' }
                }
              );
            });
        })
    );
  }
});

// Gestion des messages (pour mettre à jour le SW depuis l'UI)
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});