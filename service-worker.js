// Service Worker for WPU Safe System PWA - Android Native App Experience
// v35 - Navigation cache fallback: only exact URL (± .php) for deep links; never substitute home/login (fixes tarf_request_view URL + dashboard body)
// v34 - Navigation: return native fetch Response when redirects were followed (fixes /login URL with wrong HTML body)
// v33 - Skip view_file.php so pardon supporting documents (PDFs) load correctly
// - Pre-cache CDN CSS (Bootstrap Icons, Intro.js, Font Awesome) and JS for offline use
// - Intercept unpkg.com so fallback scripts (e.g. html5-qrcode) are cached when used
// v31 - All APIs work offline: GET = network-first + cache fallback, POST = 503 JSON when offline
const CACHE_VERSION = 'v35';
const CACHE_NAME = `wpu-safe-system-${CACHE_VERSION}`;
const RUNTIME_CACHE = `wpu-safe-runtime-${CACHE_VERSION}`;
const API_CACHE = `wpu-safe-api-${CACHE_VERSION}`;

// Get the base path dynamically from the service worker's location
const swPath = self.location.pathname;
const BASE_PATH = swPath.substring(0, swPath.lastIndexOf('/')) || '';
const ORIGIN = self.location.origin;

console.log('[SW] Service Worker path:', swPath);
console.log('[SW] BASE_PATH:', BASE_PATH);
console.log('[SW] ORIGIN:', ORIGIN);
console.log('[SW] Version:', CACHE_VERSION);

// ===== HELPER: Validate if response is valid app content =====
// CRITICAL: This prevents caching auth pages, error pages, or non-app content
function isValidAppResponse(response, html = null) {
    // Must be a successful response
    if (!response || response.status !== 200 || !response.ok) {
        return false;
    }
    
    // Check content type
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('text/html')) {
        return true; // Non-HTML assets are usually fine
    }
    
    // If we have the HTML content, validate it's actually our app
    if (html) {
        // Reject Microsoft auth pages
        if (html.includes('MicrosoftLogo') || 
            html.includes('devtunnels') ||
            html.includes('login.microsoftonline.com') ||
            html.includes('Sign in to your account') ||
            html.includes('Pick an account')) {
            console.log('[SW] ❌ Rejected: Microsoft auth page detected');
            return false;
        }
        
        // Reject generic error pages
        if (html.includes("can't reach this page") ||
            html.includes("This site can't be reached") ||
            html.includes('ERR_') ||
            html.includes('DNS_PROBE')) {
            console.log('[SW] ❌ Rejected: Error page detected');
            return false;
        }
        
        // Must contain WPU Safe System markers
        const hasAppMarkers = html.includes('WPU') || 
                              html.includes('wpu-safe') ||
                              html.includes('Faculty') ||
                              html.includes('QR Code Scanner') ||
                              html.includes('qrcode-scanner') ||
                              html.includes('Station Scanner') ||
                              html.includes('timekeeper') ||
                              html.includes(BASE_PATH);
        
        if (!hasAppMarkers) {
            console.log('[SW] ⚠️ Warning: Response may not be app content');
            // Still allow it but log warning
        }
    }
    
    return true;
}

// ===== ASSETS TO CACHE ON INSTALL =====
// login.php is cached for offline PWA access (network-first strategy ensures fresh CSRF tokens when online)
// Optimized for faster loading with minimal essential assets
const STATIC_ASSETS = [
    BASE_PATH + '/',
    BASE_PATH + '/home.php',
    // CSS - Core styles (optimized order)
    BASE_PATH + '/assets/vendor/bootstrap/css/bootstrap.min.css',
    BASE_PATH + '/assets/css/style.css',
    BASE_PATH + '/assets/css/optimized.css',
    BASE_PATH + '/assets/css/faculty-portal.css',
    BASE_PATH + '/assets/css/admin-portal.css',
    BASE_PATH + '/assets/css/mobile.css',
    // Fonts
    BASE_PATH + '/assets/vendor/fontawesome/css/all.min.css',
    // JS - Core scripts (optimized order)
    BASE_PATH + '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    BASE_PATH + '/assets/js/main.js',
    BASE_PATH + '/assets/js/performance.js',
    BASE_PATH + '/assets/js/mobile.js',
    BASE_PATH + '/assets/js/mobile-interactions-unified.js',
    BASE_PATH + '/assets/js/pwa-install-prompt.js',
    BASE_PATH + '/assets/js/pwa-app-behavior.js',
    // QR Scanner assets
    BASE_PATH + '/assets/vendor/html5-qrcode.min.js',
    BASE_PATH + '/assets/vendor/jsQR.min.js',
    BASE_PATH + '/assets/css/timekeeper-mobile.css',
    BASE_PATH + '/timekeeper/js/offline-storage.js',
    BASE_PATH + '/timekeeper/js/sync-manager.js',
    // SweetAlert2 - Local for offline support
    BASE_PATH + '/assets/vendor/sweetalert2.min.js',
    BASE_PATH + '/assets/vendor/sweetalert2.min.css',
    // Manifest and icons
    BASE_PATH + '/manifest.php',
    BASE_PATH + '/assets/icons/icon-192x192.png',
    BASE_PATH + '/assets/icons/icon-512x512.png',
    // CDN CSS/JS - pre-cache so layouts and styles work offline (full URLs left as-is by makeAbsoluteUrl)
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/fonts/bootstrap-icons.woff2',
    'https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/introjs.min.css',
    'https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/intro.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.woff2',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.woff2',
    'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js'
];

