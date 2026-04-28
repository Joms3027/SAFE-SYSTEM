/**
 * Faculty Mobile Interactions
 * Ensures all buttons and interactive elements work properly on mobile devices
 * This file should be loaded AFTER mobile-enhanced.js to override any interference
 * 
 * Version: 1.0.0
 * Date: 2025
 */

(function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        // Minimum touch target size (iOS HIG recommends 44x44)
        minTouchTarget: 44,
        // Delay for double-tap prevention (ms)
        tapDelay: 300,
        // Classes that should always be interactive
        interactiveSelectors: 'button, .btn, a.btn, input[type="submit"], input[type="button"], input[type="file"], select, textarea, [role="button"], [onclick], .nav-link, .page-link, .dropdown-item, .list-group-item-action, label[for], .submission-actions .btn, .submission-actions button, .submission-actions a, .btn-action, .submit-requirement-btn, .attach-requirement-btn, .requirement-card-footer .btn, .requirement-card-footer button, .requirement-card-footer a'
    };
    
    // State
    let lastTapTime = 0;
    let lastTapTarget = null;
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        console.log('Faculty Mobile Interactions: Initializing');
        
        // Fix all buttons and interactive elements
        fixAllInteractiveElements();
        
        // Fix buttons inside clickable containers
        fixButtonsInClickableContainers();
        
        // Fix buttons in cards
        fixButtonsInCards();
        
        // Fix form elements
        fixFormElements();
        
        // Watch for dynamically added elements
        watchForNewElements();
        
        // Ensure overlay doesn't block buttons
        fixOverlayBlocking();
        
        // Prevent double-tap zoom on buttons
        preventDoubleTapZoom();
    }
    
    /**
     * Fix all interactive elements to ensure they work on mobile
     */
    function fixAllInteractiveElements() {
        const elements = document.querySelectorAll(CONFIG.interactiveSelectors);
        
        elements.forEach(function(element) {
            fixElement(element);
        });
    }
    
    /**
     * Fix a single interactive element
     */
    function fixElement(element) {
        // Skip if already fixed
        if (element.dataset.facultyMobileFixed === 'true') {
            return;
        }
        
        // Skip buttons with custom handlers - let their specific handlers work
        // These buttons have their own event handlers that should not be interfered with
        if (element.classList.contains('submit-requirement-btn') || 
            element.classList.contains('attach-requirement-btn') ||
            element.dataset.hasCustomHandler === 'true') {
            // Still apply basic mobile fixes but don't stop propagation
            element.style.touchAction = 'manipulation';
            element.style.webkitTapHighlightColor = 'rgba(0, 51, 102, 0.2)';
            element.style.pointerEvents = 'auto';
            element.style.cursor = 'pointer';
            element.style.userSelect = 'none';
            element.style.webkitUserSelect = 'none';
            ensureMinimumTouchTarget(element);
            element.dataset.facultyMobileFixed = 'true';
            return; // Don't add event listeners that might interfere
        }
        
        element.dataset.facultyMobileFixed = 'true';
        
        // Ensure element is fully interactive
        element.style.touchAction = 'manipulation';
        element.style.webkitTapHighlightColor = 'rgba(0, 51, 102, 0.2)';
        element.style.pointerEvents = 'auto';
        element.style.cursor = 'pointer';
        element.style.userSelect = 'none';
        element.style.webkitUserSelect = 'none';
        
        // Ensure minimum touch target size
        ensureMinimumTouchTarget(element);
        
        // Stop event propagation to prevent parent handlers from interfering
        // CRITICAL: Use capture phase to intercept BEFORE parent handlers
        element.addEventListener('click', function(e) {
            // Stop click from bubbling to parent
            e.stopPropagation();
            // Don't prevent default - let element's action work
        }, true); // Capture phase
        
        // Handle touch events - don't prevent default, just stop propagation
        element.addEventListener('touchstart', function(e) {
            // Mark that touch started on this element
            element.dataset.touching = 'true';
            // Stop propagation to parent
            e.stopPropagation();
            // DON'T prevent default - browser needs this to fire click
        }, { passive: true, capture: true });
        
        element.addEventListener('touchend', function(e) {
            // Stop propagation to parent
            e.stopPropagation();
            // DON'T prevent default - browser needs this to fire click
            element.dataset.touching = 'false';
        }, { passive: true, capture: true });
        
        // Handle mousedown for desktop
        element.addEventListener('mousedown', function(e) {
            e.stopPropagation();
        }, true);
    }
    
    /**
     * Ensure minimum touch target size (44x44px recommended by iOS HIG)
     */
    function ensureMinimumTouchTarget(element) {
        // Skip if element is already large enough or is inline
        const computed = window.getComputedStyle(element);
        if (computed.display === 'inline' || computed.display === 'inline-block') {
            // For inline elements, check if they're inside a container with proper size
            const parent = element.parentElement;
            if (parent) {
                const parentComputed = window.getComputedStyle(parent);
                const parentHeight = parseFloat(parentComputed.height) || 0;
                const parentPadding = parseFloat(parentComputed.paddingTop) + parseFloat(parentComputed.paddingBottom);
                if (parentHeight + parentPadding >= CONFIG.minTouchTarget) {
                    return; // Parent provides adequate size
                }
            }
        }
        
        // Check current size
        const rect = element.getBoundingClientRect();
        const minSize = CONFIG.minTouchTarget;
        
        // Only add padding if element is too small
        if (rect.height < minSize || rect.width < minSize) {
            const currentPadding = parseFloat(computed.paddingTop) + parseFloat(computed.paddingBottom);
            const currentPaddingX = parseFloat(computed.paddingLeft) + parseFloat(computed.paddingRight);
            
            if (rect.height < minSize && currentPadding < (minSize - rect.height)) {
                const neededPadding = Math.max(0, (minSize - rect.height) / 2);
                element.style.paddingTop = (parseFloat(computed.paddingTop) || 0) + neededPadding + 'px';
                element.style.paddingBottom = (parseFloat(computed.paddingBottom) || 0) + neededPadding + 'px';
            }
            
            if (rect.width < minSize && currentPaddingX < (minSize - rect.width)) {
                const neededPadding = Math.max(0, (minSize - rect.width) / 2);
                element.style.paddingLeft = (parseFloat(computed.paddingLeft) || 0) + neededPadding + 'px';
                element.style.paddingRight = (parseFloat(computed.paddingRight) || 0) + neededPadding + 'px';
            }
        }
    }
    
    /**
     * Fix buttons inside clickable containers (like cards with onclick)
     */
    function fixButtonsInClickableContainers() {
        const buttons = document.querySelectorAll('button, .btn, a.btn, [role="button"]');
        
        buttons.forEach(function(button) {
            // Skip buttons with custom handlers
            if (button.classList.contains('submit-requirement-btn') || 
                button.classList.contains('attach-requirement-btn') ||
                button.dataset.hasCustomHandler === 'true') {
                return; // Let their specific handlers work
            }
            
            const clickableParent = button.closest('[onclick], .clickable, [data-href], tr[onclick]');
            
            if (clickableParent && clickableParent !== button) {
                // Ensure button is above parent in z-index
                button.style.position = 'relative';
                button.style.zIndex = '1000';
                
                // Stop click from reaching parent (in capture phase)
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                }, true);
                
                // Stop touch events from reaching parent
                button.addEventListener('touchstart', function(e) {
                    e.stopPropagation();
                }, { passive: true, capture: true });
                
                button.addEventListener('touchend', function(e) {
                    e.stopPropagation();
                }, { passive: true, capture: true });
            }
        });
    }
    
    /**
     * Fix buttons inside cards (common in submissions, requirements, etc.)
     */
    function fixButtonsInCards() {
        const cards = document.querySelectorAll('.card, .submission-card, .requirement-card, .mobile-list-card');
        
        cards.forEach(function(card) {
            const buttons = card.querySelectorAll('button, .btn, a.btn, .btn-action, .submission-actions .btn, .requirement-card-footer .btn');
            
            if (buttons.length > 0) {
                // Ensure card doesn't block button clicks
                buttons.forEach(function(button) {
                    // Skip buttons with custom handlers
                    if (button.classList.contains('submit-requirement-btn') || 
                        button.classList.contains('attach-requirement-btn') ||
                        button.dataset.hasCustomHandler === 'true') {
                        // Still apply basic styles but don't add event listeners
                        button.style.position = 'relative';
                        button.style.zIndex = '10';
                        button.style.pointerEvents = 'auto';
                        return; // Let their specific handlers work
                    }
                    
                    button.style.position = 'relative';
                    button.style.zIndex = '10';
                    button.style.pointerEvents = 'auto';
                    
                    // Stop events from reaching card
                    button.addEventListener('click', function(e) {
                        e.stopPropagation();
                    }, true);
                    
                    button.addEventListener('touchstart', function(e) {
                        e.stopPropagation();
                    }, { passive: true, capture: true });
                    
                    button.addEventListener('touchend', function(e) {
                        e.stopPropagation();
                    }, { passive: true, capture: true });
                });
            }
        });
    }
    
    /**
     * Fix form elements for mobile
     */
    function fixFormElements() {
        const formElements = document.querySelectorAll('input, select, textarea, label');
        
        formElements.forEach(function(element) {
            // Ensure form elements are touch-friendly
            element.style.touchAction = 'manipulation';
            element.style.webkitTapHighlightColor = 'rgba(0, 51, 102, 0.1)';
            
            // For labels, ensure they work on mobile
            if (element.tagName === 'LABEL') {
                element.style.cursor = 'pointer';
                element.style.userSelect = 'none';
            }
        });
    }
    
    /**
     * Watch for dynamically added elements
     */
    function watchForNewElements() {
        const observer = new MutationObserver(function(mutations) {
            let hasNewElements = false;
            
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        // Check if node or its children match our selectors
                        if (node.matches && node.matches(CONFIG.interactiveSelectors)) {
                            hasNewElements = true;
                        } else if (node.querySelector && node.querySelector(CONFIG.interactiveSelectors)) {
                            hasNewElements = true;
                        }
                    }
                });
            });
            
            if (hasNewElements) {
                // Reset fixed flag for new elements
                setTimeout(function() {
                    document.querySelectorAll(CONFIG.interactiveSelectors).forEach(function(element) {
                        if (!element.dataset.facultyMobileFixed) {
                            element.dataset.facultyMobileFixed = null;
                        }
                    });
                    fixAllInteractiveElements();
                    fixButtonsInClickableContainers();
                    fixButtonsInCards();
                }, 50);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
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
            
            // Check periodically
            setInterval(updateOverlayState, 200);
            updateOverlayState();
        }
        
        // Also check modal backdrops - DISABLED to prevent interference with requirements modals
        // function checkModalBackdrops() {
        //     document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        //         const computed = window.getComputedStyle(backdrop);
        //         const isHidden = backdrop.classList.contains('d-none') || computed.display === 'none';
        //         const isModalOpen = document.body.classList.contains('modal-open');
        //         
        //         // Only hide backdrop if modal is actually closed AND backdrop is hidden
        //         if (isHidden && !isModalOpen) {
        //             backdrop.style.pointerEvents = 'none';
        //             backdrop.style.zIndex = '-1';
        //         } else if (isModalOpen && backdrop.classList.contains('show')) {
        //             // Ensure backdrop has correct z-index when modal is open
        //             backdrop.style.zIndex = '1040';
        //             backdrop.style.position = 'fixed';
        //         }
        //     });
        // }
        // 
        // checkModalBackdrops();
        // setInterval(checkModalBackdrops, 500);
    }
    
    /**
     * Prevent double-tap zoom on buttons (but allow single tap)
     */
    function preventDoubleTapZoom() {
        document.addEventListener('touchend', function(e) {
            const target = e.target.closest('button, .btn, a.btn, [role="button"]');
            
            if (target) {
                const now = Date.now();
                const timeSinceLastTap = now - lastTapTime;
                
                // If this is a double-tap on the same element, prevent zoom
                if (timeSinceLastTap < CONFIG.tapDelay && target === lastTapTarget) {
                    e.preventDefault();
                } else {
                    // Allow single tap - don't prevent default
                    lastTapTime = now;
                    lastTapTarget = target;
                }
            }
        }, { passive: false });
    }
    
    /**
     * Public API - manually fix elements if needed
     */
    window.facultyMobileInteractions = {
        fixElement: fixElement,
        fixAll: function() {
            fixAllInteractiveElements();
            fixButtonsInClickableContainers();
            fixButtonsInCards();
            fixFormElements();
        },
        ensureTouchTarget: ensureMinimumTouchTarget
    };
    
    // Also run on window load to catch any late-loading content
    window.addEventListener('load', function() {
        setTimeout(function() {
            fixAllInteractiveElements();
            fixButtonsInClickableContainers();
            fixButtonsInCards();
        }, 100);
    });
    
})();


