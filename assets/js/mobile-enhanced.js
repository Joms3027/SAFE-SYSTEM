/**
 * WPU Faculty System - Enhanced Mobile JavaScript
 * Modern Mobile App-Like Interactions and Gestures
 * Version: 2.0.0
 * Date: November 9, 2025
 */

(function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        swipeThreshold: 50,
        tapTimeout: 300,
        doubleTapDelay: 300,
        pullThreshold: 80,
        bottomNavHeight: 60
    };
    
    // State management
    const state = {
        touchStartX: 0,
        touchStartY: 0,
        touchEndX: 0,
        touchEndY: 0,
        isSwiping: false,
        isPulling: false,
        lastTap: 0,
        pullDistance: 0
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        console.log('Mobile Enhanced JS Initialized');
        // Skip bottom navigation initialization - it's now rendered server-side in faculty_sidebar.php
        // initBottomNavigation(); // DISABLED - bottom nav is rendered in PHP
        initMobileSidebar();
        initTouchGestures();
        initPullToRefresh();
        initSmoothScrolling();
        initMobileOptimizations();
        initSwipeActions();
        initBottomSheets();
        initMobileSearchOptimization();
    }
    
    /**
     * Bottom Navigation
     * NOTE: Bottom navigation is now rendered server-side in faculty_sidebar.php
     * This function only handles initialization and event handlers if bottom nav exists
     */
    function initBottomNavigation() {
        // Check if bottom nav already exists (rendered from PHP)
        const existingBottomNav = document.querySelector('.faculty-bottom-nav');
        if (!existingBottomNav) {
            // No bottom nav exists, nothing to initialize
            return;
        }
        
        // Bottom nav already exists from PHP, just add event handlers
        const bottomNavItems = existingBottomNav.querySelectorAll('.bottom-nav-item');
        bottomNavItems.forEach(item => {
            // Enhanced click handler with ripple effect
            item.addEventListener('click', function(e) {
                // Create ripple effect
                createRippleEffect(e, this);
                
                // Add haptic feedback if available (mobile devices)
                if (navigator.vibrate) {
                    navigator.vibrate(10);
                }
                
                // Add active class temporarily for visual feedback
                this.classList.add('clicked');
                setTimeout(() => {
                    this.classList.remove('clicked');
                }, 200);
            });
            
            // Keyboard navigation support
            item.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
        
        // Add notification badge if needed
        if (typeof checkNotifications === 'function') {
            checkNotifications();
        }
    }
    
    /**
     * Create ripple effect on click
     */
    function createRippleEffect(e, element) {
        const ripple = element.querySelector('.nav-ripple');
        if (!ripple) return;
        
        // Get click position relative to the icon wrapper
        const iconWrapper = element.querySelector('.nav-icon-wrapper');
        if (!iconWrapper) return;
        
        const rect = iconWrapper.getBoundingClientRect();
        const x = e.clientX - rect.left - rect.width / 2;
        const y = e.clientY - rect.top - rect.height / 2;
        
        // Reset and animate ripple
        ripple.style.left = '50%';
        ripple.style.top = '50%';
        ripple.style.transform = 'translate(-50%, -50%)';
        ripple.style.width = '0';
        ripple.style.height = '0';
        ripple.style.opacity = '0';
        
        // Force reflow
        void ripple.offsetWidth;
        
        // Animate
        ripple.style.width = '80px';
        ripple.style.height = '80px';
        ripple.style.opacity = '1';
        
        // Clean up after animation
        setTimeout(() => {
            ripple.style.width = '0';
            ripple.style.height = '0';
            ripple.style.opacity = '0';
        }, 300);
    }
    
    /**
     * Check for notifications and add badge
     */
    function checkNotifications() {
        // You can integrate with your notification system here
        const homeLink = document.querySelector('.bottom-nav-item[href="dashboard.php"]');
        if (homeLink) {
            // Example: Add badge
            // const badge = document.createElement('span');
            // badge.className = 'nav-badge';
            // badge.textContent = '3';
            // homeLink.querySelector('.nav-icon').appendChild(badge);
        }
    }
    
    /**
     * Mobile Sidebar Enhancement
     */
    function initMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        let overlay = document.getElementById('sidebar-overlay');
        
        if (!sidebar || !toggle) return;
        
        // Create overlay if it doesn't exist
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sidebar-overlay';
            overlay.style.cssText = `
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1026;
                display: none;
                transition: opacity 0.3s ease;
            `;
            document.body.appendChild(overlay);
        }
        
        // Toggle sidebar
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
        
        // Close on overlay click
        overlay.addEventListener('click', function() {
            closeSidebar();
        });
        
        // Swipe to close sidebar
        let startX = 0;
        sidebar.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
        }, { passive: true });
        
        sidebar.addEventListener('touchmove', function(e) {
            const currentX = e.touches[0].clientX;
            const diff = startX - currentX;
            
            if (diff > 0 && startX < 50) { // Swipe from edge
                const translateX = Math.min(diff, sidebar.offsetWidth);
                sidebar.style.transform = `translateX(-${translateX}px)`;
            }
        }, { passive: true });
        
        sidebar.addEventListener('touchend', function(e) {
            const translateX = Math.abs(parseInt(sidebar.style.transform.replace(/[^\d-]/g, '')) || 0);
            if (translateX > sidebar.offsetWidth / 3) {
                closeSidebar();
            } else {
                sidebar.style.transform = '';
            }
        });
        
        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
        
        function toggleSidebar() {
            const isOpen = sidebar.classList.contains('show');
            if (isOpen) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }
        
        function openSidebar() {
            sidebar.classList.add('show');
            overlay.style.display = 'block';
            setTimeout(() => overlay.style.opacity = '1', 10);
            toggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebar.style.transform = '';
            overlay.style.opacity = '0';
            setTimeout(() => overlay.style.display = 'none', 300);
            toggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        
        window.toggleSidebar = toggleSidebar;
        window.closeSidebar = closeSidebar;
    }
    
    /**
     * Touch Gestures
     * FIXED: Only handle gestures on non-interactive elements to avoid blocking buttons
     */
    function initTouchGestures() {
        // Only handle touch gestures for swipe containers, not buttons
        // Use capture: false so buttons can handle their events first
        // CRITICAL: Use passive: true for touchstart/touchend to not block button clicks
        document.addEventListener('touchstart', handleTouchStart, { passive: true, capture: false });
        document.addEventListener('touchmove', handleTouchMove, { passive: false, capture: false });
        document.addEventListener('touchend', handleTouchEnd, { passive: true, capture: false });
    }
    
    function handleTouchStart(e) {
        // Don't interfere with buttons or interactive elements
        const target = e.target;
        // Expanded list of interactive elements to avoid
        const isInteractive = target.closest('button, a, .btn, .btn-action, .submit-requirement-btn, .attach-requirement-btn, input, select, textarea, [role="button"], [onclick], .dropdown-item, .nav-link, .page-link, .list-group-item-action, label[for]');
        
        if (isInteractive) {
            // Don't track touch for buttons - completely ignore
            state.touchStartX = 0;
            state.touchStartY = 0;
            state.isSwiping = false;
            return;
        }
        
        // Only track for non-interactive content areas
        state.touchStartX = e.touches[0].clientX;
        state.touchStartY = e.touches[0].clientY;
        state.isSwiping = false;
    }
    
    function handleTouchMove(e) {
        // Don't interfere with buttons or interactive elements
        const target = e.target;
        const isInteractive = target.closest('button, a, .btn, .btn-action, .submit-requirement-btn, .attach-requirement-btn, input, select, textarea, [role="button"], [onclick], .dropdown-item, .nav-link, .page-link, .list-group-item-action, label[for]');
        
        if (isInteractive) {
            // Stop tracking immediately if we're on an interactive element
            state.touchStartX = 0;
            state.touchStartY = 0;
            state.isSwiping = false;
            return;
        }
        
        // If we didn't start tracking, don't process
        if (!state.touchStartX || !state.touchStartY) {
            return;
        }
        
        state.touchEndX = e.touches[0].clientX;
        state.touchEndY = e.touches[0].clientY;
        
        const diffX = state.touchStartX - state.touchEndX;
        const diffY = state.touchStartY - state.touchEndY;
        
        // Determine if horizontal swipe (only if significant movement)
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > CONFIG.swipeThreshold) {
            state.isSwiping = true;
            // Only prevent default if we're actually swiping (not on a button)
            e.preventDefault();
        }
    }
    
    function handleTouchEnd(e) {
        // Don't interfere with buttons, links, or interactive elements
        const target = e.target;
        const isInteractive = target.closest('button, a, .btn, .btn-action, .submit-requirement-btn, .attach-requirement-btn, input, select, textarea, [role="button"], [onclick], .dropdown-item, .nav-link, .page-link, .list-group-item-action, label[for]');
        
        if (isInteractive) {
            // Let the button handle its own click - don't process as swipe
            state.touchStartX = 0;
            state.touchStartY = 0;
            state.touchEndX = 0;
            state.touchEndY = 0;
            state.isSwiping = false;
            return;
        }
        
        // If we weren't tracking or weren't swiping, reset and return
        if (!state.touchStartX || !state.touchStartY) {
            state.touchStartX = 0;
            state.touchStartY = 0;
            state.touchEndX = 0;
            state.touchEndY = 0;
            state.isSwiping = false;
            return;
        }
        
        // Only process swipe if we detected a swipe and it's significant
        if (!state.isSwiping) {
            state.touchStartX = 0;
            state.touchStartY = 0;
            state.touchEndX = 0;
            state.touchEndY = 0;
            return;
        }
        
        const diffX = state.touchStartX - state.touchEndX;
        
        // Swipe left
        if (diffX > CONFIG.swipeThreshold) {
            handleSwipeLeft(e.target);
        }
        
        // Swipe right
        if (diffX < -CONFIG.swipeThreshold) {
            handleSwipeRight(e.target);
        }
        
        // Reset
        state.touchStartX = 0;
        state.touchStartY = 0;
        state.touchEndX = 0;
        state.touchEndY = 0;
        state.isSwiping = false;
    }
    
    function handleSwipeLeft(target) {
        // Custom swipe left actions
        const swipeContainer = target.closest('.swipe-container');
        if (swipeContainer) {
            swipeContainer.classList.add('swiped-left');
        }
    }
    
    function handleSwipeRight(target) {
        // Custom swipe right actions
        const swipeContainer = target.closest('.swipe-container');
        if (swipeContainer) {
            swipeContainer.classList.remove('swiped-left');
        }
    }
    
    /**
     * Pull to Refresh
     */
    function initPullToRefresh() {
        if (window.innerWidth > 991) return;
        
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) return;
        
        let startY = 0;
        let currentY = 0;
        let pulling = false;
        
        // Create refresh indicator
        const refreshIndicator = document.createElement('div');
        refreshIndicator.className = 'pull-to-refresh';
        refreshIndicator.innerHTML = '<div class="pull-to-refresh-icon"></div>';
        document.body.insertBefore(refreshIndicator, document.body.firstChild);
        
        mainContent.addEventListener('touchstart', function(e) {
            if (mainContent.scrollTop === 0) {
                startY = e.touches[0].clientY;
                pulling = true;
            }
        }, { passive: true });
        
        mainContent.addEventListener('touchmove', function(e) {
            if (!pulling) return;
            
            currentY = e.touches[0].clientY;
            const pullDistance = currentY - startY;
            
            if (pullDistance > 0 && pullDistance < 150) {
                e.preventDefault();
                const opacity = Math.min(pullDistance / CONFIG.pullThreshold, 1);
                refreshIndicator.style.transform = `translateY(${Math.min(pullDistance, 80)}px)`;
                refreshIndicator.style.opacity = opacity;
                
                if (pullDistance > CONFIG.pullThreshold) {
                    refreshIndicator.classList.add('visible');
                }
            }
        }, { passive: false });
        
        mainContent.addEventListener('touchend', function() {
            if (!pulling) return;
            
            const pullDistance = currentY - startY;
            
            if (pullDistance > CONFIG.pullThreshold) {
                refreshIndicator.classList.add('visible');
                // Trigger refresh
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            } else {
                refreshIndicator.style.transform = '';
                refreshIndicator.style.opacity = '';
                refreshIndicator.classList.remove('visible');
            }
            
            pulling = false;
            startY = 0;
            currentY = 0;
        }, { passive: true });
    }
    
    /**
     * Smooth Scrolling
     */
    function initSmoothScrolling() {
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#' || href === '#!') return;
                
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    const offset = CONFIG.bottomNavHeight + 56; // Bottom nav + header
                    const targetPosition = target.offsetTop - offset;
                    
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    }
    
    /**
     * Mobile Optimizations
     */
    function initMobileOptimizations() {
        // Add mobile class to body
        if (window.innerWidth <= 991) {
            document.body.classList.add('mobile-device');
        }
        
        // Optimize images for mobile
        optimizeImages();
        
        // Add tap highlights
        addTapHighlights();
        
        // Improve input focus
        improveInputFocus();
        
        // Handle orientation change
        handleOrientationChange();
        
        // Lazy load images
        lazyLoadImages();
    }
    
    function optimizeImages() {
        document.querySelectorAll('img:not([loading])').forEach(img => {
            img.setAttribute('loading', 'lazy');
        });
    }
    
    function addTapHighlights() {
        const tapElements = document.querySelectorAll('.btn, .card, .list-group-item, .mobile-list-card');
        tapElements.forEach(el => {
            el.addEventListener('touchstart', function() {
                this.style.opacity = '0.7';
            }, { passive: true });
            
            el.addEventListener('touchend', function() {
                this.style.opacity = '';
            }, { passive: true });
        });
    }
    
    function improveInputFocus() {
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                // Scroll element into view
                setTimeout(() => {
                    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            });
        });
    }
    
    function handleOrientationChange() {
        window.addEventListener('orientationchange', function() {
            // Reload certain elements on orientation change
            setTimeout(() => {
                window.scrollTo(0, 0);
            }, 100);
        });
    }
    
    function lazyLoadImages() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        observer.unobserve(img);
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }
    
    /**
     * Swipe Actions on List Items
     */
    function initSwipeActions() {
        const swipeItems = document.querySelectorAll('.swipe-container');
        
        swipeItems.forEach(item => {
            let startX = 0;
            let currentX = 0;
            const content = item.querySelector('.swipe-content');
            const actions = item.querySelector('.swipe-actions');
            
            if (!content || !actions) return;
            
            item.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
            }, { passive: true });
            
            item.addEventListener('touchmove', function(e) {
                currentX = e.touches[0].clientX;
                const diff = startX - currentX;
                
                if (diff > 0 && diff < 150) {
                    content.style.transform = `translateX(-${diff}px)`;
                }
            }, { passive: true });
            
            item.addEventListener('touchend', function() {
                const diff = startX - currentX;
                
                if (diff > 75) {
                    content.style.transform = 'translateX(-150px)';
                    item.classList.add('swiped');
                } else {
                    content.style.transform = '';
                    item.classList.remove('swiped');
                }
            }, { passive: true });
        });
    }
    
    /**
     * Bottom Sheets / Modals
     */
    function initBottomSheets() {
        const bottomSheets = document.querySelectorAll('.modal');
        
        bottomSheets.forEach(sheet => {
            // Add swipe-down to close
            let startY = 0;
            const modalContent = sheet.querySelector('.modal-content');
            
            if (!modalContent) return;
            
            modalContent.addEventListener('touchstart', function(e) {
                startY = e.touches[0].clientY;
            }, { passive: true });
            
            modalContent.addEventListener('touchmove', function(e) {
                const currentY = e.touches[0].clientY;
                const diff = currentY - startY;
                
                if (diff > 0 && diff < 200) {
                    modalContent.style.transform = `translateY(${diff}px)`;
                }
            }, { passive: true });
            
            modalContent.addEventListener('touchend', function() {
                const currentY = event.changedTouches[0].clientY;
                const diff = currentY - startY;
                
                if (diff > 100) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(sheet);
                    if (modal) modal.hide();
                }
                
                modalContent.style.transform = '';
            }, { passive: true });
        });
    }
    
    /**
     * Mobile Search Optimization
     */
    function initMobileSearchOptimization() {
        const searchInputs = document.querySelectorAll('input[type="search"], input[placeholder*="Search"]');
        
        searchInputs.forEach(input => {
            // Add search icon
            if (!input.parentElement.querySelector('.search-icon')) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-search search-icon';
                icon.style.cssText = 'position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6b7280;';
                
                input.style.paddingLeft = '40px';
                input.parentElement.style.position = 'relative';
                input.parentElement.insertBefore(icon, input);
            }
            
            // Clear button
            input.addEventListener('input', function() {
                let clearBtn = this.parentElement.querySelector('.search-clear');
                
                if (this.value && !clearBtn) {
                    clearBtn = document.createElement('button');
                    clearBtn.className = 'search-clear';
                    clearBtn.innerHTML = '<i class="fas fa-times-circle"></i>';
                    clearBtn.style.cssText = 'position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; font-size: 16px; cursor: pointer;';
                    
                    clearBtn.addEventListener('click', () => {
                        input.value = '';
                        input.dispatchEvent(new Event('input'));
                        clearBtn.remove();
                    });
                    
                    this.parentElement.appendChild(clearBtn);
                } else if (!this.value && clearBtn) {
                    clearBtn.remove();
                }
            });
        });
    }
    
    /**
     * Add Ripple Effect
     */
    function addRipple(event, element) {
        const ripple = document.createElement('span');
        const rect = element.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = event.clientX - rect.left - size / 2;
        const y = event.clientY - rect.top - size / 2;
        
        ripple.style.cssText = `
            position: absolute;
            width: ${size}px;
            height: ${size}px;
            left: ${x}px;
            top: ${y}px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            transform: scale(0);
            animation: ripple 0.6s ease-out;
            pointer-events: none;
        `;
        
        element.style.position = 'relative';
        element.style.overflow = 'hidden';
        element.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
    }
    
    // Add ripple animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
    
    /**
     * Utility Functions
     */
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // Throttle function
    function throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
    
    // Export functions for external use
    window.mobileEnhanced = {
        addRipple,
        debounce,
        throttle
    };
    
})();