// Pages that should be cached for offline access
// login.php is included for offline PWA access (CSRF tokens will be refreshed on network requests)
const OFFLINE_PAGES = [
    BASE_PATH + '/login.php',
    BASE_PATH + '/faculty/dashboard.php',
    BASE_PATH + '/faculty/dtr_submissions.php',
    BASE_PATH + '/faculty/view_logs.php',
    BASE_PATH + '/faculty/pds.php',
    BASE_PATH + '/faculty/announcements.php',
    BASE_PATH + '/faculty/profile.php',
    BASE_PATH + '/faculty/requirements.php',
    BASE_PATH + '/faculty/submissions.php',
    BASE_PATH + '/station_login.php',
    BASE_PATH + '/timekeeper/qrcode-scanner.php',
    BASE_PATH + '/timekeeper/dashboard.php'
];

// Helper function to make URLs absolute
function makeAbsoluteUrl(path) {
    if (path.startsWith('http://') || path.startsWith('https://')) {
        return path;
    }
    return self.location.origin + (path.startsWith('/') ? path : '/' + path);
}

// ===== INSTALL EVENT =====
// CRITICAL: Clear ALL existing caches first to ensure fresh start
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker v' + CACHE_VERSION + '...');
    console.log('[SW] 🧹 Clearing ALL old caches for fresh start...');
    
    event.waitUntil(
        // Step 1: Delete ALL existing caches first (complete fresh start)
        caches.keys().then((cacheNames) => {
            console.log('[SW] Found caches:', cacheNames);
            return Promise.all(
                cacheNames.map((cacheName) => {
                    console.log('[SW] 🗑️ Deleting cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            console.log('[SW] ✅ All old caches deleted');
            // Step 2: Create new caches and cache assets
            return Promise.all([
                // Cache static assets
                caches.open(CACHE_NAME).then((cache) => {
                    console.log('[SW] Caching static assets...');
                    const absoluteAssets = STATIC_ASSETS.map(asset => makeAbsoluteUrl(asset));
                    
                    // Cache each asset with validation
                    return Promise.all(
                        absoluteAssets.map(url => {
                            return fetch(url, { cache: 'no-store' })
                                .then(response => {
                                    if (isValidAppResponse(response)) {
                                        return cache.put(url, response);
                                    }
                                    console.log('[SW] Skipped invalid response:', url);
                                    return Promise.resolve();
                                })
                                .catch(err => {
                                    console.log('[SW] Failed to cache:', url);
                                    return Promise.resolve();
                                });
                        })
                    );
                }),
                // Cache critical pages including login.php, station_login.php, and qrcode-scanner.php for offline PWA access
                caches.open(RUNTIME_CACHE).then((cache) => {
                    console.log('[SW] Caching critical pages...');
                    const criticalPages = [
                        makeAbsoluteUrl(BASE_PATH + '/'),
                        makeAbsoluteUrl(BASE_PATH + '/home.php'),
                        makeAbsoluteUrl(BASE_PATH + '/login.php'),
                        makeAbsoluteUrl(BASE_PATH + '/station_login.php'),
                        makeAbsoluteUrl(BASE_PATH + '/timekeeper/qrcode-scanner.php')
                    ];
                    
                    return Promise.all(
                        criticalPages.map(url => {
                            return fetch(url, { cache: 'no-store', credentials: 'include' })
                                .then(response => {
                                    if (response.ok && response.status === 200) {
                                        return response.text().then(html => {
                                            if (isValidAppResponse(response, html)) {
                                                // Re-create response with correct headers
                                                const headers = new Headers(response.headers);
                                                headers.set('Content-Type', 'text/html; charset=utf-8');
                                                const newResponse = new Response(html, {
                                                    status: 200,
                                                    statusText: 'OK',
                                                    headers: headers
                                                });
                                                return cache.put(url, newResponse);
                                            }
                                            console.log('[SW] Skipped invalid page:', url);
                                            return Promise.resolve();
                                        });
                                    }
                                    return Promise.resolve();
                                })
                                .catch(err => {
                                    console.log('[SW] Failed to cache page:', url, err.message);
                                    return Promise.resolve();
                                });
                        })
                    );
                })
            ]);
        }).then(() => {
            console.log('[SW] ✅ Installation complete - fresh cache created');
        })
    );
    
    // Activate immediately - critical for Android PWA
    self.skipWaiting();
});

// ===== ACTIVATE EVENT =====
// CRITICAL: Validate and clean all cached responses
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating Service Worker v' + CACHE_VERSION + '...');
    
    event.waitUntil(
        (async () => {
            // Delete all caches except current version
            const cacheNames = await caches.keys();
            console.log('[SW] Current caches:', cacheNames);
            
            for (const cacheName of cacheNames) {
                if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE && cacheName !== API_CACHE) {
                    console.log('[SW] 🗑️ Deleting old cache:', cacheName);
                    await caches.delete(cacheName);
                }
            }
            
            // Validate all entries in current caches
            for (const cacheName of [CACHE_NAME, RUNTIME_CACHE, API_CACHE]) {
                const hasCache = await caches.has(cacheName);
                if (!hasCache) continue;
                
                const cache = await caches.open(cacheName);
                const requests = await cache.keys();
                
                for (const request of requests) {
                    try {
                        const response = await cache.match(request);
                        if (!response || response.status !== 200) {
                            console.log('[SW] 🗑️ Removing bad response:', request.url);
                            await cache.delete(request);
                            continue;
                        }
                        
                        // For HTML responses, validate content
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('text/html')) {
                            const html = await response.clone().text();
                            if (!isValidAppResponse(response, html)) {
                                console.log('[SW] 🗑️ Removing invalid HTML:', request.url);
                                await cache.delete(request);
                            }
                        }
                    } catch (e) {
                        console.log('[SW] Error validating cache entry:', e);
                        await cache.delete(request);
                    }
                }
            }
            
            console.log('[SW] ✅ Activation complete - caches validated');
        })()
    );
    
    // Take control immediately - critical for Android PWA
    return self.clients.claim();
});

