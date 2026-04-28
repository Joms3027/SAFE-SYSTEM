/**
 * Unified Mobile Interactions
 * Handles all mobile button interactions without conflicts
 * Version: 2.0 - Simplified and fixed for mobile devices
 */

(function() {
    'use strict';
    
    // Wait for DOM and Bootstrap to be ready
    function init() {
        // Wait for Bootstrap to be available
        if (typeof bootstrap === 'undefined') {
            console.warn('Bootstrap not loaded yet, retrying...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('Mobile Interactions: Initializing');
        
        // Fix all buttons and interactive elements
        fixAllButtons();
        
        // Fix overlay blocking
        fixOverlayBlocking();
        
        // Watch for dynamically added elements
        watchForNewElements();
    }
    
    /**
     * Fix all buttons and interactive elements for mobile
     * CRITICAL: Don't prevent default on touch events - let browser handle it
     */
    function fixAllButtons() {
        const buttons = document.querySelectorAll('button, .btn, a.btn, input[type="submit"], input[type="button"], [role="button"], .btn-action, .submit-requirement-btn, .attach-requirement-btn');
        
        buttons.forEach(function(button) {
            // Skip if disabled or already fixed
            if (button.disabled || button.dataset.mobileFixed === 'true') {
                return;
            }
            // Skip filter chips (submissions page) - they use event delegation and their own styles
            if (button.classList.contains('filter-chip')) {
                return;
            }
            
            button.dataset.mobileFixed = 'true';
            
            // Apply CSS fixes
            button.style.touchAction = 'manipulation';
            button.style.webkitTapHighlightColor = 'rgba(0, 51, 102, 0.2)';
            button.style.cursor = 'pointer';
            button.style.pointerEvents = 'auto';
            
            // Ensure minimum touch target (44x44px)
            ensureMinimumTouchTarget(button);
            
            // Special handling for requirements page buttons
            // These buttons have their own click handlers in requirements.js
            // We need to ensure they work but don't interfere with their handlers
            const isRequirementsButton = button.classList.contains('submit-requirement-btn') || 
                                        button.classList.contains('attach-requirement-btn');
            
            if (isRequirementsButton) {
                // For requirements buttons, just ensure they're clickable
                // Don't add event listeners - let requirements.js handle them
                button.style.position = 'relative';
                button.style.zIndex = '1000';
                return; // Exit early - don't add event listeners
            }
            
            // Check if button is inside a clickable parent (like table row with onclick)
            const clickableParent = button.closest('[onclick], tr[onclick], .clickable, [data-href]');
            
            if (clickableParent && clickableParent !== button) {
                // CRITICAL FIX: Stop click events from reaching parent, but DON'T interfere with touch
                // Browser needs touch events to fire click - only intercept the click event
                
                // Handle click in capture phase (fires before parent handlers)
                // But use a lower priority than requirements.js handlers
                const clickHandler = function(e) {
                    // Stop click from reaching parent's onclick handler
                    e.stopPropagation();
                    // DON'T use stopImmediatePropagation - let other handlers work
                    // DON'T prevent default - let button's action work
                };
                
                // Add listener with lower priority (bubbling phase for most, capture only if needed)
                button.addEventListener('click', clickHandler, false); // false = bubbling phase
                
                // Handle mousedown (desktop) 
                button.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                }, false);
                
                // For touch events: DON'T stop propagation or prevent default
                // Let browser convert touch to click naturally
                button.addEventListener('touchstart', function(e) {
                    // Mark that touch started on button (for debugging)
                    button.dataset.touchStarted = 'true';
                    // DON'T stop propagation - browser needs this
                    // DON'T prevent default - browser needs this to fire click
                }, { passive: true }); // passive = don't block scrolling
                
                button.addEventListener('touchend', function(e) {
                    // Don't interfere - let browser convert touch to click
                    // Our click handler (above) will intercept the click
                    setTimeout(function() {
                        button.dataset.touchStarted = 'false';
                    }, 100);
                }, { passive: true });
                
                // Ensure button is above parent in stacking order
                button.style.position = 'relative';
                button.style.zIndex = '1000';
            }
        });
    }
    
    /**
     * Ensure minimum touch target size (44x44px recommended by iOS HIG)
     */
    function ensureMinimumTouchTarget(element) {
        // Skip if element has data-no-mobile-padding attribute
        if (element.dataset.noMobilePadding === 'true') {
            return;
        }
        
        const minSize = 44;
        const rect = element.getBoundingClientRect();
        const computed = window.getComputedStyle(element);
        
        // Skip if element is already large enough
        if (rect.height >= minSize && rect.width >= minSize) {
            return;
        }
        
        // For small elements, add padding
        if (rect.height < minSize) {
            const currentPaddingTop = parseFloat(computed.paddingTop) || 0;
            const currentPaddingBottom = parseFloat(computed.paddingBottom) || 0;
            const neededPadding = Math.max(0, (minSize - rect.height) / 2);
            
            if (neededPadding > 0) {
                element.style.paddingTop = (currentPaddingTop + neededPadding) + 'px';
                element.style.paddingBottom = (currentPaddingBottom + neededPadding) + 'px';
            }
        }
        
        if (rect.width < minSize) {
            const currentPaddingLeft = parseFloat(computed.paddingLeft) || 0;
            const currentPaddingRight = parseFloat(computed.paddingRight) || 0;
            const neededPadding = Math.max(0, (minSize - rect.width) / 2);
            
            if (neededPadding > 0) {
                element.style.paddingLeft = (currentPaddingLeft + neededPadding) + 'px';
                element.style.paddingRight = (currentPaddingRight + neededPadding) + 'px';
            }
        }
    }
    
    /**
     * Fix overlay blocking buttons when hidden
     */
    function fixOverlayBlocking() {
        const overlay = document.getElementById('sidebar-overlay');
        
        if (overlay) {
            function updateOverlayState() {
                const computed = window.getComputedStyle(overlay);
                const isHidden = overlay.classList.contains('d-none') ||
                                overlay.style.display === 'none' ||
                                computed.display === 'none' ||
                                (!overlay.classList.contains('show') && computed.visibility === 'hidden');
                
                if (isHidden) {
                    overlay.style.pointerEvents = 'none';
                    overlay.style.zIndex = '-1';
                    overlay.setAttribute('aria-hidden', 'true');
                } else {
                    overlay.style.pointerEvents = 'auto';
                    overlay.style.zIndex = '1026';
                    overlay.removeAttribute('aria-hidden');
                }
            }
            
            // Watch for changes
            const observer = new MutationObserver(updateOverlayState);
            observer.observe(overlay, {
                attributes: true,
                attributeFilter: ['class', 'style']
            });
            
            // Initial state
            updateOverlayState();
            
            // Also check on resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(updateOverlayState, 100);
            }, { passive: true });
        }
    }
    
    /**
     * Watch for dynamically added elements
     */
    function watchForNewElements() {
        const observer = new MutationObserver(function(mutations) {
            let hasNewButtons = false;
            
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('button, .btn, a.btn, [role="button"]')) {
                            hasNewButtons = true;
                        } else if (node.querySelector && node.querySelector('button, .btn, a.btn, [role="button"]')) {
                            hasNewButtons = true;
                        }
                    }
                });
            });
            
            if (hasNewButtons) {
                // Reset fixed flag for new buttons
                setTimeout(function() {
                    document.querySelectorAll('button, .btn, a.btn, [role="button"]').forEach(function(btn) {
                        if (!btn.dataset.mobileFixed) {
                            btn.dataset.mobileFixed = null;
                        }
                    });
                    fixAllButtons();
                }, 50);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM is already ready, but wait a bit for Bootstrap
        setTimeout(init, 50);
    }
    
    // Also run on window load to catch late-loading content
    window.addEventListener('load', function() {
        setTimeout(fixAllButtons, 100);
    });
    
    // Export public API
    window.mobileInteractions = {
        fixAll: fixAllButtons,
        fixOverlay: fixOverlayBlocking
    };
    
})();

