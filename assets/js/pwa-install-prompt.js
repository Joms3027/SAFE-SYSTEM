// PWA Install Prompt Handler for Mobile Devices
(function() {
    'use strict';

    let deferredPrompt;
    let installButton = null;
    
    // Detect device/browser
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    const isAndroid = /Android/.test(navigator.userAgent);
    const isChrome = /Chrome/.test(navigator.userAgent) && !/Edge|Edg/.test(navigator.userAgent);
    const isHTTPS = window.location.protocol === 'https:' || window.location.hostname === 'localhost';
    
    // Detect production environment (matches PHP logic from config.php)
    const isProduction = window.location.hostname !== 'localhost' && 
                        window.location.hostname !== '127.0.0.1' &&
                        !window.location.hostname.includes('.test') &&
                        !window.location.hostname.includes('.local') &&
                        !window.location.hostname.includes('xampp');

    // Show install instructions modal
    function showInstallInstructions() {
        // Remove existing modal if any
        const existingModal = document.getElementById('pwa-install-modal');
        if (existingModal) existingModal.remove();
        
        let instructions = '';
        let title = 'Install WPU Safe System';
        
        if (isIOS) {
            title = 'Add to Home Screen';
            instructions = `
                <div class="text-center mb-3">
                    <i class="fas fa-mobile-alt" style="font-size: 3rem; color: #003366;"></i>
                </div>
                <p class="mb-3">To install this app on your iPhone/iPad:</p>
                <ol class="text-start">
                    <li class="mb-2">Tap the <strong>Share</strong> button <i class="fas fa-share-square"></i> at the bottom of Safari</li>
                    <li class="mb-2">Scroll down and tap <strong>"Add to Home Screen"</strong> <i class="fas fa-plus-square"></i></li>
                    <li class="mb-2">Tap <strong>"Add"</strong> in the top right corner</li>
                </ol>
                <div class="alert alert-info mt-3" style="font-size: 0.875rem;">
                    <i class="fas fa-info-circle me-1"></i> 
                    iOS requires using Safari's share menu to install web apps.
                </div>
            `;
        } else if (isAndroid && !isHTTPS) {
            title = 'HTTPS Required';
            instructions = `
                <div class="text-center mb-3">
                    <i class="fas fa-lock" style="font-size: 3rem; color: #dc3545;"></i>
                </div>
                <p class="mb-3">To install this app, HTTPS is required.</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Please access this site via HTTPS to enable app installation.
                </div>
            `;
        } else if (isAndroid) {
            title = 'Install App';
            instructions = `
                <div class="text-center mb-3">
                    <i class="fas fa-mobile-alt" style="font-size: 3rem; color: #003366;"></i>
                </div>
                <p class="mb-3">To install this app on your Android device:</p>
                <ol class="text-start">
                    <li class="mb-2">Tap the <strong>menu button</strong> <i class="fas fa-ellipsis-v"></i> in Chrome</li>
                    <li class="mb-2">Select <strong>"Install app"</strong> or <strong>"Add to Home screen"</strong></li>
                    <li class="mb-2">Tap <strong>"Install"</strong> to confirm</li>
                </ol>
            `;
        } else {
            instructions = `
                <div class="text-center mb-3">
                    <i class="fas fa-desktop" style="font-size: 3rem; color: #003366;"></i>
                </div>
                <p class="mb-3">To install this app:</p>
                <ol class="text-start">
                    <li class="mb-2">Look for the install icon <i class="fas fa-download"></i> in your browser's address bar</li>
                    <li class="mb-2">Or use your browser's menu to find "Install" option</li>
                </ol>
            `;
        }
        
        const modal = document.createElement('div');
        modal.id = 'pwa-install-modal';
        modal.innerHTML = `
            <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-download me-2"></i>${title}</h5>
                            <button type="button" class="btn-close" id="pwa-modal-close"></button>
                        </div>
                        <div class="modal-body">
                            ${instructions}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="pwa-modal-dismiss">Got it</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Close handlers
        const closeModal = () => modal.remove();
        document.getElementById('pwa-modal-close').onclick = closeModal;
        document.getElementById('pwa-modal-dismiss').onclick = closeModal;
        modal.querySelector('.modal').onclick = (e) => {
            if (e.target === modal.querySelector('.modal')) closeModal();
        };
    }

    // Enhanced standalone detection
    function isStandaloneMode() {
        // Check display mode media query
        if (window.matchMedia('(display-mode: standalone)').matches) return true;
        if (window.matchMedia('(display-mode: fullscreen)').matches) return true;
        if (window.matchMedia('(display-mode: minimal-ui)').matches) return true;
        
        // iOS Safari standalone mode
        if (window.navigator.standalone === true) return true;
        
        // Check URL parameter
        if (window.location.search.includes('pwa=1')) return true;
        
        // Check if document has standalone class (set by pwa-meta.php)
        if (document.documentElement.classList.contains('standalone-mode')) return true;
        if (document.body.classList.contains('standalone-mode')) return true;
        
        // Heuristic: Check if window dimensions match screen (no browser chrome)
        // This is less reliable but can help
        const hasNoBrowserChrome = window.innerHeight >= screen.height * 0.9 && 
                                   window.innerWidth >= screen.width * 0.9;
        if (hasNoBrowserChrome && window.location.protocol === 'https:') {
            return true;
        }
        
        return false;
    }

    // Check if we're on login page
    function isLoginPage() {
        const pathname = window.location.pathname;
        return pathname.includes('login.php') || pathname.endsWith('/login') || pathname.endsWith('/login/');
    }

    // Create install button if it doesn't exist
    function createInstallButton() {
        // Only show install button on login page
        if (!isLoginPage()) {
            console.log('[PWA Install] Not on login page - hiding install button');
            // Remove floating button if exists
            const floatingBtn = document.getElementById('pwa-install-button');
            if (floatingBtn) {
                floatingBtn.remove();
            }
            return; // Not on login page
        }

        // Check if already installed - use enhanced detection
        if (isStandaloneMode()) {
            console.log('[PWA Install] App is already installed - hiding install button');
            // Remove floating button if exists
            const floatingBtn = document.getElementById('pwa-install-button');
            if (floatingBtn) {
                floatingBtn.remove();
            }
            return; // Already installed
        }

        // Check if floating button already exists
        if (document.getElementById('pwa-install-button')) {
            return;
        }

        // Create install button
        const button = document.createElement('button');
        button.id = 'pwa-install-button';
        button.className = 'btn btn-primary position-fixed';
        button.style.cssText = 'bottom: 20px; right: 20px; z-index: 9999; box-shadow: 0 4px 12px rgba(0, 51, 102, 0.4); border-radius: 50px; padding: 12px 24px; font-weight: 600; font-size: 14px; animation: pulse-install 2s infinite;';
        
        // Set appropriate button text based on device
        if (isIOS) {
            button.innerHTML = '<i class="fas fa-plus-circle" style="margin-right: 8px;"></i> Add to Home Screen';
        } else {
            button.innerHTML = '<i class="fas fa-download" style="margin-right: 8px;"></i> Install App';
        }
        
        button.setAttribute('aria-label', 'Install WPU Safe System App');
        
        // Add pulse animation
        if (!document.getElementById('pwa-install-styles')) {
            const style = document.createElement('style');
            style.id = 'pwa-install-styles';
            style.textContent = `
                @keyframes pulse-install {
                    0%, 100% {
                        transform: scale(1);
                        box-shadow: 0 4px 12px rgba(0, 51, 102, 0.4);
                    }
                    50% {
                        transform: scale(1.05);
                        box-shadow: 0 6px 16px rgba(0, 51, 102, 0.6);
                    }
                }
                #pwa-install-button:hover {
                    transform: scale(1.1) !important;
                    box-shadow: 0 8px 20px rgba(0, 51, 102, 0.6) !important;
                }
                @media (max-width: 768px) {
                    #pwa-install-button {
                        bottom: 15px !important;
                        right: 15px !important;
                        padding: 10px 20px !important;
                        font-size: 13px !important;
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        // Add click handler
        button.addEventListener('click', handleInstallClick);

        // Add to body
        document.body.appendChild(button);
        installButton = button;
    }

    // Handle install button click
    async function handleInstallClick(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Store installation source page for redirect after installation
        const currentPath = window.location.pathname;
        if (currentPath.includes('station_login.php')) {
            localStorage.setItem('pwa_install_source', 'station_login');
            console.log('[PWA Install] Storing installation source: station_login');
        } else if (currentPath.includes('qrcode-scanner.php')) {
            localStorage.setItem('pwa_install_source', 'qrcode_scanner');
            console.log('[PWA Install] Storing installation source: qrcode_scanner');
        } else {
            localStorage.setItem('pwa_install_source', 'default');
        }
        
        // In production: ONLY use native prompt, never show modal
        if (isProduction) {
            if (deferredPrompt) {
                try {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    console.log(`[PWA Install] Install prompt outcome: ${outcome}`);
                    deferredPrompt = null;
                    
                    // Hide install UI
                    if (installButton) installButton.style.display = 'none';
                } catch (error) {
                    console.error('[PWA Install] Error showing install prompt:', error);
                    // In production, don't show modal - just log error
                    console.log('[PWA Install] Native prompt not available in production');
                }
            } else {
                // In production: Native prompt not available - don't show modal
                console.log('[PWA Install] Native install prompt not available in production environment');
            }
        } else {
            // Development: Use native prompt if available, otherwise show instructions modal
            if (deferredPrompt) {
                try {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    console.log(`Install prompt outcome: ${outcome}`);
                    deferredPrompt = null;
                    
                    // Hide install UI
                    if (installButton) installButton.style.display = 'none';
                } catch (error) {
                    console.error('Error showing install prompt:', error);
                    showInstallInstructions();
                }
            } else {
                // No native prompt - show instructions (only in development)
                showInstallInstructions();
            }
        }
    }

    // Listen for beforeinstallprompt event (Android Chrome/Edge with HTTPS)
    window.addEventListener('beforeinstallprompt', (e) => {
        // Only handle on login page
        if (!isLoginPage()) {
            console.log('[PWA Install] Install prompt received but not on login page - ignoring');
            return;
        }

        // Don't show install button if already in standalone mode
        if (isStandaloneMode()) {
            console.log('[PWA Install] Install prompt received but app is already installed');
            return;
        }
        
        e.preventDefault();
        deferredPrompt = e;
        console.log('[PWA Install] Native install prompt available - ready for direct install');
        
        // Create install button (behavior differs in production vs development)
        createInstallButton();
    });

    // Listen for app installed event
    window.addEventListener('appinstalled', () => {
        console.log('PWA was installed');
        deferredPrompt = null;
        
        if (installButton) {
            installButton.style.display = 'none';
        }
        
        // Close any open modal
        const modal = document.getElementById('pwa-install-modal');
        if (modal) modal.remove();
        
        // Show success message
        if (typeof showNotification === 'function') {
            showNotification('App installed successfully!', 'You can now access WPU Safe System from your home screen.', 'success');
        } else {
            alert('App installed successfully! You can now access WPU Safe System from your home screen.');
        }
    });

    // Initialize on page load
    window.addEventListener('load', () => {
        // Only show install button on login page
        if (!isLoginPage()) {
            console.log('[PWA Install] Not on login page - removing any existing install buttons');
            const floatingBtn = document.getElementById('pwa-install-button');
            if (floatingBtn) floatingBtn.remove();
            return;
        }

        // Check if running as standalone app - use enhanced detection
        if (isStandaloneMode()) {
            console.log('[PWA Install] Running as installed PWA - no install button needed');
            // Ensure install button is hidden
            const floatingBtn = document.getElementById('pwa-install-button');
            if (floatingBtn) floatingBtn.remove();
            return;
        }

        // Show install button after delay (only if not in standalone mode and on login page)
        setTimeout(() => {
            if (!isStandaloneMode() && isLoginPage()) {
                // In production: Only create button if native prompt is available
                // In development: Create button anyway (will show modal if no native prompt)
                if (isProduction) {
                    if (deferredPrompt) {
                        createInstallButton();
                    } else {
                        console.log('[PWA Install] Production mode: Native prompt not available, not showing install button');
                    }
                } else {
                    // Development: Always create button (will show modal if needed)
                    createInstallButton();
                }
            }
        }, 1000);
    });
    
    // Continuously monitor for standalone mode changes (in case app is installed while open)
    setInterval(() => {
        if (isStandaloneMode() || !isLoginPage()) {
            const floatingBtn = document.getElementById('pwa-install-button');
            if (floatingBtn) floatingBtn.remove();
        }
    }, 2000);
})();