// ===== FETCH EVENT =====
// CRITICAL: Network-first strategy with validation to prevent caching bad responses
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    
    // Handle favicon.ico requests gracefully (often requested by browsers but may not exist)
    if (url.pathname.endsWith('/favicon.ico') || url.pathname.includes('favicon.ico')) {
        event.respondWith(
            new Response(null, { 
                status: 204, 
                statusText: 'No Content',
                headers: { 'Content-Type': 'image/x-icon' }
            })
        );
        return;
    }
    
    // Intercept cross-origin requests only for allowed CDNs (so we can cache and serve offline)
    if (url.origin !== self.location.origin && 
        !url.href.includes('cdn.jsdelivr.net') &&
        !url.href.includes('cdnjs.cloudflare.com') &&
        !url.href.includes('unpkg.com')) {
        return;
    }
    
    // Skip logout requests - let browser handle directly to prevent network errors
    // Check for both .php extension and clean URL
    if (url.pathname.includes('logout.php') || url.pathname.endsWith('/logout') || url.pathname === '/logout') {
        console.log('[SW] 🔐 Logout request detected - skipping service worker:', url.pathname);
        // Let the browser handle it directly without service worker intervention
        return;
    }
    
    // Skip file download/view requests - always fetch from network, never cache
    // This includes download endpoints, file viewer (PDF/docs), and file types that should be downloaded
    const downloadPatterns = [
        'download_template.php',
        'download.php',
        'view_file.php',  // Pardon supporting documents etc. - must not be intercepted (SW would corrupt PDF/binary)
        'generate_tardiness_report.php',
        'position_api.php',
        'station_api.php',
        'csv_batch_handler.php'
    ];
    
    const downloadFileExtensions = ['.xlsx', '.xls', '.csv', '.pdf', '.doc', '.docx', '.zip', '.rar'];
    
    const isDownloadRequest = 
        downloadPatterns.some(pattern => url.pathname.includes(pattern)) ||
        downloadFileExtensions.some(ext => url.pathname.toLowerCase().endsWith(ext)) ||
        url.searchParams.has('download') ||
        url.searchParams.get('download') === '1';
    
    if (isDownloadRequest) {
        console.log('[SW] 📥 Download request detected - skipping cache:', url.pathname);
        // Let the browser handle it directly without service worker intervention
        return;
    }
    
    // ===== API REQUESTS: work offline (GET = cache fallback, non-GET = 503 JSON) =====
    const isApiRequest = url.origin === self.location.origin && (
        url.pathname.includes('/api/') ||
        url.pathname.includes('_api.php') ||
        url.pathname.includes('api.php') ||
        url.pathname.includes('fetch_') ||
        url.pathname.includes('get_')
    );
    if (isApiRequest) {
        event.respondWith(
            (async () => {
                const req = event.request;
                const isGet = req.method === 'GET';
                try {
                    const response = await fetch(req, {
                        credentials: 'include',
                        cache: 'no-store'
                    });
                    if (isGet && response.ok && response.status === 200) {
                        const ct = (response.headers.get('content-type') || '').toLowerCase();
                        if (ct.includes('application/json') || ct.includes('text/plain') || ct.includes('text/html')) {
                            const apiCache = await caches.open(API_CACHE);
                            apiCache.put(req.url, response.clone());
                        }
                    }
                    return response;
                } catch (error) {
                    if (isGet) {
                        const cached = await caches.open(API_CACHE).then(c => c.match(req.url));
                        if (cached) {
                            console.log('[SW] ✅ API from cache (offline):', req.url);
                            return cached;
                        }
                    }
                    return new Response(JSON.stringify({
                        success: false,
                        message: 'No internet connection. ' + (isGet ? 'Cached data not available.' : 'Changes will sync when back online.'),
                        offline: true
                    }), {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: { 'Content-Type': 'application/json' }
                    });
                }
            })()
        );
        return;
    }
    
    // Skip non-GET requests (non-API only; API already handled above)
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Skip utility/debug pages - always fetch from network
    const utilityPages = [
        'clear_cache.php',
        'pwa_debug.php',
        'test_install.php',
        'unregister_sw.php',
        'check_pwa_installable.php'
    ];
    if (utilityPages.some(page => url.pathname.includes(page))) {
        console.log('[SW] Skipping utility page:', url.pathname);
        return;
    }
    
    // Handle login.php and station_login.php with network-first strategy, but allow offline access from cache
    // logout.php always uses network-only (should not be cached)
    if (url.pathname.includes('login.php') || url.pathname.includes('station_login.php')) {
        const pageName = url.pathname.includes('station_login.php') ? 'station_login.php' : 'login.php';
        console.log('[SW] 🔐 Login page - network first, cache fallback:', url.pathname);
        event.respondWith(
            (async () => {
                try {
                    // Try network first to get fresh CSRF token
                    const networkResponse = await fetch(event.request, { 
                        credentials: 'include', 
                        cache: 'no-store' 
                    });
                    
                    if (networkResponse.ok) {
                        // Cache the response for offline access
                        const responseClone = networkResponse.clone();
                        const cache = await caches.open(RUNTIME_CACHE);
                        // Validate before caching
                        const html = await responseClone.text();
                        if (isValidAppResponse(networkResponse, html)) {
                            await cache.put(event.request, new Response(html, {
                                status: 200,
                                headers: { 'Content-Type': 'text/html; charset=utf-8' }
                            }));
                            console.log('[SW] ✅ ' + pageName + ' cached for offline access');
                        }
                        return networkResponse;
                    }
                    throw new Error('Network response not ok');
                } catch (error) {
                    console.log('[SW] 📡 Network failed, trying cache for ' + pageName, error.message);
                    // Fallback to cache for offline access
                    let cachedResponse = await caches.match(event.request);
                    if (cachedResponse) {
                        console.log('[SW] ✅ Serving ' + pageName + ' from cache (offline mode)');
                        return cachedResponse;
                    }
                    // No cache available - try to get from OFFLINE_PAGES cache or return error
                    // Try variations of the URL
                    const urlVariations = [
                        event.request.url,
                        makeAbsoluteUrl(BASE_PATH + '/' + pageName),
                        new URL(event.request.url).origin + BASE_PATH + '/' + pageName
                    ];
                    for (const urlVar of urlVariations) {
                        cachedResponse = await caches.match(urlVar);
                        if (cachedResponse) {
                            console.log('[SW] ✅ Serving ' + pageName + ' from cache (variation)');
                            return cachedResponse;
                        }
                    }
                    // Last resort: return cached response or error page
                    console.log('[SW] ⚠️ No cache available for ' + pageName);
                    // Don't throw - return a response instead
                    return new Response('Network error and no cache available', { 
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: { 'Content-Type': 'text/plain' }
                    });
                }
            })()
        );
        return;
    }
    
    // Handle qrcode-scanner.php with network-first strategy, but allow offline access from cache
    // This enables QR scanning and attendance logging when offline
    if (url.pathname.includes('qrcode-scanner.php')) {
        console.log('[SW] 📷 QR Scanner page - network first, cache fallback:', url.pathname);
        event.respondWith(
            (async () => {
                // Helper function to try multiple URL variations in cache
                const tryCacheVariations = async (request) => {
                    const urlObj = new URL(request.url);
                    const variations = [
                        request.url,
                        urlObj.origin + urlObj.pathname,
                        makeAbsoluteUrl(BASE_PATH + '/timekeeper/qrcode-scanner.php'),
                        urlObj.origin + BASE_PATH + '/timekeeper/qrcode-scanner.php',
                        urlObj.origin + '/timekeeper/qrcode-scanner.php',
                        // Try without BASE_PATH
                        urlObj.origin + urlObj.pathname.replace(BASE_PATH, ''),
                        // Try with query params removed
                        urlObj.origin + urlObj.pathname
                    ];
                    
                    for (const urlVar of [...new Set(variations)]) {
                        try {
                            const cached = await caches.match(urlVar);
                            if (cached && cached.status === 200) {
                                // Validate cached response
                                const clone = cached.clone();
                                const html = await clone.text();
                                if (isValidAppResponse(cached, html)) {
                                    console.log('[SW] ✅ Found QR Scanner in cache:', urlVar);
                                    return new Response(html, {
                                        status: 200,
                                        headers: { 'Content-Type': 'text/html; charset=utf-8' }
                                    });
                                }
                            }
                        } catch (e) {
                            // Continue to next variation
                        }
                    }
                    return null;
                };
                
                try {
                    // Try network first to get fresh page (with timeout)
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 3000);
                    
                    try {
                        const networkResponse = await fetch(event.request, { 
                            credentials: 'include', 
                            cache: 'no-store',
                            signal: controller.signal
                        });
                        clearTimeout(timeoutId);
                        
                        if (networkResponse.ok) {
                            // Cache the response for offline access
                            const responseClone = networkResponse.clone();
                            const cache = await caches.open(RUNTIME_CACHE);
                            // Validate before caching
                            const html = await responseClone.text();
                            if (isValidAppResponse(networkResponse, html)) {
                                // Cache with multiple URL variations to ensure we can find it later
                                const urlVariations = [
                                    event.request.url,
                                    new URL(event.request.url).origin + new URL(event.request.url).pathname
                                ];
                                
                                for (const urlVar of urlVariations) {
                                    try {
                                        await cache.put(urlVar, new Response(html, {
                                            status: 200,
                                            headers: { 'Content-Type': 'text/html; charset=utf-8' }
                                        }));
                                    } catch (e) {
                                        // Continue if one fails
                                    }
                                }
                                console.log('[SW] ✅ QR Scanner page cached for offline access');
                            }
                            return networkResponse;
                        }
                        throw new Error('Network response not ok');
                    } catch (fetchError) {
                        clearTimeout(timeoutId);
                        if (fetchError.name === 'AbortError') {
                            console.log('[SW] 📡 Network timeout, trying cache');
                        } else {
                            throw fetchError;
                        }
                    }
                } catch (error) {
                    console.log('[SW] 📡 Network failed, trying cache for QR Scanner', error.message);
                }
                
                // Fallback to cache for offline access
                const cachedResponse = await tryCacheVariations(event.request);
                if (cachedResponse) {
                    console.log('[SW] ✅ Serving QR Scanner from cache (offline mode)');
                    return cachedResponse;
                }
                
                // Last resort: return error response instead of throwing
                console.log('[SW] ⚠️ No cache available for QR Scanner');
                return new Response('QR Scanner page not available offline. Please visit the page while online first to cache it.', { 
                    status: 503,
                    statusText: 'Service Unavailable',
                    headers: { 'Content-Type': 'text/plain' }
                });
            })()
        );
        return;
    }
    
    
    // ===== NAVIGATION REQUESTS (HTML pages) =====
    const isNavigation = event.request.mode === 'navigate' || 
                         (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) ||
                         url.pathname.match(/\.(php|html)$/i) ||
                         (!url.pathname.match(/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/i) && 
                          !url.pathname.includes('/api/'));
    
    if (isNavigation) {
        console.log('[SW] 🔄 Navigation request:', event.request.url);
        
        // NETWORK-FIRST strategy with validation
        event.respondWith(
            (async () => {
                // Helper: Fetch with timeout
                const fetchWithTimeout = async (request, timeout = 5000) => {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), timeout);
                    
                    try {
                        const response = await fetch(request, {
                            signal: controller.signal,
                            cache: 'no-store',
                            credentials: 'include'
                        });
                        clearTimeout(timeoutId);
                        return response;
                    } catch (error) {
                        clearTimeout(timeoutId);
                        throw error;
                    }
                };
                
                // Helper: Validate and cache response
                const validateAndCache = async (response, request) => {
                    if (!response || response.status !== 200 || !response.ok) {
                        return null;
                    }
                    
                    // Skip caching if this is a file download (check Content-Disposition header)
                    const contentDisposition = response.headers.get('content-disposition') || '';
                    if (contentDisposition.toLowerCase().includes('attachment')) {
                        console.log('[SW] 📥 Download response detected (attachment header) - skipping cache:', request.url);
                        return response; // Return response but don't cache it
                    }
                    
                    // Clone to read body
                    const clone = response.clone();
                    const html = await clone.text();
                    
                    // Validate it's actually our app
                    if (!isValidAppResponse(response, html)) {
                        console.log('[SW] ❌ Invalid response, not caching:', request.url);
                        return null;
                    }
                    
                    // Create new response to return (original was consumed)
                    const validResponse = new Response(html, {
                        status: 200,
                        statusText: 'OK',
                        headers: {
                            'Content-Type': 'text/html; charset=utf-8'
                        }
                    });
                    
                    // Cache the valid response
                    try {
                        const cache = await caches.open(RUNTIME_CACHE);
                        await cache.put(request, validResponse.clone());
                        console.log('[SW] ✅ Valid response cached:', request.url);
                    } catch (e) {
                        console.log('[SW] Cache put failed:', e);
                    }
                    
                    return validResponse;
                };
                
                // If fetch() followed redirects, we must return that Response as-is. Building a new
                // Response from response.text() drops redirect metadata; the address bar can stay on
                // /login while the body is the post-login page (e.g. dashboard), breaking deep links.
                const navigationFetchFollowedRedirect = (request, response) => {
                    if (!response || response.type !== 'basic') {
                        return false;
                    }
                    if (response.redirected) {
                        return true;
                    }
                    try {
                        return Boolean(response.url && response.url !== request.url);
                    } catch (e) {
                        return false;
                    }
                };
                
                // Helper: Get from cache
                const getFromCache = async (request) => {
                    const urlObj = new URL(request.url);
                    const pathname = urlObj.pathname;
                    const search = urlObj.search || '';
                    const origin = urlObj.origin;
                    const base = (BASE_PATH || '').toLowerCase();
                    const pathLc = pathname.toLowerCase();

                    // Same resource with or without .php (clean URLs vs direct .php)
                    const exactVariations = [
                        request.url,
                        origin + pathname + search
                    ];
                    if (/\.php$/i.test(pathname)) {
                        exactVariations.push(origin + pathname.replace(/\.php$/i, '') + search);
                    } else if (pathname.length > 0 && !pathname.endsWith('/')) {
                        exactVariations.push(origin + pathname + '.php' + search);
                    }

                    // Never fall back to home/login for arbitrary deep links — that served dashboard HTML
                    // while the address bar stayed on e.g. tarf_request_view.php (hard refresh fixed it).
                    const variations = [...exactVariations];
                    if (pathLc.includes('login.php') || pathLc.includes('station_login.php')) {
                        variations.push(
                            origin + BASE_PATH + '/login.php',
                            origin + BASE_PATH + '/station_login.php'
                        );
                    }
                    if (pathLc === base || pathLc === base + '/' ||
                        pathLc === base + '/home.php' || pathLc === base + '/index.php') {
                        variations.push(
                            origin + BASE_PATH + '/home.php',
                            origin + BASE_PATH + '/'
                        );
                    }
                    if (pathLc.includes('qrcode-scanner')) {
                        variations.push(
                            origin + BASE_PATH + '/timekeeper/qrcode-scanner.php',
                            origin + '/timekeeper/qrcode-scanner.php'
                        );
                    }

                    // Only read from the current runtime cache — global caches.match() would still
                    // return stale HTML from older SW versions (wrong body for this URL).
                    const runtimeCache = await caches.open(RUNTIME_CACHE);
                    for (const url of [...new Set(variations)]) {
                        const cached = await runtimeCache.match(url);
                        if (cached && cached.status === 200) {
                            // Skip if this is a cached download response
                            const contentDisposition = cached.headers.get('content-disposition') || '';
                            if (contentDisposition.toLowerCase().includes('attachment')) {
                                console.log('[SW] 📥 Cached download response detected - deleting from cache:', url);
                                await runtimeCache.delete(url);
                                continue; // Skip this cached response and try next variation
                            }
                            
                            // Validate cached response
                            const clone = cached.clone();
                            const html = await clone.text();
                            if (isValidAppResponse(cached, html)) {
                                // Return fresh response from cached HTML
                                return new Response(html, {
                                    status: 200,
                                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                                });
                            } else {
                                // Invalid cached response - delete it
                                console.log('[SW] 🗑️ Deleting invalid cached response:', url);
                                await runtimeCache.delete(url);
                            }
                        }
                    }
                    return null;
                };
                
                // STEP 1: Try network first (with 3 second timeout)
                try {
                    console.log('[SW] 📡 Trying network first...');
                    const networkResponse = await fetchWithTimeout(event.request, 3000);
                    
                    if (navigationFetchFollowedRedirect(event.request, networkResponse)) {
                        console.log('[SW] ↪ Navigation hit redirect chain — returning native Response (correct document URL)');
                        return networkResponse;
                    }
                    
                    const validResponse = await validateAndCache(networkResponse, event.request);
                    if (validResponse) {
                        console.log('[SW] ✅ Serving validated network response');
                        return validResponse;
                    }
                } catch (error) {
                    console.log('[SW] 📡 Network failed:', error.message);
                }
                
                // STEP 2: Network failed - try cache
                console.log('[SW] 💾 Trying cache...');
                const cachedResponse = await getFromCache(event.request);
                if (cachedResponse) {
                    console.log('[SW] ✅ Serving from cache');
                    
                    // Update cache in background
                    fetchWithTimeout(event.request, 10000)
                        .then(response => {
                            if (navigationFetchFollowedRedirect(event.request, response)) {
                                return null;
                            }
                            return validateAndCache(response, event.request);
                        })
                        .catch(() => {});
                    
                    return cachedResponse;
                }
                
                // STEP 3: No cache - try network with longer timeout
                console.log('[SW] 📡 Retrying network with longer timeout...');
                try {
                    const networkResponse = await fetchWithTimeout(event.request, 10000);
                    if (navigationFetchFollowedRedirect(event.request, networkResponse)) {
                        console.log('[SW] ↪ Extended fetch: redirect chain — returning native Response');
                        return networkResponse;
                    }
                    const validResponse = await validateAndCache(networkResponse, event.request);
                    if (validResponse) {
                        return validResponse;
                    }
                } catch (error) {
                    console.log('[SW] 📡 Extended network also failed:', error.message);
                }
                
                // STEP 4: Everything failed - show loader that auto-retries
                console.log('[SW] ⚠️ Returning loader page');
                return createLoaderPage(event.request.url);
            })()
        );
        return;
    }
    
    // ===== ASSET REQUESTS (CSS, JS, images) =====
    event.respondWith(
        (async () => {
            // Helper to determine MIME type from file extension
            const getMimeType = (pathname) => {
                if (pathname.endsWith('.css')) return 'text/css; charset=utf-8';
                if (pathname.endsWith('.js')) return 'application/javascript; charset=utf-8';
                if (pathname.endsWith('.json')) return 'application/json; charset=utf-8';
                if (pathname.endsWith('.png')) return 'image/png';
                if (pathname.endsWith('.jpg') || pathname.endsWith('.jpeg')) return 'image/jpeg';
                if (pathname.endsWith('.gif')) return 'image/gif';
                if (pathname.endsWith('.svg')) return 'image/svg+xml';
                if (pathname.endsWith('.woff')) return 'font/woff';
                if (pathname.endsWith('.woff2')) return 'font/woff2';
                if (pathname.endsWith('.ttf')) return 'font/ttf';
                if (pathname.endsWith('.otf')) return 'font/otf';
                return null;
            };
            
            // Try cache first for assets
            const cachedResponse = await caches.match(event.request);
            if (cachedResponse) {
                // Skip if this is a cached download response
                const contentDisposition = cachedResponse.headers.get('content-disposition') || '';
                if (contentDisposition.toLowerCase().includes('attachment')) {
                    console.log('[SW] 📥 Cached download response detected in assets - deleting from cache:', url.pathname);
                    const cache = await caches.open(RUNTIME_CACHE);
                    await cache.delete(event.request);
                    // Fall through to fetch from network (don't return cached response)
                } else {
                    // Ensure correct MIME type when serving from cache
                    const mimeType = getMimeType(url.pathname);
                    if (mimeType) {
                        // Clone response and set correct content type
                        const body = await cachedResponse.clone().blob();
                        const headers = new Headers(cachedResponse.headers);
                        headers.set('Content-Type', mimeType);
                        
                        // Return with correct MIME type
                        return new Response(body, {
                            status: cachedResponse.status,
                            statusText: cachedResponse.statusText,
                            headers: headers
                        });
                    }
                    
                    // Return cached version immediately for fast loading
                    // Also update cache in background for CSS/JS files
                    if (url.pathname.includes('/assets/') && 
                        (url.pathname.includes('.css') || url.pathname.includes('.js'))) {
                        // Update cache in background without blocking response
                        fetch(event.request).then(response => {
                            if (response && response.status === 200 && response.ok) {
                                caches.open(RUNTIME_CACHE).then(cache => {
                                    cache.put(event.request, response.clone());
                                });
                            }
                        }).catch(() => {
                            // Network failed, but we have cache - that's fine
                        });
                    }
                    return cachedResponse;
                }
            }
            
            // Not in cache, fetch from network
            try {
                const response = await fetch(event.request);
                
                // Skip caching if this is a file download (check Content-Disposition header)
                const contentDisposition = response.headers.get('content-disposition') || '';
                if (contentDisposition.toLowerCase().includes('attachment')) {
                    console.log('[SW] 📥 Download response detected (attachment header) - skipping cache:', url.pathname);
                    return response; // Return response but don't cache it
                }
                
                // Only cache successful responses (200 status)
                if (response && response.status === 200 && response.ok && response.type === 'basic') {
                    // Ensure correct MIME type before caching
                    const contentType = response.headers.get('content-type') || '';
                    const mimeType = getMimeType(url.pathname);
                    
                    // If response has wrong MIME type (e.g., text/html for CSS), fix it
                    if (mimeType && (!contentType || contentType.includes('text/html'))) {
                        const body = await response.clone().blob();
                        const headers = new Headers(response.headers);
                        headers.set('Content-Type', mimeType);
                        
                        const fixedResponse = new Response(body, {
                            status: 200,
                            statusText: 'OK',
                            headers: headers
                        });
                        
                        // Cache the fixed response
                        const responseToCache = fixedResponse.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => {
                            cache.put(event.request, responseToCache);
                        });
                        
                        return fixedResponse;
                    }
                    
                    // Cache the successful response
                    const responseToCache = response.clone();
                    caches.open(RUNTIME_CACHE).then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                } else {
                    // If it's an error response, make sure it's not cached
                    caches.open(RUNTIME_CACHE).then((cache) => {
                        cache.delete(event.request).catch(() => {});
                    }).catch(() => {});
                }
                
                return response;
            } catch (error) {
                // Network failed - return 404 for assets (don't cache errors)
                // Don't log errors for favicon.ico (it's common for it to not exist)
                if (!event.request.url.includes('favicon.ico')) {
                    console.log('[SW] Asset fetch failed:', event.request.url, error.message);
                }
                return new Response('', { status: 404, statusText: 'Not Found' });
            }
        })()
    );
});

