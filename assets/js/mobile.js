/**
 * Mobile-First JavaScript Enhancements for Faculty System
 * Handles responsive behavior, touch interactions, and mobile optimizations
 */

(function() {
    'use strict';
    
    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initMobileSidebar();
        initTouchOptimizations();
        initResponsiveHelpers();
        initFormEnhancements();
        initTableEnhancements();
    });
    
    /**
     * Initialize Mobile Sidebar Functionality
     */
    function initMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (!sidebar || !toggle) return;
        
        // Toggle sidebar
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
        
        // Close on overlay click
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeSidebar();
                }
            });
        }
        
        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
        
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth >= 992) {
                    closeSidebar();
                    document.body.style.overflow = '';
                }
            }, 250);
        });
        
        // Prevent body scroll when sidebar is open
        function toggleBodyScroll(lock) {
            if (window.innerWidth < 992) {
                document.body.style.overflow = lock ? 'hidden' : '';
            }
        }
        
        // Functions
        window.toggleSidebar = function() {
            const isOpen = sidebar.classList.contains('show');
            if (isOpen) {
                closeSidebar();
            } else {
                openSidebar();
            }
        };
        
        function openSidebar() {
            sidebar.classList.add('show');
            if (overlay) overlay.classList.remove('d-none');
            toggle.setAttribute('aria-expanded', 'true');
            toggleBodyScroll(true);
        }
        
        function closeSidebar() {
            sidebar.classList.remove('show');
            if (overlay) overlay.classList.add('d-none');
            toggle.setAttribute('aria-expanded', 'false');
            toggleBodyScroll(false);
        }
        
        window.closeSidebar = closeSidebar;
    }
    
    /**
     * Touch Optimizations for Mobile Devices
     */
    function initTouchOptimizations() {
        // Add touch class to body for touch devices
        if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
            document.body.classList.add('touch-device');
        }
        
        // Improve click delays on iOS
        document.body.addEventListener('touchstart', function() {}, { passive: true });
        
        // Prevent double-tap zoom on buttons - but don't block single taps
        // CRITICAL: Only prevent on double-tap, not single tap, to allow buttons to work
        let lastTouchEnd = 0;
        let lastTouchTarget = null;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            const target = e.target.closest('button, .btn, a');
            // Only prevent if it's a double-tap on the same element
            if (target && now - lastTouchEnd <= 300 && target === lastTouchTarget) {
                e.preventDefault();
            } else {
                // Allow single tap to work normally
                lastTouchTarget = target;
            }
            lastTouchEnd = now;
        }, { passive: false });
    }
    
    /**
     * Responsive Helper Functions
     */
    function initResponsiveHelpers() {
        // Detect mobile viewport changes
        const mobileQuery = window.matchMedia('(max-width: 767px)');
        const tabletQuery = window.matchMedia('(min-width: 768px) and (max-width: 1024px)');
        
        function handleMobileChange(e) {
            if (e.matches) {
                // Mobile view
                document.body.classList.add('mobile-view');
                document.body.classList.remove('tablet-view');
            } else {
                document.body.classList.remove('mobile-view');
            }
        }
        
        function handleTabletChange(e) {
            if (e.matches) {
                // Tablet view
                document.body.classList.add('tablet-view');
                document.body.classList.remove('mobile-view');
            } else {
                document.body.classList.remove('tablet-view');
            }
        }
        
        mobileQuery.addListener(handleMobileChange);
        tabletQuery.addListener(handleTabletChange);
        handleMobileChange(mobileQuery);
        handleTabletChange(tabletQuery);
        
        // Update viewport height on mobile (handles address bar)
        updateViewportHeight();
        window.addEventListener('resize', debounce(updateViewportHeight, 250));
        
        function updateViewportHeight() {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
    }
    
    /**
     * Form Enhancements for Mobile
     */
    function initFormEnhancements() {
        // Auto-focus prevention on mobile
        if (window.innerWidth < 768) {
            const autofocusElements = document.querySelectorAll('[autofocus]');
            autofocusElements.forEach(el => el.removeAttribute('autofocus'));
        }
        
        // File input enhancements
        const fileInputs = document.querySelectorAll('input[type="file"]');
        fileInputs.forEach(input => {
            input.addEventListener('change', function() {
                const fileName = this.files[0]?.name;
                if (fileName) {
                    const label = this.closest('.form-group')?.querySelector('label');
                    if (label) {
                        const originalText = label.textContent;
                        label.textContent = `Selected: ${fileName}`;
                        label.dataset.original = originalText;
                    }
                }
            });
        });
        
        // Form validation feedback
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = form.querySelector('[type="submit"]');
                if (submitBtn && form.checkValidity()) {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                }
            });
        });
    }
    
    /**
     * Table Enhancements for Mobile
     */
    function initTableEnhancements() {
        const tables = document.querySelectorAll('.table-responsive table');
        
        tables.forEach(table => {
            // Add data-label attributes if missing
            if (window.innerWidth < 768) {
                const headers = Array.from(table.querySelectorAll('thead th'));
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    cells.forEach((cell, index) => {
                        if (!cell.hasAttribute('data-label') && headers[index]) {
                            cell.setAttribute('data-label', headers[index].textContent.trim());
                        }
                    });
                });
            }
            
            // Horizontal scroll indicator
            const wrapper = table.closest('.table-responsive');
            if (wrapper) {
                addScrollIndicator(wrapper);
            }
        });
        
        function addScrollIndicator(wrapper) {
            function updateScrollIndicator() {
                const scrollLeft = wrapper.scrollLeft;
                const scrollWidth = wrapper.scrollWidth;
                const clientWidth = wrapper.clientWidth;
                
                if (scrollWidth > clientWidth) {
                    if (scrollLeft > 10) {
                        wrapper.classList.add('is-scrolled');
                    } else {
                        wrapper.classList.remove('is-scrolled');
                    }
                    
                    if (scrollLeft < scrollWidth - clientWidth - 10) {
                        wrapper.classList.add('has-more');
                    } else {
                        wrapper.classList.remove('has-more');
                    }
                } else {
                    wrapper.classList.remove('is-scrolled', 'has-more');
                }
            }
            
            wrapper.addEventListener('scroll', updateScrollIndicator);
            window.addEventListener('resize', debounce(updateScrollIndicator, 250));
            updateScrollIndicator();
        }
    }
    
    /**
     * Utility: Debounce Function
     */
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
    
    /**
     * Bootstrap Tooltip Initialization
     */
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    /**
     * Smooth Scroll for Anchor Links
     */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href !== '') {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    const headerOffset = 70;
                    const elementPosition = target.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                    
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            }
        });
    });
    
    /**
     * Image Lazy Loading Fallback
     */
    if ('loading' in HTMLImageElement.prototype) {
        const images = document.querySelectorAll('img[loading="lazy"]');
        images.forEach(img => {
            img.src = img.dataset.src || img.src;
        });
    }
    
    /**
     * Performance Monitoring (Development Only)
     */
    if (window.performance && console.time) {
        window.addEventListener('load', function() {
            const loadTime = window.performance.timing.domContentLoadedEventEnd - 
                           window.performance.timing.navigationStart;
            console.log('Page load time:', loadTime + 'ms');
        });
    }
    
})();

/**
 * Global Helper Functions
 */

// Show loading state on button
function showButtonLoading(button) {
    if (button) {
        button.classList.add('loading');
        button.disabled = true;
        button.dataset.originalText = button.innerHTML;
        const spinner = '<span class="spinner-border spinner-border-sm me-2"></span>';
        button.innerHTML = spinner + 'Loading...';
    }
}

// Hide loading state on button
function hideButtonLoading(button) {
    if (button) {
        button.classList.remove('loading');
        button.disabled = false;
        if (button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
        }
    }
}

// Show toast notification
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type} show`;
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 
                               type === 'danger' ? 'exclamation-circle' : 
                               type === 'warning' ? 'exclamation-triangle' : 
                               'info-circle'}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    let container = document.querySelector('.toast-notification-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-notification-container';
        document.body.appendChild(container);
    }
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// Confirm action with mobile-friendly dialog
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// Copy to clipboard (mobile-friendly)
async function copyToClipboard(text) {
    try {
        if (navigator.clipboard) {
            await navigator.clipboard.writeText(text);
            showToast('Copied to clipboard!', 'success');
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showToast('Copied to clipboard!', 'success');
        }
    } catch (err) {
        showToast('Failed to copy', 'danger');
    }
}
