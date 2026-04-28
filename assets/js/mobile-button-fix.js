/**
 * Mobile Button Interaction Fix
 * Ensures all buttons work properly on mobile devices
 * FIXED: Completely removed event interference - buttons now work naturally
 */

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        // ONLY fix overlay blocking - don't interfere with button events
        ensureButtonAccessibility();
        
        // Fix buttons inside clickable containers
        fixButtonsInClickableContainers();
        
        // CRITICAL: Fix buttons inside table rows with onclick (most common issue)
        fixButtonsInTableRows();
    }
    
    /**
     * CRITICAL FIX: Ensure buttons inside table rows with onclick work on mobile
     * This is the most common issue - table rows with onclick prevent button clicks
     */
    function fixButtonsInTableRows() {
        // Find all buttons inside table rows with onclick
        const buttonsInRows = document.querySelectorAll('tr[onclick] button, tr[onclick] .btn, tr[onclick] a.btn, [onclick] button, [onclick] .btn, [onclick] a.btn');
        
        buttonsInRows.forEach(function(button) {
            // Skip if already fixed
            if (button.dataset.tableRowFixed === 'true') {
                return;
            }
            button.dataset.tableRowFixed = 'true';
            
            // CRITICAL FIX: Stop click events from reaching parent, but DON'T stop touch events
            // Browser needs touch events to synthesize click - only intercept the click
            
            // Handle CLICK in capture phase - this fires AFTER browser converts touch to click
            button.addEventListener('click', function(e) {
                // Stop click from reaching parent row's onclick
                e.stopPropagation();
                e.stopImmediatePropagation();
                // DON'T prevent default - let button's href/form action work
            }, true); // true = capture phase (fires before parent handlers)
            
            // Handle mousedown (desktop) in capture phase
            button.addEventListener('mousedown', function(e) {
                // Stop mousedown from reaching parent
                e.stopPropagation();
            }, true);
            
            // For touch events: DON'T stop propagation - let browser handle touch-to-click
            // But ensure touch starts on button
            button.addEventListener('touchstart', function(e) {
                // Mark that touch started on button - don't stop propagation
                button.dataset.touchStarted = 'true';
            }, { passive: true, capture: false });
            
            // On touchend, let browser convert to click naturally
            button.addEventListener('touchend', function(e) {
                // Don't stop propagation - browser needs this to fire click
                // Just mark that this was a button touch
                if (button.dataset.touchStarted === 'true') {
                    // Browser will fire click event after touchend
                    // Our click handler (above) will intercept it
                }
                button.dataset.touchStarted = 'false';
            }, { passive: true, capture: false });
            
            // Ensure button is fully interactive
            button.style.pointerEvents = 'auto';
            button.style.zIndex = '1000';
            button.style.position = 'relative';
            button.style.touchAction = 'manipulation';
            button.style.cursor = 'pointer';
            
            // Remove any onclick handlers that might interfere
            // But keep the button's href or form action
            if (button.onclick && button.tagName !== 'A') {
                // For buttons, preserve onclick if it's different from parent
                var originalOnclick = button.onclick;
                button.onclick = function(e) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    if (originalOnclick) {
                        originalOnclick.call(this, e);
                    }
                };
            }
        });
        
        // Also handle dynamically added buttons
        const observer = new MutationObserver(function(mutations) {
            let hasNewButtons = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.matches && (node.matches('tr[onclick], [onclick]') || 
                            (node.querySelector && (node.querySelector('tr[onclick] button, tr[onclick] .btn, [onclick] button, [onclick] .btn'))))) {
                            hasNewButtons = true;
                        }
                    }
                });
            });
            if (hasNewButtons) {
                setTimeout(function() {
                    // Reset fixed flag for new buttons
                    document.querySelectorAll('tr[onclick] button, tr[onclick] .btn, [onclick] button, [onclick] .btn').forEach(function(btn) {
                        if (!btn.dataset.tableRowFixed) {
                            btn.dataset.tableRowFixed = null;
                        }
                    });
                    fixButtonsInTableRows();
                }, 100);
            }
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    }
    
    /**
     * Fix buttons inside clickable containers (like table rows with onclick)
     * This ensures buttons work even when inside clickable parent elements
     * CRITICAL FIX: Handles both click and touch events properly on mobile
     */
    function fixButtonsInClickableContainers() {
        // Find all buttons and links
        const interactiveElements = document.querySelectorAll('button, .btn, a.btn, input[type="submit"], input[type="button"], [role="button"]');
        
        interactiveElements.forEach(function(button) {
            // Skip if already processed
            if (button.dataset.buttonFixed === 'true') {
                return;
            }
            button.dataset.buttonFixed = 'true';
            
            // Check if button is inside a clickable parent (has onclick attribute or click handler)
            const clickableParent = button.closest('[onclick], tr[onclick], .clickable, [data-href]');
            
            if (clickableParent && clickableParent !== button) {
                // CRITICAL FIX: For buttons inside clickable parents, we need to:
                // 1. Stop click events from bubbling to parent (in capture phase)
                // 2. NOT interfere with touch events - let browser handle touch-to-click conversion
                // 3. Ensure the button's click fires BEFORE parent's onclick
                
                // Handle click events in CAPTURE phase (fires before parent handlers)
                // This ensures button click fires first and stops propagation
                button.addEventListener('click', function(e) {
                    // Stop event from reaching parent's onclick handler
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    // DON'T prevent default - let button's default action work
                }, true); // true = use capture phase
                
                // For touch devices, we need to ensure the click event fires
                // The browser automatically converts touch to click, but we need to
                // ensure the parent doesn't intercept it
                
                // Mark button as interactive on touchstart
                button.addEventListener('touchstart', function(e) {
                    // Store that this touch started on a button
                    button.dataset.touching = 'true';
                    // Don't prevent default - let browser handle it
                }, { passive: true, capture: false });
                
                // On touchend, let browser convert to click naturally
                // But ensure parent doesn't intercept
                button.addEventListener('touchend', function(e) {
                    // Don't prevent default - this allows browser to fire click
                    // Don't stop propagation yet - let click event fire first
                    button.dataset.touching = 'true';
                    
                    // After a brief delay, ensure parent doesn't get the event
                    // But DO NOT prevent the click from firing
                    setTimeout(function() {
                        button.dataset.touching = 'false';
                    }, 300);
                }, { passive: true, capture: false });
                
                // Also handle mousedown (for desktop) in capture phase
                button.addEventListener('mousedown', function(e) {
                    // Stop mousedown from reaching parent
                    e.stopPropagation();
                }, true);
            }
            
            // Ensure all buttons have proper CSS and are clickable
            button.style.touchAction = 'manipulation';
            button.style.webkitTapHighlightColor = 'rgba(0, 51, 102, 0.2)';
            if (!button.style.pointerEvents || button.style.pointerEvents === 'none') {
                button.style.pointerEvents = 'auto';
            }
            if (!button.style.zIndex || button.style.zIndex === 'auto') {
                button.style.zIndex = '101';
            }
            if (!button.style.position || button.style.position === 'static') {
                button.style.position = 'relative';
            }
            
            // Ensure button is not disabled by CSS
            button.style.cursor = 'pointer';
            button.style.userSelect = 'none';
            button.style.webkitUserSelect = 'none';
        });
        
        // Also handle dynamically added buttons
        const observer = new MutationObserver(function(mutations) {
            let hasNewButtons = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.matches && (node.matches('button, .btn, a.btn, [role="button"]') || node.querySelector('button, .btn, a.btn, [role="button"]'))) {
                            hasNewButtons = true;
                        }
                    }
                });
            });
            if (hasNewButtons) {
                // Reset processed flag for new buttons
                setTimeout(function() {
                    document.querySelectorAll('button, .btn, a.btn, [role="button"]').forEach(function(btn) {
                        if (!btn.dataset.buttonFixed) {
                            btn.dataset.buttonFixed = null;
                        }
                    });
                    fixButtonsInClickableContainers();
                }, 50);
            }
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    }
    
    /**
     * Ensure buttons are not blocked by overlays
     * This is the critical fix - overlays must not block when hidden
     */
    function ensureButtonAccessibility() {
        // Check sidebar overlay - this is the main culprit
        const overlay = document.getElementById('sidebar-overlay');
        if (overlay) {
            // Function to update overlay state
            function updateOverlayState() {
                const computedStyle = window.getComputedStyle(overlay);
                const isHidden = overlay.classList.contains('d-none') || 
                                overlay.style.display === 'none' || 
                                computedStyle.display === 'none' ||
                                (!overlay.classList.contains('show') && computedStyle.visibility === 'hidden');
                
                if (isHidden) {
                    overlay.style.pointerEvents = 'none';
                    overlay.style.zIndex = '-1';
                    overlay.style.display = 'none';
                    overlay.style.visibility = 'hidden';
                    overlay.setAttribute('aria-hidden', 'true');
                } else {
                    overlay.style.pointerEvents = 'auto';
                    overlay.style.zIndex = '1026';
                    overlay.removeAttribute('aria-hidden');
                }
            }
            
            // Set initial state
            updateOverlayState();
            
            // Watch for changes to overlay visibility
            const observer = new MutationObserver(function() {
                updateOverlayState();
            });
            
            observer.observe(overlay, { 
                attributes: true, 
                attributeFilter: ['class', 'style'],
                childList: false,
                subtree: false
            });
            
            // Also check on resize (breakpoint changes)
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(updateOverlayState, 50);
            }, { passive: true });
            
            // Check periodically to ensure overlay state is correct
            const checkInterval = setInterval(function() {
                updateOverlayState();
            }, 200);
            
            // Stop checking after page unload
            window.addEventListener('beforeunload', function() {
                clearInterval(checkInterval);
            });
        }
        
        // Check for modal backdrops when hidden - DISABLED to prevent interference with requirements modals
        // const checkModalBackdrops = function() {
        //     document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        //         const computedStyle = window.getComputedStyle(backdrop);
        //         const isHidden = backdrop.classList.contains('d-none') || 
        //                         backdrop.style.display === 'none' ||
        //                         computedStyle.display === 'none';
        //         const isModalOpen = document.body.classList.contains('modal-open');
        //         
        //         // Only hide backdrop if modal is actually closed AND backdrop is hidden
        //         if (isHidden && !isModalOpen) {
        //             backdrop.style.pointerEvents = 'none';
        //             backdrop.style.zIndex = '-1';
        //             backdrop.style.display = 'none';
        //         } else if (isModalOpen && backdrop.classList.contains('show')) {
        //             // Ensure backdrop has correct z-index when modal is open
        //             backdrop.style.zIndex = '1040';
        //             backdrop.style.position = 'fixed';
        //         }
        //     });
        // };
        // 
        // checkModalBackdrops();
        // setInterval(checkModalBackdrops, 500);
    }
    
    // Export function to manually fix buttons if needed
    window.fixMobileButtons = fixButtonsInClickableContainers;
    
    // Also run on window load to catch any late-loading content
    window.addEventListener('load', function() {
        setTimeout(function() {
            fixButtonsInClickableContainers();
            ensureButtonAccessibility();
        }, 100);
    });
})();