// Create a loader page that shows immediately and fetches real content in background
function createLoaderPage(requestUrl) {
    const html = `
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>WPU Safe - Loading...</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #003366 0%, #001a33 100%);
                    color: white;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    text-align: center;
                    max-width: 400px;
                }
                .spinner {
                    width: 50px;
                    height: 50px;
                    border: 4px solid rgba(255,255,255,.3);
                    border-top-color: white;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin: 0 auto 24px;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                h1 {
                    font-size: 24px;
                    margin-bottom: 16px;
                    font-weight: 600;
                }
                p {
                    font-size: 16px;
                    opacity: 0.8;
                    margin-bottom: 24px;
                    line-height: 1.5;
                }
                .status {
                    font-size: 14px;
                    opacity: 0.6;
                    margin-top: 16px;
                }
                .buttons {
                    display: none;
                    margin-top: 24px;
                }
                .buttons.show {
                    display: block;
                }
                button {
                    background: white;
                    color: #003366;
                    border: none;
                    padding: 14px 28px;
                    border-radius: 8px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    margin: 8px;
                    transition: transform 0.2s, opacity 0.2s;
                }
                button:active {
                    transform: scale(0.95);
                }
                button.secondary {
                    background: rgba(255,255,255,0.2);
                    color: white;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="spinner" id="spinner"></div>
                <h1 id="title">Loading WPU Safe...</h1>
                <p id="message">Please wait while we connect to the server.</p>
                <div class="status" id="status">Attempt 1 of 10</div>
                <div class="buttons" id="buttons">
                    <button onclick="clearCacheAndRetry()">Clear Cache & Retry</button>
                    <button class="secondary" onclick="forceReload()">Force Reload</button>
                </div>
            </div>
            <script>
                (function() {
                    const targetUrl = '${requestUrl}';
                    const basePath = '${BASE_PATH}';
                    const appBase = location.origin + basePath;
                    // Ensure .php URL for retry (avoids .htaccess rewrite 404 on some servers)
                    function ensurePhpUrl(url) {
                        try {
                            const u = new URL(url);
                            const path = u.pathname;
                            if (path.match(/\\.(php|html|htm|asp|aspx)$/i)) return url;
                            u.pathname = path.replace(/\\/$/, '') + '.php';
                            return u.toString();
                        } catch (_) { return url; }
                    }
                    const retryUrl = ensurePhpUrl(targetUrl);
                    let retryCount = 0;
                    const maxRetries = 10;
                    let loaded = false;
                    
                    const statusEl = document.getElementById('status');
                    const buttonsEl = document.getElementById('buttons');
                    const titleEl = document.getElementById('title');
                    const messageEl = document.getElementById('message');
                    
                    function updateStatus(msg) {
                        statusEl.textContent = msg;
                    }
                    
                    function showButtons() {
                        buttonsEl.classList.add('show');
                        titleEl.textContent = 'Connection Issue';
                        messageEl.textContent = 'Having trouble connecting to the server.';
                    }
                    
                    // Validate response is actually our app
                    function isValidResponse(html) {
                        if (!html) return false;
                        // Reject Microsoft auth pages
                        if (html.includes('MicrosoftLogo') || html.includes('login.microsoftonline.com')) return false;
                        // Reject error pages
                        if (html.includes("can't reach") || html.includes('ERR_')) return false;
                        return true;
                    }
                    
                    function loadPage(urlIndex = 0) {
                        const urlVariations = [
                            targetUrl,
                            retryUrl,
                            appBase + '/home.php',
                            appBase + '/login.php',
                            appBase + '/station_login.php'
                        ];
                        const uniqueUrls = [...new Set(urlVariations)];
                        
                        if (loaded || urlIndex >= uniqueUrls.length) {
                            if (!loaded && retryCount < maxRetries) {
                                retryCount++;
                                updateStatus('Attempt ' + (retryCount + 1) + ' of ' + maxRetries);
                                setTimeout(() => loadPage(0), Math.min(1000 * retryCount, 3000));
                            } else if (!loaded) {
                                showButtons();
                                updateStatus('Connection failed after ' + maxRetries + ' attempts');
                            }
                            return;
                        }
                        
                        const urlToTry = uniqueUrls[urlIndex];
                        console.log('[Loader] Trying:', urlToTry);
                        
                        fetch(urlToTry, {
                            credentials: 'include',
                            cache: 'no-store',
                            headers: { 'Accept': 'text/html' }
                        })
                        .then(response => {
                            if (!response.ok || response.status !== 200) {
                                throw new Error('Bad response: ' + response.status);
                            }
                            return response.text();
                        })
                        .then(html => {
                            if (loaded) return;
                            
                            // Validate the response
                            if (!isValidResponse(html)) {
                                console.log('[Loader] Invalid response (auth/error page)');
                                throw new Error('Invalid response');
                            }
                            
                            loaded = true;
                            document.open();
                            document.write(html);
                            document.close();
                        })
                        .catch(error => {
                            console.log('[Loader] Failed:', urlToTry, error.message);
                            loadPage(urlIndex + 1);
                        });
                    }
                    
                    // Clear cache and retry
                    window.clearCacheAndRetry = async function() {
                        titleEl.textContent = 'Clearing Cache...';
                        messageEl.textContent = 'Please wait...';
                        buttonsEl.classList.remove('show');
                        
                        try {
                            // Clear all caches
                            const cacheNames = await caches.keys();
                            await Promise.all(cacheNames.map(name => caches.delete(name)));
                            console.log('[Loader] Caches cleared');
                            
                            // Tell service worker to clear too
                            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                                navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_ALL_CACHES' });
                            }
                            
                            // Unregister service worker
                            const registrations = await navigator.serviceWorker.getRegistrations();
                            await Promise.all(registrations.map(r => r.unregister()));
                            console.log('[Loader] Service workers unregistered');
                        } catch (e) {
                            console.log('[Loader] Error clearing:', e);
                        }
                        
                        // Hard reload (use retryUrl with .php to avoid rewrite 404)
                        setTimeout(() => {
                            const sep = retryUrl.includes('?') ? '&' : '?';
                            window.location.href = retryUrl + sep + 'nocache=' + Date.now();
                        }, 500);
                    };
                    
                    window.forceReload = function() {
                        window.location.reload(true);
                    };
                    
                    // Start loading
                    loadPage();
                    
                    // Show buttons after 8 seconds if still loading
                    setTimeout(() => {
                        if (!loaded) showButtons();
                    }, 8000);
                })();
            </script>
        </body>
        </html>
    `;
    return new Response(html, {
        headers: { 
            'Content-Type': 'text/html',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        }
    });
}

