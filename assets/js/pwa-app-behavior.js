/**
 * PWA App-Like Behavior Script
 * Makes the installed PWA behave like a native Android/iOS application
 */

(function() {
    'use strict';

    // Check if running as installed PWA (standalone or fullscreen)
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                        window.matchMedia('(display-mode: fullscreen)').matches ||
                        window.matchMedia('(display-mode: window-controls-overlay)').matches ||
                        window.navigator.standalone === true;
    
    const isFullscreen = window.matchMedia('(display-mode: fullscreen)').matches;
    const isAndroid = /Android/i.test(navigator.userAgent);
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    // Always expose app status globally
    window.isPWAStandalone = isStandalone;
    window.isAndroidPWA = isStandalone && isAndroid;
    window.isIOSPWA = isStandalone && isIOS;

    if (isStandalone) {
        console.log('[PWA] Running as NATIVE APP - No browser UI');
        console.log('[PWA] Platform:', isAndroid ? 'Android' : (isIOS ? 'iOS' : 'Desktop'));

        // Add platform-specific classes
        document.documentElement.classList.add('pwa-standalone');
        document.body.classList.add('pwa-standalone');
        
        if (isAndroid) {
            document.documentElement.classList.add('pwa-android');
            document.body.classList.add('pwa-android');
        }
        if (isIOS) {
            document.documentElement.classList.add('pwa-ios');
            document.body.classList.add('pwa-ios');
        }
        if (isFullscreen) {
            document.documentElement.classList.add('pwa-fullscreen');
            document.body.classList.add('pwa-fullscreen');
        }

        // ===== ANDROID BACK BUTTON HANDLING =====
        // This is CRITICAL for native Android app behavior
        if (isAndroid) {
            // Track navigation history for back button
            let historyStack = [window.location.href];
            let isNavigatingBack = false;

            // Push state on navigation to enable back button
            window.addEventListener('popstate', function(e) {
                isNavigatingBack = true;
                // Allow default browser behavior - go back
                if (historyStack.length > 1) {
                    historyStack.pop();
                }
            });

            // Track page changes
            const originalPushState = history.pushState;
            history.pushState = function() {
                originalPushState.apply(history, arguments);
                historyStack.push(window.location.href);
            };

            // Handle hardware back button - only exit if at app root
            window.addEventListener('load', function() {
                // Add initial history entry to prevent immediate exit
                if (history.length === 1) {
                    history.pushState({ pwa: true }, '', window.location.href);
                }
            });

            // Prevent accidental app exit
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    // ESC key on Android can close app - prevent if not at root
                    if (history.length > 1) {
                        e.preventDefault();
                        history.back();
                    }
                }
            });
        }

        // ===== HANDLE LINKS WITHIN THE APP =====
        // Keep all navigation within the PWA (don't open browser)
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');
            if (!href) return;

            // Skip if it's meant to open in new tab/window
            if (link.target === '_blank') {
                // For external links, open in browser
                return;
            }

            // Skip download links
            if (link.hasAttribute('download')) return;

            // Skip javascript: links
            if (href.startsWith('javascript:')) return;

            // Skip anchor links
            if (href.startsWith('#')) return;

            // Handle tel: and mailto: links normally
            if (href.startsWith('tel:') || href.startsWith('mailto:')) return;

            // Check if this is an internal link
            try {
                const url = new URL(href, window.location.origin);
                const isInternal = url.origin === window.location.origin;
                
                if (isInternal) {
                    // Navigate within the app
                    e.preventDefault();
                    window.location.href = url.href;
                } else {
                    // External link - open in default browser (not in-app browser)
                    // On Android PWA, this will open the user's default browser
                    e.preventDefault();
                    window.open(url.href, '_blank', 'noopener,noreferrer');
                }
            } catch (error) {
                // Invalid URL, let browser handle it
                console.log('[PWA] Could not parse URL:', href);
            }
        }, true);

        // ===== SERVICE WORKER INTEGRATION =====
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then(registration => {
                console.log('[PWA] Service Worker active - App is ready');
                
                // Request notification permission for Android
                if (isAndroid && 'Notification' in window && Notification.permission === 'default') {
                    // Delay permission request until user interacts
                    document.addEventListener('click', function requestNotificationOnce() {
                        Notification.requestPermission().then(permission => {
                            console.log('[PWA] Notification permission:', permission);
                        });
                        document.removeEventListener('click', requestNotificationOnce);
                    }, { once: true });
                }
            });
        }

        // ===== APP LIFECYCLE HANDLERS =====
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                console.log('[PWA] App went to background');
                // Save any pending data
                if (typeof saveAppState === 'function') {
                    saveAppState();
                }
            } else {
                console.log('[PWA] App came to foreground');
                // Refresh data when app resumes
                if (typeof refreshAppData === 'function') {
                    refreshAppData();
                }
            }
        });

        // ===== NETWORK STATUS =====
        window.addEventListener('online', () => {
            console.log('[PWA] Network: Online');
            document.body.classList.remove('pwa-offline');
            document.body.classList.add('pwa-online');
            
            // Show toast notification
            showPWAToast('Back online', 'success');
            
            // Trigger data sync
            if (typeof syncOfflineData === 'function') {
                syncOfflineData();
            }
        });

        window.addEventListener('offline', () => {
            console.log('[PWA] Network: Offline');
            document.body.classList.remove('pwa-online');
            document.body.classList.add('pwa-offline');
            
            // Show toast notification
            showPWAToast('You are offline. Some features may be limited.', 'warning');
        });

        // ===== PREVENT BROWSER-LIKE BEHAVIORS =====
        // Prevent pull-to-refresh (but allow normal scroll)
        let touchStartY = 0;
        document.addEventListener('touchstart', function(e) {
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            const touchY = e.touches[0].clientY;
            const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
            
            // If at top of page and pulling down, prevent refresh
            if (scrollTop === 0 && touchY > touchStartY) {
                // Check if we're in a scrollable container
                let target = e.target;
                while (target && target !== document.body) {
                    if (target.scrollTop > 0) {
                        return; // Allow scroll in container
                    }
                    target = target.parentElement;
                }
            }
        }, { passive: true });

        // Disable long-press context menu on images (feels more native)
        document.addEventListener('contextmenu', function(e) {
            if (e.target.tagName === 'IMG') {
                e.preventDefault();
            }
        });

        // ===== INJECT NATIVE APP STYLES =====
        const appStyle = document.createElement('style');
        appStyle.id = 'pwa-native-styles';
        appStyle.textContent = `
            /* ===== ANDROID PWA NATIVE APP STYLES ===== */
            
            /* Full screen height - no browser chrome */
            html.pwa-standalone,
            html.pwa-android {
                height: 100%;
                height: 100dvh;
                height: -webkit-fill-available;
                overflow: hidden;
            }
            
            body.pwa-standalone,
            body.pwa-android {
                min-height: 100%;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
                margin: 0;
                padding: 0;
                overflow-x: hidden;
                overflow-y: auto;
                /* Prevent overscroll bounce that reveals empty space */
                overscroll-behavior: none;
                overscroll-behavior-y: contain;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Status bar area padding for Android */
            .pwa-android .navbar,
            .pwa-android .admin-navbar,
            .pwa-android header,
            .pwa-android .app-header {
                padding-top: max(0.5rem, env(safe-area-inset-top, 0px));
            }
            
            /* Bottom navigation safe area */
            .pwa-android .bottom-nav,
            .pwa-android .mobile-nav,
            .pwa-android footer {
                padding-bottom: env(safe-area-inset-bottom, 0px);
            }
            
            /* Native tap highlight */
            .pwa-standalone *,
            .pwa-android * {
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.1);
            }
            
            /* Smooth scrolling in content areas */
            .pwa-android .main-content,
            .pwa-android .content-wrapper,
            .pwa-android main {
                -webkit-overflow-scrolling: touch;
            }
            
            /* Hide scrollbars on Android (looks more native) */
            .pwa-android::-webkit-scrollbar,
            .pwa-android *::-webkit-scrollbar {
                width: 0px;
                background: transparent;
            }
            
            /* Disable text selection on navigation elements */
            .pwa-android .navbar,
            .pwa-android .nav-link,
            .pwa-android .btn,
            .pwa-android button {
                -webkit-user-select: none;
                user-select: none;
            }
            
            /* Fullscreen wrapper */
            .pwa-android #wrapper,
            .pwa-android .app-wrapper {
                display: flex;
                flex-direction: column;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
            }
            
            /* Offline indicator */
            .pwa-offline::after {
                content: 'Offline Mode';
                position: fixed;
                bottom: 70px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(255, 152, 0, 0.95);
                color: #000;
                padding: 8px 16px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                z-index: 99999;
                animation: slideUp 0.3s ease-out;
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateX(-50%) translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateX(-50%) translateY(0);
                }
            }
            
            /* PWA Toast Notification */
            .pwa-toast {
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%) translateY(100px);
                background: #323232;
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 99999;
                opacity: 0;
                transition: all 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                max-width: 90%;
                text-align: center;
            }
            
            .pwa-toast.show {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
            
            .pwa-toast.success {
                background: #4CAF50;
            }
            
            .pwa-toast.warning {
                background: #FF9800;
                color: #000;
            }
            
            .pwa-toast.error {
                background: #f44336;
            }
            
            /* ===== iOS SPECIFIC ===== */
            .pwa-ios {
                height: -webkit-fill-available;
            }
            
            .pwa-ios body {
                min-height: -webkit-fill-available;
            }
        `;
        document.head.appendChild(appStyle);

        console.log('[PWA] Native app mode initialized');
    } else {
        console.log('[PWA] Running in browser mode - Install for native experience');
    }

    // ===== TOAST NOTIFICATION FUNCTION =====
    window.showPWAToast = function(message, type = 'info', duration = 3000) {
        // Remove existing toast
        const existing = document.querySelector('.pwa-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `pwa-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
        
        // Auto-hide
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };

    // ===== REGISTER UPDATE HANDLER =====
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then(registration => {
            // Check for updates periodically
            setInterval(() => {
                registration.update();
            }, 60 * 60 * 1000); // Check every hour

            // Handle updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New version available
                        if (isStandalone) {
                            showPWAToast('Update available! Tap to refresh.', 'info', 5000);
                            document.querySelector('.pwa-toast').addEventListener('click', () => {
                                window.location.reload();
                            });
                        }
                    }
                });
            });
        });
    }
})();
