/**
 * Performance Optimization JavaScript
 * Handles lazy loading, deferred initialization, and performance monitoring
 */

(function() {
    'use strict';

    // Performance configuration
    const PERF_CONFIG = {
        lazyLoadImages: true,
        lazyLoadThreshold: '200px',
        deferNonCritical: true,
        enablePrefetch: true,
        monitorPerformance: false
    };

    /**
     * Lazy load images using Intersection Observer
     */
    function initLazyLoading() {
        if (!PERF_CONFIG.lazyLoadImages) return;

        // Check for native lazy loading support
        if ('loading' in HTMLImageElement.prototype) {
            // Browser supports native lazy loading
            document.querySelectorAll('img[data-src]').forEach(img => {
                img.src = img.dataset.src;
                img.loading = 'lazy';
            });
            return;
        }

        // Fallback to Intersection Observer
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
            }, {
                rootMargin: PERF_CONFIG.lazyLoadThreshold
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        } else {
            // Fallback for older browsers
            document.querySelectorAll('img[data-src]').forEach(img => {
                img.src = img.dataset.src;
            });
        }
    }

    /**
     * Defer non-critical JavaScript execution
     */
    function deferNonCritical(callback) {
        if (!PERF_CONFIG.deferNonCritical) {
            callback();
            return;
        }

        if ('requestIdleCallback' in window) {
            requestIdleCallback(callback, { timeout: 2000 });
        } else {
            setTimeout(callback, 100);
        }
    }

    /**
     * Prefetch links on hover for faster navigation
     */
    function initLinkPrefetch() {
        if (!PERF_CONFIG.enablePrefetch) return;
        if (!('IntersectionObserver' in window)) return;

        const prefetchedLinks = new Set();

        function prefetchLink(href) {
            if (prefetchedLinks.has(href)) return;
            if (!href.startsWith(window.location.origin)) return;
            if (href.includes('#')) return;

            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = href;
            link.as = 'document';
            document.head.appendChild(link);
            prefetchedLinks.add(href);
        }

        // Prefetch on hover with delay
        let hoverTimeout;
        document.addEventListener('mouseover', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;
            
            clearTimeout(hoverTimeout);
            hoverTimeout = setTimeout(() => {
                prefetchLink(link.href);
            }, 100);
        }, { passive: true });

        // Clear timeout on mouseout
        document.addEventListener('mouseout', () => {
            clearTimeout(hoverTimeout);
        }, { passive: true });
    }

    /**
     * Optimize scroll performance
     */
    function optimizeScroll() {
        let ticking = false;
        
        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(() => {
                    // Trigger custom scroll event for components
                    document.dispatchEvent(new CustomEvent('optimizedScroll'));
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    /**
     * Load script dynamically
     */
    window.loadScript = function(src, callback, defer = true) {
        const script = document.createElement('script');
        script.src = src;
        if (defer) script.defer = true;
        if (callback) script.onload = callback;
        document.body.appendChild(script);
    };

    /**
     * Load CSS dynamically
     */
    window.loadCSS = function(href, callback) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.media = 'print';
        link.onload = function() {
            this.media = 'all';
            if (callback) callback();
        };
        document.head.appendChild(link);
    };

    /**
     * Debounce function for performance
     */
    window.debounce = function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    /**
     * Throttle function for performance
     */
    window.throttle = function(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    };

    /**
     * Initialize performance optimizations
     */
    function init() {
        // Initialize lazy loading
        initLazyLoading();

        // Defer non-critical initializations
        deferNonCritical(() => {
            initLinkPrefetch();
            optimizeScroll();
        });

        // Log performance metrics if enabled
        if (PERF_CONFIG.monitorPerformance && 'performance' in window) {
            window.addEventListener('load', () => {
                setTimeout(() => {
                    const perfData = performance.getEntriesByType('navigation')[0];
                    if (perfData) {
                        console.log('[Performance] Page Load:', {
                            'DNS': Math.round(perfData.domainLookupEnd - perfData.domainLookupStart) + 'ms',
                            'TCP': Math.round(perfData.connectEnd - perfData.connectStart) + 'ms',
                            'TTFB': Math.round(perfData.responseStart - perfData.requestStart) + 'ms',
                            'DOM Ready': Math.round(perfData.domContentLoadedEventEnd) + 'ms',
                            'Load': Math.round(perfData.loadEventEnd) + 'ms'
                        });
                    }
                }, 0);
            });
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for external use
    window.PerfOptimizer = {
        loadScript: window.loadScript,
        loadCSS: window.loadCSS,
        debounce: window.debounce,
        throttle: window.throttle,
        deferNonCritical: deferNonCritical
    };
})();