// Create a simple offline page with retry functionality
function createOfflinePage() {
    const html = `
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>WPU Safe - Loading...</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #003366 0%, #001a33 100%);
                    color: white;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    text-align: center;
                    max-width: 400px;
                }
                .icon {
                    font-size: 64px;
                    margin-bottom: 24px;
                    animation: pulse 2s infinite;
                }
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.5; }
                }
                h1 {
                    font-size: 24px;
                    margin-bottom: 16px;
                    font-weight: 600;
                }
                p {
                    font-size: 16px;
                    opacity: 0.8;
                    margin-bottom: 24px;
                    line-height: 1.5;
                }
                button {
                    background: white;
                    color: #003366;
                    border: none;
                    padding: 14px 32px;
                    border-radius: 8px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: transform 0.2s;
                    margin: 8px;
                }
                button:active {
                    transform: scale(0.95);
                }
                .loading {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    border: 3px solid rgba(255,255,255,.3);
                    border-radius: 50%;
                    border-top-color: white;
                    animation: spin 1s ease-in-out infinite;
                    margin-left: 10px;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="icon">📡</div>
                <h1>Connecting...</h1>
                <p>Please wait while we connect to the server.</p>
                <div>
                    <button onclick="retryConnection()">Retry Connection</button>
                    <button onclick="window.location.href='${BASE_PATH}/login.php'">Go to Login</button>
                </div>
            </div>
            <script>
                // Auto-retry after 2 seconds
                setTimeout(() => {
                    retryConnection();
                }, 2000);
                
                function retryConnection() {
                    window.location.reload();
                }
            </script>
        </body>
        </html>
    `;
    return new Response(html, {
        headers: { 'Content-Type': 'text/html' }
    });
}

// ===== BACKGROUND SYNC (for offline form submissions) =====
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync:', event.tag);
    
    if (event.tag === 'sync-attendance') {
        event.waitUntil(syncAttendance());
    }
    if (event.tag === 'sync-forms') {
        event.waitUntil(syncForms());
    }
});

async function syncAttendance() {
    console.log('[SW] Background sync: Starting attendance sync...');
    
    // Notify clients to sync their offline storage
    const clients = await self.clients.matchAll({ includeUncontrolled: true });
    clients.forEach(client => {
        client.postMessage({ 
            type: 'SYNC_ATTENDANCE',
            timestamp: Date.now()
        });
    });
    
    console.log('[SW] Background sync: Notified', clients.length, 'clients to sync');
}

async function syncForms() {
    // Similar to syncAttendance
    return Promise.resolve();
}

// ===== PUSH NOTIFICATIONS (for Android) =====
self.addEventListener('push', (event) => {
    console.log('[SW] Push notification received');
    
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'WPU Safe System';
    const options = {
        body: data.body || 'You have a new notification',
        icon: makeAbsoluteUrl(BASE_PATH + '/assets/icons/icon-192x192.png'),
        badge: makeAbsoluteUrl(BASE_PATH + '/assets/icons/icon-96x96.png'),
        tag: data.tag || 'default',
        data: {
            url: data.url || makeAbsoluteUrl(BASE_PATH + '/')
        },
        vibrate: [100, 50, 100],
        requireInteraction: data.requireInteraction || false,
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// ===== NOTIFICATION CLICK HANDLER =====
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked');
    event.notification.close();
    
    const urlToOpen = event.notification.data?.url || makeAbsoluteUrl(BASE_PATH + '/');
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Check if app is already open
                for (const client of clientList) {
                    if (client.url.includes(BASE_PATH) && 'focus' in client) {
                        client.navigate(urlToOpen);
                        return client.focus();
                    }
                }
                // Open new window if app isn't open
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

// ===== MESSAGE HANDLER =====
self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);
    
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    // Force clear all caches (for troubleshooting)
    if (event.data && event.data.type === 'CLEAR_ALL_CACHES') {
        console.log('[SW] 🧹 Force clearing all caches...');
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('[SW] 🗑️ Deleting:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            console.log('[SW] ✅ All caches cleared');
            // Notify the page
            if (event.source) {
                event.source.postMessage({ type: 'CACHES_CLEARED' });
            }
            // Also notify all clients
            self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'CACHES_CLEARED' });
                });
            });
        });
    }
    
    // Force update service worker
    if (event.data && event.data.type === 'FORCE_UPDATE') {
        console.log('[SW] Force update requested...');
        self.skipWaiting();
        // Clear caches and reload
        caches.keys().then(cacheNames => {
            return Promise.all(cacheNames.map(c => caches.delete(c)));
        }).then(() => {
            self.clients.matchAll().then(clients => {
                clients.forEach(client => client.navigate(client.url));
            });
        });
    }
});

console.log('[SW] Service Worker loaded - Version:', CACHE_VERSION);
